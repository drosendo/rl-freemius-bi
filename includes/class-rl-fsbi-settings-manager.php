<?php
/**
 * Settings Manager using RL Options Framework
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RL_FSBI_Settings_Manager {

	/**
	 * Framework instance.
	 *
	 * @var RL_Options_Framework|null
	 */
	private $framework = null;

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
		require_once RL_FSBI_PLUGIN_DIR . 'includes/fields/class-rl-fsbi-field-checkbox-list.php';

		$this->framework = new RL_Options_Framework(
			array(
				'option_name'   => 'rl_fsbi_settings',
				'menu_title'    => esc_html__( 'Settings', 'rl-freemius-bi' ),
				'page_title'    => esc_html__( 'RL Freemius BI Settings', 'rl-freemius-bi' ),
				'capability'    => 'manage_options',
				'page_slug'     => 'rl-freemius-bi-settings',
				'parent_menu'   => 'rl-freemius-bi',
				'register_menu' => true,
				'text_domain'   => 'rl-freemius-bi',
				'ajax_action'   => 'rl_fsbi_save_settings',
				'context'       => 'plugin',
				'debug_level'   => 'debug',
			)
		);

		// Hook into framework boot to ensure custom field renderer is registered into field registry
		add_action(
			'rl_fsbi_settings_framework_boot',
			function ( $fw ) {
				if ( $fw instanceof RL_Options_Framework ) {
					$fw->register_field_renderer( new RL_FSBI_Field_Checkbox_List() );
				}
			}
		);

		$this->framework->init();

		// Register custom field renderer for checkbox list after init so field_registry is initialized
		$this->framework->register_field_renderer( new RL_FSBI_Field_Checkbox_List() );

		$this->add_tabs();
		$this->add_sections();
		$this->add_fields();
		$this->register_framework_hooks();
	}

	/**
	 * Check if Freemius API credentials exist and connection has succeeded.
	 *
	 * @return bool
	 */
	public function is_api_connected(): bool {
		$settings = $this->get_all_settings();
		$dev_id   = ! empty( $settings['rl_fsbi_developer_id'] );
		$pub_key  = ! empty( $settings['rl_fsbi_public_key'] );
		$sec_key  = ! empty( $settings['rl_fsbi_secret_key'] );

		if ( ! $dev_id || ! $pub_key || ! $sec_key ) {
			return false;
		}

		if ( isset( $settings['rl_fsbi_connection_status'] ) ) {
			return 'connected' === $settings['rl_fsbi_connection_status'];
		}

		// Fallback for existing installations that already populated catalog
		return ! empty( $settings['rl_fsbi_plugins_catalog'] ) || ! empty( $settings['rl_fsbi_selected_plugins'] );
	}

	/**
	 * Add tabs to settings page.
	 * All operational tabs are hidden until API credentials are verified.
	 * The Support tab is automatically provided by RL Options Framework.
	 */
	private function add_tabs() {
		$this->framework->add_tab(
			'api_config',
			array(
				'label' => esc_html__( 'API Configuration', 'rl-freemius-bi' ),
			)
		);

		if ( $this->is_api_connected() ) {
			$this->framework->add_tab(
				'plugin_scope',
				array(
					'label' => esc_html__( 'Plugin Scope', 'rl-freemius-bi' ),
				)
			);

			$this->framework->add_tab(
				'currency_settings',
				array(
					'label' => esc_html__( 'Multi-Currency & Display', 'rl-freemius-bi' ),
				)
			);

			$this->framework->add_tab(
				'sync_settings',
				array(
					'label' => esc_html__( 'Synchronization', 'rl-freemius-bi' ),
				)
			);
		}
	}

	/**
	 * Add sections to tabs.
	 */
	private function add_sections() {
		$is_connected = $this->is_api_connected();
		$status       = (string) ( $this->get_all_settings()['rl_fsbi_discovery_status'] ?? '' );

		$api_desc = $is_connected
			? esc_html__( '✓ Connected to Freemius REST API. Your credentials are verified and all dashboard configuration tabs are unlocked.', 'rl-freemius-bi' )
			: esc_html__( 'Enter your Freemius Developer ID, Public API Key, and Secret API Key below, then click "Save Changes" to connect your account and unlock the dashboard configuration tabs.', 'rl-freemius-bi' );

		if ( ! $is_connected && '' !== $status ) {
			$api_desc .= ' (' . $status . ')';
		}

		$this->framework->add_section(
			'api_config',
			'freemius_api',
			array(
				'title'       => esc_html__( 'Freemius REST API Credentials', 'rl-freemius-bi' ),
				'description' => $api_desc,
			)
		);

		if ( $is_connected ) {
			$catalog = $this->get_all_settings()['rl_fsbi_plugins_catalog'] ?? array();
			$count   = is_array( $catalog ) ? count( $catalog ) : 0;

			$this->framework->add_section(
				'plugin_scope',
				'plugin_selection',
				array(
					'title'       => esc_html__( 'Select Plugins To Track', 'rl-freemius-bi' ),
					'description' => sprintf(
						/* translators: %d: count of discovered plugins */
						esc_html__( '%d plugins discovered from your Freemius account. Check which plugins are synced and visible across the Freemius BI dashboards.', 'rl-freemius-bi' ),
						$count
					),
				)
			);

			$this->framework->add_section(
				'currency_settings',
				'currency_config',
				array(
					'title'       => esc_html__( 'Multi-Currency & Conversion Configuration', 'rl-freemius-bi' ),
					'description' => esc_html__( 'Freemius supports multi-currency transactions. Configure consolidated display currency, formatting, and live conversion rates.', 'rl-freemius-bi' ),
				)
			);

			$this->framework->add_section(
				'sync_settings',
				'sync_config',
				array(
					'title'       => esc_html__( 'Synchronization Settings', 'rl-freemius-bi' ),
					'description' => esc_html__( 'Configure automatic background data synchronization with Freemius API.', 'rl-freemius-bi' ),
				)
			);
		}
	}

	/**
	 * Add fields to sections with rich, self-explanatory descriptions.
	 */
	private function add_fields() {
		// --- Tab: API Configuration ---
		$this->framework->add_field(
			'api_config',
			'freemius_api',
			array(
				'id'                => 'rl_fsbi_developer_id',
				'type'              => 'text',
				'label'             => esc_html__( 'Developer ID', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Your unique numeric Freemius Developer ID. Found in your Freemius Developer Dashboard under My Profile / Settings.', 'rl-freemius-bi' ),
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
				'desc'              => esc_html__( 'Your Freemius Public API Key (starts with pk_). Required to authenticate read/write REST API requests.', 'rl-freemius-bi' ),
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
				'desc'              => esc_html__( 'Your Freemius Secret API Key (starts with sk_). Used to cryptographically sign API requests with HMAC-SHA256. Case-sensitive.', 'rl-freemius-bi' ),
				'placeholder'       => 'sk_xxxxxxxxxxxxxxxxxxxx',
				'required'          => true,
				'sanitize_callback' => array( $this, 'sanitize_freemius_key' ),
			)
		);

		// Operational fields only registered if connected
		if ( ! $this->is_api_connected() ) {
			return;
		}

		$plugin_field_options = $this->get_discovered_plugin_field_options();

		// --- Tab: Plugin Scope ---
		$this->framework->add_field(
			'plugin_scope',
			'plugin_selection',
			array(
				'id'      => 'rl_fsbi_selected_plugins',
				'type'    => 'checkbox_list',
				'label'   => esc_html__( 'Tracked Plugins', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Check each plugin you want tracked on the dashboard. Unchecked plugins will be excluded from metrics, KPI calculations, and sync jobs. Use Select All or Deselect All to quickly adjust your scope.', 'rl-freemius-bi' ),
				'options' => $plugin_field_options,
				'default' => array_keys( $plugin_field_options ),
			)
		);

		// --- Tab: Multi-Currency & Display ---
		$this->framework->add_field(
			'currency_settings',
			'currency_config',
			array(
				'id'      => 'rl_fsbi_conversion_currency',
				'type'    => 'select',
				'label'   => esc_html__( 'Dashboard Consolidated Currency', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'The primary base currency for your dashboard. Freemius supports multi-currency pricing (USD, EUR, GBP, CAD, AUD, CHF, PLN, ILS, RSD). All transactions in other currencies will be converted into this base currency for unified KPI totals, MRR, and renewal graphs.', 'rl-freemius-bi' ),
				'options' => array(
					'USD' => 'USD - United States Dollar ($)',
					'EUR' => 'EUR - Euro (€)',
					'GBP' => 'GBP - British Pound (£)',
					'CAD' => 'CAD - Canadian Dollar (CA$)',
					'AUD' => 'AUD - Australian Dollar (A$)',
					'CHF' => 'CHF - Swiss Franc (CHF)',
					'PLN' => 'PLN - Polish Złoty (zł)',
					'ILS' => 'ILS - Israeli New Shekel (₪)',
					'RSD' => 'RSD - Serbian Dinar (din)',
				),
				'default' => 'USD',
			)
		);

		$this->framework->add_field(
			'currency_settings',
			'currency_config',
			array(
				'id'      => 'rl_fsbi_locale_format',
				'type'    => 'select',
				'label'   => esc_html__( 'Currency Format Locale', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Formatting locale for numbers and currency symbols (e.g. en-US outputs $1,234.56, de-DE outputs 1.234,56 €, pt-PT outputs 1 234,56 €). Governs comma/period decimal placement and symbol positions.', 'rl-freemius-bi' ),
				'options' => array(
					'en-US' => 'en-US ($1,234.56 - United States)',
					'en-GB' => 'en-GB (£1,234.56 - United Kingdom)',
					'pt-PT' => 'pt-PT (1 234,56 € - Portugal)',
					'pt-BR' => 'pt-BR (R$ 1.234,56 - Brazil)',
					'de-DE' => 'de-DE (1.234,56 € - Germany)',
					'fr-FR' => 'fr-FR (1 234,56 € - France)',
					'es-ES' => 'es-ES (1.234,56 € - Spain)',
					'it-IT' => 'it-IT (1.234,56 € - Italy)',
					'nl-NL' => 'nl-NL (€ 1.234,56 - Netherlands)',
					'pl-PL' => 'pl-PL (1 234,56 zł - Poland)',
					'ja-JP' => 'ja-JP (¥1,235 - Japan)',
				),
				'default' => 'en-US',
			)
		);

		$this->framework->add_field(
			'currency_settings',
			'currency_config',
			array(
				'id'      => 'rl_fsbi_conversion_provider',
				'type'    => 'select',
				'label'   => esc_html__( 'Currency Conversion Tool', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Choose which exchange rate provider to use for live currency conversion. FreeCurrencyAPI provides real-time mid-market rates with 5,000 free monthly requests. Wise provides historical payout snapshot rates. Choose None to keep raw numbers unadjusted.', 'rl-freemius-bi' ),
				'options' => array(
					'freecurrencyapi' => esc_html__( 'FreeCurrencyAPI (freecurrencyapi.com - Recommended)', 'rl-freemius-bi' ),
					'wise'            => esc_html__( 'Wise / Transferwise (api.transferwise.com)', 'rl-freemius-bi' ),
					'none'            => esc_html__( 'None (Keep original amounts unadjusted)', 'rl-freemius-bi' ),
				),
				'default' => 'freecurrencyapi',
			)
		);

		$this->framework->add_field(
			'currency_settings',
			'currency_config',
			array(
				'id'          => 'rl_fsbi_freecurrencyapi_key',
				'type'        => 'text',
				'label'       => esc_html__( 'FreeCurrencyAPI Key', 'rl-freemius-bi' ),
				'desc'        => esc_html__( 'Your API Key from freecurrencyapi.com. Sign up for free to get 5,000 monthly conversion requests. Rates are automatically cached for 24 hours to conserve quota.', 'rl-freemius-bi' ),
				'placeholder' => 'fca_live_...',
				'conditions'  => array(
					array(
						'field'    => 'rl_fsbi_conversion_provider',
						'operator' => 'equals',
						'value'    => 'freecurrencyapi',
					),
				),
			)
		);

		$this->framework->add_field(
			'currency_settings',
			'currency_config',
			array(
				'id'                => 'rl_fsbi_transferwise_token',
				'type'              => 'text',
				'label'             => esc_html__( 'Wise API Token', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Your personal read-only API token from wise.com (TransferWise). Used to fetch historical mid-market exchange rates matching payout cycles.', 'rl-freemius-bi' ),
				'sanitize_callback' => array( $this, 'sanitize_freemius_key' ),
				'conditions'        => array(
					array(
						'field'    => 'rl_fsbi_conversion_provider',
						'operator' => 'equals',
						'value'    => 'wise',
					),
				),
			)
		);

		$this->framework->add_field(
			'currency_settings',
			'currency_config',
			array(
				'id'      => 'rl_fsbi_enable_charts',
				'type'    => 'toggle',
				'label'   => esc_html__( 'Enable Charts', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Enable or disable interactive Chart.js charts on the dashboard, including Monthly Revenue Breakdown, Currency Distribution, and Expected Renewals by Day.', 'rl-freemius-bi' ),
				'default' => 1,
			)
		);


		// --- Tab: Synchronization ---
		$this->framework->add_field(
			'sync_settings',
			'sync_config',
			array(
				'id'      => 'rl_fsbi_sync_enabled',
				'type'    => 'toggle',
				'label'   => esc_html__( 'Enable Automatic Background Sync', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'When enabled, a background WordPress WP-Cron job periodically synchronizes new payments, renewals, licenses, and balances from Freemius without slowing down page visits.', 'rl-freemius-bi' ),
				'default' => 1,
			)
		);

		$this->framework->add_field(
			'sync_settings',
			'sync_config',
			array(
				'id'      => 'rl_fsbi_sync_interval',
				'type'    => 'select',
				'label'   => esc_html__( 'Sync Frequency', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'How often the background WP-Cron synchronization runs. "Hourly" is recommended to keep real-time sales and renewals up to date.', 'rl-freemius-bi' ),
				'options' => array(
					'hourly'     => esc_html__( 'Hourly', 'rl-freemius-bi' ),
					'twicedaily' => esc_html__( 'Twice Daily', 'rl-freemius-bi' ),
					'daily'      => esc_html__( 'Daily', 'rl-freemius-bi' ),
				),
				'default' => 'hourly',
			)
		);

		$this->framework->add_field(
			'sync_settings',
			'sync_config',
			array(
				'id'      => 'rl_fsbi_retention_days',
				'type'    => 'number',
				'label'   => esc_html__( 'Data Retention (Days)', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Number of days of transaction history to keep in local database tables. Set to 0 for unlimited retention (strongly recommended: never deletes historical payment records, ensuring historical analytics and CSV exports remain accurate). Set a positive number (e.g. 365) only if you wish to automatically purge records older than that number of days.', 'rl-freemius-bi' ),
				'default' => 0,
				'min'     => 0,
				'max'     => 3650,
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
	 * Save handler to test connection and auto-discover plugin catalog from Freemius.
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
			$this->persist_settings_updates(
				array(
					'rl_fsbi_connection_status' => 'disconnected',
					'rl_fsbi_discovery_status'  => esc_html__( 'Credentials incomplete. Please provide Developer ID, Public API Key, and Secret API Key.', 'rl-freemius-bi' ),
				)
			);
			return;
		}

		$current_hash     = md5( $developer_id . '|' . $public_key . '|' . $secret_key );
		$existing_hash    = isset( $saved['rl_fsbi_credentials_hash'] ) ? (string) $saved['rl_fsbi_credentials_hash'] : '';
		$existing_catalog = isset( $saved['rl_fsbi_plugins_catalog'] ) && is_array( $saved['rl_fsbi_plugins_catalog'] ) ? $saved['rl_fsbi_plugins_catalog'] : array();
		$is_connected     = isset( $saved['rl_fsbi_connection_status'] ) && 'connected' === $saved['rl_fsbi_connection_status'];

		// If credentials didn't change and we are already connected, keep existing catalog
		if ( $current_hash === $existing_hash && ! empty( $existing_catalog ) && $is_connected ) {
			return;
		}

		$api     = new RL_FSBI_API( $developer_id, $public_key, $secret_key );
		$plugins = $api->retrieve_plugins();

		if ( false === $plugins || empty( $plugins ) ) {
			$this->persist_settings_updates(
				array(
					'rl_fsbi_credentials_hash'  => $current_hash,
					'rl_fsbi_connection_status' => 'failed',
					'rl_fsbi_discovery_status'  => esc_html__( 'Connection failed. Freemius rejected the credentials. Verify your Developer ID, Public Key, and Secret Key and save again.', 'rl-freemius-bi' ),
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
					'rl_fsbi_credentials_hash'  => $current_hash,
					'rl_fsbi_connection_status' => 'connected',
					'rl_fsbi_discovery_status'  => esc_html__( 'Connected successfully, but no active plugins were returned by Freemius for this account.', 'rl-freemius-bi' ),
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

		// Note: We deliberately set rl_fsbi_run_initial_sweep => false so settings saving
		// reloads quickly and never blocks page execution with multi-minute data syncs.
		$this->persist_settings_updates(
			array(
				'rl_fsbi_connection_status' => 'connected',
				'rl_fsbi_plugins_catalog'   => $catalog,
				'rl_fsbi_selected_plugins'  => $selected_plugins,
				'rl_fsbi_credentials_hash'  => $current_hash,
				'rl_fsbi_discovery_status'  => sprintf(
					/* translators: %d: number of discovered plugins */
					esc_html__( 'Connection verified! %d plugins discovered.', 'rl-freemius-bi' ),
					count( $catalog )
				),
				'rl_fsbi_run_initial_sweep' => false,
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
	public function sanitize_freemius_key( $value ): string {
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
	public function sanitize_developer_id( $value ): string {
		return preg_replace( '/[^0-9]/', '', (string) $value );
	}

	/**
	 * Persist partial settings into framework option row.
	 *
	 * @param array $updates Updates map.
	 */
	private function persist_settings_updates( array $updates ) {
		$settings = $this->get_all_settings();
		foreach ( $updates as $key => $value ) {
			$settings[ $key ] = $value;
		}

		update_option( 'rl_fsbi_settings', $settings );
	}

	/**
	 * Build plugin options from discovered catalog for checkbox list.
	 *
	 * @return array
	 */
	private function get_discovered_plugin_field_options(): array {
		$settings = $this->get_all_settings();
		$catalog  = $settings['rl_fsbi_plugins_catalog'] ?? array();
		if ( ! is_array( $catalog ) || empty( $catalog ) ) {
			$catalog = $this->framework ? $this->framework->get_option( 'rl_fsbi_plugins_catalog', array() ) : array();
		}

		$options = array();
		if ( is_array( $catalog ) ) {
			foreach ( $catalog as $plugin_id => $plugin_data ) {
				$plugin_id = (string) $plugin_id;
				$title     = '';
				$slug      = '';

				if ( is_object( $plugin_data ) ) {
					$title = trim( (string) ( $plugin_data->title ?? '' ) );
					$slug  = trim( (string) ( $plugin_data->slug ?? '' ) );
				} elseif ( is_array( $plugin_data ) ) {
					$title = trim( (string) ( $plugin_data['title'] ?? '' ) );
					$slug  = trim( (string) ( $plugin_data['slug'] ?? '' ) );
				}

				$options[ $plugin_id ] = array(
					'title' => '' !== $title ? sanitize_text_field( $title ) : 'Plugin #' . $plugin_id,
					'slug'  => sanitize_key( $slug ),
				);
			}
		}

		// If catalog is empty but tracked plugin IDs exist, generate fallback entries
		if ( empty( $options ) && ! empty( $settings['rl_fsbi_selected_plugins'] ) && is_array( $settings['rl_fsbi_selected_plugins'] ) ) {
			foreach ( $settings['rl_fsbi_selected_plugins'] as $plugin_id ) {
				$plugin_id             = (string) $plugin_id;
				$options[ $plugin_id ] = array(
					'title' => 'Plugin #' . $plugin_id,
					'slug'  => '',
				);
			}
		}

		return $options;
	}

	/**
	 * Get option value using the framework.
	 *
	 * @param string $option_key Option key.
	 * @param mixed  $default    Default value.
	 * @return mixed
	 */
	public function get_option( $option_key, $default = false ) {
		return $this->framework ? $this->framework->get_option( $option_key, $default ) : $default;
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public function get_all_settings(): array {
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
	public function update_option( $option_key, $value ): bool {
		$settings = $this->get_all_settings();
		$settings[ $option_key ] = $value;
		return (bool) update_option( 'rl_fsbi_settings', $settings );
	}

	/**
	 * Initialize framework on admin_menu hook.
	 */
	public function init() {
		if ( $this->framework ) {
			$this->framework->init();
			$this->framework->register_field_renderer( new RL_FSBI_Field_Checkbox_List() );
		}
	}

	/**
	 * Get dictionary of field descriptions for UI enhancement.
	 *
	 * @return array<string, string>
	 */
	public function get_field_descriptions(): array {
		$descriptions = array();
		if ( ! $this->framework ) {
			return $descriptions;
		}

		$tabs = $this->framework->get_tabs();
		foreach ( $tabs as $tab ) {
			if ( empty( $tab['sections'] ) || ! is_array( $tab['sections'] ) ) {
				continue;
			}
			foreach ( $tab['sections'] as $section ) {
				if ( empty( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
					continue;
				}
				foreach ( $section['fields'] as $field ) {
					if ( ! empty( $field['id'] ) && ! empty( $field['desc'] ) ) {
						$descriptions[ (string) $field['id'] ] = (string) $field['desc'];
					}
				}
			}
		}

		return $descriptions;
	}
}
