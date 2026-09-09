<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/admin
 */

class RL_FSBI_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @access   private
	 * @var      string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @access   private
	 * @var      string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Settings manager instance
	 *
	 * @access   private
	 * @var      RL_FSBI_Settings_Manager $settings
	 */
	private $settings;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->settings    = new RL_FSBI_Settings_Manager();
	}

	/**
	 * Register the stylesheets for the admin area.
	 */
	public function enqueue_styles() {
		if ( ! $this->is_plugin_page() ) {
			return;
		}

		wp_enqueue_style(
			$this->plugin_name . '-admin',
			RL_FSBI_PLUGIN_URL . 'assets/css/admin/rl-fsbi-admin.css',
			array(),
			$this->version,
			'all'
		);
	}

	/**
	 * Register the JavaScript for the admin area.
	 */
	public function enqueue_scripts() {
		if ( ! $this->is_plugin_page() ) {
			return;
		}

		wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@3/dist/chart.min.js', array(), '3.9.1', false );
		wp_enqueue_script( 'datatables-js', 'https://cdn.jsdelivr.net/npm/datatables.net@1/js/jquery.dataTables.min.js', array( 'jquery' ), '1.13.4', false );
		wp_enqueue_style( 'datatables-css', 'https://cdn.jsdelivr.net/npm/datatables.net-dt@1/css/jquery.dataTables.min.css', array(), '1.13.4', 'all' );

		wp_enqueue_script(
			$this->plugin_name . '-admin',
			RL_FSBI_PLUGIN_URL . 'assets/js/admin/rl-fsbi-admin.js',
			array( 'jquery', 'chart-js', 'datatables-js' ),
			$this->version,
			false
		);

		wp_localize_script( $this->plugin_name . '-admin', 'rlFsbiAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'rl_fsbi_nonce' ),
		) );
	}

	/**
	 * Add the admin menu page.
	 */
	public function add_plugin_admin_menu() {
		add_menu_page(
			esc_html__( 'RL Freemius BI', 'rl-freemius-bi' ),
			esc_html__( 'Freemius BI', 'rl-freemius-bi' ),
			'manage_options',
			$this->plugin_name,
			array( $this, 'display_admin_page' ),
			'dashicons-chart-line',
			25
		);
		// Settings menu is auto-registered by the framework
	}

	/**
	 * Render the admin page.
	 */
	public function display_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rl-freemius-bi' ) );
		}

		require RL_FSBI_PLUGIN_DIR . 'admin/partials/rl-fsbi-dashboard.php';
	}

	/**
	 * Handle AJAX sync request.
	 */
	public function handle_sync_ajax() {
		check_ajax_referer( 'rl_fsbi_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Unauthorized', 'rl-freemius-bi' ) );
		}

		$result = $this->sync_freemius_data();

		if ( $result ) {
			wp_send_json_success( array(
				'message' => esc_html__( 'Data synchronized successfully', 'rl-freemius-bi' ),
			) );
		} else {
			wp_send_json_error( esc_html__( 'Sync failed. Check your API credentials.', 'rl-freemius-bi' ) );
		}
	}

	/**
	 * Scheduled sync callback.
	 */
	public function scheduled_sync() {
		$this->sync_freemius_data();
	}

	/**
	 * Sync data from Freemius API.
	 *
	 * @return bool Success status.
	 */
	private function sync_freemius_data() {
		$developer_id = $this->settings->get_option( 'rl_fsbi_developer_id' );
		$public_key   = $this->settings->get_option( 'rl_fsbi_public_key' );
		$secret_key   = $this->settings->get_option( 'rl_fsbi_secret_key' );

		if ( ! $developer_id || ! $public_key || ! $secret_key ) {
			return false;
		}

		$api = new RL_FSBI_API( $developer_id, $public_key, $secret_key );
		$repo = new RL_FSBI_Repository();

		// Fetch plugins
		$plugins = $api->retrieve_plugins();
		if ( empty( $plugins ) ) {
			return false;
		}

		foreach ( $plugins as $plugin ) {
			$plugin_id = intval( $plugin['id'] );

			// Sync payments with pagination
			$this->sync_payments( $plugin_id, $api, $repo );

			// Sync subscriptions with pagination
			$this->sync_subscriptions( $plugin_id, $api, $repo );

			// Sync licenses with pagination
			$this->sync_licenses( $plugin_id, $api, $repo );

			// Sync plans
			$this->sync_plans( $plugin_id, $api, $repo );
		}

		return true;
	}

	/**
	 * Sync payments with pagination.
	 *
	 * @param int                $plugin_id Plugin ID.
	 * @param RL_FSBI_API        $api API instance.
	 * @param RL_FSBI_Repository $repo Repository instance.
	 */
	private function sync_payments( $plugin_id, $api, $repo ) {
		$offset = 0;
		$count  = 50;

		while ( true ) {
			$payments = $api->retrieve_payments( $plugin_id, array(
				'count'  => $count,
				'offset' => $offset,
			) );

			if ( empty( $payments ) ) {
				break;
			}

			foreach ( $payments as $payment ) {
				$data = array(
					'plugin_id'        => $plugin_id,
					'payment_id'       => intval( $payment['id'] ),
					'user_id'          => intval( $payment['user_id'] ),
					'subscription_id'  => isset( $payment['subscription_id'] ) ? intval( $payment['subscription_id'] ) : null,
					'transaction_date' => sanitize_text_field( $payment['created'] ),
					'gross'            => (float) $payment['gross'],
					'net'              => (float) $payment['net'],
					'fee'              => (float) ( isset( $payment['fee'] ) ? $payment['fee'] : 0 ),
					'currency'         => sanitize_text_field( $payment['currency'] ),
					'payment_method'   => sanitize_text_field( $payment['payment_method'] ),
					'status'           => sanitize_text_field( $payment['status'] ),
					'country_code'     => isset( $payment['country_code'] ) ? sanitize_text_field( $payment['country_code'] ) : null,
					'is_refund'        => isset( $payment['refund_id'] ) ? 1 : 0,
					'refund_id'        => isset( $payment['refund_id'] ) ? intval( $payment['refund_id'] ) : null,
				);

				$repo->upsert_payment( $data );
			}

			$offset += $count;
		}
	}

	/**
	 * Sync subscriptions with pagination.
	 *
	 * @param int                $plugin_id Plugin ID.
	 * @param RL_FSBI_API        $api API instance.
	 * @param RL_FSBI_Repository $repo Repository instance.
	 */
	private function sync_subscriptions( $plugin_id, $api, $repo ) {
		$offset = 0;
		$count  = 50;

		while ( true ) {
			$subscriptions = $api->retrieve_subscriptions( $plugin_id, array(
				'count'  => $count,
				'offset' => $offset,
			) );

			if ( empty( $subscriptions ) ) {
				break;
			}

			foreach ( $subscriptions as $subscription ) {
				$data = array(
					'plugin_id'           => $plugin_id,
					'subscription_id'     => intval( $subscription['id'] ),
					'user_id'             => intval( $subscription['user_id'] ),
					'plan_id'             => intval( $subscription['plan_id'] ),
					'status'              => sanitize_text_field( $subscription['status'] ),
					'billing_cycle'       => sanitize_text_field( $subscription['billing_cycle'] ),
					'trial_ends'          => isset( $subscription['trial_ends'] ) ? sanitize_text_field( $subscription['trial_ends'] ) : null,
					'next_renewal'        => sanitize_text_field( $subscription['next_billing'] ),
					'expires_at'          => isset( $subscription['end_date'] ) ? sanitize_text_field( $subscription['end_date'] ) : null,
					'outstanding_balance' => (float) ( isset( $subscription['outstanding_balance'] ) ? $subscription['outstanding_balance'] : 0 ),
					'currency'            => sanitize_text_field( $subscription['currency'] ),
				);

				$repo->upsert_subscription( $data );
			}

			$offset += $count;
		}
	}

	/**
	 * Sync licenses with pagination.
	 *
	 * @param int                $plugin_id Plugin ID.
	 * @param RL_FSBI_API        $api API instance.
	 * @param RL_FSBI_Repository $repo Repository instance.
	 */
	private function sync_licenses( $plugin_id, $api, $repo ) {
		$offset = 0;
		$count  = 50;

		while ( true ) {
			$licenses = $api->retrieve_licenses( $plugin_id, array(
				'count'  => $count,
				'offset' => $offset,
			) );

			if ( empty( $licenses ) ) {
				break;
			}

			// License sync logic to be implemented
			$offset += $count;
		}
	}

	/**
	 * Sync plans.
	 *
	 * @param int                $plugin_id Plugin ID.
	 * @param RL_FSBI_API        $api API instance.
	 * @param RL_FSBI_Repository $repo Repository instance.
	 */
	private function sync_plans( $plugin_id, $api, $repo ) {
		$plans = $api->retrieve_plans( $plugin_id );

		foreach ( $plans as $plan ) {
			// Plan sync logic to be implemented
		}
	}

	/**
	 * Check if current page is plugin page.
	 *
	 * @return bool True if on plugin admin page.
	 */
	private function is_plugin_page() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		return in_array( $screen->base, array( 'toplevel_page_' . $this->plugin_name, $this->plugin_name . '_page_' . $this->plugin_name . '-settings' ), true );
	}

	/**
	 * Handle AJAX request for dashboard data.
	 */
	public function handle_get_dashboard_data_ajax() {
		check_ajax_referer( 'rl_fsbi_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Unauthorized', 'rl-freemius-bi' ) );
		}

		$repo = new RL_FSBI_Repository();

		$plugin_id = isset( $_POST['plugin_id'] ) ? sanitize_text_field( $_POST['plugin_id'] ) : 'all';
		$currency  = isset( $_POST['currency'] ) ? sanitize_text_field( $_POST['currency'] ) : 'all';
		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : null;
		$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : null;

		// Get KPI data
		$gross_revenue = $plugin_id !== 'all'
			? $repo->calculate_gross_revenue( (int) $plugin_id, $currency !== 'all' ? $currency : null, $start_date, $end_date )
			: 0;

		$net_revenue = $plugin_id !== 'all'
			? $repo->calculate_net_revenue( (int) $plugin_id, $currency !== 'all' ? $currency : null, $start_date, $end_date )
			: 0;

		// Get payments for table
		$payments = $plugin_id !== 'all'
			? $repo->get_payments( (int) $plugin_id, $start_date, $end_date )
			: array();

		// Filter payments by currency if needed
		if ( $currency !== 'all' ) {
			$payments = array_filter( $payments, function( $p ) use ( $currency ) {
				return $p->currency === $currency;
			} );
		}

		$data = array(
			'gross_revenue'       => $gross_revenue,
			'net_revenue'         => $net_revenue,
			'mrr'                 => $gross_revenue / ( empty( $payments ) ? 1 : count( array_unique( wp_list_pluck( $payments, 'subscription_id' ) ) ) ),
			'active_subscriptions' => count( array_unique( wp_list_pluck( $payments, 'subscription_id' ) ) ),
			'payments'            => array_map( function( $p ) {
				return (array) $p;
			}, $payments ),
			'revenue_trend'       => $this->calculate_revenue_trend( $payments ),
			'currency_distribution' => $this->calculate_currency_distribution( $payments ),
		);

		wp_send_json_success( $data );
	}

	/**
	 * Calculate daily revenue trend from payments.
	 *
	 * @param array $payments Payment records.
	 * @return array Trend data (date => amount).
	 */
	private function calculate_revenue_trend( $payments ) {
		$trend = array();

		foreach ( $payments as $payment ) {
			$date = substr( $payment->transaction_date, 0, 10 );
			if ( ! isset( $trend[ $date ] ) ) {
				$trend[ $date ] = 0;
			}
			$trend[ $date ] += (float) $payment->gross;
		}

		ksort( $trend );
		return $trend;
	}

	/**
	 * Calculate currency distribution from payments.
	 *
	 * @param array $payments Payment records.
	 * @return array Distribution data (currency => amount).
	 */
	private function calculate_currency_distribution( $payments ) {
		$distribution = array();

		foreach ( $payments as $payment ) {
			$currency = $payment->currency;
			if ( ! isset( $distribution[ $currency ] ) ) {
				$distribution[ $currency ] = 0;
			}
			$distribution[ $currency ] += (float) $payment->gross;
		}

		return $distribution;
	}
}
