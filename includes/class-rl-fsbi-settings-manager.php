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
		require_once RL_FSBI_PLUGIN_DIR . 'includes/fields/class-rl-fsbi-field-password.php';

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
				'debug_level'   => 'error',
			)
		);

		// Hook into framework boot to ensure custom field renderer is registered into field registry
		add_action(
			'rl_fsbi_settings_framework_boot',
			function ( $fw ) {
				if ( $fw instanceof RL_Options_Framework ) {
					$fw->register_field_renderer( new RL_FSBI_Field_Checkbox_List() );
					$fw->register_field_renderer( new RL_FSBI_Field_Password() );
				}
			}
		);

		$this->framework->init();

		// Register custom field renderers after init so field_registry is initialized
		$this->framework->register_field_renderer( new RL_FSBI_Field_Checkbox_List() );
		$this->framework->register_field_renderer( new RL_FSBI_Field_Password() );

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

			$this->framework->add_tab(
				'newsletter_settings',
				array(
					'label' => esc_html__( 'Newsletter', 'rl-freemius-bi' ),
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

		// Support-tab documentation for the read-only endpoint, so the contract
		// lives next to the settings rather than only in the repository.
		$this->framework->add_section(
			'support',
			'rest_summary',
			array(
				'title'       => esc_html__( 'Read-Only Summary Endpoint', 'rl-freemius-bi' ),
				'description' => esc_html__( 'A stable JSON contract for other systems. Aggregates only: no emails, user ids, install secrets or API keys.', 'rl-freemius-bi' ),
				'priority'    => 100,
			)
		);

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
				'plugin_scope',
				'wporg_slugs',
				array(
					'title'       => esc_html__( 'WordPress.org Slugs', 'rl-freemius-bi' ),
					'description' => esc_html__( 'Only some Freemius products are also listed on WordPress.org. Give each one its directory slug and the dashboard will show a separate "WordPress.org (free)" widget for it; leave a slug empty and that widget is not rendered and no WordPress.org request is made.', 'rl-freemius-bi' ),
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

			$this->framework->add_section(
				'newsletter_settings',
				'newsletter_provider_section',
				array(
					'title'       => esc_html__( 'Email Marketing Service', 'rl-freemius-bi' ),
					'description' => esc_html__( 'Choose which email marketing provider to synchronize your Freemius marketing opt-ins with.', 'rl-freemius-bi' ),
				)
			);

			$this->framework->add_section(
				'newsletter_settings',
				'mailchimp_config',
				array(
					'title'       => esc_html__( 'Mailchimp Integration', 'rl-freemius-bi' ),
					'description' => esc_html__( 'Configure your Mailchimp API key and default audience to synchronize newsletter opt-in contacts.', 'rl-freemius-bi' ),
					'conditions'  => array(
						array(
							'field'    => 'rl_fsbi_newsletter_provider',
							'operator' => 'equals',
							'value'    => 'mailchimp',
						),
					),
				)
			);

			$this->framework->add_section(
				'newsletter_settings',
				'mailchimp_plan_tags',
				array(
					'title'       => esc_html__( 'Freemius Plans & Mailchimp Tags Mapping', 'rl-freemius-bi' ),
					'description' => esc_html__( 'Link Mailchimp audience tags to each Freemius pricing plan. When syncing users: paying customers receive their designated plan tag (e.g. "PRO USER"), while free-tier WordPress.org users who agreed to the Freemius onboarding opt-in receive the Default Tag below.', 'rl-freemius-bi' ),
					'conditions'  => array(
						array(
							'field'    => 'rl_fsbi_newsletter_provider',
							'operator' => 'equals',
							'value'    => 'mailchimp',
						),
					),
				)
			);

			$this->framework->add_section(
				'newsletter_settings',
				'mailchimp_testing',
				array(
					'title'       => esc_html__( 'Mailchimp Test & Sample Run', 'rl-freemius-bi' ),
					'description' => esc_html__( 'Execute a test sample run with a small batch of users to verify Freemius user retrieval, opt-in evaluation, plan detection, tag assignment, and Mailchimp synchronization.', 'rl-freemius-bi' ),
					'conditions'  => array(
						array(
							'field'    => 'rl_fsbi_newsletter_provider',
							'operator' => 'equals',
							'value'    => 'mailchimp',
						),
					),
				)
			);

			$this->framework->add_section(
				'newsletter_settings',
				'kit_config',
				array(
					'title'       => esc_html__( 'Kit.com (ConvertKit) Integration', 'rl-freemius-bi' ),
					'description' => esc_html__( 'Configure your Kit API Key and API Secret to synchronize newsletter opt-in contacts with your Kit account.', 'rl-freemius-bi' ),
					'conditions'  => array(
						array(
							'field'    => 'rl_fsbi_newsletter_provider',
							'operator' => 'equals',
							'value'    => 'kit',
						),
					),
				)
			);

			$this->framework->add_section(
				'newsletter_settings',
				'kit_plan_tags',
				array(
					'title'       => esc_html__( 'Freemius Plans & Kit Tags Mapping', 'rl-freemius-bi' ),
					'description' => esc_html__( 'Link Kit subscriber tags to each Freemius pricing plan. When syncing users: paying customers receive their designated plan tag, while free-tier WordPress.org users receive the Default Tag below.', 'rl-freemius-bi' ),
					'conditions'  => array(
						array(
							'field'    => 'rl_fsbi_newsletter_provider',
							'operator' => 'equals',
							'value'    => 'kit',
						),
					),
				)
			);

			$this->framework->add_section(
				'newsletter_settings',
				'kit_testing',
				array(
					'title'       => esc_html__( 'Kit.com Test & Sample Run', 'rl-freemius-bi' ),
					'description' => esc_html__( 'Execute a test sample run with a small batch of users to verify Freemius user retrieval, opt-in evaluation, plan detection, tag assignment, site URL sync, and Kit.com subscriber creation.', 'rl-freemius-bi' ),
					'conditions'  => array(
						array(
							'field'    => 'rl_fsbi_newsletter_provider',
							'operator' => 'equals',
							'value'    => 'kit',
						),
					),
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
				'type'              => 'password',
				'preserve_option' => 'rl_fsbi_settings',
				'label'             => esc_html__( 'Secret API Key', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Your Freemius Secret API Key (starts with sk_). Used to cryptographically sign API requests with HMAC-SHA256. Case-sensitive. Leave blank to keep the stored key.', 'rl-freemius-bi' ),
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
				'type'        => 'password',
				'preserve_option' => 'rl_fsbi_settings',
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
				'type'              => 'password',
				'preserve_option' => 'rl_fsbi_settings',
				'label'             => esc_html__( 'Wise API Token', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Your personal read-only API token from wise.com (TransferWise). Used to fetch historical mid-market exchange rates matching payout cycles. Leave blank to keep the stored token.', 'rl-freemius-bi' ),
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

		$this->framework->add_field(
			'plugin_scope',
			'wporg_slugs',
			array(
				'id'   => 'rl_fsbi_wporg_slugs_panel',
				'type' => 'html',
				'html' => $this->get_wporg_slugs_panel_html(),
			)
		);

		$this->framework->add_section(
			'plugin_scope',
			'monthly_targets',
			array(
				'title'       => esc_html__( 'Monthly New Subscription Targets', 'rl-freemius-bi' ),
				'description' => esc_html__( 'Optional per-product goal for first-time paid subscriptions in a month. The Portfolio Performance widget on the WordPress dashboard compares the current month against the sum of these targets and shows a per-plugin breakdown; leave a target empty and that product contributes nothing to the total.', 'rl-freemius-bi' ),
			)
		);

		$this->framework->add_field(
			'plugin_scope',
			'monthly_targets',
			array(
				'id'   => 'rl_fsbi_monthly_targets_panel',
				'type' => 'html',
				'html' => $this->get_monthly_targets_panel_html(),
			)
		);

		$this->framework->add_field(
			'support',
			'rest_summary',
			array(
				'id'   => 'rl_fsbi_rest_summary_docs',
				'type' => 'html',
				'html' => $this->get_rest_summary_docs_html(),
			)
		);

		$this->framework->add_field(
			'sync_settings',
			'sync_config',
			array(
				'id'      => 'rl_fsbi_monthly_subscription_target',
				'type'    => 'number',
				'label'   => esc_html__( 'Monthly New Subscription Target', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'How many new paid subscriptions you aim to win each month. The dashboard compares the current month against this number.', 'rl-freemius-bi' ),
				'default' => 5,
				'min'     => 0,
				'max'     => 100000,
			)
		);

		$this->framework->add_field(
			'sync_settings',
			'sync_config',
			array(
				'id'      => 'rl_fsbi_fs_revenue_share',
				'type'    => 'number',
				'label'   => esc_html__( 'Freemius Revenue Share (%)', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'The percentage Freemius charges on each sale. The Payments API returns no commission, so it has to be set here. It is prefilled from the synced account balance when that reports a rate; leave it empty and commission is not deducted, which overstates net revenue - the dashboard says so instead of applying 0%.', 'rl-freemius-bi' ),
				'default' => '',
				'min'     => 0,
				'max'     => 100,
			)
		);

		$this->framework->add_field(
			'sync_settings',
			'sync_config',
			array(
				'id'      => 'rl_fsbi_fs_fixed_fee',
				'type'    => 'number',
				'label'   => esc_html__( 'Freemius Fixed Fee per Transaction', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'The fixed fee Freemius adds to the percentage on each sale, where the plan carries one. Leave empty to use the documented defaults per currency (EUR 0.45, GBP 0.40, USD 0.50). Set 0 to disable it. Refunds do not return it.', 'rl-freemius-bi' ),
				'default' => '',
				'min'     => 0,
				'max'     => 1000,
			)
		);

		$this->framework->add_field(
			'sync_settings',
			'sync_config',
			array(
				'id'      => 'rl_fsbi_payout_day',
				'type'    => 'number',
				'label'   => esc_html__( 'Payout Day of Month', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'The Freemius API exposes no payout schedule, so payout dates on the dashboard are estimates calculated from this rule. Day of the month you are normally paid (1-28).', 'rl-freemius-bi' ),
				'default' => 15,
				'min'     => 1,
				'max'     => 28,
			)
		);

		$this->framework->add_field(
			'sync_settings',
			'sync_config',
			array(
				'id'      => 'rl_fsbi_payout_lag_months',
				'type'    => 'number',
				'label'   => esc_html__( 'Payout Lag (Months)', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'How many months after the revenue month that revenue is paid out (0-6). Used only to estimate the date shown next to "Current payout" and "Next payout".', 'rl-freemius-bi' ),
				'default' => 2,
				'min'     => 0,
				'max'     => 6,
			)
		);

		$this->framework->add_field(
			'sync_settings',
			'sync_config',
			array(
				'id'   => 'rl_fsbi_user_backfill_panel',
				'type' => 'html',
				'html' => $this->get_user_backfill_panel_html(),
			)
		);

		// --- Tab: Newsletter ---
		$this->framework->add_field(
			'newsletter_settings',
			'newsletter_provider_section',
			array(
				'id'      => 'rl_fsbi_newsletter_provider',
				'type'    => 'select',
				'label'   => esc_html__( 'Email Marketing Service', 'rl-freemius-bi' ),
				'desc'    => esc_html__( 'Select which email marketing platform to connect and synchronize your Freemius marketing opt-ins with.', 'rl-freemius-bi' ),
				'options' => array(
					'mailchimp' => esc_html__( 'Mailchimp', 'rl-freemius-bi' ),
					'kit'       => esc_html__( 'Kit.com (ConvertKit)', 'rl-freemius-bi' ),
				),
				'default' => 'mailchimp',
			)
		);

		// --- Mailchimp Fields ---
		$this->framework->add_field(
			'newsletter_settings',
			'mailchimp_config',
			array(
				'id'                => 'rl_fsbi_mailchimp_api_key',
				'type'              => 'password',
				'preserve_option' => 'rl_fsbi_settings',
				'label'             => esc_html__( 'Mailchimp API Key', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Your Mailchimp API key (e.g., xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-usX). Once saved, available audiences will populate in the dropdown below. Leave blank to keep the stored key.', 'rl-freemius-bi' ),
				'placeholder'       => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-usX',
				'sanitize_callback' => 'sanitize_text_field',
				'conditions'        => array(
					array(
						'field'    => 'rl_fsbi_newsletter_provider',
						'operator' => 'equals',
						'value'    => 'mailchimp',
					),
				),
			)
		);

		$mailchimp_lists = $this->get_mailchimp_lists();
		if ( ! empty( $mailchimp_lists ) ) {
			$this->framework->add_field(
				'newsletter_settings',
				'mailchimp_config',
				array(
					'id'         => 'rl_fsbi_mailchimp_list_id',
					'type'       => 'select',
					'label'      => esc_html__( 'Default Audience (List)', 'rl-freemius-bi' ),
					'desc'       => esc_html__( 'Select the default Mailchimp audience (list) to which opted-in users will be assigned.', 'rl-freemius-bi' ),
					'options'    => array( '' => esc_html__( '-- Select Default Audience --', 'rl-freemius-bi' ) ) + $mailchimp_lists,
					'conditions' => array(
						array(
							'field'    => 'rl_fsbi_newsletter_provider',
							'operator' => 'equals',
							'value'    => 'mailchimp',
						),
					),
				)
			);
		} else {
			$this->framework->add_field(
				'newsletter_settings',
				'mailchimp_config',
				array(
					'id'                => 'rl_fsbi_mailchimp_list_id',
					'type'              => 'text',
					'label'             => esc_html__( 'Default Audience (List) ID', 'rl-freemius-bi' ),
					'desc'              => esc_html__( 'Enter your Mailchimp Audience (List) ID (e.g. abc1234def). If a valid API key is saved, your audiences will automatically load into a dropdown selector here.', 'rl-freemius-bi' ),
					'placeholder'       => 'abc1234def',
					'sanitize_callback' => 'sanitize_text_field',
					'conditions'        => array(
						array(
							'field'    => 'rl_fsbi_newsletter_provider',
							'operator' => 'equals',
							'value'    => 'mailchimp',
						),
					),
				)
			);
		}

		$this->framework->add_field(
			'newsletter_settings',
			'mailchimp_plan_tags',
			array(
				'id'                => 'rl_fsbi_mc_default_tag',
				'type'              => 'text',
				'label'             => esc_html__( 'Default Tag (Free / WordPress.org Opt-in)', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Tag assigned to free users who installed your plugin from WordPress.org and clicked "Allow & Continue" on the Freemius onboarding connect screen, granting email permission for security and feature updates. Default: "Freemius Opt-in".', 'rl-freemius-bi' ),
				'placeholder'       => 'Freemius Opt-in',
				'default'           => 'Freemius Opt-in',
				'sanitize_callback' => 'sanitize_text_field',
				'conditions'        => array(
					array(
						'field'    => 'rl_fsbi_newsletter_provider',
						'operator' => 'equals',
						'value'    => 'mailchimp',
					),
				),
			)
		);

		$active_list_id = trim( (string) $this->get_option( 'rl_fsbi_mailchimp_list_id', '' ) );
		$available_tags = array();
		if ( ! empty( $active_list_id ) ) {
			$mc_service     = new RL_FSBI_Mailchimp( (string) $this->get_option( 'rl_fsbi_mailchimp_api_key', '' ) );
			$available_tags = $mc_service->get_tags( $active_list_id );
		}

		$plans = $this->get_available_plans();
		if ( ! empty( $plans ) ) {
			foreach ( $plans as $plan ) {
				$field_id = 'rl_fsbi_mc_plan_tag_' . $plan['plan_id'];
				if ( ! empty( $available_tags ) ) {
					$tag_select_opts = array( '' => esc_html__( '-- Use Default Tag --', 'rl-freemius-bi' ) ) + $available_tags;
					$this->framework->add_field(
						'newsletter_settings',
						'mailchimp_plan_tags',
						array(
							'id'         => $field_id,
							'type'       => 'select',
							'label'      => sprintf( esc_html__( 'Tag for %s', 'rl-freemius-bi' ), $plan['title'] ),
							'desc'       => sprintf( esc_html__( 'Choose the Mailchimp tag to assign to subscribers on plan "%s" (Plan ID: %d).', 'rl-freemius-bi' ), $plan['title'], $plan['plan_id'] ),
							'options'    => $tag_select_opts,
							'conditions' => array(
								array(
									'field'    => 'rl_fsbi_newsletter_provider',
									'operator' => 'equals',
									'value'    => 'mailchimp',
								),
							),
						)
					);
				} else {
					$this->framework->add_field(
						'newsletter_settings',
						'mailchimp_plan_tags',
						array(
							'id'                => $field_id,
							'type'              => 'text',
							'label'             => sprintf( esc_html__( 'Tag for %s', 'rl-freemius-bi' ), $plan['title'] ),
							'desc'              => sprintf( esc_html__( 'Type the Mailchimp tag name to assign to subscribers on plan "%s" (Plan ID: %d).', 'rl-freemius-bi' ), $plan['title'], $plan['plan_id'] ),
							'placeholder'       => $plan['title'],
							'sanitize_callback' => 'sanitize_text_field',
							'conditions'        => array(
								array(
									'field'    => 'rl_fsbi_newsletter_provider',
									'operator' => 'equals',
									'value'    => 'mailchimp',
								),
							),
						)
					);
				}
			}
		}

		$this->framework->add_field(
			'newsletter_settings',
			'mailchimp_testing',
			array(
				'id'         => 'rl_fsbi_mc_sample_run',
				'type'       => 'html',
				'html'       => $this->get_mailchimp_sample_run_html(),
				'conditions' => array(
					array(
						'field'    => 'rl_fsbi_newsletter_provider',
						'operator' => 'equals',
						'value'    => 'mailchimp',
					),
				),
			)
		);

		// --- Kit.com Fields ---
		$this->framework->add_field(
			'newsletter_settings',
			'kit_config',
			array(
				'id'                => 'rl_fsbi_kit_api_key',
				'type'              => 'password',
				'preserve_option' => 'rl_fsbi_settings',
				'label'             => esc_html__( 'Kit V4 API Key', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Your Kit API V4 Key. Located in Kit under Settings > Developer > V4 API Keys. (Passed via X-Kit-Api-Key header). Leave blank to keep the stored key.', 'rl-freemius-bi' ),
				'placeholder'       => 'kit_xxxxxxxxxxxxxxxxxxxxxxxxxxxx',
				'sanitize_callback' => 'sanitize_text_field',
				'conditions'        => array(
					array(
						'field'    => 'rl_fsbi_newsletter_provider',
						'operator' => 'equals',
						'value'    => 'kit',
					),
				),
			)
		);

		$this->framework->add_field(
			'newsletter_settings',
			'kit_config',
			array(
				'id'                => 'rl_fsbi_kit_api_secret',
				'type'              => 'password',
				'preserve_option' => 'rl_fsbi_settings',
				'label'             => esc_html__( 'Kit API Secret (Legacy / Optional)', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Optional. In Kit API v4, your V4 API Key authenticates all operations (subscribers, tags, custom fields) directly. Leave blank to keep the stored value.', 'rl-freemius-bi' ),
				'placeholder'       => '',
				'sanitize_callback' => 'sanitize_text_field',
				'conditions'        => array(
					array(
						'field'    => 'rl_fsbi_newsletter_provider',
						'operator' => 'equals',
						'value'    => 'kit',
					),
				),
			)
		);

		$this->framework->add_field(
			'newsletter_settings',
			'kit_config',
			array(
				'id'                => 'rl_fsbi_kit_subscriber_limit',
				'type'              => 'number',
				'label'             => esc_html__( 'Subscriber Limit', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Maximum number of active Kit subscribers to keep. Leave empty or set 0 for unlimited. Once the limit is reached, syncing stops adding new subscribers and reports how many were skipped; existing subscribers are still updated and tagged.', 'rl-freemius-bi' ),
				'placeholder'       => esc_html__( 'e.g. 10000 - leave empty for unlimited', 'rl-freemius-bi' ),
				'min'               => 0,
				'step'              => 1,
				'sanitize_callback' => array( $this, 'sanitize_subscriber_limit' ),
				'conditions'        => array(
					array(
						'field'    => 'rl_fsbi_newsletter_provider',
						'operator' => 'equals',
						'value'    => 'kit',
					),
				),
			)
		);

		$this->framework->add_field(
			'newsletter_settings',
			'kit_config',
			array(
				'id'                => 'rl_fsbi_consent_field',
				'type'              => 'select',
				'label'             => esc_html__( 'Marketing Consent Required', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Which signal counts as marketing consent before a contact is pushed to your provider. Freemius records one checkbox (is_marketing_allowed) and whether the address is verified; there is no consent timestamp and no double opt-in. "Opt-in + verified address" is the strictest form of consent the Freemius data can prove, and is the default. "Paid customers only" narrows further but is a targeting choice rather than a consent one.', 'rl-freemius-bi' ),
				'options'           => array(
					'verified_optin'          => esc_html__( 'Freemius opt-in + verified address (recommended)', 'rl-freemius-bi' ),
					'verified_optin_customer' => esc_html__( 'Freemius opt-in + verified address + at least one payment', 'rl-freemius-bi' ),
					'optin'                   => esc_html__( 'Freemius opt-in only (permissive, legacy behaviour)', 'rl-freemius-bi' ),
					'none'                    => esc_html__( 'Never push automatically — CSV export only', 'rl-freemius-bi' ),
				),
				'default'           => 'verified_optin',
			)
		);

		$this->framework->add_field(
			'newsletter_settings',
			'kit_config',
			array(
				'id'                => 'rl_fsbi_kit_prune_enabled',
				'type'              => 'toggle',
				'label'             => esc_html__( 'Allow Removing Kit Subscribers', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Off by default. Nothing in this plugin deletes or unsubscribes a contact automatically, and the quota tools stay disabled until you switch this on. Every removal still asks for confirmation and is permanent in Kit.', 'rl-freemius-bi' ),
				'default'           => 0,
			)
		);

		$this->framework->add_field(
			'newsletter_settings',
			'kit_config',
			array(
				'id'                => 'rl_fsbi_kit_prune_scope',
				'type'              => 'select',
				'label'             => esc_html__( 'Prune Scope', 'rl-freemius-bi' ),
				'desc'              => esc_html__( 'Which subscribers the quota tool is allowed to remove. "Any active subscriber" removes the oldest active subscribers regardless of tags. "Only tagged" restricts removal to subscribers carrying the Kit tags this plugin assigns.', 'rl-freemius-bi' ),
				'options'           => array(
					'all'    => esc_html__( 'Any active subscriber (oldest first)', 'rl-freemius-bi' ),
					'tagged' => esc_html__( 'Only subscribers tagged by this plugin', 'rl-freemius-bi' ),
				),
				'default'           => 'tagged',
				'conditions'        => array(
					array(
						'field'    => 'rl_fsbi_newsletter_provider',
						'operator' => 'equals',
						'value'    => 'kit',
					),
				),
			)
		);

		$this->framework->add_field(
			'newsletter_settings',
			'kit_config',
			array(
				'id'   => 'rl_fsbi_kit_quota_panel',
				'type' => 'html',
				'html' => $this->get_kit_quota_panel_html(),
			)
		);

		$kit_tags = $this->get_kit_tags();
		if ( ! empty( $kit_tags ) ) {
			$tag_options = array( '' => esc_html__( '-- Select Default Tag --', 'rl-freemius-bi' ) ) + $kit_tags;
			$this->framework->add_field(
				'newsletter_settings',
				'kit_plan_tags',
				array(
					'id'         => 'rl_fsbi_kit_default_tag',
					'type'       => 'select',
					'label'      => esc_html__( 'Default Tag (Free / WordPress.org Opt-in)', 'rl-freemius-bi' ),
					'desc'       => esc_html__( 'Tag assigned to free users who agreed to Freemius onboarding. Default: "Freemius Opt-in".', 'rl-freemius-bi' ),
					'options'    => $tag_options,
					'conditions' => array(
						array(
							'field'    => 'rl_fsbi_newsletter_provider',
							'operator' => 'equals',
							'value'    => 'kit',
						),
					),
				)
			);
		} else {
			$this->framework->add_field(
				'newsletter_settings',
				'kit_plan_tags',
				array(
					'id'                => 'rl_fsbi_kit_default_tag',
					'type'              => 'text',
					'label'             => esc_html__( 'Default Tag (Free / WordPress.org Opt-in)', 'rl-freemius-bi' ),
					'desc'              => esc_html__( 'Tag name assigned to free users who agreed to Freemius onboarding. Default: "Freemius Opt-in".', 'rl-freemius-bi' ),
					'placeholder'       => 'Freemius Opt-in',
					'default'           => 'Freemius Opt-in',
					'sanitize_callback' => 'sanitize_text_field',
					'conditions'        => array(
						array(
							'field'    => 'rl_fsbi_newsletter_provider',
							'operator' => 'equals',
							'value'    => 'kit',
						),
					),
				)
			);
		}

		if ( ! empty( $plans ) ) {
			foreach ( $plans as $plan ) {
				$field_id = 'rl_fsbi_kit_plan_tag_' . $plan['plan_id'];
				if ( ! empty( $kit_tags ) ) {
					$tag_select_opts = array( '' => esc_html__( '-- Use Default Tag --', 'rl-freemius-bi' ) ) + $kit_tags;
					$this->framework->add_field(
						'newsletter_settings',
						'kit_plan_tags',
						array(
							'id'         => $field_id,
							'type'       => 'select',
							'label'      => sprintf( esc_html__( 'Tag for %s', 'rl-freemius-bi' ), $plan['title'] ),
							'desc'       => sprintf( esc_html__( 'Choose the Kit tag to assign to subscribers on plan "%s" (Plan ID: %d).', 'rl-freemius-bi' ), $plan['title'], $plan['plan_id'] ),
							'options'    => $tag_select_opts,
							'conditions' => array(
								array(
									'field'    => 'rl_fsbi_newsletter_provider',
									'operator' => 'equals',
									'value'    => 'kit',
								),
							),
						)
					);
				} else {
					$this->framework->add_field(
						'newsletter_settings',
						'kit_plan_tags',
						array(
							'id'                => $field_id,
							'type'              => 'text',
							'label'             => sprintf( esc_html__( 'Tag for %s', 'rl-freemius-bi' ), $plan['title'] ),
							'desc'              => sprintf( esc_html__( 'Type the Kit tag name to assign to subscribers on plan "%s" (Plan ID: %d).', 'rl-freemius-bi' ), $plan['title'], $plan['plan_id'] ),
							'placeholder'       => $plan['title'],
							'sanitize_callback' => 'sanitize_text_field',
							'conditions'        => array(
								array(
									'field'    => 'rl_fsbi_newsletter_provider',
									'operator' => 'equals',
									'value'    => 'kit',
								),
							),
						)
					);
				}
			}
		}

		$this->framework->add_field(
			'newsletter_settings',
			'kit_testing',
			array(
				'id'         => 'rl_fsbi_kit_sample_run',
				'type'       => 'html',
				'html'       => $this->get_kit_sample_run_html(),
				'conditions' => array(
					array(
						'field'    => 'rl_fsbi_newsletter_provider',
						'operator' => 'equals',
						'value'    => 'kit',
					),
				),
			)
		);
	}

	/**
	 * Build HTML for Mailchimp Sample Run testing panel.
	 *
	 * @return string
	 */
	private function get_mailchimp_sample_run_html(): string {
		$catalog = $this->get_option( 'rl_fsbi_plugins_catalog', array() );
		$plugin_options = '<option value="all">' . esc_html__( 'All Tracked Plugins', 'rl-freemius-bi' ) . '</option>';
		if ( is_array( $catalog ) ) {
			foreach ( $catalog as $pid => $info ) {
				$title = ! empty( $info['title'] ) ? $info['title'] : 'Plugin #' . $pid;
				$plugin_options .= '<option value="' . esc_attr( $pid ) . '">' . esc_html( $title ) . ' (#' . esc_html( $pid ) . ')</option>';
			}
		}

		ob_start();
		?>
		<div class="rl-fsbi-sample-run-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); max-width:850px;">
			<h4 style="margin:0 0 10px; font-size:15px; color:#1e293b; font-weight:600;">
				<?php echo esc_html__( 'Sample Synchronization Test (Deep Debugging)', 'rl-freemius-bi' ); ?>
			</h4>
			<p style="margin:0 0 16px; color:#64748b; font-size:13px; line-height:1.5;">
				<?php echo esc_html__( 'Fetch a small sample of users directly from Freemius, inspect each contact\'s marketing permission and subscription plan, apply your configured Mailchimp tags, and monitor every step in real-time.', 'rl-freemius-bi' ); ?>
			</p>

			<div style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; margin-bottom:16px; background:#f8fafc; padding:14px; border-radius:6px; border:1px solid #e2e8f0;">
				<div>
					<label for="rl-fsbi-sample-user-count" style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:4px;">
						<?php echo esc_html__( 'Sample Size (Users)', 'rl-freemius-bi' ); ?>
					</label>
					<input type="number" id="rl-fsbi-sample-user-count" value="5" min="1" max="50" style="width:90px;" class="small-text">
				</div>

				<div>
					<label for="rl-fsbi-sample-plugin-select" style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:4px;">
						<?php echo esc_html__( 'Select Plugin Scope', 'rl-freemius-bi' ); ?>
					</label>
					<select id="rl-fsbi-sample-plugin-select" style="min-width:200px;">
						<?php echo $plugin_options; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</select>
				</div>

				<div style="padding-bottom:6px;">
					<label style="font-size:13px; color:#334155; cursor:pointer;">
						<input type="checkbox" id="rl-fsbi-sample-dry-run" value="1" checked="checked" style="margin-top:0;">
						<strong><?php echo esc_html__( 'Dry Run Mode', 'rl-freemius-bi' ); ?></strong>
						<span style="color:#64748b; font-size:12px;">(<?php echo esc_html__( 'tests plan lookup and tags without modifying Mailchimp', 'rl-freemius-bi' ); ?>)</span>
					</label>
				</div>

				<div style="margin-left:auto;">
					<button type="button" class="button button-primary" id="rl-fsbi-run-sample-test-btn" style="display:inline-flex; align-items:center; gap:6px; font-weight:600;">
						<span class="dashicons dashicons-controls-play" style="font-size:16px; width:16px; height:16px; line-height:16px;"></span>
						<span><?php echo esc_html__( 'Run Sample Sync Test', 'rl-freemius-bi' ); ?></span>
					</button>
					<button type="button" class="button" id="rl-fsbi-clear-sample-log-btn" style="display:none; margin-left:6px;">
						<?php echo esc_html__( 'Clear Log', 'rl-freemius-bi' ); ?>
					</button>
				</div>
			</div>

			<div id="rl-fsbi-sample-log-wrap" style="display:none;">
				<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
					<span style="font-size:12px; font-weight:600; color:#475569;">
						<?php echo esc_html__( 'Diagnostic Execution Log (rlLOG)', 'rl-freemius-bi' ); ?>:
					</span>
					<span id="rl-fsbi-sample-summary-badge" style="font-size:11px; padding:2px 8px; border-radius:10px; background:#e2e8f0; color:#334155; font-weight:600;"></span>
				</div>
				<div id="rl-fsbi-sample-log-console" style="background:#0f172a; color:#f8fafc; font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:12px; padding:16px; border-radius:8px; max-height:360px; overflow-y:auto; line-height:1.6; border:1px solid #334155; box-shadow:inset 0 2px 4px rgba(0,0,0,0.4);"></div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
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

		if ( isset( $saved['rl_fsbi_mailchimp_api_key'] ) ) {
			$mc_key = trim( (string) $saved['rl_fsbi_mailchimp_api_key'] );
			if ( ! empty( $mc_key ) ) {
				delete_transient( 'rl_fsbi_mc_lists_' . md5( $mc_key ) );
				$mc = new RL_FSBI_Mailchimp( $mc_key );
				if ( $mc->is_configured() ) {
					$lists = $mc->get_lists( true );
					$current_list_id = trim( (string) ( $saved['rl_fsbi_mailchimp_list_id'] ?? $this->get_option( 'rl_fsbi_mailchimp_list_id', '' ) ) );
					if ( empty( $current_list_id ) && count( $lists ) === 1 ) {
						$current_list_id = (string) array_key_first( $lists );
						$this->persist_settings_updates( array( 'rl_fsbi_mailchimp_list_id' => $current_list_id ) );
					}
					if ( ! empty( $current_list_id ) ) {
						$mc->get_tags( $current_list_id, true );
					}
				}
			}
		}

		if ( isset( $saved['rl_fsbi_kit_api_key'] ) ) {
			$kit_key    = trim( (string) $saved['rl_fsbi_kit_api_key'] );
			$kit_secret = trim( (string) ( $saved['rl_fsbi_kit_api_secret'] ?? '' ) );
			if ( ! empty( $kit_key ) ) {
				delete_transient( 'rl_fsbi_kit_tags_' . md5( $kit_key ) );
				$kit = new RL_FSBI_Kit( $kit_key, $kit_secret );
				if ( $kit->is_configured() ) {
					$kit->get_tags( true );
					$kit->ensure_custom_field( 'Website' );
				}
			}
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
	 * Sanitize the subscriber limit. An empty value means unlimited.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_subscriber_limit( $value ): string {
		$value = trim( preg_replace( '/[^0-9]/', '', (string) $value ) );

		if ( '' === $value ) {
			return '';
		}

		return (string) max( 0, (int) $value );
	}

	/**
	 * Render the read-only Kit quota panel with the manual prune controls.
	 *
	 * The panel is inert markup; the buttons are wired in rl-fsbi-settings.js
	 * and every action is re-checked server-side.
	 *
	 * @return string
	 */
	/**
	 * Settings panel for the Freemius user (email) backfill.
	 *
	 * @return string
	 */
	private function get_user_backfill_panel_html(): string {
		// Never instantiate RL_FSBI_User_Sync here: its constructor builds a
		// settings manager, which re-registers these fields and re-renders this
		// panel — unbounded recursion that exhausts memory on every request.
		global $wpdb;

		$table      = $wpdb->prefix . 'rl_fsbi_users';
		$licences   = $wpdb->prefix . 'rl_fsbi_licenses';
		$has_table  = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$total      = $has_table ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0;
		$with_email = $has_table ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE email IS NOT NULL AND email <> ''" ) : 0;

		// The number that actually matters is whether we can reach the people who
		// hold a licence, not how many user records were crawled.
		$holders = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$licences} WHERE user_id > 0" );
		$missing = $has_table
			? (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT l.user_id) FROM {$licences} l
				LEFT JOIN {$table} u ON u.user_id = l.user_id
				WHERE l.user_id > 0 AND ( u.email IS NULL OR u.email = '' )"
			)
			: $holders;
		$covered = max( 0, $holders - $missing );
		$percent = $holders > 0 ? ( $covered / $holders ) * 100 : 100.0;

		$sync_state = get_option( 'rl_fsbi_user_sync_state' );
		$offset     = is_array( $sync_state ) ? (int) ( $sync_state['offset'] ?? 0 ) : 0;
		$progress   = $total > 0 ? min( 100, ( $offset / $total ) * 100 ) : 0.0;

		$last_run   = (string) get_option( 'rl_fsbi_user_sync_last_run', '' );
		$cycle_done = (int) get_option( 'rl_fsbi_user_sync_cycle_done', 0 );
		$error      = (string) get_option( 'rl_fsbi_user_sync_error', '' );
		$next_run   = class_exists( 'RL_FSBI_User_Sync' )
			? wp_next_scheduled( RL_FSBI_User_Sync::CRON_HOOK )
			: false;
		$cooldown   = class_exists( 'RL_FSBI_User_Sync' )
			? (int) RL_FSBI_User_Sync::CYCLE_COOLDOWN
			: 6 * HOUR_IN_SECONDS;

		$notice = get_transient( 'rl_fsbi_backfill_notice' );
		if ( $notice ) {
			delete_transient( 'rl_fsbi_backfill_notice' );
		}

		$stat_style  = 'display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#646970;margin-bottom:2px';
		$value_style = 'display:block;font-size:20px;line-height:1.25;color:#1d2327';
		$sub_style   = 'display:block;font-size:11px;color:#646970;margin-top:1px';

		ob_start();
		?>
		<div class="rl-fsbi-user-backfill" style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 18px;max-width:860px">
			<h3 style="margin:0 0 6px;font-size:14px"><?php echo esc_html__( 'Customer emails (background sync)', 'rl-freemius-bi' ); ?></h3>
			<p class="description" style="margin:0 0 12px">
				<?php echo esc_html__( 'Freemius returns emails 50 users per request, so they are fetched in a resumable background pass every 5 minutes. Each pass picks up where the last one stopped.', 'rl-freemius-bi' ); ?>
			</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success inline" style="margin:0 0 10px"><p><?php echo esc_html( (string) $notice ); ?></p></div>
			<?php endif; ?>

			<div style="display:flex;gap:32px;flex-wrap:wrap;margin:0 0 12px">
				<div>
					<span style="<?php echo esc_attr( $stat_style ); ?>"><?php echo esc_html__( 'Licence holders resolved', 'rl-freemius-bi' ); ?></span>
					<strong style="<?php echo esc_attr( $value_style ); ?>"><?php echo esc_html( number_format_i18n( $percent, 1 ) . '%' ); ?></strong>
					<span style="<?php echo esc_attr( $sub_style ); ?>">
						<?php
						printf(
							/* translators: 1: covered holders, 2: total holders */
							esc_html__( '%1$s of %2$s', 'rl-freemius-bi' ),
							esc_html( number_format_i18n( $covered ) ),
							esc_html( number_format_i18n( $holders ) )
						);
						?>
					</span>
				</div>
				<div>
					<span style="<?php echo esc_attr( $stat_style ); ?>"><?php echo esc_html__( 'Emails missing', 'rl-freemius-bi' ); ?></span>
					<strong style="<?php echo esc_attr( $value_style ); ?>"><?php echo esc_html( number_format_i18n( $missing ) ); ?></strong>
					<span style="<?php echo esc_attr( $sub_style ); ?>">
						<?php
						printf(
							/* translators: %s: percentage of licence holders */
							esc_html__( '%s%% of licence holders', 'rl-freemius-bi' ),
							esc_html( number_format_i18n( $holders > 0 ? ( $missing / $holders ) * 100 : 0, 1 ) )
						);
						?>
					</span>
				</div>
				<div>
					<span style="<?php echo esc_attr( $stat_style ); ?>"><?php echo esc_html__( 'Users stored', 'rl-freemius-bi' ); ?></span>
					<strong style="<?php echo esc_attr( $value_style ); ?>"><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
					<span style="<?php echo esc_attr( $sub_style ); ?>">
						<?php
						printf(
							/* translators: %s: users with an email */
							esc_html__( '%s with an email', 'rl-freemius-bi' ),
							esc_html( number_format_i18n( $with_email ) )
						);
						?>
					</span>
				</div>
			</div>

			<?php if ( $offset > 0 ) : ?>
				<div style="height:6px;background:#f0f0f1;border-radius:3px;overflow:hidden;margin:0 0 8px">
					<span style="display:block;height:100%;width:<?php echo esc_attr( (string) round( $progress, 1 ) ); ?>%;background:#2271b1"></span>
				</div>
			<?php endif; ?>

			<p style="margin:0 0 12px;font-size:12px;color:#50575e">
				<?php
				if ( '' !== $error ) {
					printf(
						/* translators: %s: error message */
						esc_html__( 'Last pass stopped with an error: %s', 'rl-freemius-bi' ),
						esc_html( $error )
					);
				} elseif ( $offset > 0 ) {
					printf(
						/* translators: 1: crawled so far, 2: total users, 3: percentage */
						esc_html__( 'Crawling — %1$s of %2$s crawled (%3$s%%).', 'rl-freemius-bi' ),
						esc_html( number_format_i18n( $offset ) ),
						esc_html( number_format_i18n( $total ) ),
						esc_html( number_format_i18n( $progress, 1 ) )
					);
				} elseif ( $cycle_done > 0 ) {
					$wait = max( 0, $cooldown - ( time() - $cycle_done ) );

					printf(
						/* translators: %s: human readable time difference */
						esc_html__( 'Last full pass finished %s ago.', 'rl-freemius-bi' ),
						esc_html( human_time_diff( $cycle_done, time() ) )
					);

					if ( $wait > 0 ) {
						printf(
							' ' . /* translators: %s: human readable time difference */ esc_html__( 'Next pass starts in %s.', 'rl-freemius-bi' ),
							esc_html( human_time_diff( time() + $wait, time() ) )
						);
					} else {
						printf(
							' ' . esc_html__( 'The next pass is due now.', 'rl-freemius-bi' )
						);
					}
				} else {
					esc_html_e( 'Waiting for the first pass to run.', 'rl-freemius-bi' );
				}

				if ( '' !== $last_run ) {
					printf(
						' ' . /* translators: %s: human readable time difference */ esc_html__( 'Last run %s ago.', 'rl-freemius-bi' ),
						esc_html( human_time_diff( strtotime( $last_run ), time() ) )
					);
				}

				if ( $next_run ) {
					printf(
						' ' . /* translators: %s: human readable time difference */ esc_html__( 'Next check in %s.', 'rl-freemius-bi' ),
						esc_html( human_time_diff( time(), $next_run ) )
					);
				}
				?>
			</p>

			<?php if ( $missing > 0 ) : ?>
				<p style="margin:0 0 12px;font-size:12px;color:#8a4b00">
					<?php
					printf(
						/* translators: %s: number of licence holders */
						esc_html__( '%s licence holder(s) have no email in Freemius at all. Crawling cannot resolve them — their user record is not in this product.', 'rl-freemius-bi' ),
						esc_html( number_format_i18n( $missing ) )
					);
					?>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rl_fsbi_user_backfill" />
				<?php wp_nonce_field( 'rl_fsbi_user_backfill' ); ?>
				<button type="submit" class="button"><?php echo esc_html__( 'Run user backfill now', 'rl-freemius-bi' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Save the per-plugin WordPress.org slugs, validating each against the API.
	 *
	 * A slug WordPress.org actively denies is dropped rather than stored: a typo
	 * would otherwise render a widget full of zeros, which is worse than the
	 * widget being absent. A slug we merely failed to confirm is kept — an
	 * unreachable API is not evidence that the slug is wrong, and discarding it
	 * left the admin staring at an empty field that looked saved.
	 *
	 * The submitted map is merged into the stored one, so a rejected entry can
	 * never delete the other products' slugs. Emptying a field clears just that
	 * product.
	 *
	 * @return void
	 */
	public function handle_save_wporg_slugs(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'rl-freemius-bi' ) );
		}

		check_admin_referer( 'rl_fsbi_save_wporg_slugs' );

		$input   = isset( $_POST['wporg'] ) && is_array( $_POST['wporg'] ) ? wp_unslash( $_POST['wporg'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$stored  = $this->get_option( 'rl_fsbi_wporg_slugs', array() );
		$saved   = is_array( $stored ) ? $stored : array();

		$rejected   = array();
		$unverified = array();
		$verified   = array();

		foreach ( $input as $plugin_id => $slug ) {
			$plugin_id = (int) $plugin_id;
			$slug      = sanitize_title( (string) $slug );

			if ( $plugin_id <= 0 ) {
				continue;
			}

			if ( '' === $slug ) {
				unset( $saved[ $plugin_id ] );
				continue;
			}

			$probe = $this->probe_wporg_slug( $slug );

			if ( 'not_found' === $probe['state'] ) {
				$rejected[] = $slug;
				continue;
			}

			$saved[ $plugin_id ] = $slug;

			if ( 'unverifiable' === $probe['state'] ) {
				$unverified[] = $slug . ' (' . $probe['detail'] . ')';
			} else {
				$verified[] = $slug . ' ' . number_format_i18n( $probe['active_installs'] );
			}
		}

		$this->update_option( 'rl_fsbi_wporg_slugs', $saved );

		// Drop cached snapshots so the change shows immediately.
		foreach ( array_merge( array_values( $saved ), $rejected ) as $slug ) {
			delete_transient( 'rl_fsbi_wporg_' . md5( sanitize_title( (string) $slug ) ) );
		}

		// The assembled dashboard payload is cached for 15 minutes and is keyed
		// by a generation counter, so without this the card stayed absent until
		// the cache expired: the slug was saved but the payload still said there
		// was none.
		update_option( 'rl_fsbi_cache_generation', time(), false );

		$parts = array();

		if ( ! empty( $verified ) ) {
			$parts[] = sprintf(
				/* translators: %s: comma separated slugs with active install counts */
				__( 'Saved and confirmed on WordPress.org: %s.', 'rl-freemius-bi' ),
				implode( ', ', $verified )
			);
		}

		if ( ! empty( $unverified ) ) {
			$parts[] = sprintf(
				/* translators: %s: comma separated slugs with the reason the check failed */
				__( 'Saved, but WordPress.org could not be reached to confirm it: %s. The widget will fill in on the next successful sync.', 'rl-freemius-bi' ),
				implode( ', ', $unverified )
			);
		}

		if ( ! empty( $rejected ) ) {
			$parts[] = sprintf(
				/* translators: %s: comma separated slugs */
				__( 'Not saved, no such plugin on WordPress.org: %s.', 'rl-freemius-bi' ),
				implode( ', ', $rejected )
			);
		}

		$message = empty( $parts ) ? '1' : implode( ' ', $parts );

		$redirect_args = array(
			'page'                => $this->framework ? $this->framework->get_config( 'page_slug' ) : 'rl-freemius-bi-settings',
			'tab'                 => 'plugin_scope',
			'rl_fsbi_wporg_saved' => rawurlencode( $message ),
		);

		if ( empty( $rejected ) && empty( $unverified ) ) {
			$redirect_args['rl_fsbi_wporg_ok'] = '1';
		}

		wp_safe_redirect(
			add_query_arg(
				$redirect_args,
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Run the WordPress.org lookup for one product right now.
	 *
	 * The dashboard already reports the last cached result, but a cached
	 * failure cannot tell an administrator whether the fetch works today. This
	 * forces one request, drops the cached snapshot, rebuilds the dashboard
	 * payload and reports the outcome on screen.
	 *
	 * @return void
	 */
	public function handle_check_wporg(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'rl-freemius-bi' ) );
		}

		check_admin_referer( 'rl_fsbi_check_wporg' );

		$plugin_id = isset( $_GET['plugin_id'] ) ? (int) $_GET['plugin_id'] : 0;
		$slug      = $plugin_id > 0 ? $this->get_wporg_slug( $plugin_id ) : '';

		if ( '' === $slug ) {
			$message = __( 'No WordPress.org slug is saved for that product.', 'rl-freemius-bi' );
		} else {
			// Drop the cached snapshot first so the row below this notice shows
			// the result of this run rather than the previous one.
			delete_transient( 'rl_fsbi_wporg_' . md5( $slug ) );

			$admin = new RL_FSBI_Admin( 'rl-freemius-bi', defined( 'RL_FSBI_VERSION' ) ? RL_FSBI_VERSION : '' );
			$snapshot = $admin->get_wporg_snapshot( $slug );

			update_option( 'rl_fsbi_cache_generation', time(), false );

			if ( ! empty( $snapshot['available'] ) ) {
				$message = sprintf(
					/* translators: 1: slug, 2: active install count, 3: plugin version */
					__( 'WordPress.org answered for %1$s: %2$s active installs, version %3$s.', 'rl-freemius-bi' ),
					$slug,
					number_format_i18n( (int) ( $snapshot['active_installs'] ?? 0 ) ),
					(string) ( $snapshot['version'] ?? '—' )
				);
			} elseif ( 'not_found' === ( $snapshot['reason'] ?? '' ) ) {
				$message = sprintf(
					/* translators: 1: slug, 2: HTTP status */
					__( 'WordPress.org has no plugin called %1$s (%2$s).', 'rl-freemius-bi' ),
					$slug,
					(string) ( $snapshot['detail'] ?? '' )
				);
			} else {
				$message = sprintf(
					/* translators: 1: slug, 2: error detail */
					__( 'Could not reach WordPress.org for %1$s: %2$s', 'rl-freemius-bi' ),
					$slug,
					(string) ( $snapshot['detail'] ?? 'unknown error' )
				);
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                  => $this->framework ? $this->framework->get_config( 'page_slug' ) : 'rl-freemius-bi-settings',
					'tab'                   => 'plugin_scope',
					'rl_fsbi_wporg_checked' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Save the per-plugin monthly targets.
	 *
	 * An empty field means "no target" and is stored as nothing, so the widget
	 * can hide the "/ TARGET" part for a product nobody set a goal for.
	 *
	 * @return void
	 */
	public function handle_save_monthly_targets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'rl-freemius-bi' ) );
		}

		check_admin_referer( 'rl_fsbi_save_monthly_targets' );

		$input = isset( $_POST['target'] ) && is_array( $_POST['target'] ) ? wp_unslash( $_POST['target'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$saved = array();

		foreach ( $input as $plugin_id => $value ) {
			$plugin_id = (int) $plugin_id;
			$value     = trim( sanitize_text_field( (string) $value ) );

			if ( $plugin_id <= 0 || '' === $value ) {
				continue;
			}

			$saved[ $plugin_id ] = max( 0, (int) $value );
		}

		$this->update_option( 'rl_fsbi_monthly_targets', $saved );

		// Same reason as the slugs: the widget payload is cached, so without
		// this a saved target would not show until the cache expired.
		update_option( 'rl_fsbi_cache_generation', time(), false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                   => $this->framework ? $this->framework->get_config( 'page_slug' ) : 'rl-freemius-bi-settings',
					'tab'                    => 'plugin_scope',
					'rl_fsbi_targets_saved'  => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Per-plugin monthly target editor for the Plugin Scope tab.
	 *
	 * @return string
	 */
	private function get_monthly_targets_panel_html(): string {
		$catalog = $this->get_option( 'rl_fsbi_plugins_catalog', array() );
		$catalog = is_array( $catalog ) ? $catalog : array();
		$targets = $this->get_option( 'rl_fsbi_monthly_targets', array() );
		$targets = is_array( $targets ) ? $targets : array();
		$saved   = isset( $_GET['rl_fsbi_targets_saved'] ) && '1' === $_GET['rl_fsbi_targets_saved']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		?>
		<div class="rl-fsbi-wporg-slugs">
			<?php if ( $saved ) : ?>
				<div class="notice notice-success inline" style="margin:0 0 10px;"><p><?php esc_html_e( 'Monthly targets saved.', 'rl-freemius-bi' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rl_fsbi_save_monthly_targets" />
				<?php wp_nonce_field( 'rl_fsbi_save_monthly_targets' ); ?>

				<table>
					<tbody>
					<?php foreach ( $catalog as $plugin ) :
						$plugin_id = isset( $plugin['id'] ) ? (int) $plugin['id'] : 0;
						$title     = isset( $plugin['title'] ) ? (string) $plugin['title'] : 'Plugin #' . $plugin_id;
						$stored    = isset( $targets[ $plugin_id ] ) ? (int) $targets[ $plugin_id ] : null;
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $title ); ?></th>
							<td>
								<input type="number" min="0" step="1" class="small-text"
									name="target[<?php echo esc_attr( (string) $plugin_id ); ?>]"
									value="<?php echo null === $stored ? '' : esc_attr( (string) $stored ); ?>"
									placeholder="<?php echo esc_attr__( 'No target', 'rl-freemius-bi' ); ?>"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: plugin title */ __( 'Monthly new subscription target for %s', 'rl-freemius-bi' ), $title ) ); ?>" />
								<span class="rl-fsbi-wporg-note"><?php esc_html_e( 'First-time paid subscriptions per month. Leave empty for no target.', 'rl-freemius-bi' ); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<p class="submit" style="margin:12px 0 0;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Targets', 'rl-freemius-bi' ); ?></button>
				</p>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Ask WordPress.org about a slug.
	 *
	 * Three outcomes, because "I could not check" and "WordPress.org says no"
	 * need different treatment: only the second justifies throwing away what the
	 * admin typed.
	 *
	 * @param string $slug Plugin slug.
	 * @return array{state:string,detail:string,active_installs:int}
	 */
	private function probe_wporg_slug( string $slug ): array {
		$url = add_query_arg(
			array(
				'action'        => 'plugin_information',
				'request[slug]' => $slug,
			),
			'https://api.wordpress.org/plugins/info/1.2/'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => array( 'Accept' => 'application/json' ) ) );

		if ( is_wp_error( $response ) ) {
			$this->log_wporg_probe( $slug, 'request_failed', $response->get_error_message() );

			return array( 'state' => 'unverifiable', 'detail' => $response->get_error_message(), 'active_installs' => 0 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 404 === $code || ( is_array( $body ) && ! empty( $body['error'] ) ) ) {
			$this->log_wporg_probe( $slug, 'not_found', 'HTTP ' . $code );

			return array( 'state' => 'not_found', 'detail' => 'HTTP ' . $code, 'active_installs' => 0 );
		}

		if ( 200 !== $code || ! is_array( $body ) ) {
			$this->log_wporg_probe( $slug, 'unverifiable', 'HTTP ' . $code );

			return array( 'state' => 'unverifiable', 'detail' => 'HTTP ' . $code, 'active_installs' => 0 );
		}

		$this->log_wporg_probe( $slug, 'found', 'HTTP 200' );

		return array(
			'state'           => 'found',
			'detail'          => 'HTTP 200',
			'active_installs' => isset( $body['active_installs'] ) ? (int) $body['active_installs'] : 0,
		);
	}

	/**
	 * Record a WordPress.org lookup when Debug Mode is on.
	 *
	 * @param string $slug   Plugin slug.
	 * @param string $state  Outcome label.
	 * @param string $detail HTTP status or error message.
	 * @return void
	 */
	private function log_wporg_probe( string $slug, string $state, string $detail ): void {
		if ( ! $this->is_debug_mode() ) {
			return;
		}

		error_log( sprintf( '[rl-fsbi] wp.org probe slug=%s state=%s detail=%s', $slug, $state, $detail ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Stored WordPress.org slug for a tracked plugin.
	 *
	 * @param int $plugin_id Freemius plugin id.
	 * @return string Empty when the product is not on WordPress.org.
	 */
	public function get_wporg_slug( $plugin_id ): string {
		$map = $this->get_option( 'rl_fsbi_wporg_slugs', array() );
		if ( ! is_array( $map ) ) {
			return '';
		}

		$slug = isset( $map[ (int) $plugin_id ] ) ? (string) $map[ (int) $plugin_id ] : '';

		return sanitize_title( $slug );
	}

	/**
	 * Per-plugin slug editor for the Plugin Scope tab.
	 *
	 * @return string
	 */
	private function get_wporg_slugs_panel_html(): string {
		$catalog = $this->get_option( 'rl_fsbi_plugins_catalog', array() );
		$catalog = is_array( $catalog ) ? $catalog : array();

		$message = isset( $_GET['rl_fsbi_wporg_saved'] ) ? sanitize_text_field( wp_unslash( $_GET['rl_fsbi_wporg_saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fully_saved = isset( $_GET['rl_fsbi_wporg_ok'] ) && '1' === $_GET['rl_fsbi_wporg_ok']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		?>
		<div class="rl-fsbi-wporg-slugs">
			<style>
				.rl-fsbi-wporg-slugs{max-width:820px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:14px 18px}
				.rl-fsbi-wporg-slugs table{width:100%;border-collapse:collapse;font-size:13px}
				.rl-fsbi-wporg-slugs th,.rl-fsbi-wporg-slugs td{text-align:left;padding:8px 6px;border-bottom:1px solid #f0f0f1;vertical-align:middle}
				.rl-fsbi-wporg-slugs th{font-weight:600;width:44%}
				.rl-fsbi-wporg-slugs input[type=text]{width:100%;max-width:280px}
				.rl-fsbi-wporg-slugs .rl-fsbi-wporg-note{color:#646970;font-size:12px;display:block;margin-top:3px}
			</style>

			<?php if ( isset( $_GET['rl_fsbi_wporg_checked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-info inline" style="margin:0 0 10px;"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['rl_fsbi_wporg_checked'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<?php if ( '1' === $message ) : ?>
				<div class="notice notice-success inline" style="margin:0 0 10px;"><p><?php esc_html_e( 'WordPress.org slugs saved.', 'rl-freemius-bi' ); ?></p></div>
			<?php elseif ( '' !== $message ) : ?>
				<div class="notice <?php echo $fully_saved ? 'notice-success' : 'notice-warning'; ?> inline" style="margin:0 0 10px;"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rl_fsbi_save_wporg_slugs" />
				<?php wp_nonce_field( 'rl_fsbi_save_wporg_slugs' ); ?>

				<table>
					<tbody>
					<?php foreach ( $catalog as $plugin ) :
						$plugin_id    = isset( $plugin['id'] ) ? (int) $plugin['id'] : 0;
						$title        = isset( $plugin['title'] ) ? (string) $plugin['title'] : 'Plugin #' . $plugin_id;
						$freemius     = isset( $plugin['slug'] ) ? sanitize_title( (string) $plugin['slug'] ) : '';
						$stored       = $this->get_wporg_slug( $plugin_id );
						?>
						<tr>
							<th scope="row">
								<?php echo esc_html( $title ); ?>
								<?php if ( '' !== $freemius ) : ?>
									<span class="rl-fsbi-wporg-note"><?php echo esc_html( sprintf( /* translators: %s: Freemius product slug */ __( 'Freemius slug: %s', 'rl-freemius-bi' ), $freemius ) ); ?></span>
								<?php endif; ?>
							</th>
							<td>
								<input type="text" name="wporg[<?php echo esc_attr( (string) $plugin_id ); ?>]"
									value="<?php echo esc_attr( $stored ); ?>"
									placeholder="<?php echo esc_attr__( 'e.g. my-plugin-slug', 'rl-freemius-bi' ); ?>"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: plugin title */ __( 'WordPress.org slug for %s', 'rl-freemius-bi' ), $title ) ); ?>" />
								<?php if ( '' !== $stored ) : ?>
									<span class="rl-fsbi-wporg-note"><?php echo esc_html( sprintf( /* translators: %s: stored slug */ __( 'Saved: %s', 'rl-freemius-bi' ), $stored ) ); ?><?php
									$last_check = get_transient( 'rl_fsbi_wporg_' . md5( $stored ) );
									if ( is_array( $last_check ) ) {
										echo ' — ';
										if ( ! empty( $last_check['available'] ) ) {
											echo esc_html( sprintf(
												/* translators: 1: active install count, 2: last check time */
												__( 'last check found %1$s active installs (%2$s)', 'rl-freemius-bi' ),
												number_format_i18n( (int) ( $last_check['active_installs'] ?? 0 ) ),
												(string) ( $last_check['fetched_at'] ?? '' )
											) );
										} else {
											echo esc_html( sprintf(
												/* translators: %s: reason the lookup failed */
												__( 'last check failed: %s', 'rl-freemius-bi' ),
												(string) ( $last_check['detail'] ?? $last_check['reason'] ?? '' )
											) );
										}
									} else {
										echo ' — ' . esc_html__( 'not checked yet', 'rl-freemius-bi' );
									}
									?>
									<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'rl_fsbi_check_wporg', 'plugin_id' => $plugin_id ), admin_url( 'admin-post.php' ) ), 'rl_fsbi_check_wporg' ) ); ?>"><?php esc_html_e( 'Check now', 'rl-freemius-bi' ); ?></a>
									</span>
								<?php else : ?>
									<span class="rl-fsbi-wporg-note"><?php esc_html_e( 'Nothing saved - the WordPress.org widget is not shown for this product. Leave empty if it is not on WordPress.org.', 'rl-freemius-bi' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<p class="submit" style="margin:12px 0 0;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Slugs', 'rl-freemius-bi' ); ?></button>
				</p>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Support-tab documentation for GET /wp-json/rl-freemius-bi/v1/summary.
	 *
	 * @return string
	 */
	private function get_rest_summary_docs_html(): string {
		$endpoint = rest_url( 'rl-freemius-bi/v1/summary' );
		$fields   = array(
			esc_html__( 'generated_at', 'rl-freemius-bi' )        => esc_html__( 'When the figures were assembled, ISO 8601 UTC.', 'rl-freemius-bi' ),
			esc_html__( 'plugin_id', 'rl-freemius-bi' )           => esc_html__( 'Scope the figures cover. 0 means every tracked plugin.', 'rl-freemius-bi' ),
			esc_html__( 'currency', 'rl-freemius-bi' )            => esc_html__( 'Report currency of the money figures. Default EUR.', 'rl-freemius-bi' ),
			esc_html__( 'period', 'rl-freemius-bi' )              => esc_html__( 'start, end and months the request resolved to.', 'rl-freemius-bi' ),
			esc_html__( 'new_subscriptions', 'rl-freemius-bi' )   => esc_html__( 'Per month: new (first payment from a new customer), reactivation (first payment from someone whose earlier subscription had ended) and total.', 'rl-freemius-bi' ),
			esc_html__( 'renewals', 'rl-freemius-bi' )            => esc_html__( 'Renewal payments per month.', 'rl-freemius-bi' ),
			esc_html__( 'cancellations', 'rl-freemius-bi' )       => esc_html__( 'Cancellations per month, measured against the base that existed at the start of that month.', 'rl-freemius-bi' ),
			esc_html__( 'refunds', 'rl-freemius-bi' )             => esc_html__( 'Refund amounts and counts per month.', 'rl-freemius-bi' ),
			esc_html__( 'mrr', 'rl-freemius-bi' )                 => esc_html__( 'Monthly recurring revenue at the end of the period, converted to the report currency.', 'rl-freemius-bi' ),
			esc_html__( 'net_revenue_by_month', 'rl-freemius-bi' ) => esc_html__( 'Net revenue per month, converted at each transaction day\'s rate.', 'rl-freemius-bi' ),
			esc_html__( 'gross_revenue_by_month', 'rl-freemius-bi' ) => esc_html__( 'Gross revenue per month, same conversion rule.', 'rl-freemius-bi' ),
			esc_html__( 'coupons', 'rl-freemius-bi' )             => esc_html__( 'Per coupon: code, discount, status (active/expired/exhausted/inactive), uses in the period, limit, remaining, expiry, revenue and the discount given.', 'rl-freemius-bi' ),
		);

		$params = array(
			esc_html__( 'plugin_id', 'rl-freemius-bi' ) => esc_html__( 'Optional. Tracked plugin id, or omit for all.', 'rl-freemius-bi' ),
			esc_html__( 'months', 'rl-freemius-bi' )    => esc_html__( 'Optional. Trailing months, clamped to 1–36. Default 12.', 'rl-freemius-bi' ),
			esc_html__( 'currency', 'rl-freemius-bi' )  => esc_html__( 'Optional. Three-letter code, falls back to EUR.', 'rl-freemius-bi' ),
		);

		ob_start();
		?>
		<div class="rl-fsbi-rest-docs">
			<style>
				.rl-fsbi-rest-docs{max-width:860px}
				.rl-fsbi-rest-docs code{background:#f0f0f1;padding:2px 6px;border-radius:3px}
				.rl-fsbi-rest-docs pre{background:#1d2327;color:#e7ebff;padding:12px 14px;border-radius:6px;overflow:auto;font-size:12px;line-height:1.5}
				.rl-fsbi-rest-docs table{width:100%;border-collapse:collapse;margin:10px 0 16px;font-size:13px}
				.rl-fsbi-rest-docs th,.rl-fsbi-rest-docs td{text-align:left;padding:7px 10px;border-bottom:1px solid #f0f0f1;vertical-align:top}
				.rl-fsbi-rest-docs th{width:190px;font-weight:600}
				.rl-fsbi-rest-docs h4{margin:16px 0 6px;font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:#50575e}
			</style>

			<p>
				<?php echo esc_html__( 'Endpoint:', 'rl-freemius-bi' ); ?>
				<code><?php echo esc_html( $endpoint ); ?></code>
			</p>

			<h4><?php echo esc_html__( 'Authentication', 'rl-freemius-bi' ); ?></h4>
			<p>
				<?php echo esc_html__( 'Requires a user who can manage options. A browser session works, and so does a WordPress application password — create one under Users → Profile → Application Passwords, then call the endpoint from a script. Anonymous requests are refused with 401.', 'rl-freemius-bi' ); ?>
			</p>
			<pre>curl -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
  <?php echo esc_html( $endpoint ); ?>?months=12</pre>

			<h4><?php echo esc_html__( 'Query parameters', 'rl-freemius-bi' ); ?></h4>
			<table>
				<?php foreach ( $params as $name => $desc ) : ?>
					<tr><th><code><?php echo esc_html( $name ); ?></code></th><td><?php echo esc_html( $desc ); ?></td></tr>
				<?php endforeach; ?>
			</table>

			<h4><?php echo esc_html__( 'Response fields', 'rl-freemius-bi' ); ?></h4>
			<table>
				<?php foreach ( $fields as $name => $desc ) : ?>
					<tr><th><code><?php echo esc_html( $name ); ?></code></th><td><?php echo esc_html( $desc ); ?></td></tr>
				<?php endforeach; ?>
			</table>

			<p class="description">
				<?php echo esc_html__( 'Money figures are converted at the exchange rate of the day each transaction happened, so totals can differ by a few cents from a sum taken elsewhere. Responses are sent with Cache-Control: private, max-age=300.', 'rl-freemius-bi' ); ?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function get_kit_quota_panel_html(): string {
		$limit = trim( (string) $this->get_option( 'rl_fsbi_kit_subscriber_limit', '' ) );
		$limit = '' === $limit ? 0 : max( 0, (int) $limit );

		$limit_label = $limit > 0
			? number_format_i18n( $limit )
			: esc_html__( 'Unlimited', 'rl-freemius-bi' );

		$scope = trim( (string) $this->get_option( 'rl_fsbi_kit_prune_scope', 'all' ) );
		$scope = 'tagged' === $scope ? 'tagged' : 'all';

		$scope_label = 'tagged' === $scope
			? esc_html__( 'Only subscribers tagged by this plugin', 'rl-freemius-bi' )
			: esc_html__( 'Any active subscriber (oldest first)', 'rl-freemius-bi' );

		$warning_text = 'tagged' === $scope
			? esc_html__( "Kit treats it the same as the person clicking unsubscribe: they stop receiving all email and must explicitly opt in again. Only subscribers carrying this plugin's Kit tags are eligible.", 'rl-freemius-bi' )
			: esc_html__( 'Kit treats it the same as the person clicking unsubscribe: they stop receiving all email and must explicitly opt in again. Any active subscriber is eligible, oldest first.', 'rl-freemius-bi' );

		$prune_enabled = (bool) $this->get_option( 'rl_fsbi_kit_prune_enabled', false );
		$settings_url  = admin_url( 'admin.php?page=' . $this->framework->get_config( 'page_slug' ) . '&tab=newsletter_settings#kit_config' );

		ob_start();
		?>
		<div class="rl-fsbi-kit-quota" data-limit="<?php echo esc_attr( (string) $limit ); ?>" data-prune-enabled="<?php echo $prune_enabled ? '1' : '0'; ?>">
			<style>
				.rl-fsbi-kit-quota{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 18px;max-width:860px;margin:4px 0 8px}
				.rl-fsbi-kit-quota-title{margin:0 0 6px;font-size:14px;line-height:1.4}
				.rl-fsbi-kit-quota-grid{display:grid;grid-template-columns:1fr auto;margin:12px 0;border-top:1px solid #f0f0f1}
				.rl-fsbi-kit-quota-row{display:contents}
				.rl-fsbi-kit-quota-row>span,.rl-fsbi-kit-quota-row>strong{padding:9px 2px;border-bottom:1px solid #f0f0f1;font-size:13px}
				.rl-fsbi-kit-quota-row>span{color:#50575e}
				.rl-fsbi-kit-quota-row>strong{text-align:right;color:#1d2327;font-variant-numeric:tabular-nums}
				.rl-fsbi-kit-quota-actions{display:flex;align-items:center;gap:10px;margin:14px 0 4px}
				.rl-fsbi-kit-quota-locked{font-size:12px;color:#646970;font-style:normal}
				.rl-fsbi-kit-quota-locked a{margin-left:6px}
				.rl-fsbi-kit-quota-actions [hidden]{display:none!important}
				.rl-fsbi-kit-quota-actions .spinner{margin:0;float:none}
				.rl-fsbi-kit-quota-progress{margin:14px 0 4px}
				.rl-fsbi-kit-quota-progress-track{height:10px;background:#f0f0f1;border-radius:999px;overflow:hidden;box-shadow:inset 0 1px 2px rgba(0,0,0,.07)}
				.rl-fsbi-kit-quota-progress-fill{display:block;height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,#2271b1,#72aee6);transition:width .35s ease}
				.rl-fsbi-kit-quota-progress-text{margin-top:6px;font-size:12px;color:#50575e}
				.rl-fsbi-kit-quota-warning{margin:14px 0 0;padding:10px 12px;border-left:4px solid #d63638;background:#fcf0f1;font-size:12px;line-height:1.5;color:#1d2327}
				.rl-fsbi-kit-quota-output{margin:10px 0 0;font-size:13px;min-height:1px}
				.rl-fsbi-kit-quota-list{margin:10px 0 0;padding-left:18px;max-height:260px;overflow:auto;font-size:12px;color:#50575e}
				.rl-fsbi-kit-quota-list li{padding:2px 0}
			</style>

			<h3 class="rl-fsbi-kit-quota-title"><?php echo esc_html__( 'Kit subscriber quota', 'rl-freemius-bi' ); ?></h3>
			<p class="description" style="margin:0">
				<?php echo esc_html__( 'Kit plans are billed on active subscribers. Preview how far you are over the limit before removing anyone.', 'rl-freemius-bi' ); ?>
			</p>

			<div class="rl-fsbi-kit-quota-grid">
				<div class="rl-fsbi-kit-quota-row">
					<span><?php echo esc_html__( 'Configured limit', 'rl-freemius-bi' ); ?></span>
					<strong id="rl-fsbi-kit-quota-limit"><?php echo esc_html( (string) $limit_label ); ?></strong>
				</div>
				<div class="rl-fsbi-kit-quota-row">
					<span><?php echo esc_html__( 'Active subscribers in Kit', 'rl-freemius-bi' ); ?></span>
					<strong id="rl-fsbi-kit-quota-total">&mdash;</strong>
				</div>
				<div class="rl-fsbi-kit-quota-row">
					<span><?php echo esc_html__( 'Over the limit by', 'rl-freemius-bi' ); ?></span>
					<strong id="rl-fsbi-kit-quota-excess">&mdash;</strong>
				</div>
				<div class="rl-fsbi-kit-quota-row">
					<span><?php echo esc_html__( 'Prune scope', 'rl-freemius-bi' ); ?></span>
					<strong id="rl-fsbi-kit-quota-scope"><?php echo esc_html( (string) $scope_label ); ?></strong>
				</div>
				<div class="rl-fsbi-kit-quota-row">
					<span><?php echo esc_html__( 'Ready to remove (next batch)', 'rl-freemius-bi' ); ?></span>
					<strong id="rl-fsbi-kit-quota-candidates">&mdash;</strong>
				</div>
			</div>

			<div class="rl-fsbi-kit-quota-actions">
				<button type="button" class="button" id="rl-fsbi-kit-quota-preview">
					<?php echo esc_html__( 'Refresh from Kit', 'rl-freemius-bi' ); ?>
				</button>
				<?php if ( $prune_enabled ) : ?>
				<button type="button" class="button" id="rl-fsbi-kit-quota-prune" disabled="disabled">
					<?php echo esc_html__( 'Unsubscribe oldest batch', 'rl-freemius-bi' ); ?>
				</button>
				<button type="button" class="button" id="rl-fsbi-kit-quota-drain" disabled="disabled">
					<?php echo esc_html__( 'Remove all over the limit', 'rl-freemius-bi' ); ?>
				</button>
				<?php else : ?>
				<em class="rl-fsbi-kit-quota-locked">
					<?php esc_html_e( 'Removal is switched off. Nothing here deletes a contact until you turn it on.', 'rl-freemius-bi' ); ?>
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Enable in Settings', 'rl-freemius-bi' ); ?></a>
				</em>
				<?php endif; ?>
				<button type="button" class="button" id="rl-fsbi-kit-quota-stop" hidden>
					<?php echo esc_html__( 'Stop', 'rl-freemius-bi' ); ?>
				</button>
				<span class="spinner rl-fsbi-kit-quota-spinner"></span>
			</div>

			<div class="rl-fsbi-kit-quota-progress" id="rl-fsbi-kit-quota-progress" hidden>
				<div class="rl-fsbi-kit-quota-progress-track">
					<span class="rl-fsbi-kit-quota-progress-fill" id="rl-fsbi-kit-quota-progress-fill"></span>
				</div>
				<div class="rl-fsbi-kit-quota-progress-text" id="rl-fsbi-kit-quota-progress-text" aria-live="polite"></div>
			</div>

			<div class="rl-fsbi-kit-quota-warning" id="rl-fsbi-kit-quota-warning" role="alert" hidden>
				<strong><?php echo esc_html__( 'Unsubscribing is permanent.', 'rl-freemius-bi' ); ?></strong>
				<?php echo esc_html( (string) $warning_text ); ?>
			</div>

			<div class="rl-fsbi-kit-quota-output" id="rl-fsbi-kit-quota-output" role="status" aria-live="polite"></div>
			<ol class="rl-fsbi-kit-quota-list" id="rl-fsbi-kit-quota-list"></ol>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Sanitize developer ID.

	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_developer_id( $value ): string {
		$raw = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $raw ) {
			delete_option( 'rl_fsbi_developer_id_invalid' );

			return '';
		}

		// Never coerce. Stripping the non-digits turned an autofilled address such
		// as john1985@gmail.com into "1985", which saved cleanly and then failed
		// every API call against a stranger's developer id.
		if ( ! ctype_digit( $raw ) ) {
			update_option( 'rl_fsbi_developer_id_invalid', 1, false );

			return (string) $this->get_option( 'rl_fsbi_developer_id', '' );
		}

		delete_option( 'rl_fsbi_developer_id_invalid' );

		return $raw;
	}

	/**
	 * Fetch Mailchimp audiences (lists) using the configured Mailchimp API key.
	 *
	 * @return array<string, string> List options as [id => name].
	 */
	public function get_mailchimp_lists(): array {
		$api_key = trim( (string) $this->get_option( 'rl_fsbi_mailchimp_api_key', '' ) );
		if ( empty( $api_key ) ) {
			return array();
		}

		$mc_service = new RL_FSBI_Mailchimp( $api_key );
		$raw_lists  = $mc_service->get_lists();

		$lists = array();
		foreach ( $raw_lists as $id => $name ) {
			$lists[ $id ] = $name . ' (' . $id . ')';
		}

		return $lists;
	}

	/**
	 * Fetch Kit tags using the configured Kit API credentials.
	 *
	 * @return array<int, string> Tag options as [id => name].
	 */
	public function get_kit_tags(): array {
		$api_key    = trim( (string) $this->get_option( 'rl_fsbi_kit_api_key', '' ) );
		$api_secret = trim( (string) $this->get_option( 'rl_fsbi_kit_api_secret', '' ) );
		if ( empty( $api_key ) ) {
			return array();
		}

		$kit_service = new RL_FSBI_Kit( $api_key, $api_secret );
		return $kit_service->get_tags();
	}

	/**
	 * Build HTML for Kit.com Sample Run testing panel.
	 *
	 * @return string
	 */
	private function get_kit_sample_run_html(): string {
		$catalog = $this->get_option( 'rl_fsbi_plugins_catalog', array() );
		$plugin_options = '<option value="all">' . esc_html__( 'All Tracked Plugins', 'rl-freemius-bi' ) . '</option>';
		if ( is_array( $catalog ) ) {
			foreach ( $catalog as $pid => $info ) {
				$title = ! empty( $info['title'] ) ? $info['title'] : 'Plugin #' . $pid;
				$plugin_options .= '<option value="' . esc_attr( $pid ) . '">' . esc_html( $title ) . ' (#' . esc_html( $pid ) . ')</option>';
			}
		}

		ob_start();
		?>
		<div class="rl-fsbi-sample-run-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); max-width:850px;">
			<h4 style="margin:0 0 10px; font-size:15px; color:#1e293b; font-weight:600;">
				<?php echo esc_html__( 'Kit.com Sample Synchronization Test (Deep Debugging)', 'rl-freemius-bi' ); ?>
			</h4>
			<p style="margin:0 0 16px; color:#64748b; font-size:13px; line-height:1.5;">
				<?php echo esc_html__( 'Fetch a small sample of users directly from Freemius, inspect marketing permission and plan detection, resolve assigned Kit tags, synchronize website origin to the "website" custom field, and monitor every step in real-time.', 'rl-freemius-bi' ); ?>
			</p>

			<div style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; margin-bottom:16px; background:#f8fafc; padding:14px; border-radius:6px; border:1px solid #e2e8f0;">
				<div>
					<label for="rl-fsbi-kit-sample-user-count" style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:4px;">
						<?php echo esc_html__( 'Sample Size (Users)', 'rl-freemius-bi' ); ?>
					</label>
					<input type="number" id="rl-fsbi-kit-sample-user-count" value="5" min="1" max="50" style="width:90px;" class="small-text">
				</div>

				<div>
					<label for="rl-fsbi-kit-sample-plugin-select" style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:4px;">
						<?php echo esc_html__( 'Select Plugin Scope', 'rl-freemius-bi' ); ?>
					</label>
					<select id="rl-fsbi-kit-sample-plugin-select" style="min-width:200px;">
						<?php echo $plugin_options; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</select>
				</div>

				<div style="padding-bottom:6px;">
					<label style="font-size:13px; color:#334155; cursor:pointer;">
						<input type="checkbox" id="rl-fsbi-kit-sample-dry-run" value="1" checked="checked" style="margin-top:0;">
						<strong><?php echo esc_html__( 'Dry Run Mode', 'rl-freemius-bi' ); ?></strong>
						<span style="color:#64748b; font-size:12px;">(<?php echo esc_html__( 'tests plan lookup and tags without modifying Kit', 'rl-freemius-bi' ); ?>)</span>
					</label>
				</div>

				<div style="margin-left:auto;">
					<button type="button" class="button button-primary" id="rl-fsbi-run-kit-sample-test-btn" style="display:inline-flex; align-items:center; gap:6px; font-weight:600;">
						<span class="dashicons dashicons-controls-play" style="font-size:16px; width:16px; height:16px; line-height:16px;"></span>
						<span><?php echo esc_html__( 'Run Kit.com Sample Test', 'rl-freemius-bi' ); ?></span>
					</button>
					<button type="button" class="button" id="rl-fsbi-clear-kit-sample-log-btn" style="display:none; margin-left:6px;">
						<?php echo esc_html__( 'Clear Log', 'rl-freemius-bi' ); ?>
					</button>
				</div>
			</div>

			<div id="rl-fsbi-kit-sample-log-wrap" style="display:none;">
				<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
					<span style="font-size:12px; font-weight:600; color:#475569;">
						<?php echo esc_html__( 'Kit.com Diagnostic Execution Log (rlLOG)', 'rl-freemius-bi' ); ?>:
					</span>
					<span id="rl-fsbi-kit-sample-summary-badge" style="font-size:11px; padding:2px 8px; border-radius:10px; background:#e2e8f0; color:#334155; font-weight:600;"></span>
				</div>
				<div id="rl-fsbi-kit-sample-log-console" style="background:#0f172a; color:#f8fafc; font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:12px; padding:16px; border-radius:8px; max-height:360px; overflow-y:auto; line-height:1.6; border:1px solid #334155; box-shadow:inset 0 2px 4px rgba(0,0,0,0.4);"></div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Get all available Freemius plans.
	 *
	 * Checks the local database first; if empty, fetches from Freemius API.
	 *
	 * @return array<int, array{plan_id: int, plugin_id: int, title: string}>
	 */
	public function get_available_plans(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rl_fsbi_plans';
		$plans = array();

		$catalog = $this->get_option( 'rl_fsbi_plugins_catalog', array() );

		// Check local database table
		$results = $wpdb->get_results( "SELECT plan_id, plugin_id, plan_name FROM {$table} ORDER BY plugin_id ASC, plan_name ASC" );
		if ( ! empty( $results ) && is_array( $results ) ) {
			foreach ( $results as $row ) {
				$plugin_prefix = '';
				if ( is_array( $catalog ) && isset( $catalog[ (string) $row->plugin_id ]['title'] ) ) {
					$plugin_prefix = $catalog[ (string) $row->plugin_id ]['title'] . ': ';
				}
				$plans[ (int) $row->plan_id ] = array(
					'plan_id'   => (int) $row->plan_id,
					'plugin_id' => (int) $row->plugin_id,
					'title'     => $plugin_prefix . ( ! empty( $row->plan_name ) ? $row->plan_name : 'Plan #' . $row->plan_id ),
				);
			}
		}

		// If DB table has no plans yet but API credentials are valid, query Freemius API
		if ( empty( $plans ) && $this->is_api_connected() && is_array( $catalog ) ) {
			$dev_id  = $this->get_option( 'rl_fsbi_developer_id' );
			$pub_key = $this->get_option( 'rl_fsbi_public_key' );
			$sec_key = $this->get_option( 'rl_fsbi_secret_key' );

			if ( $dev_id && $pub_key && $sec_key ) {
				$api = new RL_FSBI_API( $dev_id, $pub_key, $sec_key );
				foreach ( array_keys( $catalog ) as $pid ) {
					$p_plans = $api->retrieve_plans( (int) $pid );
					if ( ! empty( $p_plans ) && is_array( $p_plans ) ) {
						$p_title = $catalog[ (string) $pid ]['title'] ?? ( 'Plugin #' . $pid );
						foreach ( $p_plans as $pl ) {
							$pl_id    = (int) ( is_array( $pl ) ? ( $pl['id'] ?? 0 ) : ( $pl->id ?? 0 ) );
							$pl_title = is_array( $pl ) ? ( $pl['title'] ?? '' ) : ( $pl->title ?? '' );
							if ( $pl_id > 0 ) {
								$plans[ $pl_id ] = array(
									'plan_id'   => $pl_id,
									'plugin_id' => (int) $pid,
									'title'     => $p_title . ': ' . ( ! empty( $pl_title ) ? $pl_title : 'Plan #' . $pl_id ),
								);
							}
						}
					}
				}
			}
		}

		return $plans;
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
	/**
	 * Whether verbose debug logging is switched on.
	 *
	 * Driven by the Support tab toggle; the reading is a plain option lookup so
	 * the banner does not have to boot the framework.
	 *
	 * @return bool
	 */
	public function is_debug_mode(): bool {
		if ( ! $this->framework ) {
			return false;
		}

		return (bool) $this->framework->get_option( 'enable_debug', false );
	}

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
			$this->framework->register_field_renderer( new RL_FSBI_Field_Password() );
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
