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
	public function get_payments( $plugin_id, $start_date = null, $end_date = null ) {
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->wpdb->prefix}rl_fsbi_payments WHERE plugin_id = %d",
			$plugin_id
		);

		if ( $start_date ) {
			$query .= $this->wpdb->prepare( ' AND transaction_date >= %s', $start_date . ' 00:00:00' );
		}

		if ( $end_date ) {
			$query .= $this->wpdb->prepare( ' AND transaction_date <= %s', $end_date . ' 23:59:59' );
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
	public function calculate_gross_revenue( $plugin_id, $currency = null, $start_date = null, $end_date = null ) {
		$query = $this->wpdb->prepare(
			"SELECT SUM(CAST(gross AS DECIMAL(10,2))) as total FROM {$this->wpdb->prefix}rl_fsbi_payments WHERE plugin_id = %d AND is_refund = 0",
			$plugin_id
		);

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
	public function calculate_net_revenue( $plugin_id, $currency = null, $start_date = null, $end_date = null ) {
		$query = $this->wpdb->prepare(
			"SELECT SUM(CAST(net AS DECIMAL(10,2))) as total FROM {$this->wpdb->prefix}rl_fsbi_payments WHERE plugin_id = %d AND is_refund = 0",
			$plugin_id
		);

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

		if ( $existing ) {
			return $this->wpdb->update(
				$this->wpdb->prefix . 'rl_fsbi_payments',
				$data,
				array( 'id' => $existing->id ),
				array_fill( 0, count( $data ), '%s' )
			);
		} else {
			return $this->wpdb->insert(
				$this->wpdb->prefix . 'rl_fsbi_payments',
				$data,
				array_fill( 0, count( $data ), '%s' )
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

		if ( $existing ) {
			return $this->wpdb->update(
				$this->wpdb->prefix . 'rl_fsbi_subscriptions',
				$data,
				array( 'id' => $existing->id )
			);
		} else {
			return $this->wpdb->insert(
				$this->wpdb->prefix . 'rl_fsbi_subscriptions',
				$data
			);
		}
	}
}
