<?php
/**
 * Settings Manager using RL Options Framework
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes
 */

class RL_FSBI_Settings_Manager {

	/**
	 * Framework instance.
	 *
	 * @var RL_Options_Framework
	 */
	private $framework;

	/**
	 * Ensure save hook registration happens once.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init_framework();
	}

	/**
	 * Initialize the options framework.
	 */
	public function init_framework() {
		if ( $this->framework ) {
			return;
		}

		require_once RL_FSBI_PLUGIN_DIR . 'includes/library/rloptionsFramework/main.php';

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

		$this->framework->init();
		$this->add_tabs();
		$this->add_sections();
		$this->add_fields();
		$this->register_framework_hooks();
	}

	/**
	 * Add tabs to settings page.
	 */
	private function add_tabs() {
		$this->framework->add_tab(
			'api_config',
			array(
				'label' => esc_html__( 'API Configuration', 'rl-freemius-bi' ),
			)
		);

		$this->framework->add_tab(
			'plugin_scope',
			array(
				'label' => esc_html__( 'Plugin Scope', 'rl-freemius-bi' ),
			)
		);

		$this->framework->add_tab(
			'general',
			array(
				'label' => esc_html__( 'General Settings', 'rl-freemius-bi' ),
			)
		);

		$this->framework->add_tab(
			'info',
			array(
				'label' => esc_html__( 'Information', 'rl-freemius-bi' ),
			)
		);
	}

	/**
	 * Add sections to tabs.
	 */
	private function add_sections() {
		$this->framework->add_section(
			'api_config',
			'freemius_api',
			array(
				'title' => esc_html__( 'Freemius REST API Credentials', 'rl-freemius-bi' ),
				'desc'  => esc_html__( 'Enter your Freemius API credentials, save, and the plugin catalog will be discovered automatically.', 'rl-freemius-bi' ),
			)
		);

		$this->framework->add_section(
			'plugin_scope',
			'plugin_selection',
			array(
				'title' => esc_html__( 'Select Plugins To Track', 'rl-freemius-bi' ),
				'desc'  => esc_html__( 'Choose which discovered plugins will be synced and shown under the Freemius BI menu.', 'rl-freemius-bi' ),
			)
		);

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
	 * Add fields to sections.
	 */
	private function add_fields() {
		$plugin_field_options = $this->get_discovered_plugin_field_options();

		$this->framework->add_field(
			'api_config',
			'freemius_api',
			array(
				'id'                => 'rl_fsbi_developer_id',
				'type'              => 'text',
				'label'             => esc_html__( 'Developer ID', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Your Freemius Developer ID from developer.freemius.com', 'rl-freemius-bi' ),
				'placeholder'       => '123456',
				'required'          => true,
				'sanitize_callback' => array( $this, 'sanitize_developer_id' ),
			)
		);

		$this->framework->add_field(
			'api_config',
			'freemius_api',
			array(
				'id'                => 'rl_fsbi_public_key',
				'type'              => 'text',
				'label'             => esc_html__( 'Public API Key', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Your Freemius public API key (starts with pk_)', 'rl-freemius-bi' ),
				'placeholder'       => 'pk_xxxxxxxxxxxxxxxxxxxx',
				'required'          => true,
				'sanitize_callback' => array( $this, 'sanitize_freemius_key' ),
			)
		);

		$this->framework->add_field(
			'api_config',
			'freemius_api',
			array(
				'id'                => 'rl_fsbi_secret_key',
				'type'              => 'text',
				'label'             => esc_html__( 'Secret API Key', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Case-sensitive key from Freemius. Special characters are preserved.', 'rl-freemius-bi' ),
				'placeholder'       => 'sk_xxxxxxxxxxxxxxxxxxxx',
				'required'          => true,
				'sanitize_callback' => array( $this, 'sanitize_freemius_key' ),
			)
		);

		$this->framework->add_field(
			'plugin_scope',
			'plugin_selection',
			array(
				'id'      => 'rl_fsbi_plugin_scope_info',
				'type'    => 'info',
				'label'   => esc_html__( 'Discovery Status', 'rl-freemius-bi' ),
				'content' => $this->get_plugin_scope_info_content( $plugin_field_options ),
			)
		);

		$this->framework->add_field(
			'plugin_scope',
			'plugin_selection',
			array(
				'id'      => 'rl_fsbi_selected_plugins',
				'type'    => 'multiselect',
				'label'   => esc_html__( 'Tracked Plugins', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Each selected plugin gets its own dashboard submenu and is included in sync jobs.', 'rl-freemius-bi' ),
				'options' => $plugin_field_options,
				'default' => array_keys( $plugin_field_options ),
			)
		);

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
				'id'      => 'rl_fsbi_sync_interval',
				'type'    => 'select',
				'label'   => esc_html__( 'Sync Interval', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'How often to synchronize data with Freemius', 'rl-freemius-bi' ),
				'options' => array(
					'hourly'     => esc_html__( 'Hourly', 'rl-freemius-bi' ),
					'twicedaily' => esc_html__( 'Twice Daily', 'rl-freemius-bi' ),
					'daily'      => esc_html__( 'Daily', 'rl-freemius-bi' ),
				),
				'default' => 'hourly',
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

		$this->framework->add_field(
			'general',
			'display_settings',
			array(
				'id'          => 'rl_fsbi_locale_format',
				'type'        => 'text',
				'label'       => esc_html__( 'Locale Currency Format', 'rl-freemius-bi' ),
				'desc'        => esc_html__( 'Language-sensitive number formatting. Examples: pt-PT, de-DE, ja-JP. Default: us-US', 'rl-freemius-bi' ),
				'default'     => 'us-US',
				'placeholder' => 'us-US',
			)
		);

		$this->framework->add_field(
			'general',
			'display_settings',
			array(
				'id'                => 'rl_fsbi_transferwise_token',
				'type'              => 'text',
				'label'             => esc_html__( 'Transferwise Token', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'API token for Transferwise currency conversion', 'rl-freemius-bi' ),
				'sanitize_callback' => array( $this, 'sanitize_freemius_key' ),
			)
		);

		$this->framework->add_field(
			'general',
			'display_settings',
			array(
				'id'      => 'rl_fsbi_conversion_currency',
				'type'    => 'select',
				'label'   => esc_html__( 'Conversion Currency', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Base currency for payout display (EUR, USD, GBP).', 'rl-freemius-bi' ),
				'options' => array(
					'EUR' => 'EUR - Euro',
					'USD' => 'USD - United States Dollar',
					'GBP' => 'GBP - British Pound',
				),
				'default' => 'EUR',
			)
		);

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
	 * Register framework lifecycle hooks.
	 */
	private function register_framework_hooks() {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'rl_fsbi_settings_settings_saved', array( $this, 'handle_settings_saved' ), 10, 1 );
		self::$hooks_registered = true;
	}

	/**
	 * Save handler to auto-discover plugin catalog from Freemius.
	 *
	 * @param array $saved Settings payload.
	 */
	public function handle_settings_saved( $saved ) {
		if ( ! is_array( $saved ) ) {
			return;
		}

		$developer_id = isset( $saved['rl_fsbi_developer_id'] ) ? trim( (string) $saved['rl_fsbi_developer_id'] ) : '';
		$public_key   = isset( $saved['rl_fsbi_public_key'] ) ? trim( (string) $saved['rl_fsbi_public_key'] ) : '';
		$secret_key   = isset( $saved['rl_fsbi_secret_key'] ) ? trim( (string) $saved['rl_fsbi_secret_key'] ) : '';

		if ( '' === $developer_id || '' === $public_key || '' === $secret_key ) {
			return;
		}

		$current_hash = md5( $developer_id . '|' . $public_key . '|' . $secret_key );
		$existing_hash = isset( $saved['rl_fsbi_credentials_hash'] ) ? (string) $saved['rl_fsbi_credentials_hash'] : '';
		$existing_catalog = isset( $saved['rl_fsbi_plugins_catalog'] ) && is_array( $saved['rl_fsbi_plugins_catalog'] ) ? $saved['rl_fsbi_plugins_catalog'] : array();

		if ( $current_hash === $existing_hash && ! empty( $existing_catalog ) ) {
			return;
		}

		$api = new RL_FSBI_API( $developer_id, $public_key, $secret_key );
		$plugins = $api->retrieve_plugins();

		if ( empty( $plugins ) ) {
			$this->persist_settings_updates(
				array(
					'rl_fsbi_credentials_hash' => $current_hash,
					'rl_fsbi_discovery_status' => esc_html__( 'Discovery failed. Verify your Freemius credentials and save again.', 'rl-freemius-bi' ),
					'rl_fsbi_run_initial_sweep' => false,
				)
			);
			return;
		}

		$catalog = array();
		foreach ( $plugins as $plugin ) {
			$plugin_id = (int) ( is_array( $plugin ) ? ( $plugin['id'] ?? 0 ) : ( $plugin->id ?? 0 ) );
			if ( $plugin_id <= 0 ) {
				continue;
			}

			$title = is_array( $plugin ) ? ( $plugin['title'] ?? '' ) : ( $plugin->title ?? '' );
			$slug  = is_array( $plugin ) ? ( $plugin['slug'] ?? '' ) : ( $plugin->slug ?? '' );

			$catalog[ (string) $plugin_id ] = array(
				'id'    => $plugin_id,
				'title' => sanitize_text_field( (string) $title ),
				'slug'  => sanitize_key( (string) $slug ),
			);
		}

		if ( empty( $catalog ) ) {
			$this->persist_settings_updates(
				array(
					'rl_fsbi_credentials_hash' => $current_hash,
					'rl_fsbi_discovery_status' => esc_html__( 'No plugins returned by Freemius for this account.', 'rl-freemius-bi' ),
					'rl_fsbi_run_initial_sweep' => false,
				)
			);
			return;
		}

		$selected_plugins = isset( $saved['rl_fsbi_selected_plugins'] ) && is_array( $saved['rl_fsbi_selected_plugins'] )
			? array_values( array_map( 'strval', $saved['rl_fsbi_selected_plugins'] ) )
			: array();

		if ( empty( $selected_plugins ) ) {
			$selected_plugins = array_keys( $catalog );
		}

		$selected_plugins = array_values( array_intersect( $selected_plugins, array_keys( $catalog ) ) );

		$this->persist_settings_updates(
			array(
				'rl_fsbi_plugins_catalog'   => $catalog,
				'rl_fsbi_selected_plugins'  => $selected_plugins,
				'rl_fsbi_credentials_hash'  => $current_hash,
				'rl_fsbi_discovery_status'  => sprintf(
					/* translators: %d: number of discovered plugins */
					esc_html__( 'Discovery completed. %d plugins found. Save once more if you change selection.', 'rl-freemius-bi' ),
					count( $catalog )
				),
				'rl_fsbi_run_initial_sweep' => true,
				'rl_fsbi_discovered_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Preserve exact keys from Freemius while removing only invisible control chars.
	 *
	 * @param mixed $value Raw key value.
	 * @return string
	 */
	public function sanitize_freemius_key( $value ) {
		$value = (string) $value;
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
		return trim( $value );
	}

	/**
	 * Sanitize developer ID.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_developer_id( $value ) {
		return preg_replace( '/[^0-9]/', '', (string) $value );
	}

	/**
	 * Persist partial settings into framework option row.
	 *
	 * @param array $updates Updates map.
	 */
	private function persist_settings_updates( $updates ) {
		$settings = $this->get_all_settings();
		foreach ( $updates as $key => $value ) {
			$settings[ $key ] = $value;
		}

		update_option( 'rl_fsbi_settings', $settings );
	}

	/**
	 * Build plugin multiselect options from discovered catalog.
	 *
	 * @return array
	 */
	private function get_discovered_plugin_field_options() {
		$catalog = $this->framework->get_option( 'rl_fsbi_plugins_catalog', array() );
		if ( ! is_array( $catalog ) ) {
			return array();
		}

		$options = array();
		foreach ( $catalog as $plugin_id => $plugin_data ) {
			$plugin_id = (string) $plugin_id;
			$title = '';
			if ( is_array( $plugin_data ) && isset( $plugin_data['title'] ) ) {
				$title = trim( (string) $plugin_data['title'] );
			}

			$options[ $plugin_id ] = '' !== $title ? sanitize_text_field( $title ) . ' (#' . $plugin_id . ')' : 'Plugin #' . $plugin_id;
		}

		return $options;
	}

	/**
	 * Get plugin scope info content.
	 *
	 * @param array $plugin_field_options Options map.
	 * @return string
	 */
	private function get_plugin_scope_info_content( $plugin_field_options ) {
		$status = (string) $this->framework->get_option( 'rl_fsbi_discovery_status', '' );
		$discovered_at = (string) $this->framework->get_option( 'rl_fsbi_discovered_at_utc', '' );
		$last_sync_status = (string) $this->framework->get_option( 'rl_fsbi_last_sync_status', '' );
		$last_sync_at = (string) $this->framework->get_option( 'rl_fsbi_last_sync_at_utc', '' );

		$content = '<p>' . esc_html__( 'Save valid credentials in API Configuration to run discovery.', 'rl-freemius-bi' ) . '</p>';
		$content .= '<p><strong>' . esc_html__( 'Discovered Plugins:', 'rl-freemius-bi' ) . '</strong> ' . (int) count( $plugin_field_options ) . '</p>';

		if ( '' !== $status ) {
			$content .= '<p><strong>' . esc_html__( 'Last Status:', 'rl-freemius-bi' ) . '</strong> ' . esc_html( $status ) . '</p>';
		}

		if ( '' !== $discovered_at ) {
			$content .= '<p><strong>' . esc_html__( 'Last Discovery (UTC):', 'rl-freemius-bi' ) . '</strong> ' . esc_html( $discovered_at ) . '</p>';
		}

		if ( '' !== $last_sync_status ) {
			$content .= '<p><strong>' . esc_html__( 'Last Sync Status:', 'rl-freemius-bi' ) . '</strong> ' . esc_html( $last_sync_status ) . '</p>';
		}

		if ( '' !== $last_sync_at ) {
			$content .= '<p><strong>' . esc_html__( 'Last Sync (UTC):', 'rl-freemius-bi' ) . '</strong> ' . esc_html( $last_sync_at ) . '</p>';
		}

		return $content;
	}

	/**
	 * Get database information content.
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
			$table_exists = (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					DB_NAME,
					$full_table_name
				)
			);

			$row_count = 0;
			if ( $table_exists ) {
				$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$full_table_name}" );
			}

			$content .= '<li>';
			$content .= '<code>' . esc_html( $full_table_name ) . '</code> ';
			$content .= '- ' . sprintf( esc_html__( '%d records', 'rl-freemius-bi' ), $row_count );
			$content .= '</li>';
		}

		$content .= '</ul>';
		$content .= '<p><strong>' . esc_html__( 'Note:', 'rl-freemius-bi' ) . '</strong> ' . esc_html__( 'Data is preserved when the plugin is deactivated or reactivated. No data loss occurs.', 'rl-freemius-bi' ) . '</p>';

		return $content;
	}

	/**
	 * Get option value using the framework.
	 *
	 * @param string $option_key Option key.
	 * @param mixed  $default    Default value.
	 * @return mixed
	 */
	public function get_option( $option_key, $default = false ) {
		return $this->framework->get_option( $option_key, $default );
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public function get_all_settings() {
		$settings = get_option( 'rl_fsbi_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Update a single option key in framework option row.
	 *
	 * @param string $option_key Option key.
	 * @param mixed  $value      Option value.
	 * @return bool
	 */
	public function update_option( $option_key, $value ) {
		$settings = $this->get_all_settings();
		$settings[ $option_key ] = $value;
		return (bool) update_option( 'rl_fsbi_settings', $settings );
	}

	/**
	 * Initialize framework on admin_menu hook.
	 */
	public function init() {
		$this->framework->init();
	}

	/**
	 * Get framework instance.
	 *
	 * @return RL_Options_Framework
	 */
	public function get_framework() {
		return $this->framework;
	}
}
