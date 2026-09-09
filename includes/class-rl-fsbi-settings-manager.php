<?php
/**
 * Settings Manager using RL Options Framework
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes
 */

class RL_FSBI_Settings_Manager {

	/**
	 * Framework instance
	 *
	 * @var RL_Options_Framework
	 */
	private $framework;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Load and initialize framework immediately
		$this->init_framework();
	}

	/**
	 * Initialize the options framework
	 */
	public function init_framework() {
		// Only initialize once
		if ( $this->framework ) {
			return;
		}

		// Load the standalone framework copy bundled with this plugin
		require_once RL_FSBI_PLUGIN_DIR . 'includes/library/rloptionsFramework/main.php';

		// Framework is completely independent—loaded from plugin's own copy
		$this->framework = new RL_Options_Framework(
			array(
				'option_name'    => 'rl_fsbi_settings',
				'menu_title'     => esc_html__( 'Settings', 'rl-freemius-bi' ),
				'page_title'     => esc_html__( 'RL Freemius BI Settings', 'rl-freemius-bi' ),
				'capability'     => 'manage_options',
				'page_slug'      => 'rl-freemius-bi-settings',
				'parent_menu'    => 'rl-freemius-bi',
				'register_menu'  => true,
				'text_domain'    => 'rl-freemius-bi',
				'ajax_action'    => 'rl_fsbi_save_settings',
				'context'        => 'plugin',
			)
		);

		// Initialize framework immediately (this registers the admin_menu hook)
		$this->framework->init();

		// Now define tabs, sections, and fields
		$this->add_tabs();
		$this->add_sections();
		$this->add_fields();
	}

	/**
	 * Add tabs to settings page
	 */
	private function add_tabs() {
		// API Configuration Tab
		$this->framework->add_tab(
			'api_config',
			array(
				'label' => esc_html__( 'API Configuration', 'rl-freemius-bi' ),
			)
		);

		// General Settings Tab
		$this->framework->add_tab(
			'general',
			array(
				'label' => esc_html__( 'General Settings', 'rl-freemius-bi' ),
			)
		);

		// Information Tab
		$this->framework->add_tab(
			'info',
			array(
				'label' => esc_html__( 'Information', 'rl-freemius-bi' ),
			)
		);
	}

	/**
	 * Add sections to tabs
	 */
	private function add_sections() {
		// API Configuration Section
		$this->framework->add_section(
			'api_config',
			'freemius_api',
			array(
				'title' => esc_html__( 'Freemius REST API Credentials', 'rl-freemius-bi' ),
				'desc'  => esc_html__( 'Enter your Freemius API credentials to sync data. Get these from developer.freemius.com', 'rl-freemius-bi' ),
			)
		);

		// General Settings Sections
		$this->framework->add_section(
			'general',
			'sync_settings',
			array(
				'title' => esc_html__( 'Synchronization Settings', 'rl-freemius-bi' ),
				'desc'  => esc_html__( 'Configure how data is synchronized with Freemius', 'rl-freemius-bi' ),
			)
		);

		$this->framework->add_section(
			'general',
			'display_settings',
			array(
				'title' => esc_html__( 'Dashboard Display', 'rl-freemius-bi' ),
				'desc'  => esc_html__( 'Customize dashboard appearance and behavior', 'rl-freemius-bi' ),
			)
		);

		// Info Section
		$this->framework->add_section(
			'info',
			'database_info',
			array(
				'title' => esc_html__( 'Database Tables', 'rl-freemius-bi' ),
				'desc'  => esc_html__( 'Information about plugin database structure', 'rl-freemius-bi' ),
			)
		);
	}

	/**
	 * Add fields to sections
	 */
	private function add_fields() {
		// ===== API Configuration Fields =====
		$this->framework->add_field(
			'api_config',
			'freemius_api',
			array(
				'id'          => 'rl_fsbi_developer_id',
				'type'        => 'text',
				'label'       => esc_html__( 'Developer ID', 'rl-freemius-bi' ),
				'desc'        => esc_html__( 'Your Freemius Developer ID from developer.freemius.com', 'rl-freemius-bi' ),
				'placeholder' => '123456',
				'required'    => true,
			)
		);

		$this->framework->add_field(
			'api_config',
			'freemius_api',
			array(
				'id'          => 'rl_fsbi_public_key',
				'type'        => 'text',
				'label'       => esc_html__( 'Public API Key', 'rl-freemius-bi' ),
				'desc'        => esc_html__( 'Your Freemius public API key (starts with pk_)', 'rl-freemius-bi' ),
				'placeholder' => 'pk_xxxxxxxxxxxxxxxxxxxx',
				'required'    => true,
			)
		);

		$this->framework->add_field(
			'api_config',
			'freemius_api',
			array(
				'id'          => 'rl_fsbi_secret_key',
				'type'        => 'password',
				'label'       => esc_html__( 'Secret API Key', 'rl-freemius-bi' ),
				'desc'        => esc_html__( 'Your Freemius secret API key (starts with sk_). Keep this secure!', 'rl-freemius-bi' ),
				'placeholder' => 'sk_xxxxxxxxxxxxxxxxxxxx',
				'required'    => true,
			)
		);

		// ===== Synchronization Settings =====
		$this->framework->add_field(
			'general',
			'sync_settings',
			array(
				'id'      => 'rl_fsbi_sync_enabled',
				'type'    => 'toggle',
				'label'   => esc_html__( 'Enable Automatic Sync', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Automatically sync data with Freemius every hour', 'rl-freemius-bi' ),
				'default' => 1,
			)
		);

		$this->framework->add_field(
			'general',
			'sync_settings',
			array(
				'id'        => 'rl_fsbi_sync_interval',
				'type'      => 'select',
				'label'     => esc_html__( 'Sync Interval', 'rl-freemius-bi' ),
				'desc'      => esc_html__( 'How often to synchronize data with Freemius', 'rl-freemius-bi' ),
				'options'   => array(
					'hourly'     => esc_html__( 'Hourly', 'rl-freemius-bi' ),
					'twicedaily' => esc_html__( 'Twice Daily', 'rl-freemius-bi' ),
					'daily'      => esc_html__( 'Daily', 'rl-freemius-bi' ),
				),
				'default'   => 'hourly',
				'conditions' => array(
					array(
						'field'    => 'rl_fsbi_sync_enabled',
						'operator' => 'truthy',
					),
				),
			)
		);

		$this->framework->add_field(
			'general',
			'sync_settings',
			array(
				'id'      => 'rl_fsbi_retention_days',
				'type'    => 'number',
				'label'   => esc_html__( 'Data Retention (Days)', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'How many days of historical data to keep (0 = unlimited)', 'rl-freemius-bi' ),
				'default' => 0,
				'min'     => 0,
				'max'     => 3650,
			)
		);

		// ===== Dashboard Display Settings =====
		$this->framework->add_field(
			'general',
			'display_settings',
			array(
				'id'      => 'rl_fsbi_default_currency',
				'type'    => 'select',
				'label'   => esc_html__( 'Default Currency', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Default currency to display on dashboard', 'rl-freemius-bi' ),
				'options' => array(
					'USD' => 'USD - United States Dollar',
					'EUR' => 'EUR - Euro',
					'GBP' => 'GBP - British Pound',
					'all' => esc_html__( 'All Currencies', 'rl-freemius-bi' ),
				),
				'default' => 'USD',
			)
		);

		$this->framework->add_field(
			'general',
			'display_settings',
			array(
				'id'      => 'rl_fsbi_enable_charts',
				'type'    => 'toggle',
				'label'   => esc_html__( 'Enable Charts', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Show revenue and currency distribution charts', 'rl-freemius-bi' ),
				'default' => 1,
			)
		);

		$this->framework->add_field(
			'general',
			'display_settings',
			array(
				'id'      => 'rl_fsbi_table_rows',
				'type'    => 'number',
				'label'   => esc_html__( 'Rows Per Page', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Number of rows to display in payment table', 'rl-freemius-bi' ),
				'default' => 25,
				'min'     => 5,
				'max'     => 100,
			)
		);

		// ===== Locale Currency Format =====
		$this->framework->add_field(
			'general',
			'display_settings',
			array(
				'id'      => 'rl_fsbi_locale_format',
				'type'    => 'text',
				'label'   => esc_html__( 'Locale Currency Format', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Language-sensitive number formatting. Examples: pt-PT, de-DE, ja-JP. Default: us-US', 'rl-freemius-bi' ),
				'default' => 'us-US',
				'placeholder' => 'us-US',
			)
		);

		// ===== Transferwise Token =====
		$this->framework->add_field(
			'general',
			'display_settings',
			array(
				'id'      => 'rl_fsbi_transferwise_token',
				'type'    => 'password',
				'label'   => esc_html__( 'Transferwise Token', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'API token for Transferwise currency conversion', 'rl-freemius-bi' ),
			)
		);

		// ===== Transferwise Conversion Currency =====
		$this->framework->add_field(
			'general',
			'display_settings',
			array(
				'id'      => 'rl_fsbi_conversion_currency',
				'type'    => 'select',
				'label'   => esc_html__( 'Conversion Currency', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Base currency for payout display (currency conversion via Transferwise). Options: EUR, USD, GBP. Default: EUR', 'rl-freemius-bi' ),
				'options' => array(
					'EUR' => 'EUR - Euro',
					'USD' => 'USD - United States Dollar',
					'GBP' => 'GBP - British Pound',
				),
				'default' => 'EUR',
			)
		);

		// ===== Database Info Field =====
		$this->framework->add_field(
			'info',
			'database_info',
			array(
				'id'      => 'rl_fsbi_db_info',
				'type'    => 'info',
				'label'   => esc_html__( 'Database Tables', 'rl-freemius-bi' ),
				'content' => $this->get_database_info_content(),
			)
		);
	}

	/**
	 * Get database information content
	 *
	 * @return string HTML content
	 */
	private function get_database_info_content() {
		global $wpdb;

		$tables = array(
			'wp_rl_fsbi_payments',
			'wp_rl_fsbi_subscriptions',
			'wp_rl_fsbi_licenses',
			'wp_rl_fsbi_plans',
			'wp_rl_fsbi_balances',
		);

		$content = '<p>' . esc_html__( 'The following tables have been created to store plugin data:', 'rl-freemius-bi' ) . '</p>';
		$content .= '<ul style="list-style: disc; margin-left: 20px;">';

		foreach ( $tables as $table ) {
			$full_table_name = $wpdb->prefix . substr( $table, 3 );
			$row_count       = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', DB_NAME, $full_table_name ) );
			$row_count       = $wpdb->get_var( "SELECT COUNT(*) FROM $full_table_name" );

			$content .= '<li>';
			$content .= '<code>' . esc_html( $full_table_name ) . '</code> ';
			$content .= '— ' . sprintf( esc_html__( '%d records', 'rl-freemius-bi' ), (int) $row_count );
			$content .= '</li>';
		}

		$content .= '</ul>';
		$content .= '<p><strong>' . esc_html__( 'Note:', 'rl-freemius-bi' ) . '</strong> ' . esc_html__( 'Data is preserved when the plugin is deactivated or reactivated. No data loss occurs.', 'rl-freemius-bi' ) . '</p>';

		return $content;
	}

	/**
	 * Get option value using the framework
	 *
	 * @param  string $option_key Option key
	 * @param  mixed  $default Default value
	 * @return mixed Option value
	 */
	public function get_option( $option_key, $default = false ) {
		return $this->framework->get_option( $option_key, $default );
	}

	/**
	 * Get all settings
	 *
	 * @return array All settings
	 */
	public function get_all_settings() {
		return $this->framework->get_all_options();
	}

	/**
	 * Update option value
	 *
	 * @param string $option_key Option key
	 * @param mixed  $value Option value
	 * @return bool Success status
	 */
	public function update_option( $option_key, $value ) {
		return $this->framework->update_option( $option_key, $value );
	}

	/**
	 * Initialize framework on admin_menu hook
	 */
	public function init() {
		$this->framework->init();
	}

	/**
	 * Get framework instance
	 *
	 * @return RL_Options_Framework Framework instance
	 */
	public function get_framework() {
		return $this->framework;
	}
}
