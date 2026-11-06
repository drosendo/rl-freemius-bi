<?php
/**
 * Database queries and analytics for RL Freemius BI.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes
 */

class RL_FSBI_Repository {

	private $wpdb;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Get all payments for a plugin within a date range.
	 *
	 * @param  int    $plugin_id Plugin ID.
	 * @param  string $start_date Start date (YYYY-MM-DD).
	 * @param  string $end_date End date (YYYY-MM-DD).
	 * @return array Array of payments.
	 */
	public function get_payments( $plugin_id = 0, $start_date = null, $end_date = null, $currency = null ) {
		$query = "SELECT * FROM {$this->wpdb->prefix}rl_fsbi_payments WHERE 1=1";

		if ( $plugin_id > 0 ) {
			$query .= $this->wpdb->prepare( ' AND plugin_id = %d', $plugin_id );
		}

		if ( $start_date ) {
			$query .= $this->wpdb->prepare( ' AND transaction_date >= %s', $start_date . ' 00:00:00' );
		}

		if ( $end_date ) {
			$query .= $this->wpdb->prepare( ' AND transaction_date <= %s', $end_date . ' 23:59:59' );
		}

		if ( $currency ) {
			$query .= $this->wpdb->prepare( ' AND currency = %s', $currency );
		}

		$query .= ' ORDER BY transaction_date DESC';

		return $this->wpdb->get_results( $query );
	}

	/**
	 * Calculate total gross revenue by currency.
	 *
	 * @param  int    $plugin_id Plugin ID.
	 * @param  string $currency Currency code (USD, EUR, GBP).
	 * @param  string $start_date Start date (YYYY-MM-DD).
	 * @param  string $end_date End date (YYYY-MM-DD).
	 * @return float Total gross revenue.
	 */
	public function calculate_gross_revenue( $plugin_id = 0, $currency = null, $start_date = null, $end_date = null ) {
		$query = "SELECT SUM(CAST(gross AS DECIMAL(10,2))) as total FROM {$this->wpdb->prefix}rl_fsbi_payments WHERE is_refund = 0";

		if ( $plugin_id > 0 ) {
			$query .= $this->wpdb->prepare( ' AND plugin_id = %d', $plugin_id );
		}

		if ( $currency ) {
			$query .= $this->wpdb->prepare( ' AND currency = %s', $currency );
		}

		if ( $start_date ) {
			$query .= $this->wpdb->prepare( ' AND transaction_date >= %s', $start_date . ' 00:00:00' );
		}

		if ( $end_date ) {
			$query .= $this->wpdb->prepare( ' AND transaction_date <= %s', $end_date . ' 23:59:59' );
		}

		$result = $this->wpdb->get_var( $query );
		return $result ? (float) $result : 0.0;
	}

	/**
	 * Calculate total net revenue (gross - fees).
	 *
	 * @param  int    $plugin_id Plugin ID.
	 * @param  string $currency Currency code.
	 * @param  string $start_date Start date (YYYY-MM-DD).
	 * @param  string $end_date End date (YYYY-MM-DD).
	 * @return float Total net revenue.
	 */
	public function calculate_net_revenue( $plugin_id = 0, $currency = null, $start_date = null, $end_date = null ) {
		$query = "SELECT SUM(CAST(net AS DECIMAL(10,2))) as total FROM {$this->wpdb->prefix}rl_fsbi_payments WHERE is_refund = 0";

		if ( $plugin_id > 0 ) {
			$query .= $this->wpdb->prepare( ' AND plugin_id = %d', $plugin_id );
		}

		if ( $currency ) {
			$query .= $this->wpdb->prepare( ' AND currency = %s', $currency );
		}

		if ( $start_date ) {
			$query .= $this->wpdb->prepare( ' AND transaction_date >= %s', $start_date . ' 00:00:00' );
		}

		if ( $end_date ) {
			$query .= $this->wpdb->prepare( ' AND transaction_date <= %s', $end_date . ' 23:59:59' );
		}

		$result = $this->wpdb->get_var( $query );
		return $result ? (float) $result : 0.0;
	}

	/**
	 * Get available currencies from payment data.
	 *
	 * @param  int $plugin_id Plugin ID (0 for all).
	 * @return array Array of currency codes.
	 */
	public function get_available_currencies( $plugin_id = 0 ) {
		$query = "SELECT DISTINCT currency FROM {$this->wpdb->prefix}rl_fsbi_payments WHERE 1=1";

		if ( $plugin_id ) {
			$query .= $this->wpdb->prepare( ' AND plugin_id = %d', $plugin_id );
		}

		$query .= ' ORDER BY currency';

		$results = $this->wpdb->get_results( $query );
		return wp_list_pluck( $results, 'currency' );
	}

	/**
	 * Insert or update a payment record.
	 *
	 * @param  array $data Payment data.
	 * @return int|false Payment ID or false on failure.
	 */
	public function upsert_payment( $data ) {
		$defaults = array(
			'plugin_id'       => 0,
			'payment_id'      => 0,
			'user_id'         => 0,
			'subscription_id' => null,
			'transaction_date' => current_time( 'mysql' ),
			'gross'           => 0,
			'net'             => 0,
			'fee'             => 0,
			'currency'        => 'USD',
			'payment_method'  => null,
			'status'          => 'completed',
			'country_code'    => null,
			'is_refund'       => 0,
			'refund_id'       => null,
			'metadata'        => null,
			'payment_type'    => null,
			'is_renewal'      => 0,
			'gateway'         => null,
			'gateway_fee'     => null,
			'vat'             => null,
			'coupon_id'       => null,
			'plan_id'         => null,
			'pricing_id'      => null,
			'license_id'      => null,
			'install_id'      => null,
			'bound_payment_id' => null,
			'refund_reason'   => null,
		);

		$data = wp_parse_args( $data, $defaults );

		// Explicit cast to float to prevent PHP 8.2+ type coercion errors
		$data['gross']       = (float) $data['gross'];
		$data['net']         = (float) $data['net'];
		$data['fee']         = (float) $data['fee'];
		$data['is_renewal']  = ! empty( $data['is_renewal'] ) ? 1 : 0;
		$data['gateway_fee'] = null === $data['gateway_fee'] ? null : (float) $data['gateway_fee'];
		$data['vat']         = null === $data['vat'] ? null : (float) $data['vat'];

		$existing = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}rl_fsbi_payments WHERE payment_id = %d",
				$data['payment_id']
			)
		);

		$formats = array(
			'%d', // plugin_id
			'%d', // payment_id
			'%d', // user_id
			'%d', // subscription_id
			'%s', // transaction_date
			'%f', // gross
			'%f', // net
			'%f', // fee
			'%s', // currency
			'%s', // payment_method
			'%s', // status
			'%s', // country_code
			'%d', // is_refund
			'%d', // refund_id
			'%s', // metadata
			'%s', // payment_type
			'%d', // is_renewal
			'%s', // gateway
			'%f', // gateway_fee
			'%f', // vat
			'%d', // coupon_id
			'%d', // plan_id
			'%d', // pricing_id
			'%d', // license_id
			'%d', // install_id
			'%d', // bound_payment_id
			'%s', // refund_reason
		);

		if ( $existing ) {
			return $this->wpdb->update(
				$this->wpdb->prefix . 'rl_fsbi_payments',
				$data,
				array( 'id' => $existing->id ),
				$formats,
				array( '%d' )
			);
		} else {
			return $this->wpdb->insert(
				$this->wpdb->prefix . 'rl_fsbi_payments',
				$data,
				$formats
			);
		}
	}

	/**
	 * Insert or update a subscription record.
	 *
	 * @param  array $data Subscription data.
	 * @return int|false ID or false on failure.
	 */
	public function upsert_subscription( $data ) {
		$defaults = array(
			'plugin_id'        => 0,
			'subscription_id'  => 0,
			'user_id'          => 0,
			'plan_id'          => null,
			'status'           => 'active',
			'billing_cycle'    => null,
			'trial_ends'       => null,
			'next_renewal'     => null,
			'expires_at'       => null,
			'outstanding_balance' => 0,
			'currency'         => 'USD',
			'metadata'         => null,
			'failed_payments'  => 0,
			'canceled_at'      => null,
			'amount_per_cycle' => null,
			'renewal_amount'   => null,
			'initial_amount'   => null,
			'total_gross'      => null,
			'tax_rate'         => null,
			'pricing_id'       => null,
			'license_id'       => null,
			'install_id'       => null,
		);

		$data = wp_parse_args( $data, $defaults );
		$data['outstanding_balance'] = (float) $data['outstanding_balance'];
		$data['failed_payments']     = (int) $data['failed_payments'];

		$existing = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}rl_fsbi_subscriptions WHERE subscription_id = %d",
				$data['subscription_id']
			)
		);

		$formats = array(
			'%d', // plugin_id
			'%d', // subscription_id
			'%d', // user_id
			'%d', // plan_id
			'%s', // status
			'%s', // billing_cycle
			'%s', // trial_ends
			'%s', // next_renewal
			'%s', // expires_at
			'%f', // outstanding_balance
			'%s', // currency
			'%s', // metadata
			'%d', // failed_payments
			'%s', // canceled_at
			'%f', // amount_per_cycle
			'%f', // renewal_amount
			'%f', // initial_amount
			'%f', // total_gross
			'%f', // tax_rate
			'%d', // pricing_id
			'%d', // license_id
			'%d', // install_id
		);

		if ( $existing ) {
			return $this->wpdb->update(
				$this->wpdb->prefix . 'rl_fsbi_subscriptions',
				$data,
				array( 'id' => $existing->id ),
				$formats,
				array( '%d' )
			);
		} else {
			return $this->wpdb->insert(
				$this->wpdb->prefix . 'rl_fsbi_subscriptions',
				$data,
				$formats
			);
		}
	}

	/**
	 * Insert or update a license record.
	 *
	 * @param array $data License data.
	 * @return int|false
	 */
	public function upsert_license( $data ) {
		$defaults = array(
			'plugin_id'     => 0,
			'license_id'    => 0,
			'user_id'       => 0,
			'plan_id'       => null,
			'pricing_id'    => null,
			'license_key'   => null,
			'status'        => 'active',
			'quota_sites'   => null,
			'active_sites'  => 0,
			'activated'     => 0,
			'is_cancelled'  => 0,
			'expires_at'    => null,
			'fs_created_at' => null,
			'fs_updated_at' => null,
			'updated_at'    => null,
		);

		$data = wp_parse_args( $data, $defaults );

		$existing = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}rl_fsbi_licenses WHERE license_id = %d",
				$data['license_id']
			)
		);

		$formats = array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' );

		if ( $existing ) {
			return $this->wpdb->update(
				$this->wpdb->prefix . 'rl_fsbi_licenses',
				$data,
				array( 'id' => $existing->id ),
				$formats,
				array( '%d' )
			);
		}

		return $this->wpdb->insert( $this->wpdb->prefix . 'rl_fsbi_licenses', $data, $formats );
	}

	/**
	 * Insert or update a plan record.
	 *
	 * @param array $data Plan data.
	 * @return int|false
	 */
	public function upsert_plan( $data ) {
		$defaults = array(
			'plugin_id'     => 0,
			'plan_id'       => 0,
			'plan_name'     => '',
			'description'   => null,
			'price_usd'     => null,
			'price_eur'     => null,
			'price_gbp'     => null,
			'billing_cycle' => null,
			'trial_days'    => null,
			'features'      => null,
		);

		$data = wp_parse_args( $data, $defaults );

		$existing = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}rl_fsbi_plans WHERE plan_id = %d",
				$data['plan_id']
			)
		);

		$formats = array( '%d', '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%d', '%s' );

		if ( $existing ) {
			return $this->wpdb->update(
				$this->wpdb->prefix . 'rl_fsbi_plans',
				$data,
				array( 'id' => $existing->id ),
				$formats,
				array( '%d' )
			);
		}

		return $this->wpdb->insert( $this->wpdb->prefix . 'rl_fsbi_plans', $data, $formats );
	}

	/**
	 * Insert or update developer balance record.
	 *
	 * @param array $data Balance data.
	 * @return int|false
	 */
	public function upsert_balance( $data ) {
		$defaults = array(
			'app_id'             => 0,
			'developer_id'       => 0,
			'processed_balance'  => 0,
			'pending_balance'    => 0,
			'commission_rate'    => 0,
			'commission_amount'  => 0,
			'net_payout'         => 0,
			'currency'           => 'USD',
		);

		$data = wp_parse_args( $data, $defaults );

		$existing = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}rl_fsbi_balances WHERE app_id = %d AND developer_id = %d",
				$data['app_id'],
				$data['developer_id']
			)
		);

		$formats = array( '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%s' );

		if ( $existing ) {
			return $this->wpdb->update(
				$this->wpdb->prefix . 'rl_fsbi_balances',
				$data,
				array( 'id' => $existing->id ),
				$formats,
				array( '%d' )
			);
		}

		return $this->wpdb->insert( $this->wpdb->prefix . 'rl_fsbi_balances', $data, $formats );
	}

	/**
	 * Count active subscriptions for dashboard KPIs.
	 *
	 * @param int         $plugin_id Plugin ID or 0 for all.
	 * @param string|null $currency  Currency code filter.
	 * @return int
	 */
	public function get_active_subscriptions_count( $plugin_id = 0, $currency = null ) {
		$query = "SELECT COUNT(DISTINCT subscription_id) FROM {$this->wpdb->prefix}rl_fsbi_subscriptions WHERE status = 'active'";

		if ( $plugin_id > 0 ) {
			$query .= $this->wpdb->prepare( ' AND plugin_id = %d', $plugin_id );
		}

		if ( $currency ) {
			$query .= $this->wpdb->prepare( ' AND currency = %s', $currency );
		}

		$result = $this->wpdb->get_var( $query );

		if ( null === $result ) {
			return 0;
		}

		return (int) $result;
	}

	/**
	 * Insert or update a trial record.
	 *
	 * @param array $data Trial data.
	 * @return int|false
	 */
	public function upsert_trial( $data ) {
		$defaults = array(
			'plugin_id'           => 0,
			'trial_id'            => 0,
			'user_id'             => 0,
			'payment_id'          => null,
			'subscription_id'     => null,
			'license_id'          => null,
			'started_at'          => null,
			'trial_ends_at'       => null,
			'with_payment_method' => 0,
			'canceled_at'         => null,
			'converted_at'        => null,
			'environment'         => null,
			'metadata'            => null,
		);

		$data = wp_parse_args( $data, $defaults );
		$data['with_payment_method'] = ! empty( $data['with_payment_method'] ) ? 1 : 0;

		$existing = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}rl_fsbi_trials WHERE trial_id = %d",
				$data['trial_id']
			)
		);

		$formats = array( '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );

		if ( $existing ) {
			return $this->wpdb->update(
				$this->wpdb->prefix . 'rl_fsbi_trials',
				$data,
				array( 'id' => $existing->id ),
				$formats,
				array( '%d' )
			);
		}

		return $this->wpdb->insert( $this->wpdb->prefix . 'rl_fsbi_trials', $data, $formats );
	}

	/**
	 * Insert or update an install record.
	 *
	 * @param array $data Install data.
	 * @return int|false
	 */
	public function upsert_install( $data ) {
		$defaults = array(
			'plugin_id'      => 0,
			'install_id'     => 0,
			'user_id'        => 0,
			'license_id'     => null,
			'plan_id'        => null,
			'site_id'        => null,
			'title'          => null,
			'url'            => null,
			'version'        => null,
			'is_active'      => 1,
			'is_premium'     => 0,
			'is_uninstalled' => 0,
			'last_seen_at'   => null,
			'environment'    => null,
			'metadata'       => null,
		);

		$data = wp_parse_args( $data, $defaults );
		$data['is_active']      = ! empty( $data['is_active'] ) ? 1 : 0;
		$data['is_premium']     = ! empty( $data['is_premium'] ) ? 1 : 0;
		$data['is_uninstalled'] = ! empty( $data['is_uninstalled'] ) ? 1 : 0;

		$existing = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}rl_fsbi_installs WHERE install_id = %d",
				$data['install_id']
			)
		);

		$formats = array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' );

		if ( $existing ) {
			return $this->wpdb->update(
				$this->wpdb->prefix . 'rl_fsbi_installs',
				$data,
				array( 'id' => $existing->id ),
				$formats,
				array( '%d' )
			);
		}

		return $this->wpdb->insert( $this->wpdb->prefix . 'rl_fsbi_installs', $data, $formats );
	}

	/**
	 * Insert or update the uninstall feedback for an install.
	 *
	 * @param array $data Uninstall data.
	 * @return int|false
	 */
	public function upsert_uninstall( $data ) {
		$defaults = array(
			'plugin_id'      => 0,
			'install_id'     => 0,
			'uninstall_id'   => 0,
			'site_id'        => null,
			'reason_id'      => 0,
			'reason_label'   => null,
			'reason_info'    => null,
			'uninstalled_at' => null,
			'metadata'       => null,
		);

		$data = wp_parse_args( $data, $defaults );

		$existing = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}rl_fsbi_uninstalls WHERE plugin_id = %d AND install_id = %d",
				$data['plugin_id'],
				$data['install_id']
			)
		);

		$formats = array( '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' );

		if ( $existing ) {
			return $this->wpdb->update(
				$this->wpdb->prefix . 'rl_fsbi_uninstalls',
				$data,
				array( 'id' => $existing->id ),
				$formats,
				array( '%d' )
			);
		}

		return $this->wpdb->insert( $this->wpdb->prefix . 'rl_fsbi_uninstalls', $data, $formats );
	}

	/**
	 * Trial funnel counts for the dashboard.
	 *
	 * @param int    $plugin_id  Plugin ID or 0 for all.
	 * @param string $start_date Period start (YYYY-MM-DD).
	 * @param string $end_date   Period end (YYYY-MM-DD).
	 * @return array
	 */
	public function get_trial_funnel( $plugin_id = 0, $start_date = null, $end_date = null ) {
		$table = $this->wpdb->prefix . 'rl_fsbi_trials';
		$where = ' WHERE 1=1';

		if ( $plugin_id > 0 ) {
			$where .= $this->wpdb->prepare( ' AND plugin_id = %d', $plugin_id );
		}

		if ( $start_date ) {
			$where .= $this->wpdb->prepare( ' AND started_at >= %s', $start_date . ' 00:00:00' );
		}

		if ( $end_date ) {
			$where .= $this->wpdb->prepare( ' AND started_at <= %s', $end_date . ' 23:59:59' );
		}

		$row = $this->wpdb->get_row(
			"SELECT COUNT(*) AS started,
				SUM(CASE WHEN with_payment_method = 1 THEN 1 ELSE 0 END) AS with_payment_method,
				SUM(CASE WHEN converted_at IS NOT NULL THEN 1 ELSE 0 END) AS converted,
				SUM(CASE WHEN canceled_at IS NOT NULL AND converted_at IS NULL THEN 1 ELSE 0 END) AS canceled
			FROM {$table}{$where}"
		);

		if ( ! $row ) {
			return array(
				'started'             => 0,
				'with_payment_method' => 0,
				'converted'           => 0,
				'canceled'            => 0,
				'rate'                => 0.0,
			);
		}

		$started   = (int) $row->started;
		$converted = (int) $row->converted;

		return array(
			'started'             => $started,
			'with_payment_method' => (int) $row->with_payment_method,
			'converted'           => $converted,
			'canceled'            => (int) $row->canceled,
			'rate'                => $started > 0 ? round( ( $converted / $started ) * 100, 2 ) : 0.0,
		);
	}

	/**
	 * Uninstall reasons grouped for the dashboard, most frequent first.
	 *
	 * @param int    $plugin_id Plugin ID or 0 for all.
	 * @param string $since     Only count uninstalls on or after this date.
	 * @param int    $limit     Maximum number of reasons.
	 * @return array
	 */
	public function get_churn_reasons( $plugin_id = 0, $since = null, $limit = 8 ) {
		$table = $this->wpdb->prefix . 'rl_fsbi_uninstalls';
		$where = ' WHERE 1=1';

		if ( $plugin_id > 0 ) {
			$where .= $this->wpdb->prepare( ' AND plugin_id = %d', $plugin_id );
		}

		if ( $since ) {
			$where .= $this->wpdb->prepare( ' AND (uninstalled_at IS NULL OR uninstalled_at >= %s)', $since . ' 00:00:00' );
		}

		$limit = max( 1, min( 20, (int) $limit ) );

		$rows = $this->wpdb->get_results(
			"SELECT reason_id, COUNT(*) AS total,
				MAX(reason_label) AS reason_label,
				MAX(reason_info) AS reason_info
			FROM {$table}{$where}
			GROUP BY reason_id
			ORDER BY total DESC
			LIMIT {$limit}"
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'reason_id'    => (int) $row->reason_id,
				'count'        => (int) $row->total,
				'reason_label' => isset( $row->reason_label ) ? (string) $row->reason_label : '',
				'reason_info'  => isset( $row->reason_info ) ? (string) $row->reason_info : '',
			);
		}

		return $out;
	}

	/**
	 * Count uninstalls in a period, including installs whose reason was not
	 * returned by the API yet.
	 *
	 * @param int    $plugin_id  Plugin ID or 0 for all.
	 * @param string $start_date Period start (YYYY-MM-DD).
	 * @param string $end_date   Period end (YYYY-MM-DD).
	 * @return int
	 */
	public function count_uninstalls( $plugin_id = 0, $start_date = null, $end_date = null ) {
		$table = $this->wpdb->prefix . 'rl_fsbi_installs';
		$where = ' WHERE is_uninstalled = 1';

		if ( $plugin_id > 0 ) {
			$where .= $this->wpdb->prepare( ' AND plugin_id = %d', $plugin_id );
		}

		if ( $start_date ) {
			$where .= $this->wpdb->prepare( ' AND last_seen_at >= %s', $start_date . ' 00:00:00' );
		}

		if ( $end_date ) {
			$where .= $this->wpdb->prepare( ' AND last_seen_at <= %s', $end_date . ' 23:59:59' );
		}

		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$where}" );
	}

	/**
	 * Install IDs that need their uninstall reason fetched.
	 *
	 * @param int $plugin_id Plugin ID.
	 * @param int $limit     Maximum number of installs.
	 * @return int[]
	 */
	public function get_installs_missing_uninstall_reason( $plugin_id, $limit = 3 ) {
		$installs   = $this->wpdb->prefix . 'rl_fsbi_installs';
		$uninstalls = $this->wpdb->prefix . 'rl_fsbi_uninstalls';
		$limit      = max( 1, min( 10, (int) $limit ) );

		$rows = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT i.install_id FROM {$installs} i
				LEFT JOIN {$uninstalls} u ON u.install_id = i.install_id AND u.plugin_id = i.plugin_id
				WHERE i.plugin_id = %d AND i.is_uninstalled = 1 AND u.id IS NULL
				ORDER BY i.last_seen_at DESC
				LIMIT {$limit}",
				$plugin_id
			)
		);

		return array_map( 'intval', (array) $rows );
	}
}
