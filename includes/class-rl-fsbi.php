<?php
/**
 * The core plugin class.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes
 */

class RL_FSBI {

	/**
	 * The loader that's responsible for maintaining and registering all hooks.
	 *
	 * @access   protected
	 * @var      RL_FSBI_Loader $loader Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @access   protected
	 * @var      string $plugin_name The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @access   protected
	 * @var      string $version The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 */
	public function __construct() {
		$this->version      = RL_FSBI_VERSION;
		$this->plugin_name  = 'rl-freemius-bi';

		$this->load_dependencies();
		$this->define_admin_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @access   private
	 */
	private function load_dependencies() {
		require_once RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi-loader.php';
		require_once RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi-i18n.php';
		require_once RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi-repository.php';
		require_once RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi-settings-manager.php';
		require_once RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi-mailchimp.php';
		require_once RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi-kit.php';
		require_once RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi-user-sync.php';
		require_once RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi-rest-api.php';
		require_once RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi-cli.php';
		require_once RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi-github-updater.php';
		require_once RL_FSBI_PLUGIN_DIR . 'includes/freemius/class-rl-fsbi-api.php';
		require_once RL_FSBI_PLUGIN_DIR . 'admin/class-rl-fsbi-admin.php';

		$this->loader = new RL_FSBI_Loader();
	}

	/**
	 * Register all of the hooks related to the admin area functionality.
	 *
	 * @access   private
	 */
	private function define_admin_hooks() {
		$plugin_admin = new RL_FSBI_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_vendor_assets', 20 );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'maybe_run_initial_sweep' );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'ensure_hourly_sync_cron' );
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_plugin_admin_menu' );
		$this->loader->add_action( 'admin_post_rl_fsbi_user_backfill', $plugin_admin, 'handle_user_backfill_action' );
		$this->loader->add_action( 'admin_post_rl_fsbi_save_wporg_slugs', $this, 'handle_save_wporg_slugs' );
		$this->loader->add_action( 'admin_post_rl_fsbi_save_monthly_targets', $this, 'handle_save_monthly_targets' );
		$this->loader->add_action( 'rest_api_init', $this, 'register_rest_routes' );

		// Lazy callbacks only: instantiating these here would build a settings
		// manager on every single request.
		$this->loader->add_filter( 'cron_schedules', $this, 'add_fsbi_cron_schedules' );
		$this->loader->add_action( 'admin_init', $this, 'schedule_background_jobs' );
		$this->loader->add_action( 'admin_init', $this, 'maybe_reset_debug_mode', 5 );
		$this->loader->add_action( 'rl_fsbi_user_sync', $this, 'run_user_sync_cron' );
		$this->loader->add_action( 'rl_fsbi_api_log_cleanup', $this, 'run_api_log_cleanup' );
		$this->loader->add_action( 'rl_fsbi_fetch_coupons', $plugin_admin, 'run_scheduled_coupon_fetch' );
		$this->loader->add_action( 'rl_fsbi_rest_warm', $this, 'run_rest_warm' );

		// GitHub release updates. Manual only: this surfaces the normal update
		// row, nothing is installed without an administrator clicking Update.
		$updater = new RL_FSBI_GitHub_Updater( $this->get_version() );
		// Read filter, not pre_set: pre_set only runs when WordPress rewrites the
		// transient, which it throttles to once an hour or day. A freshly published
		// release would then stay invisible until that schedule came round.
		$this->loader->add_filter( 'site_transient_update_plugins', $updater, 'check_for_update' );
		$this->loader->add_filter( 'plugins_api', $updater, 'plugin_info', 20, 3 );
		$this->loader->add_filter( 'upgrader_source_selection', $updater, 'fix_source_dir', 10, 3 );
		$this->loader->add_action( 'upgrader_process_complete', $updater, 'flush_after_update', 10, 2 );
		$this->loader->add_action( 'deleted_site_transient_update_plugins', $updater, 'flush_cache' );
		$this->loader->add_action( 'admin_notices', $updater, 'render_status_notice' );

		$this->loader->add_action( 'admin_notices', $plugin_admin, 'render_kit_quota_notice' );
		$this->loader->add_action( 'admin_notices', $plugin_admin, 'render_credential_notice' );
		$this->loader->add_action( 'admin_notices', $plugin_admin, 'render_debug_notice' );
		$this->loader->add_action( 'admin_notices', $plugin_admin, 'render_incomplete_upload_notice' );
		$this->loader->add_action( 'wp_dashboard_setup', $plugin_admin, 'register_wordpress_dashboard_widget' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_sync_data', $plugin_admin, 'handle_sync_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_sync_batch', $plugin_admin, 'handle_sync_batch_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_sync_cancel', $plugin_admin, 'handle_sync_cancel_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_refresh_latest', $plugin_admin, 'handle_refresh_latest_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_get_dashboard_data', $plugin_admin, 'handle_get_dashboard_data_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_export_monthly_csv', $plugin_admin, 'handle_export_monthly_csv_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_get_marketing_optins', $plugin_admin, 'handle_get_marketing_optins_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_fetch_mailchimp_data', $plugin_admin, 'handle_fetch_mailchimp_data_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_fetch_mailchimp_list_stats', $plugin_admin, 'handle_fetch_mailchimp_list_stats_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_sync_mailchimp_optins', $plugin_admin, 'handle_sync_mailchimp_optins_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_sample_mailchimp_test', $plugin_admin, 'handle_sample_mailchimp_test_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_fetch_kit_data', $plugin_admin, 'handle_fetch_kit_data_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_kit_prune_preview', $plugin_admin, 'handle_kit_prune_preview_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_kit_prune_batch', $plugin_admin, 'handle_kit_prune_batch_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_fetch_kit_stats', $plugin_admin, 'handle_fetch_kit_stats_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_sample_kit_test', $plugin_admin, 'handle_sample_kit_test_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_export_optins_csv', $plugin_admin, 'handle_export_optins_csv_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_generate_optins_csv_batch', $plugin_admin, 'handle_generate_optins_csv_batch_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_download_generated_csv', $plugin_admin, 'handle_download_generated_csv_ajax' );
		$this->loader->add_action( 'rl_fsbi_scheduled_sync', $plugin_admin, 'scheduled_sync' );
	}

	/**
	 * Register the read-only REST routes exposed under rl-fsbi/v1.
	 */
	/**
	 * Passthrough for the per-plugin WordPress.org slug form.
	 *
	 * The settings manager is built here rather than at boot so ordinary
	 * requests never pay for it.
	 *
	 * @return void
	 */
	public function handle_save_wporg_slugs() {
		$settings = new RL_FSBI_Settings_Manager();
		$settings->handle_save_wporg_slugs();
	}

	/**
	 * Passthrough for the per-plugin monthly target form.
	 *
	 * @return void
	 */
	public function handle_save_monthly_targets() {
		$settings = new RL_FSBI_Settings_Manager();
		$settings->handle_save_monthly_targets();
	}

	public function register_rest_routes() {
		$rest = new RL_FSBI_REST_API();
		$rest->register_routes();
	}


	/**
	 * Register the five-minute cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_fsbi_cron_schedules( $schedules ) {
		if ( ! isset( $schedules[ RL_FSBI_User_Sync::SCHEDULE ] ) ) {
			$schedules[ RL_FSBI_User_Sync::SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => esc_html__( 'Every 5 minutes (RL Freemius BI)', 'rl-freemius-bi' ),
			);
		}

		return $schedules;
	}

	/**
	 * Create the add-on tables and schedule both background jobs.
	 */
	public function schedule_background_jobs() {
		RL_FSBI_Activator::ensure_aux_tables();
		RL_FSBI_User_Sync::ensure_scheduled();

		if ( ! wp_next_scheduled( 'rl_fsbi_rest_warm' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'rl_fsbi_rest_warm' );
		}
	}

	/**
	 * Force Debug Mode off once per release.
	 *
	 * Debug Mode used to be written on by default, so a site that upgraded with
	 * it enabled kept verbose logging running indefinitely. This clears it once
	 * per version; an administrator who switches it back on afterwards keeps it
	 * on until the next release.
	 *
	 * @return void
	 */
	public function maybe_reset_debug_mode() {
		if ( get_option( 'rl_fsbi_debug_reset_for' ) === $this->get_version() ) {
			return;
		}

		$settings = get_option( 'rl_fsbi_settings' );
		if ( is_array( $settings ) && ! empty( $settings['enable_debug'] ) ) {
			$settings['enable_debug'] = false;
			update_option( 'rl_fsbi_settings', $settings );
		}

		update_option( 'rl_fsbi_debug_reset_for', $this->get_version(), false );
	}

	/**
	 * Cron: crawl a slice of Freemius users.
	 */
	public function run_user_sync_cron() {
		$sync = new RL_FSBI_User_Sync();
		$sync->run_cron();
	}

	/**
	 * Cron: prune the REST access log.
	 */
	public function run_api_log_cleanup() {
		$sync = new RL_FSBI_User_Sync();
		$sync->cleanup_log();
	}

	/**
	 * Cron: rebuild the cached REST dataset.
	 */
	public function run_rest_warm() {
		$rest = new RL_FSBI_REST_API();
		$rest->warm_cache();
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Reference to the loader class as used to register hooks.
	 *
	 * @return    RL_FSBI_Loader    Orchestrates hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}
}
