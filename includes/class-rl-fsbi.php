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

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'maybe_run_initial_sweep' );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'ensure_hourly_sync_cron' );
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_plugin_admin_menu' );
		$this->loader->add_action( 'wp_dashboard_setup', $plugin_admin, 'register_wordpress_dashboard_widget' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_sync_data', $plugin_admin, 'handle_sync_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_sync_batch', $plugin_admin, 'handle_sync_batch_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_sync_cancel', $plugin_admin, 'handle_sync_cancel_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_refresh_latest', $plugin_admin, 'handle_refresh_latest_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_get_dashboard_data', $plugin_admin, 'handle_get_dashboard_data_ajax' );
		$this->loader->add_action( 'wp_ajax_rl_fsbi_export_monthly_csv', $plugin_admin, 'handle_export_monthly_csv_ajax' );
		$this->loader->add_action( 'rl_fsbi_scheduled_sync', $plugin_admin, 'scheduled_sync' );
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
