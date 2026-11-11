<?php
/**
 * First-run detection of the account's commercial shape.
 *
 * The Freemius Payments API returns no commission, so the revenue share has to
 * be worked out from what the account actually looks like. This class pulls the
 * full payment history, computes the lifetime gross in USD (each payment at its
 * own date) and tests the documented commission models against the rate the
 * balances endpoint reports, picking the closest one and saying how close.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect commission model, currencies, plans, cycles and WordPress.org presence.
 */
class RL_FSBI_Detection {

	/**
	 * Stored detection result.
	 */
	const OPTION_SUMMARY = 'rl_fsbi_detection_summary';

	/**
	 * Whether the administrator has accepted the detection result.
	 */
	const OPTION_ACCEPTED = 'rl_fsbi_detection_accepted';

	/**
	 * Legacy progressive tier boundaries and rates.
	 */
	const TIER_1_LIMIT = 1000.0;
	const TIER_1_RATE  = 27.0;
	const TIER_2_LIMIT = 5000.0;
	const TIER_2_RATE  = 17.0;
	const TIER_3_RATE  = 7.0;

	/**
	 * Date the current pricing started. A first payment before this, with no
	 * rate reported by Freemius, is evidence of the legacy progressive model.
	 */
	const NEW_PRICING_DATE = '2026-10-05';

	/**
	 * Settings manager.
	 *
	 * @var RL_FSBI_Settings_Manager
	 */
	private $settings;

	/**
	 * Summary being assembled, so the model chooser can see the account age.
	 *
	 * @var array
	 */
	private $current_summary = array();

	/**
	 * Constructor.
	 *
	 * @param RL_FSBI_Settings_Manager $settings Settings manager.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Run detection for the given products.
	 *
	 * @param array $plugin_ids Freemius product ids in scope.
	 * @param bool  $persist    Store the summary.
	 * @return array
	 */
	public function run( array $plugin_ids, $persist = true ) {
		$plugin_ids = array_values( array_filter( array_map( 'intval', $plugin_ids ) ) );
		$summary    = array(
			'ran_at'     => current_time( 'mysql' ),
			'plugins'    => array(),
			'model'      => '',
			'model_label' => '',
			'marginal_rate' => 0.0,
			'lifetime_average' => 0.0,
			'observed_rate' => 0.0,
			'confidence' => 0.0,
			'comparison' => array(),
			'currencies' => array(),
			'plans'      => array(),
			'cycles'     => array(),
			'active_installs' => 0,
			'wporg'      => array(),
			'lifetime_gross_usd' => 0.0,
			'warnings'   => array(),
		);

		if ( empty( $plugin_ids ) ) {
			$summary['warnings'][] = __( 'No products in scope, nothing to detect.', 'rl-freemius-bi' );

			return $summary;
		}

		$api = $this->make_api();
		if ( ! $api ) {
			$summary['warnings'][] = __( 'Freemius credentials are missing, so the payment history cannot be read.', 'rl-freemius-bi' );

			return $summary;
		}

		$lifetime_gross_usd = 0.0;
		$payment_currencies = array();
		$sales              = array();

		foreach ( $plugin_ids as $plugin_id ) {
			$payments = $this->fetch_all_payments( $api, $plugin_id );
			$gross    = $this->gross_in_usd( $payments, $payment_currencies, $sales );
			$lifetime_gross_usd += $gross;

			$summary['plugins'][ $plugin_id ] = array(
				'payments'    => count( $payments ),
				'gross_usd'   => round( $gross, 2 ),
			);
		}

		// Where each tier boundary falls in time, so a month can be costed by
		// each payment's position in the lifetime cumulative rather than by an
		// average applied to everything.
		$tier_index = $this->build_tier_index( $sales );
		$summary['first_payment_date'] = $tier_index['first_date'];
		$summary['tier_dates']         = $tier_index['tier_dates'];

		$summary['lifetime_gross_usd'] = round( $lifetime_gross_usd, 2 );
		$summary['currencies']         = array_values( array_unique( $payment_currencies ) );

		$observed = $this->observed_rate( $plugin_ids );
		$summary['observed_rate'] = $observed;

		$models = $this->evaluate_models( $lifetime_gross_usd, $observed );
		$summary['comparison'] = $models;

		$this->current_summary = $summary;

		$chosen = $this->choose_model( $models, $observed );
		$summary['model']              = $chosen['model'];
		$summary['assumed']            = ! empty( $chosen['assumed'] );
		$summary['basis']              = (string) ( $chosen['basis'] ?? '' );
		$summary['model_label']        = $chosen['label'];
		$summary['marginal_rate']      = $chosen['marginal_rate'];
		$summary['lifetime_average']   = $chosen['lifetime_average'];
		$summary['confidence']         = $chosen['confidence'];

		if ( $observed <= 0 ) {
			$summary['warnings'][] = __( 'Freemius reports no commission rate for this account, so the model is a guess until a rate is set. Net revenue uses gross minus refunds and gateway fees in the meantime.', 'rl-freemius-bi' );
		}

		$this->detect_plans_and_cycles( $plugin_ids, $summary );
		$this->detect_installs_and_wporg( $plugin_ids, $summary );

		if ( $persist ) {
			$this->settings->update_option( self::OPTION_SUMMARY, $summary );
			$this->settings->update_option( self::OPTION_ACCEPTED, '' );
		}

		$this->log( 'detection finished', array(
			'model'   => $summary['model'],
			'gross'   => $summary['lifetime_gross_usd'],
			'average' => $summary['lifetime_average'],
			'observed' => $observed,
		) );

		return $summary;
	}

	/**
	 * Commission on a lifetime gross under the legacy progressive tiers.
	 *
	 * @param float $gross Lifetime gross.
	 * @return float
	 */
	public static function progressive_commission( $gross ) {
		$gross = max( 0.0, (float) $gross );
		$first = min( $gross, self::TIER_1_LIMIT );
		$second = min( max( $gross - self::TIER_1_LIMIT, 0.0 ), self::TIER_2_LIMIT - self::TIER_1_LIMIT );
		$third = max( $gross - self::TIER_2_LIMIT, 0.0 );

		return ( $first * self::TIER_1_RATE / 100 ) + ( $second * self::TIER_2_RATE / 100 ) + ( $third * self::TIER_3_RATE / 100 );
	}

	/**
	 * Marginal rate that applies to the next dollar of lifetime gross.
	 *
	 * @param float $gross Lifetime gross.
	 * @return float
	 */
	public static function progressive_marginal_rate( $gross ) {
		$gross = (float) $gross;

		if ( $gross <= self::TIER_1_LIMIT ) {
			return self::TIER_1_RATE;
		}

		if ( $gross <= self::TIER_2_LIMIT ) {
			return self::TIER_2_RATE;
		}

		return self::TIER_3_RATE;
	}

	/**
	 * Current plan: two percentages plus a fixed fee per transaction.
	 *
	 * @return float
	 */
	public static function current_model_rate() {
		return 3.8 + 2.3;
	}

	/**
	 * Build the comparison table for every documented model.
	 *
	 * @param float $gross    Lifetime gross in USD.
	 * @param float $observed Rate reported by balances, as a percentage.
	 * @return array
	 */
	private function evaluate_models( $gross, $observed ) {
		$progressive = self::progressive_commission( $gross );
		$progressive_avg = $gross > 0 ? ( $progressive / $gross ) * 100 : 0.0;
		$current_avg = self::current_model_rate();

		$rows = array(
			'legacy'  => array(
				'label' => __( 'Legacy progressive (27% to $1,000, 17% to $5,000, 7% after)', 'rl-freemius-bi' ),
				'average' => round( $progressive_avg, 2 ),
				'marginal_rate' => self::progressive_marginal_rate( $gross ),
				'commission_usd' => round( $progressive, 2 ),
			),
			'current' => array(
				'label' => __( 'Current (3.8% + 2.3% + fixed fee)', 'rl-freemius-bi' ),
				'average' => round( $current_avg, 2 ),
				'marginal_rate' => $current_avg,
				'commission_usd' => round( $gross * $current_avg / 100, 2 ),
			),
			'flat'    => array(
				'label' => __( 'Flat percentage', 'rl-freemius-bi' ),
				'average' => round( $observed, 2 ),
				'marginal_rate' => $observed,
				'commission_usd' => round( $gross * $observed / 100, 2 ),
			),
		);

		foreach ( $rows as $key => $row ) {
			$rows[ $key ]['difference'] = round( abs( $row['average'] - $observed ), 2 );
		}

		return $rows;
	}

	/**
	 * Pick the model closest to the observed rate.
	 *
	 * - Legacy wins outright when it is within one percentage point, which is
	 *   the documented tolerance for the progressive tiers.
	 * - Otherwise the closest of Current and Flat wins; Flat is the observed
	 *   rate itself, so it is only chosen when nothing else explains it.
	 *
	 * @param array $models   Comparison rows.
	 * @param float $observed Observed rate.
	 * @return array
	 */
	private function choose_model( $models, $observed ) {
		$legacy = $models['legacy'];

		if ( $observed > 0 && $legacy['difference'] <= 1.0 ) {
			return array(
				'model' => 'legacy',
				'label' => __( 'Legacy progressive (auto-detected)', 'rl-freemius-bi' ),
				'marginal_rate' => $legacy['marginal_rate'],
				'lifetime_average' => $legacy['average'],
				'confidence' => $this->confidence( $legacy['difference'], $observed ),
			);
		}

		// No rate from Freemius: account age is the evidence. A first payment
		// before the current pricing started means the account was onboarded under
		// the legacy tiers, so assume progressive - clearly marked as assumed, and
		// applied only if the administrator accepts it.
		if ( $observed <= 0 ) {
			$first_date = (string) ( $this->current_summary['first_payment_date'] ?? '' );
			$assumed    = '' !== $first_date && $first_date < self::NEW_PRICING_DATE;

			if ( $assumed ) {
				return array(
					'model' => 'legacy',
					'label' => __( 'Legacy progressive (assumed from account age)', 'rl-freemius-bi' ),
					'marginal_rate' => $legacy['marginal_rate'],
					'lifetime_average' => $legacy['average'],
					'confidence' => 0.0,
					'assumed' => true,
					'basis' => sprintf(
						/* translators: 1: first payment date, 2: pricing date */
						__( 'First payment on %1$s, before the current pricing started on %2$s, and Freemius reports no rate. Confirm in Freemius -> Earnings -> Payouts Information -> Fees Breakdown.', 'rl-freemius-bi' ),
						$first_date,
						self::NEW_PRICING_DATE
					),
				);
			}

			return array(
				'model' => 'unknown',
				'label' => __( 'Unknown - no rate reported by Freemius', 'rl-freemius-bi' ),
				'marginal_rate' => 0.0,
				'lifetime_average' => 0.0,
				'confidence' => 0.0,
				'assumed' => false,
			);
		}

		if ( $observed > 0 && $models['current']['difference'] < $models['legacy']['difference'] ) {
			return array(
				'model' => 'current',
				'label' => __( 'Current plan, 3.8% + 2.3% + fixed fee (auto-detected)', 'rl-freemius-bi' ),
				'marginal_rate' => $models['current']['marginal_rate'],
				'lifetime_average' => $models['current']['average'],
				'confidence' => $this->confidence( $models['current']['difference'], $observed ),
			);
		}

		return array(
			'model' => 'flat',
			'label' => $observed > 0
				? __( 'Flat percentage (auto-detected)', 'rl-freemius-bi' )
				: __( 'Unknown - no rate reported by Freemius', 'rl-freemius-bi' ),
			'marginal_rate' => $observed,
			'lifetime_average' => $observed,
			'confidence' => $observed > 0 ? $this->confidence( 0.0, $observed ) : 0.0,
		);
	}

	/**
	 * Turn a difference into a 0-100 confidence, rounded.
	 *
	 * @param float $difference Percentage points away from the observed rate.
	 * @param float $observed   Observed rate.
	 * @return float
	 */
	private function confidence( $difference, $observed ) {
		if ( $observed <= 0 ) {
			return 0.0;
		}

		$score = max( 0.0, 100.0 - ( $difference / $observed ) * 100.0 );

		return round( $score, 1 );
	}

	/**
	 * Rate Freemius reports for the account, as a percentage.
	 *
	 * @return float
	 */
	private function observed_rate( array $plugin_ids ) {
		global $wpdb;

		$table = $wpdb->prefix . 'rl_fsbi_balances';
		$ids   = implode( ',', array_map( 'intval', $plugin_ids ) );

		// Scoped to the products being detected: a demo or local product must
		// never decide the rate for a real one.
		$rates = $wpdb->get_col( "SELECT commission_rate FROM {$table} WHERE commission_rate > 0 AND app_id IN ({$ids})" );

		if ( empty( $rates ) ) {
			return 0.0;
		}

		return round( array_sum( array_map( 'floatval', $rates ) ) / count( $rates ), 2 );
	}

	/**
	 * Pull every payment of a product, all pages.
	 *
	 * @param RL_FSBI_API $api       API client.
	 * @param int         $plugin_id Product id.
	 * @return array
	 */
	private function fetch_all_payments( $api, $plugin_id ) {
		$all    = array();
		$offset = 0;
		$count  = 100;

		while ( true ) {
			$page = $api->retrieve_payments( (int) $plugin_id, array( 'count' => $count, 'offset' => $offset ) );

			if ( empty( $page ) ) {
				break;
			}

			foreach ( $page as $payment ) {
				if ( is_array( $payment ) ) {
					$all[] = $payment;
				}
			}

			// A full history pull takes a while and the browser used to sit on a
			// blank page with no sign of life. The count is published so the notice
			// can say what is happening.
			update_option( 'rl_fsbi_detection_progress', array(
				'stage'     => 'payments',
				'plugin_id' => (int) $plugin_id,
				'fetched'   => count( $all ),
				'started'   => (int) get_option( 'rl_fsbi_detection_started', time() ),
				'at'        => time(),
			), false );

			$offset += $count;

			if ( $offset > 20000 ) {
				$this->log( 'payment pull capped', array( 'plugin_id' => $plugin_id, 'offset' => $offset ) );
				break;
			}
		}

		$this->log( 'payment pull', array( 'plugin_id' => $plugin_id, 'payments' => count( $all ) ) );

		return $all;
	}

	/**
	 * Lifetime gross of sales in USD, each payment at its own date.
	 *
	 * Only sale rows count: refunds, chargebacks and disputes are not revenue.
	 *
	 * @param array $payments           Raw payment payloads.
	 * @param array $currencies_seen    Collected currencies, by reference.
	 * @return float
	 */
	private function gross_in_usd( array $payments, array &$currencies_seen, array &$sales = array() ) {
		$total = 0.0;

		foreach ( $payments as $payment ) {
			$type = strtolower( (string) ( $payment['type'] ?? '' ) );
			if ( '' !== $type && 'payment' !== $type ) {
				continue;
			}

			$gross = (float) ( $payment['gross'] ?? 0 );
			if ( $gross <= 0 ) {
				continue;
			}

			$currency = strtoupper( (string) ( $payment['currency'] ?? 'USD' ) );
			if ( ! in_array( $currency, $currencies_seen, true ) ) {
				$currencies_seen[] = $currency;
			}

			$date = isset( $payment['created'] ) ? substr( (string) $payment['created'], 0, 10 ) : '';
			$usd  = $this->to_usd( $gross, $currency, $date );
			$total += $usd;
			$sales[] = array( 'date' => $date, 'usd' => $usd );
		}

		return $total;
	}

	/**
	 * Date at which each progressive tier boundary is crossed.
	 *
	 * Walking the sales in date order gives, for any later payment, the tier it
	 * falls into: everything up to the $1,000 boundary is charged 27%, up to
	 * $5,000 is charged 17%, and anything after is charged 7%.
	 *
	 * @param array $sales Dated USD sales.
	 * @return array{first_date:string,tier_dates:array}
	 */
	private function build_tier_index( array $sales ) {
		usort( $sales, static function ( $a, $b ) {
			return strcmp( (string) $a['date'], (string) $b['date'] );
		} );

		$cumulative = 0.0;
		$first_date = '';
		$tier_dates = array( 'tier1' => '', 'tier2' => '' );

		foreach ( $sales as $sale ) {
			if ( '' === $first_date && '' !== (string) $sale['date'] ) {
				$first_date = (string) $sale['date'];
			}

			$cumulative += (float) $sale['usd'];

			if ( '' === $tier_dates['tier1'] && $cumulative >= self::TIER_1_LIMIT ) {
				$tier_dates['tier1'] = (string) $sale['date'];
			}

			if ( '' === $tier_dates['tier2'] && $cumulative >= self::TIER_2_LIMIT ) {
				$tier_dates['tier2'] = (string) $sale['date'];
			}
		}

		$this->log( 'tier index', array(
			'first_date' => $first_date,
			'tier_dates' => $tier_dates,
			'cumulative' => round( $cumulative, 2 ),
		) );

		return array( 'first_date' => $first_date, 'tier_dates' => $tier_dates );
	}

	/**
	 * Convert an amount to USD at the payment date.
	 *
	 * Uses the same converter the dashboards use, so detection and reporting
	 * cannot disagree about what a dollar is.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Source currency.
	 * @param string $date     Y-m-d.
	 * @return float
	 */
	private function to_usd( $amount, $currency, $date ) {
		if ( 'USD' === $currency || '' === $currency ) {
			return (float) $amount;
		}

		$converter = new RL_FSBI_Admin( 'rl-freemius-bi', defined( 'RL_FSBI_VERSION' ) ? RL_FSBI_VERSION : '' );

		return (float) $converter->convert_currency_amount( (float) $amount, $currency, 'USD', $date );
	}

	/**
	 * Plans and billing cycles seen in the synced subscriptions.
	 *
	 * @param array $plugin_ids Products in scope.
	 * @param array $summary    Summary, by reference.
	 * @return void
	 */
	private function detect_plans_and_cycles( array $plugin_ids, array &$summary ) {
		global $wpdb;

		$table = $wpdb->prefix . 'rl_fsbi_subscriptions';
		$ids   = implode( ',', array_map( 'intval', $plugin_ids ) );

		$plans = $wpdb->get_col( "SELECT DISTINCT plan_id FROM {$table} WHERE plugin_id IN ({$ids}) AND plan_id > 0" );
		$cycles = $wpdb->get_col( "SELECT DISTINCT billing_cycle FROM {$table} WHERE plugin_id IN ({$ids}) AND billing_cycle IS NOT NULL AND billing_cycle <> ''" );

		$summary['plans']  = array_values( array_map( 'intval', $plans ) );
		$summary['cycles'] = array_values( array_map( 'strval', $cycles ) );

		$this->log( 'plans and cycles', $summary['plans'] + $summary['cycles'] );
	}

	/**
	 * Active installs from the synced catalog and a WordPress.org slug probe.
	 *
	 * @param array $plugin_ids Products in scope.
	 * @param array $summary    Summary, by reference.
	 * @return void
	 */
	private function detect_installs_and_wporg( array $plugin_ids, array &$summary ) {
		$catalog = $this->settings->get_option( 'rl_fsbi_plugins_catalog', array() );
		$catalog = is_array( $catalog ) ? $catalog : array();
		$total   = 0;

		foreach ( $plugin_ids as $plugin_id ) {
			$entry = $catalog[ (string) $plugin_id ] ?? array();
			$total += (int) ( $entry['active_installs'] ?? 0 );

			$slug = sanitize_title( (string) ( $entry['slug'] ?? '' ) );
			if ( '' === $slug ) {
				$summary['wporg'][ $plugin_id ] = array( 'slug' => '', 'found' => false, 'reason' => 'no freemius slug' );
				continue;
			}

			$result = $this->probe_wporg( $slug );
			$summary['wporg'][ $plugin_id ] = $result;

			// Prefill the slug field, but never overwrite a manual value.
			if ( ! empty( $result['found'] ) ) {
				$current = (string) $this->settings->get_option( 'rl_fsbi_wporg_slug_' . (int) $plugin_id, '' );
				if ( '' === trim( $current ) ) {
					$this->settings->update_option( 'rl_fsbi_wporg_slug_' . (int) $plugin_id, $slug );
					$this->log( 'wporg slug prefilled', array( 'plugin_id' => $plugin_id, 'slug' => $slug ) );
				}
			}
		}

		$summary['active_installs'] = $total;
	}

	/**
	 * Ask WordPress.org whether a slug exists.
	 *
	 * @param string $slug Plugin slug.
	 * @return array{slug:string,found:bool,active_installs:int,version:string,reason:string}
	 */
	private function probe_wporg( $slug ) {
		$url = add_query_arg(
			array( 'action' => 'plugin_information', 'request[slug]' => $slug ),
			'https://api.wordpress.org/plugins/info/1.2/'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => array( 'Accept' => 'application/json' ) ) );

		if ( is_wp_error( $response ) ) {
			$this->log( 'wporg probe failed', array( 'slug' => $slug, 'error' => $response->get_error_message() ) );

			return array( 'slug' => $slug, 'found' => false, 'active_installs' => 0, 'version' => '', 'reason' => $response->get_error_message() );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! empty( $body['error'] ) ) {
			return array( 'slug' => $slug, 'found' => false, 'active_installs' => 0, 'version' => '', 'reason' => 'not found on WordPress.org' );
		}

		return array(
			'slug' => $slug,
			'found' => true,
			'active_installs' => (int) ( $body['active_installs'] ?? 0 ),
			'version' => (string) ( $body['version'] ?? '' ),
			'reason' => '',
		);
	}

	/**
	 * Build the Freemius API client from the stored credentials.
	 *
	 * @return RL_FSBI_API|null
	 */
	private function make_api() {
		$developer_id = (string) $this->settings->get_option( 'rl_fsbi_developer_id', '' );
		$public_key   = (string) $this->settings->get_option( 'rl_fsbi_public_key', '' );
		$secret_key   = (string) $this->settings->get_option( 'rl_fsbi_secret_key', '' );

		if ( '' === $developer_id || '' === $secret_key ) {
			return null;
		}

		return new RL_FSBI_API( $developer_id, $public_key, $secret_key );
	}

	/**
	 * Write a detection step to the PHP log when Debug Mode is on.
	 *
	 * @param string $message Message.
	 * @param mixed  $data    Optional payload.
	 * @return void
	 */
	private function log( $message, $data = null ) {
		if ( ! $this->settings->is_debug_mode() ) {
			return;
		}

		$suffix = null === $data ? '' : ' ' . wp_json_encode( $data );
		error_log( '[rl-fsbi] detect: ' . $message . $suffix ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
