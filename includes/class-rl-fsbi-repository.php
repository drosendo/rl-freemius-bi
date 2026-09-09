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
		);

		$data = wp_parse_args( $data, $defaults );

		// Explicit cast to float to prevent PHP 8.2+ type coercion errors
		$data['gross'] = (float) $data['gross'];
		$data['net']   = (float) $data['net'];
		$data['fee']   = (float) $data['fee'];

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
		);

		$data = wp_parse_args( $data, $defaults );
		$data['outstanding_balance'] = (float) $data['outstanding_balance'];

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
			'plugin_id'    => 0,
			'license_id'   => 0,
			'user_id'      => 0,
			'plan_id'      => null,
			'license_key'  => null,
			'status'       => 'valid',
			'quota_sites'  => null,
			'active_sites' => 0,
			'expires_at'   => null,
		);

		$data = wp_parse_args( $data, $defaults );

		$existing = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}rl_fsbi_licenses WHERE license_id = %d",
				$data['license_id']
			)
		);

		$formats = array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s' );

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
}
