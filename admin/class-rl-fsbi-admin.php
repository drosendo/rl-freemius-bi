<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/admin
 */

class RL_FSBI_Admin
{

	/**
	 * Cached Kit quota position so the admin notice never blocks on the API.
	 */
	private const KIT_QUOTA_TRANSIENT = 'rl_fsbi_kit_quota_snapshot';

	/**
	 * Short lock preventing concurrent admin loads from all querying Kit.
	 */
	private const KIT_QUOTA_LOCK = 'rl_fsbi_kit_quota_lock';

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
	 * Locale currency format (e.g., en-US, pt-PT, de-DE)
	 *
	 * @access   private
	 * @var      string $locale_format
	 */
	private $locale_format;

	/**
	 * Transferwise API token
	 *
	 * @access   private
	 * @var      string $transferwise_token
	 */
	private $transferwise_token;

	/**
	 * FreeCurrencyAPI Key
	 *
	 * @access   private
	 * @var      string $freecurrencyapi_key
	 */
	private $freecurrencyapi_key;

	/**
	 * Conversion rate provider ('freecurrencyapi', 'wise', 'none')
	 *
	 * @access   private
	 * @var      string $conversion_provider
	 */
	private $conversion_provider;

	/**
	 * Conversion base currency (all Freemius supported currencies)
	 *
	 * @access   private
	 * @var      string $conversion_currency
	 */
	private $conversion_currency;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version The version of this plugin.
	 */
	public function __construct($plugin_name, $version)
	{
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->settings    = new RL_FSBI_Settings_Manager();

		// Load display & conversion settings
		$this->locale_format       = trim((string) $this->settings->get_option('rl_fsbi_locale_format')) ?: 'en-US';
		$this->conversion_provider = trim((string) $this->settings->get_option('rl_fsbi_conversion_provider')) ?: 'freecurrencyapi';
		$this->freecurrencyapi_key = trim((string) $this->settings->get_option('rl_fsbi_freecurrencyapi_key'));
		$this->transferwise_token  = trim((string) $this->settings->get_option('rl_fsbi_transferwise_token'));

		$allowed_currencies = array('USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'PLN', 'ILS', 'RSD');
		$conversion_currency = strtoupper(trim((string) $this->settings->get_option('rl_fsbi_conversion_currency')));
		$this->conversion_currency = in_array($conversion_currency, $allowed_currencies, true) ? $conversion_currency : 'USD';
	}

	/**
	 * Serve the tooltip assets from this plugin, whatever else claimed the handle.
	 *
	 * `tippy-css`, `popper-js` and `tippy-js` are generic handle names, and
	 * whichever plugin registers them first wins — on a site running another RL
	 * plugin that was a different plugin's directory, which 404s when that
	 * plugin is absent. This runs last on our own screens, replaces the
	 * registration and enqueues it again so the request cannot point anywhere
	 * but here. Deregistering drops the handle from the queue, hence the
	 * explicit re-enqueue.
	 *
	 * @return void
	 */
	public function enqueue_vendor_assets()
	{
		if (! $this->is_plugin_page()) {
			return;
		}

		// The settings framework registers its stylesheet under the name it also
		// uses for the script, so that handle is dropped and the stylesheet is
		// served once, from here.
		wp_deregister_style('tippy-js');
		wp_deregister_style('tippy-css');

		$css = $this->locate_vendor_asset('tippy/tippy.css');
		if ('' !== $css['url']) {
			wp_register_style('tippy-css', $css['url'], array(), $css['version']);
			wp_enqueue_style('tippy-css');
		}

		$popper = $this->locate_vendor_asset('popper/popper.min.js');
		$popper_registered = false;
		if ('' !== $popper['url']) {
			wp_deregister_script('popper-js');
			wp_register_script('popper-js', $popper['url'], array(), $popper['version'], true);
			wp_enqueue_script('popper-js');
			$popper_registered = true;
		}

		$tippy = $this->locate_vendor_asset('tippy/tippy.umd.min.js');
		if ('' !== $tippy['url']) {
			wp_deregister_script('tippy-js');
			wp_register_script('tippy-js', $tippy['url'], $popper_registered ? array('popper-js') : array(), $tippy['version'], true);
			wp_enqueue_script('tippy-js');
		}
	}

	/**
	 * Register the stylesheets for the admin area.
	 */
	public function enqueue_styles()
	{
		if (! $this->is_plugin_page()) {
			return;
		}

		$css_path = RL_FSBI_PLUGIN_DIR . 'assets/css/admin/rl-fsbi-admin.css';
		$ver      = $this->asset_version('assets/css/admin/rl-fsbi-admin.css');

		wp_enqueue_style(
			$this->plugin_name . '-admin',
			RL_FSBI_PLUGIN_URL . 'assets/css/admin/rl-fsbi-admin.css',
			array(),
			$ver,
			'all'
		);
	}

	/**
	 * Cache-busting stamp for a plugin asset.
	 *
	 * The host serves these files with a 30-day max-age, and an FTP upload does
	 * not always change the file's modification time, so filemtime() alone can
	 * hand the browser a URL it has already cached. The size is part of the stamp
	 * because content that changed always changes one of the two.
	 *
	 * @param string $relative Path under the plugin directory.
	 * @return string
	 */
	private function asset_version($relative)
	{
		$path = RL_FSBI_PLUGIN_DIR . ltrim($relative, '/');

		if (! file_exists($path)) {
			return (string) $this->version;
		}

		return filemtime($path) . '.' . filesize($path);
	}

	/**
	 * Version stamp for a file bundled under assets/lib/.
	 *
	 * @param string $relative Path under assets/lib/.
	 * @return string
	 */
	private function lib_version($relative)
	{
		$path = RL_FSBI_PLUGIN_DIR . 'assets/lib/' . ltrim($relative, '/');

		return $this->asset_version('assets/lib/' . $relative);
	}

	/**
	 * Locate a bundled vendor file, preferring the plugin's own copy.
	 *
	 * A deploy that skips `assets/lib/` leaves the plugin looking current while
	 * its libraries 404, which is what happened on a live site. Falling back to the copy that has shipped inside the settings
	 * framework since the first release means a partial upload degrades to the
	 * older file instead of breaking tooltips with two 404s.
	 *
	 * @param string $relative Path under the vendor directory, e.g. tippy/tippy.css.
	 * @return array{url:string,version:string} Empty url when nothing is found.
	 */
	private function locate_vendor_asset($relative)
	{
		$relative = ltrim($relative, '/');
		// assets/lib, never "vendor": deployment tooling routinely ignores any
		// directory called vendor, which is how these files failed to reach a
		// live site while being present in the repository and the release zip.
		$candidates = array(
			RL_FSBI_PLUGIN_DIR . 'assets/lib/' . $relative => RL_FSBI_PLUGIN_URL . 'assets/lib/' . $relative,
			RL_FSBI_PLUGIN_DIR . 'includes/library/rloptionsFramework/assets/vendor/' . $relative => RL_FSBI_PLUGIN_URL . 'includes/library/rloptionsFramework/assets/vendor/' . $relative,
		);

		foreach ($candidates as $path => $url) {
			if (file_exists($path)) {
				return array('url' => $url, 'version' => (string) filemtime($path));
			}
		}

		return array('url' => '', 'version' => $this->version);
	}

	/**
	 * Register the JavaScript for the admin area.
	 */
	public function enqueue_scripts()
	{
		$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

		if ('rl-freemius-bi-settings' === $page) {
			wp_register_script('rl-freemius-bi-settings-rl-logger', false);

			$settings_js_path = RL_FSBI_PLUGIN_DIR . 'assets/js/admin/rl-fsbi-settings.js';
			$settings_js_ver  = $this->asset_version('assets/js/admin/rl-fsbi-settings.js');

			wp_enqueue_script(
				$this->plugin_name . '-settings',
				RL_FSBI_PLUGIN_URL . 'assets/js/admin/rl-fsbi-settings.js',
				array('jquery'),
				$settings_js_ver,
				true
			);

			wp_localize_script(
				$this->plugin_name . '-settings',
				'rlFsbiSettingsData',
				array(
					'ajaxUrl'      => admin_url('admin-ajax.php'),
					'nonce'        => wp_create_nonce('rl_fsbi_nonce'),
					'descriptions' => $this->settings ? $this->settings->get_field_descriptions() : array(),
					'kitQuotaEnabled' => '' !== trim((string) $this->settings->get_option('rl_fsbi_kit_api_key', '')),
				)
			);
			return;
		}

		if (! $this->is_plugin_page()) {
			return;
		}

		// Bundled, not pulled from a CDN. These were enqueued from jsDelivr in the
		// <head>, render-blocking, so a CDN that stalled or was unreachable left
		// the dashboard at "Loading analytics… 0%" with no script ever executing.
		wp_enqueue_script('chart-js', RL_FSBI_PLUGIN_URL . 'assets/lib/chartjs/chart.min.js', array(), $this->lib_version('chartjs/chart.min.js'), false);
		wp_enqueue_script('datatables-js', RL_FSBI_PLUGIN_URL . 'assets/lib/datatables/jquery.dataTables.min.js', array('jquery'), $this->lib_version('datatables/jquery.dataTables.min.js'), false);
		wp_enqueue_style('datatables-css', RL_FSBI_PLUGIN_URL . 'assets/lib/datatables/jquery.dataTables.min.css', array(), $this->lib_version('datatables/jquery.dataTables.min.css'), 'all');

		$admin_js_path = RL_FSBI_PLUGIN_DIR . 'assets/js/admin/rl-fsbi-admin.js';
		$admin_js_ver  = $this->asset_version('assets/js/admin/rl-fsbi-admin.js');

		wp_enqueue_script(
			$this->plugin_name . '-admin',
			RL_FSBI_PLUGIN_URL . 'assets/js/admin/rl-fsbi-admin.js',
			array('jquery', 'chart-js', 'datatables-js', 'tippy-js'),
			$admin_js_ver,
			true
		);

		wp_localize_script($this->plugin_name . '-admin', 'rlFsbiAdmin', array(
			'ajaxUrl'         => admin_url('admin-ajax.php'),
			'nonce'           => wp_create_nonce('rl_fsbi_nonce'),
			'localeFormat'    => $this->locale_format,
			'defaultCurrency' => strtoupper((string) $this->settings->get_option('rl_fsbi_conversion_currency', $this->settings->get_option('rl_fsbi_default_currency', 'USD'))),
			'tableRows'       => 12,
			'forcedPluginId'     => $this->get_forced_plugin_id_from_request(),
			'pluginsCatalog'     => $this->get_plugins_catalog(),
			'newsletterProvider' => (string) $this->settings->get_option('rl_fsbi_newsletter_provider', 'mailchimp'),
			'lastNewsletterSync' => (string) get_option('rl_fsbi_newsletter_last_sync_utc', ''),
			'latestExport'       => $this->get_latest_export_for_ui(),
			'adminEmail'         => (string) get_option('admin_email', ''),
			'loadingStrings'     => array(
				'contacting' => __('Contacting Freemius…', 'rl-freemius-bi'),
				'building'   => __('Building your analytics…', 'rl-freemius-bi'),
				'rendering'  => __('Rendering charts…', 'rl-freemius-bi'),
				'done'       => __('Ready', 'rl-freemius-bi'),
			),
		));
	}

	/**
	 * Add the admin menu page.
	 */
	public function add_plugin_admin_menu()
	{
		add_menu_page(
			esc_html__('RL Freemius BI', 'rl-freemius-bi'),
			esc_html__('Freemius BI', 'rl-freemius-bi'),
			'manage_options',
			$this->plugin_name,
			array($this, 'display_admin_page'),
			'dashicons-chart-line',
			25
		);

		$selected_plugin_ids = $this->get_selected_plugin_ids();
		$catalog = $this->get_plugins_catalog();

		foreach ($selected_plugin_ids as $plugin_id) {
			$menu_slug = 'rl-freemius-bi-plugin-' . $plugin_id;
			$menu_title = isset($catalog[(string) $plugin_id]) ? $catalog[(string) $plugin_id] : 'Plugin #' . $plugin_id;

			add_submenu_page(
				$this->plugin_name,
				$menu_title,
				$menu_title,
				'manage_options',
				$menu_slug,
				function () use ($plugin_id) {
					$this->display_plugin_dashboard($plugin_id);
				}
			);
		}

		// Settings menu is auto-registered by the framework
	}

	/**
	 * Add the portfolio analytics widget to the main WordPress Dashboard.
	 */
	public function register_wordpress_dashboard_widget()
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		wp_add_dashboard_widget(
			'rl_fsbi_portfolio_widget',
			esc_html__('Portfolio Performance', 'rl-freemius-bi'),
			array($this, 'render_wordpress_dashboard_widget')
		);
	}

	/**
	 * Render live portfolio analytics for the WordPress Dashboard.
	 */
	public function render_wordpress_dashboard_widget()
	{
		$data = $this->get_wordpress_dashboard_portfolio_data();
		$currency = $data['currency'];
		$format = function ($value) use ($currency) {
			return esc_html(number_format_i18n((float) $value, 2)) . ' ' . esc_html($currency);
		};
		// Unescaped twin, for places that escape the whole string themselves
		// (attributes) or would otherwise double-encode the entities.
		$format_plain = function ($value) use ($currency) {
			return number_format_i18n((float) $value, 2) . ' ' . $currency;
		};
		// The months table prints bare numbers and names the currency once, so
		// six columns fit a WordPress dashboard column without wrapping.
		$format_num = function ($value) {
			return number_format_i18n((float) $value, 2);
		};
?>
		<style>
			#rl_fsbi_portfolio_widget .inside {
				background: #111521;
				padding: 14px 20px 20px 14px;
				color: #e7ebff
			}

			/* The widget lives in a WordPress dashboard column whose width does
			   not follow the viewport (it narrows again on very wide screens once
			   WordPress adds a column), so the grid is driven by the widget's own
			   width rather than by a media query. */
			#rl_fsbi_portfolio_widget .inside {
				container-type: inline-size
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-widget {
				display: grid;
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 10px;
				margin: 0 0 12px
			}

			@container (min-width: 520px) {
				#rl_fsbi_portfolio_widget .rl-fsbi-wp-widget {
					grid-template-columns: repeat(4, minmax(0, 1fr))
				}
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-metric {
				padding: 11px 12px;
				border: 1px solid #2b3150;
				border-radius: 8px;
				background: #1f253a;
				min-width: 0
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-metric span {
				display: block;
				color: #9aa4cc;
				font-size: 10px;
				text-transform: uppercase;
				letter-spacing: .06em;
				margin-bottom: 5px
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-metric strong {
				font-size: 18px;
				color: #ecf1ff;
				overflow-wrap: anywhere
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-metric .rl-fsbi-wp-scope {
				display: block;
				margin-top: 4px;
				color: #7d88b4;
				font-size: 9px;
				text-transform: none;
				letter-spacing: 0;
				cursor: help
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-newsubs {
				margin: 0 0 10px;
				padding: 8px 11px;
				border: 1px solid #2b3150;
				border-left: 3px solid #5f6bff;
				border-radius: 6px;
				background: #1a2033;
				color: #cdd6f5;
				font-size: 12px;
				line-height: 1.45;
				cursor: help
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-newsubs strong {
				color: #ecf1ff
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-newsubs-value {
				color: #8fd6ff;
				font-weight: 700
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-newsubs-rest {
				color: #9aa4cc
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-alert {
				display: flex;
				align-items: center;
				gap: 6px;
				margin: 0 0 10px;
				padding: 7px 11px;
				border: 1px solid #4b3a1d;
				border-left: 3px solid #f59e0b;
				border-radius: 6px;
				background: #2a2418;
				font-size: 12px
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-alert .dashicons {
				color: #f59e0b;
				font-size: 15px;
				width: 15px;
				height: 15px
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-alert a {
				color: #ffd79a;
				text-decoration: none
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-alert a:hover,
			#rl_fsbi_portfolio_widget .rl-fsbi-wp-alert a:focus {
				color: #ffe9c7;
				text-decoration: underline
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-payouts {
				margin: 12px 0 0;
				padding: 8px 11px;
				border: 1px solid #2b3150;
				border-radius: 6px;
				background: #1a2033;
				font-size: 12px
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-payout-line {
				margin: 0 0 4px;
				color: #cdd6f5
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-payout-line:last-child {
				margin-bottom: 0
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-payout-label {
				color: #9aa4cc
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-estimated {
				margin-left: 5px;
				padding: 1px 6px;
				border: 1px dashed #6b769e;
				border-radius: 999px;
				color: #9aa4cc;
				font-size: 9px;
				font-style: normal;
				text-transform: uppercase;
				letter-spacing: .05em;
				cursor: help
			}

			/* The current month is one bar: solid where the data is real, dashed
			   above it for the projection, so it cannot read as a separate month. */
			#rl_fsbi_portfolio_widget .rl-fsbi-wp-bar-projected {
				background: repeating-linear-gradient(135deg, rgba(125, 136, 180, 0.35) 0 4px, transparent 4px 9px);
				border: 1px dashed #7d88b4
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-bar-actual {
				position: absolute;
				left: 0;
				right: 0;
				bottom: 0;
				background: #5f6bff;
				border-radius: 0 0 4px 4px
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-bar-projected strong {
				color: #b9c2e4
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-bar-projected strong em {
				display: block;
				color: #9aa4cc;
				font-size: 8px;
				font-style: normal;
				font-weight: 400;
				text-transform: uppercase;
				letter-spacing: .04em
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-target {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				font-size: 11px;
				white-space: nowrap
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-target .dashicons {
				font-size: 14px;
				width: 14px;
				height: 14px
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-target.is-good {
				color: #4ade80
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-target.is-warn {
				color: #fbbf24
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-target-none {
				color: #7d88b4;
				font-size: 11px
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-bar-partial small {
				white-space: normal;
				width: 140%;
				line-height: 1.15;
				text-align: center;
				left: 50%;
				transform: translateX(-50%)
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-projection-note {
				color: #9aa4cc;
				font-size: 10px
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-breakdown {
				margin: 14px 0 0;
				border: 1px solid #2b3150;
				border-radius: 6px;
				background: #1a2033
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-breakdown summary {
				padding: 8px 11px;
				color: #cdd6f5;
				font-size: 12px;
				font-weight: 600;
				cursor: pointer
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-breakdown-scroll {
				overflow-x: auto
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-breakdown table.widefat {
				margin: 0;
				min-width: 480px;
				font-variant-numeric: tabular-nums
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-breakdown table.widefat td:not(:first-child) {
				white-space: nowrap
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-breakdown table.widefat td a {
				color: #9fd0ff;
				text-decoration: none
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-breakdown table.widefat td a:hover,
			#rl_fsbi_portfolio_widget .rl-fsbi-wp-breakdown table.widefat td a:focus {
				color: #cfe8ff;
				text-decoration: underline
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-breakdown-note {
				margin: 0;
				padding: 0 11px 9px;
				color: #8994bd;
				font-size: 10px;
				line-height: 1.45
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-footer {
				margin: 12px 0 0;
				font-size: 12px
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-footer a {
				color: #9fd0ff;
				text-decoration: none
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-footer a:hover,
			#rl_fsbi_portfolio_widget .rl-fsbi-wp-footer a:focus {
				color: #cfe8ff;
				text-decoration: underline
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-table-scroll {
				overflow-x: auto
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-derived {
				color: #fbbf24;
				cursor: help
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-unset {
				display: inline-flex;
				align-items: center;
				gap: 3px;
				color: #fbbf24;
				font-size: 10px;
				cursor: help
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-unset .dashicons {
				font-size: 13px;
				width: 13px;
				height: 13px
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-footnote {
				margin: 6px 0 0;
				color: #8994bd;
				font-size: 10px;
				line-height: 1.45
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-months {
				min-width: 320px;
				font-size: 11px;
				font-variant-numeric: tabular-nums
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-months th {
				padding: 5px 6px;
				white-space: nowrap
			}

			/* Money wraps rather than pushing the last column out of view when
			   the dashboard column is at its narrowest. */
			#rl_fsbi_portfolio_widget .rl-fsbi-wp-months td {
				padding: 5px 6px;
				white-space: normal;
				overflow-wrap: anywhere
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-chart-wrap {
				position: relative
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-chart {
				display: flex;
				align-items: flex-end;
				gap: 8px;
				height: 155px;
				padding: 12px 8px 22px;
				border: 1px solid #2b3150;
				border-radius: 8px;
				background: #191e2e;
				overflow: visible
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-axis-x {
				margin: 5px 0 0;
				color: #9aa4cc;
				font-size: 9px;
				text-transform: uppercase;
				letter-spacing: .05em
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-bar {
				flex: 1;
				min-width: 10px;
				background: #5f6bff;
				border-radius: 4px 4px 0 0;
				position: relative;
				max-height: 92px
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-bar.rl-fsbi-wp-current {
				background: #1fb897
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-bar.rl-fsbi-wp-next {
				background: #6678d8
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-bar.rl-fsbi-wp-neutral {
				background: #5f6bff
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-bar strong {
				position: absolute;
				bottom: 100%;
				left: 50%;
				transform: translateX(-50%);
				margin-bottom: 4px;
				color: #dce3ff;
				font-size: 9px;
				white-space: nowrap
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-bar small {
				position: absolute;
				top: 100%;
				left: 50%;
				transform: translateX(-50%);
				margin-top: 5px;
				color: #9aa4cc;
				font-size: 10px;
				white-space: nowrap
			}

			#rl_fsbi_portfolio_widget .inside table.widefat {
				background: #191e2e;
				color: #e7ebff;
				border-color: #2b3150
			}

			#rl_fsbi_portfolio_widget .inside table.widefat thead th {
				background: #202640;
				color: #b8c3eb;
				text-transform: uppercase;
				font-size: 10px;
				border-color: #2b3150
			}

			#rl_fsbi_portfolio_widget .inside table.widefat tbody tr,
			#rl_fsbi_portfolio_widget .inside table.widefat tbody tr:nth-child(odd),
			#rl_fsbi_portfolio_widget .inside table.widefat tbody tr:nth-child(even) {
				background: #191e2e
			}

			#rl_fsbi_portfolio_widget .inside table.widefat tbody td {
				color: #dce3ff !important;
				border-color: #2b3150
			}

			#rl_fsbi_portfolio_widget .inside table.widefat tbody tr.rl-fsbi-wp-current {
				background: #21464d !important
			}

			#rl_fsbi_portfolio_widget .inside table.widefat tbody tr.rl-fsbi-wp-next {
				background: #304b77 !important
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-payout-badge {
				display: inline-block;
				margin-left: 5px;
				padding: 2px 5px;
				border-radius: 999px;
				font-size: 9px;
				font-weight: 700;
				white-space: nowrap
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-payout-current {
				background: #1fb897;
				color: #062c2b
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-payout-next {
				background: #6678d8;
				color: #fff
			}

			@media(max-width:1100px) {
				#rl_fsbi_portfolio_widget .rl-fsbi-wp-metric strong {
					font-size: 15px
				}
			}

			@media(max-width:600px) {
				#rl_fsbi_portfolio_widget .inside {
					padding: 10px
				}

				#rl_fsbi_portfolio_widget .rl-fsbi-wp-widget {
					grid-template-columns: repeat(2, minmax(0, 1fr));
					gap: 7px
				}

				#rl_fsbi_portfolio_widget .rl-fsbi-wp-metric {
					padding: 9px
				}

				#rl_fsbi_portfolio_widget .rl-fsbi-wp-metric strong {
					font-size: 14px
				}

				#rl_fsbi_portfolio_widget .rl-fsbi-wp-chart {
					gap: 5px;
					height: 105px;
					padding-left: 5px;
					padding-right: 5px
				}

				#rl_fsbi_portfolio_widget .rl-fsbi-wp-bar small {
					font-size: 8px
				}
			}
		</style>
		<div class="rl-fsbi-wp-widget">
			<div class="rl-fsbi-wp-metric"><span><?php echo esc_html__('Net Revenue', 'rl-freemius-bi'); ?></span><strong><?php echo $format($data['net_revenue']); ?></strong></div>
			<div class="rl-fsbi-wp-metric">
				<span><?php echo esc_html__('MRR', 'rl-freemius-bi'); ?></span>
				<strong><?php echo $format($data['mrr']); ?></strong>
				<span class="rl-fsbi-wp-scope" title="<?php echo esc_attr($this->describe_scope_breakdown($data, 'mrr')); ?>"><?php echo esc_html(sprintf(/* translators: %d: number of plugins */ __('All plugins in scope (%d)', 'rl-freemius-bi'), (int) $data['scope_count'])); ?></span>
			</div>
			<div class="rl-fsbi-wp-metric">
				<span><?php echo esc_html__('ARR', 'rl-freemius-bi'); ?></span>
				<strong><?php echo $format($data['arr']); ?></strong>
				<span class="rl-fsbi-wp-scope" title="<?php echo esc_attr($this->describe_scope_breakdown($data, 'mrr')); ?>"><?php echo esc_html(sprintf(/* translators: %d: number of plugins */ __('All plugins in scope (%d)', 'rl-freemius-bi'), (int) $data['scope_count'])); ?></span>
			</div>
			<div class="rl-fsbi-wp-metric">
				<span><?php echo esc_html__('Active Subscribers', 'rl-freemius-bi'); ?></span>
				<strong><?php echo esc_html(number_format_i18n($data['active_subscriptions'])); ?></strong>
				<span class="rl-fsbi-wp-scope" title="<?php echo esc_attr($this->describe_scope_breakdown($data, 'subscribers')); ?>"><?php echo esc_html(sprintf(/* translators: %d: number of plugins */ __('All plugins in scope (%d)', 'rl-freemius-bi'), (int) $data['scope_count'])); ?></span>
			</div>
		</div>

		<?php
		$new_subs = $data['new_subscriptions'];
		$has_target = $new_subs['target'] > 0;
		$new_subs_tooltip = $this->describe_scope_breakdown($data, 'new');
		?>
		<p class="rl-fsbi-wp-newsubs" title="<?php echo esc_attr($new_subs_tooltip); ?>">
			<strong><?php echo esc_html__('New subscriptions:', 'rl-freemius-bi'); ?></strong>
			<span class="rl-fsbi-wp-newsubs-value"><?php echo esc_html(number_format_i18n($new_subs['new'])); ?><?php if ($has_target) : ?> / <?php echo esc_html(number_format_i18n($new_subs['target'])); ?><?php endif; ?></span>
			<span class="rl-fsbi-wp-newsubs-rest"><?php echo esc_html(sprintf(
				/* translators: 1: number of reactivations, 2: month name */
				_n('· %1$s reactivation · %2$s', '· %1$s reactivations · %2$s', (int) $new_subs['reactivations'], 'rl-freemius-bi'),
				number_format_i18n($new_subs['reactivations']),
				$new_subs['month']
			)); ?></span>
		</p>

		<?php if (empty($data['commission_rate'])) : ?>
			<p class="rl-fsbi-wp-alert">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<a href="<?php echo esc_url(admin_url('admin.php?page=rl-freemius-bi-settings&tab=sync_settings')); ?>"><?php echo esc_html__('Freemius revenue share is not set, so commission is not deducted and net revenue is overstated. Set it in Settings → Synchronization.', 'rl-freemius-bi'); ?></a>
			</p>
		<?php endif; ?>

		<?php if ($data['retention']['upcoming'] > 0 || $data['retention']['failed'] > 0) : ?>
			<p class="rl-fsbi-wp-alert">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<a href="<?php echo esc_url($data['retention']['url']); ?>"><?php
					$renewals_text = sprintf(
						/* translators: %s: number of renewals */
						_n('%s renewal due in the next 30 days', '%s renewals due in the next 30 days', (int) $data['retention']['upcoming'], 'rl-freemius-bi'),
						number_format_i18n($data['retention']['upcoming'])
					);
					$failed_text = sprintf(
						/* translators: %s: number of subscriptions with a failed payment */
						_n('%s with failed payment', '%s with failed payment', (int) $data['retention']['failed'], 'rl-freemius-bi'),
						number_format_i18n($data['retention']['failed'])
					);
					echo esc_html($renewals_text . ' · ' . $failed_text);
				?></a>
			</p>
		<?php endif; ?>

		<div class="rl-fsbi-wp-chart-wrap" role="img" aria-label="<?php echo esc_attr__('Three month net revenue trend, current month in progress', 'rl-freemius-bi'); ?>">
			<div class="rl-fsbi-wp-chart">
				<?php foreach ($data['trend'] as $point) : ?>
					<?php
					$bar_class = 'current' === $point['payout_status'] ? 'rl-fsbi-wp-current' : ('next' === $point['payout_status'] ? 'rl-fsbi-wp-next' : 'rl-fsbi-wp-neutral');
					$has_projection = ! empty($point['projection_height']);
					$bar_height = $has_projection ? $point['projection_height'] : $point['height'];
					$bar_title = $point['label'] . ': ' . $format_plain($point['value']);
					if ($has_projection) {
						/* translators: 1: month label, 2: projected net revenue */
						$bar_title .= ' · ' . sprintf(__('Linear projection: %s', 'rl-freemius-bi'), $format_plain($point['projection']));
					}
					?>
					<div class="rl-fsbi-wp-bar <?php echo esc_attr($bar_class); ?><?php echo $point['partial'] ? ' rl-fsbi-wp-bar-partial' : ''; ?><?php echo $has_projection ? ' rl-fsbi-wp-bar-projected' : ''; ?>" style="height: <?php echo esc_attr($bar_height); ?>%;" title="<?php echo esc_attr($bar_title); ?>">
						<?php if ($has_projection) : ?>
							<span class="rl-fsbi-wp-bar-actual" style="height: <?php echo esc_attr($point['partial_share']); ?>%;"></span>
							<strong><?php echo esc_html($format($point['projection'])); ?><em><?php echo esc_html__('Linear projection', 'rl-freemius-bi'); ?></em></strong>
						<?php else : ?>
							<strong><?php echo esc_html($format($point['value'])); ?></strong>
						<?php endif; ?>
						<small><?php echo esc_html($point['label']); ?></small>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="rl-fsbi-wp-axis-x"><?php echo esc_html(sprintf(/* translators: %s: currency code */ __('Net revenue by month, amounts in %s', 'rl-freemius-bi'), $currency)); ?></div>
		</div>

		<?php if (! empty($data['payouts'])) : ?>
			<div class="rl-fsbi-wp-payouts">
				<?php foreach (array('current' => __('Current payout', 'rl-freemius-bi'), 'next' => __('Next payout', 'rl-freemius-bi')) as $key => $label) : ?>
					<?php if (empty($data['payouts'][$key])) {
						continue;
					}
					$payout = $data['payouts'][$key]; ?>
					<p class="rl-fsbi-wp-payout-line">
						<span class="rl-fsbi-wp-payout-label"><?php echo esc_html($label); ?>:</span>
						<strong><?php echo $format($payout['amount']); ?></strong>
						<?php if ('' !== $payout['date']) : ?>· <span><?php echo esc_html($payout['date']['label']); ?></span><?php endif; ?>
						<?php if (! empty($payout['date']['estimated'])) : ?><em class="rl-fsbi-wp-estimated" title="<?php echo esc_attr__('The Freemius API does not expose a payout schedule. This date is calculated from the payout rule in Settings, not reported by Freemius.', 'rl-freemius-bi'); ?>"><?php echo esc_html__('estimated', 'rl-freemius-bi'); ?></em><?php endif; ?>
					</p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="rl-fsbi-wp-table-scroll">
		<table class="widefat striped rl-fsbi-wp-months" style="margin-top:18px">
			<thead>
				<tr>
					<th><?php echo esc_html__('Month', 'rl-freemius-bi'); ?></th>
					<th><?php echo esc_html__('Gross', 'rl-freemius-bi'); ?></th>
					<th><?php echo esc_html__('Refunds', 'rl-freemius-bi'); ?></th>
					<th title="<?php echo esc_attr__('Payment processor fee, as recorded on the payment.', 'rl-freemius-bi'); ?>"><?php echo esc_html__('Gateway', 'rl-freemius-bi'); ?></th>
					<th title="<?php echo esc_attr__('Derived, because the Payments API returns no commission: revenue share x (gross + VAT) + the fixed fee, with the percentage part returned on refunds and the fixed and gateway fees kept.', 'rl-freemius-bi'); ?>"><?php echo esc_html__('Commission', 'rl-freemius-bi'); ?></th>
					<th title="<?php echo esc_attr__('Tax collected on the payments. Gross is tax-exclusive, so VAT is not subtracted from it - shown for information only.', 'rl-freemius-bi'); ?>"><?php echo esc_html__('VAT *', 'rl-freemius-bi'); ?></th>
					<th><?php echo esc_html__('Net', 'rl-freemius-bi'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($data['trend'] as $point) : ?>
					<tr class="<?php echo esc_attr('current' === $point['payout_status'] ? 'rl-fsbi-wp-current' : ('next' === $point['payout_status'] ? 'rl-fsbi-wp-next' : '')); ?>">
						<td><?php echo esc_html($point['label']); ?><?php if ('current' === $point['payout_status']) : ?><span class="rl-fsbi-wp-payout-badge rl-fsbi-wp-payout-current"><?php echo esc_html__('CURRENT PAYOUT', 'rl-freemius-bi'); ?></span><?php elseif ('next' === $point['payout_status']) : ?><span class="rl-fsbi-wp-payout-badge rl-fsbi-wp-payout-next"><?php echo esc_html__('NEXT PAYOUT', 'rl-freemius-bi'); ?></span><?php endif; ?><?php if ($point['partial'] && ! empty($point['projection_height'])) : ?><br /><small class="rl-fsbi-wp-projection-note"><?php echo esc_html(sprintf(/* translators: %s: projected net revenue */ __('Linear projection: %s', 'rl-freemius-bi'), $format_plain($point['projection']))); ?></small><?php endif; ?></td>
						<td><?php echo esc_html($format_num($point['gross'])); ?></td>
						<td><?php echo esc_html($format_num($point['refunds'])); ?></td>
						<td><?php echo esc_html($format_num($point['gateway'])); ?></td>
						<td title="<?php echo esc_attr($this->describe_commission_tooltip($point)); ?>">
							<?php if (empty($point['commission_configured'])) : ?>
								<span class="rl-fsbi-wp-unset" title="<?php echo esc_attr__('Freemius revenue share is not set, so no commission is applied and net is overstated. Set it in Settings -> Synchronization.', 'rl-freemius-bi'); ?>"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php echo esc_html__('not set', 'rl-freemius-bi'); ?></span>
							<?php else : ?>
								<?php echo esc_html($format_num($point['commission'])); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html($format_num($point['vat'])); ?></td>
						<td><strong><?php echo esc_html($format_num($point['value'])); ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<p class="rl-fsbi-wp-footnote"><?php
			echo esc_html(sprintf(
				/* translators: %s: configured revenue share */
				__('Gross − refunds − commission − gateway fees = net. Gross is tax-exclusive and VAT is shown for information only. Commission is derived (revenue share %s%% × (gross + VAT) + fixed fee), because the Freemius Payments API returns no commission; refunds return the percentage part, the fixed and gateway fees are kept.', 'rl-freemius-bi'),
				number_format_i18n((float) ($data['commission_rate'] ?? 0), 2)
			));
		?></p>

		<?php if (! empty($data['plugins'])) : ?>
			<details class="rl-fsbi-wp-breakdown">
				<summary><?php echo esc_html__('Per-plugin breakdown', 'rl-freemius-bi'); ?></summary>
				<div class="rl-fsbi-wp-breakdown-scroll">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html__('Plugin', 'rl-freemius-bi'); ?></th>
								<th scope="col"><?php echo esc_html__('Subscribers', 'rl-freemius-bi'); ?></th>
								<th scope="col"><?php echo esc_html__('MRR', 'rl-freemius-bi'); ?></th>
								<th scope="col"><?php echo esc_html__('New this month', 'rl-freemius-bi'); ?></th>
								<th scope="col"><?php echo esc_html__('Reactivations', 'rl-freemius-bi'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($data['plugins'] as $plugin) : ?>
								<tr>
									<td><a href="<?php echo esc_url($plugin['url']); ?>"><?php echo esc_html($plugin['title']); ?></a></td>
									<td><?php echo esc_html(number_format_i18n($plugin['subscribers'])); ?></td>
									<td><?php echo $format($plugin['mrr']); ?></td>
									<td>
										<?php if ($plugin['target'] > 0) : ?>
											<?php
											$pace_class = 'good' === $plugin['pace'] ? 'is-good' : 'is-warn';
											$pace_icon  = 'good' === $plugin['pace'] ? 'dashicons-yes-alt' : 'dashicons-warning';
											$pace_label = 'good' === $plugin['pace']
												/* translators: 1: new subscriptions so far, 2: number expected by today */
												? sprintf(__('on track (%1$s of %2$s expected)', 'rl-freemius-bi'), number_format_i18n($plugin['new'], 1), number_format_i18n($plugin['expected'], 1))
												/* translators: 1: new subscriptions so far, 2: number expected by today */
												: sprintf(__('behind (%1$s of %2$s expected)', 'rl-freemius-bi'), number_format_i18n($plugin['new'], 1), number_format_i18n($plugin['expected'], 1));
											?>
											<span class="rl-fsbi-wp-target <?php echo esc_attr($pace_class); ?>" title="<?php echo esc_attr($pace_label); ?>">
												<span class="dashicons <?php echo esc_attr($pace_icon); ?>" aria-hidden="true"></span>
												<?php echo esc_html(number_format_i18n($plugin['new']) . ' / ' . number_format_i18n($plugin['target'])); ?>
											</span>
											<?php if ($plugin['projection'] > 0 && null !== $plugin['projection']) : ?>
												<br /><small class="rl-fsbi-wp-projection-note"><?php echo esc_html(sprintf(/* translators: %s: projected month-end count */ __('Linear projection: %s', 'rl-freemius-bi'), number_format_i18n($plugin['projection']))); ?></small>
											<?php endif; ?>
										<?php else : ?>
											<span class="rl-fsbi-wp-target-none"><?php echo esc_html(number_format_i18n($plugin['new'])); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html(number_format_i18n($plugin['reactivations'])); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="rl-fsbi-wp-breakdown-note"><?php echo esc_html__('New this month counts first-time paid subscriptions, excluding renewals and trials. Reactivations are customers whose earlier subscription had ended, shown separately and not included in that count.', 'rl-freemius-bi'); ?></p>
			</details>
		<?php endif; ?>

		<p class="rl-fsbi-wp-footer">
			<a href="<?php echo esc_url($data['dashboard_url']); ?>"><?php echo esc_html__('Open full dashboard', 'rl-freemius-bi'); ?></a>
		</p>
<?php
	}

	/**
	 * Explain where a month's commission figure came from.
	 *
	 * @param array $point One row of the widget trend.
	 * @return string
	 */
	private function describe_commission_tooltip($point)
	{
		$summary = $this->get_detection_summary();
		$assumed_note = (! empty($summary['assumed']) && 'legacy' === $this->get_commission_model())
			? __(' Assumed legacy tiers from account age - Freemius reports no rate for this account; confirm in Freemius -> Earnings -> Payouts Information -> Fees Breakdown.', 'rl-freemius-bi')
			: '';
		if (empty($point['commission_configured'])) {
			return __('No commission applied: the Freemius revenue share is not configured. The Payments API does not return it, so it has to be set on the Settings page; until then net revenue is overstated by the whole commission.', 'rl-freemius-bi');
		}

		return sprintf(
			/* translators: 1: effective percentage, 2: configured rate, 3: number of sales, 4: percentage part, 5: fixed part */
			__('Derived from the %2$s%% revenue share on (gross + VAT), over %3$s sales; refunds return the percentage part only. Effective rate this month: %1$s%%. Percentage part %4$s + fixed fee part %5$s.', 'rl-freemius-bi'),
			number_format_i18n((float) ($point['commission_pct'] ?? 0), 2),
			number_format_i18n((float) ($point['rate'] ?? 0), 2),
			number_format_i18n((int) ($point['sale_rows'] ?? 0)),
			number_format_i18n((float) ($point['commission_pct_part'] ?? 0), 2),
			number_format_i18n((float) ($point['commission_fixed_part'] ?? 0), 2)
		) . $assumed_note;
	}

	/**
	 * Per-plugin breakdown of one metric, for a tooltip.
	 *
	 * @param array  $data   Widget data.
	 * @param string $metric mrr|subscribers|new.
	 * @return string
	 */
	private function describe_scope_breakdown($data, $metric)
	{
		$lines = array();

		foreach ((array) $data['plugins'] as $plugin) {
			switch ($metric) {
				case 'subscribers':
					$lines[] = sprintf(/* translators: 1: plugin name, 2: subscriber count */ __('%1$s: %2$s subscribers', 'rl-freemius-bi'), $plugin['title'], number_format_i18n($plugin['subscribers']));
					break;
				case 'new':
					$line = sprintf(
						/* translators: 1: plugin name, 2: new subscriptions, 3: reactivations */
						_n('%1$s: %2$s new, %3$s reactivation', '%1$s: %2$s new, %3$s reactivations', (int) $plugin['reactivations'], 'rl-freemius-bi'),
						$plugin['title'],
						number_format_i18n($plugin['new']),
						number_format_i18n($plugin['reactivations'])
					);
					if (! empty($plugin['target'])) {
						$line .= ' — ' . sprintf(
							/* translators: 1: new subscriptions, 2: target, 3: pace */
							__('%1$s of %2$s target, %3$s', 'rl-freemius-bi'),
							number_format_i18n($plugin['new']),
							number_format_i18n($plugin['target']),
							'good' === $plugin['pace'] ? __('on track', 'rl-freemius-bi') : __('behind pace', 'rl-freemius-bi')
						);
					}
					$lines[] = $line;
					break;
				default:
					$lines[] = sprintf(/* translators: 1: plugin name, 2: MRR amount, 3: currency */ __('%1$s: %2$s %3$s MRR', 'rl-freemius-bi'), $plugin['title'], number_format_i18n((float) $plugin['mrr'], 2), $data['currency']);
			}
		}

		return implode("\n", $lines);
	}

	private function get_wordpress_dashboard_portfolio_data()
	{
		$plugin_ids = $this->get_selected_plugin_ids();
		$currency = strtoupper((string) $this->settings->get_option('rl_fsbi_conversion_currency', $this->settings->get_option('rl_fsbi_default_currency', 'EUR')));
		$currency = in_array($currency, array('USD', 'EUR', 'GBP'), true) ? $currency : 'EUR';

		// Built only from the local synced tables. The key carries the sync
		// generation and the calendar day, so a new import or a date change
		// rebuilds it without ever touching the Freemius API on a page load.
		$cache_key = 'rl_fsbi_wp_portfolio_' . md5(implode('|', array(
			$currency,
			implode(',', $plugin_ids),
			current_time('Y-m-d'),
			(int) get_option('rl_fsbi_cache_generation', 1),
			defined('RL_FSBI_VERSION') ? RL_FSBI_VERSION : '',
		)));

		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			$cached['from_cache'] = true;

			return $cached;
		}

		$repo = new RL_FSBI_Repository();
		$today = current_time('Y-m-d');
		$month_start = wp_date('Y-m-01', current_time('timestamp'));
		$payments = array();
		$trend = array();
		$current_month_payments = array();

		for ($i = 0; $i <= 2; $i++) {
			$month = wp_date('Y-m', strtotime('-' . $i . ' months', current_time('timestamp')));
			$start = $month . '-01';
			$end = wp_date('Y-m-t', strtotime($start));
			$month_payments = array();
			foreach ($plugin_ids as $plugin_id) {
				$month_payments = array_merge($month_payments, $repo->get_payments($plugin_id, $start, $end, null));
			}
			$payments = array_merge($payments, $month_payments);
			if (0 === $i) {
				$current_month_payments = $month_payments;
			}
			$row = $this->summarise_month_payments($month_payments, $currency, $start);
			$gross = $row['gross'];
			$refunds = $row['refunds'];
			$fees = $row['gateway'];
			$net = $row['net'];
			$trend[] = array(
				'month' => wp_date('M', strtotime($start)),
				'key' => $month,
				'value' => $net,
				'gross' => $gross,
				'refunds' => $refunds,
				'fees' => $fees,
				'commission' => $row['commission'],
				'commission_pct_part' => $row['commission_pct_part'],
				'commission_fixed_part' => $row['commission_fixed_part'],
				'gateway' => $row['gateway'],
				'vat' => $row['vat'],
				'commission_configured' => $row['configured'],
				'derived_rows' => $row['derived_rows'],
				'sale_rows' => $row['sale_rows'],
				'commission_pct' => $row['commission_pct'],
				'partial' => 0 === $i,
				'label' => 0 === $i
					? sprintf(
						/* translators: 1: short month name, 2: day of month the data runs to */
						esc_html__('%1$s (to %2$s)', 'rl-freemius-bi'),
						wp_date('M', strtotime($start)),
						wp_date('j M', current_time('timestamp'))
					)
					: wp_date('M', strtotime($start)),
				// With the 10th-of-X+2 rule, on 11 November the money on its way is
				// October's (paid 10 Dec) and the one after is November's (10 Jan).
				'payout_status' => $month === wp_date('Y-m', strtotime('-1 month', current_time('timestamp')))
					? 'current'
					: ($month === wp_date('Y-m', current_time('timestamp')) ? 'next' : ''),
			);
		}

		// Same three months, same per-payment conversion as the table below, so the
		// headline and the rows cannot disagree.
		$net_revenue = array_sum(array_column($trend, 'value'));

		$subscriptions = $this->get_subscriptions_for_dashboard(0, null);
		$mrr = $this->calculate_period_mrr($subscriptions, $today, $currency)['converted'];
		$stats = $this->calculate_subscription_stats($subscriptions, $today);

		// The month in progress is not comparable with a finished one: on the
		// 11th it has barely a third of the days, so reading it as a drop is a
		// mistake the label and the projection are there to prevent.
		$day_of_month = (int) wp_date('j', current_time('timestamp'));
		$days_in_month = (int) wp_date('t', current_time('timestamp'));
		$projection = $day_of_month > 0
			? ($trend[0]['value'] / $day_of_month) * $days_in_month
			: $trend[0]['value'];
		$trend[0]['projection'] = round($projection, 2);

		// Charts read oldest to newest.
		$trend = array_reverse($trend);

		$show_projection = $day_of_month >= 7;
		$max = max(1, max(array_column($trend, 'value')));
		if ($show_projection) {
			$max = max($max, (float) $trend[count($trend) - 1]['projection']);
		}
		foreach ($trend as &$point) {
			$point['height'] = max(8, round(($point['value'] / $max) * 100, 1));
			$point['projection_height'] = ($show_projection && $point['partial'] && isset($point['projection']))
				? max(8, round(((float) $point['projection'] / $max) * 100, 1))
				: 0;
			$point['partial_share'] = ($point['projection_height'] > 0)
				? round(($point['height'] / $point['projection_height']) * 100, 1)
				: 0;
		}
		unset($point);

		// Per-plugin split. The headline figures stay exactly as they were
		// (revenue and subscriptions across everything synced); these rows exist
		// so the total can be reconciled and each product reached in one click.
		// Framework field per product (Plugin Scope -> Monthly New Subscription
		// Targets); the old map is only a fallback for values saved before it.
		$targets = array();
		$plugins = array();
		$new_total = 0;
		$reactivation_total = 0;
		$target_total = 0;

		foreach ($plugin_ids as $plugin_id) {
			$own = array_values(array_filter($subscriptions, static function ($subscription) use ($plugin_id) {
				return (int) ($subscription->plugin_id ?? 0) === $plugin_id;
			}));
			$own_payments = array_values(array_filter($current_month_payments, static function ($payment) use ($plugin_id) {
				return (int) ($payment->plugin_id ?? 0) === $plugin_id;
			}));

			$own_stats = $this->calculate_subscription_stats($own, $today);
			$own_mrr = $this->calculate_period_mrr($own, $today, $currency)['converted'];
			$own_series = $this->build_new_subscription_series($own_payments, $own, 1);
			$own_target = $this->settings->get_monthly_target($plugin_id);

			// Pace, not just a percentage: on the 10th of a 30-day month, 33% of
			// the target is on track, not behind.
			$expected_so_far = $own_target > 0 ? ($own_target * $day_of_month) / max(1, $days_in_month) : 0.0;
			$own_new = (int) $own_series['current_new'];

			$plugins[] = array(
				'id' => $plugin_id,
				'title' => $this->get_plugin_clean_title($plugin_id),
				'url' => admin_url('admin.php?page=rl-freemius-bi-plugin-' . $plugin_id . '#dashboard'),
				'subscribers' => (int) $own_stats['active'],
				'mrr' => (float) $own_mrr,
				'new' => $own_new,
				'reactivations' => (int) $own_series['current_reactivation'],
				'target' => $own_target,
				'expected' => round($expected_so_far, 1),
				'pace' => $own_target > 0
					? ($own_new >= $expected_so_far ? 'good' : 'warn')
					: 'none',
				'projection' => $day_of_month >= 7 ? (int) round(($own_new / max(1, $day_of_month)) * $days_in_month) : 0,
			);

			$new_total += (int) $own_series['current_new'];
			$reactivation_total += (int) $own_series['current_reactivation'];
			$target_total += $own_target;
		}

		// Payout schedule. The public Freemius API documents no payout endpoint
		// and no payout date, so these are derived from the rule configured in
		// Settings and flagged as estimates wherever they are shown.
		$payouts = array();
		foreach ($trend as $point) {
			if ('' === $point['payout_status']) {
				continue;
			}
			$payouts[$point['payout_status']] = array(
				'month' => $point['key'],
				'amount' => (float) $point['value'],
				'date' => $this->estimate_payout_date($point['key']),
			);
		}

		$retention = $this->build_retention_snapshot($subscriptions, $stats);
		$retention_url = admin_url('admin.php?page=rl-freemius-bi' . (1 === count($plugin_ids) ? '-plugin-' . (int) $plugin_ids[0] : '') . '#rl-fsbi-block-retention');

		$data = array(
			'currency' => $currency,
			'net_revenue' => $net_revenue,
			'mrr' => $mrr,
			'arr' => $mrr * 12,
			'active_subscriptions' => (int) $stats['active'],
			'trend' => $trend,
			'plugins' => $plugins,
			'scope_count' => count($plugin_ids),
			'new_subscriptions' => array(
				'new' => $new_total,
				'reactivations' => $reactivation_total,
				'target' => $target_total,
				'month' => wp_date('F Y', current_time('timestamp')),
			),
			'payouts' => $payouts,
			'retention' => array(
				'upcoming' => (int) $retention['upcoming_30d'],
				'failed' => (int) $retention['failed_payments'],
				'url' => $retention_url,
			),
			'commission_rate' => $this->get_freemius_revenue_share(),
			'dashboard_url' => admin_url('admin.php?page=rl-freemius-bi'),
			'built_at' => current_time('Y-m-d H:i'),
		);

		set_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);

		return $data;
	}
	/**
	 * Gross, refunds, commission, gateway fees and net for one month.
	 *
	 * The Freemius Payments API carries no fee, net or commission field, and
	 * "gross" is tax-exclusive with `vat` holding the tax. The commission is
	 * therefore always derived, never read from a stored column:
	 *
	 *   sale   : rate x (gross + vat) + fixed fee
	 *   refund : the percentage part only is returned; the fixed fee and the
	 *            gateway fee are not
	 *
	 * Gross - refunds - commission - gateway fees = net, every term of it from
	 * a documented rule rather than from a figure chosen to make the row add up.
	 *
	 * @param array  $payments Payment rows.
	 * @param string $currency Reporting currency.
	 * @param string $fallback_date Date used when a row has none.
	 * @return array
	 */
	private function summarise_month_payments($payments, $currency, $fallback_date)
	{
		$gross = 0.0;
		$refunds = 0.0;
		$commission = 0.0;
		$commission_pct_part = 0.0;
		$commission_fixed_part = 0.0;
		$gateway = 0.0;
		$vat_total = 0.0;
		$net = 0.0;
		$sale_rows = 0;

		$rate = $this->get_freemius_revenue_share();
		$configured = $rate > 0 || 'legacy' === $this->get_commission_model();

		foreach ($payments as $payment) {
			$amounts = $this->resolve_payment_amounts($payment, 0.0);
			$source_currency = strtoupper((string) ($payment->currency ?? $currency));
			$date = (string) ($payment->transaction_date ?? $fallback_date);
			$convert = function ($value) use ($source_currency, $currency, $date) {
				return $this->convert_currency_amount((float) $value, $source_currency, $currency, $date);
			};

			$row_gross = $convert(abs((float) $amounts['gross']));
			$row_vat = $convert(abs(isset($payment->vat) ? (float) $payment->vat : 0.0));
			$row_gateway = $convert(abs(isset($payment->gateway_fee) ? (float) $payment->gateway_fee : 0.0));

			// The legacy model charges by the payment position in the lifetime
			// cumulative, so the rate is resolved per row before the sale/refund
			// split: a refund reverses the same tier its sale was charged at.
			$row_rate = $this->get_commission_rate_for_date($date);
			$row_configured = $configured || $row_rate > 0;

			if (! $this->is_sale_payment($payment)) {
				// A row that is not a sale but carries a positive amount is the
				// original payment recorded as disputed; its matching chargeback
				// carries the negative. Counting both would double the refund.
				if ((float) $amounts['gross'] >= 0) {
					continue;
				}

				$commission_part = $row_configured ? ($row_gross + $row_vat) * ($row_rate / 100) : 0.0;
				$refunds += $row_gross;
				$commission -= $commission_part;
				$commission_pct_part -= $commission_part;
				$vat_total -= $row_vat;
				// A chargeback carries its own dispute fee, which is a cost of the
				// month and belongs in Gateway, not in the refunded amount.
				$gateway += $row_gateway;
				$net -= ($row_gross - $commission_part) + $row_gateway;
				continue;
			}

			$gross += $row_gross;
			$vat_total += $row_vat;
			$gateway += $row_gateway;
			$sale_rows++;

			if ($row_configured) {
				$row_pct = ($row_gross + $row_vat) * ($row_rate / 100);
				$row_fixed = $convert($this->get_freemius_fixed_fee($source_currency));
				$row_commission = $row_pct + $row_fixed;
				$commission += $row_commission;
				$commission_pct_part += $row_pct;
				$commission_fixed_part += $row_fixed;
				$net += $row_gross - $row_commission - $row_gateway;
			} else {
				// No revenue share known: report gross minus refunds and gateway
				// fees rather than netting to zero, and let the UI warn that
				// commission is missing from the figure.
				$net += $row_gross - $row_gateway;
			}
		}

		$totals = array(
			'gross' => round($gross, 2),
			'refunds' => round($refunds, 2),
			'commission' => round($commission, 2),
			'commission_pct_part' => round($commission_pct_part, 2),
			'commission_fixed_part' => round($commission_fixed_part, 2),
			'gateway' => round($gateway, 2),
			'vat' => round($vat_total, 2),
			'net' => round($net, 2),
			'configured' => $configured,
			'rate' => $rate,
			'commission_pct' => $gross > 0 ? round(($commission / $gross) * 100, 2) : 0.0,
			'sale_rows' => $sale_rows,
			'derived_rows' => $sale_rows,
		);

		$this->audit_month_commission($totals);

		return $totals;
	}

	/**
	 * Whether a payment row is a sale rather than a refund, chargeback or dispute.
	 *
	 * The Payments API states the kind in `type`. Sign heuristics are not enough:
	 * a chargeback can be stored with a positive gross and no refund id, which
	 * is how one ended up inside Gross as well as Refunds.
	 *
	 * @param object $payment Payment row.
	 * @return bool
	 */
	private function is_sale_payment($payment)
	{
		$type = strtolower(trim((string) ($payment->payment_type ?? '')));

		if ('' === $type) {
			$meta = json_decode((string) ($payment->metadata ?? ''), true);
			$type = is_array($meta) && isset($meta['type']) ? strtolower(trim((string) $meta['type'])) : '';
		}

		// The metadata copy of the type is the API's own value; a stale or
		// defaulted payment_type column must not turn a chargeback back into a
		// sale, which is how one was counted in Gross and in Refunds at once.
		$meta = json_decode((string) ($payment->metadata ?? ''), true);
		$meta_type = is_array($meta) && isset($meta['type']) ? strtolower(trim((string) $meta['type'])) : '';

		if ('' !== $meta_type && ! in_array($meta_type, array('payment', 'sale', 'purchase'), true)) {
			return false;
		}

		if (in_array($type, array('payment', 'sale', 'purchase', ''), true)) {
			return (float) ($payment->gross ?? 0) >= 0;
		}

		return false;
	}

	/**
	 * The account's Freemius revenue share, as a percentage.
	 *
	 * The API exposes no per-payment commission, so this is a setting. It falls
	 * back to the synced balance only when that reports a rate, because a zero
	 * from the sync means "unknown", not "free".
	 *
	 * @return float
	 */
	private function get_freemius_revenue_share()
	{
		$configured = $this->settings->get_option('rl_fsbi_fs_revenue_share', '');
		if ('' !== $configured && null !== $configured) {
			return max(0.0, (float) $configured);
		}

		if ('legacy' === $this->get_commission_model()) {
			$summary = $this->get_detection_summary();
			return RL_FSBI_Detection::progressive_marginal_rate((float) ($summary['lifetime_gross_usd'] ?? 0));
		}

		return max(0.0, $this->get_synced_commission_rate());
	}

	/**
	 * Commission model in force: legacy tiers, flat, current, or empty.
	 *
	 * @return string
	 */
	private function get_commission_model()
	{
		$model = (string) $this->settings->get_option('rl_fsbi_commission_model', '');
		if ('' !== $model) {
			return $model;
		}

		if ('' !== (string) $this->settings->get_option(RL_FSBI_Detection::OPTION_ACCEPTED, '')) {
			$summary = $this->get_detection_summary();
			return (string) ($summary['model'] ?? '');
		}

		return '';
	}

	/**
	 * Stored detection summary.
	 *
	 * @return array
	 */
	private function get_detection_summary()
	{
		if (! class_exists('RL_FSBI_Detection')) {
			return array();
		}

		$summary = $this->settings->get_option(RL_FSBI_Detection::OPTION_SUMMARY, array());

		return is_array($summary) ? $summary : array();
	}

	/**
	 * Revenue share that applies to a payment on a given date.
	 *
	 * With the legacy model the tier depends on where the payment sits in the
	 * lifetime cumulative gross, so a month is costed by its payments' position
	 * in time rather than by an average applied to everything.
	 *
	 * @param string $date Payment date, Y-m-d.
	 * @return float
	 */
	private function get_commission_rate_for_date($date)
	{
		if ('legacy' !== $this->get_commission_model()) {
			return $this->get_freemius_revenue_share();
		}

		$summary = $this->get_detection_summary();
		$tier1 = (string) ($summary['tier_dates']['tier1'] ?? '');
		$tier2 = (string) ($summary['tier_dates']['tier2'] ?? '');
		$date = substr((string) $date, 0, 10);

		if ('' === $tier1) {
			return RL_FSBI_Detection::TIER_1_RATE;
		}

		if ($date <= $tier1) {
			return RL_FSBI_Detection::TIER_1_RATE;
		}

		if ('' === $tier2 || $date <= $tier2) {
			return RL_FSBI_Detection::TIER_2_RATE;
		}

		return RL_FSBI_Detection::TIER_3_RATE;
	}


	/**
	 * The fixed per-transaction fee Freemius charges, in the payment currency.
	 *
	 * Documented defaults: EUR 0.45, GBP 0.40, USD 0.50 otherwise, applicable
	 * only where the plan carries one. An empty setting uses those defaults; 0
	 * disables it.
	 *
	 * @param string $currency Payment currency.
	 * @return float
	 */
	private function get_freemius_fixed_fee($currency)
	{
		$model = $this->get_commission_model();
		$configured = $this->settings->get_option('rl_fsbi_fs_fixed_fee', '');
		$has_configured = '' !== $configured && null !== $configured;

		// The legacy progressive plan carries no per-transaction fee, and the
		// fixed fee is part of the current plan's pricing only. Applying the
		// default here inflated September and October by roughly a tenth of a
		// unit per sale. A fee is used when the model has one built in
		// (current), or when an administrator sets one explicitly.
		if ('legacy' === $model && ! $has_configured) {
			return 0.0;
		}

		if ('flat' === $model && ! $has_configured) {
			return 0.0;
		}

		if ($has_configured) {
			return max(0.0, (float) $configured);
		}

		$currency = strtoupper((string) $currency);
		if ('EUR' === $currency) {
			return 0.45;
		}
		if ('GBP' === $currency) {
			return 0.40;
		}

		return 0.50;
	}

	/**
	 * The commission rate Freemius reports for this account, as a percentage.
	 *
	 * @return float
	 */
	private function get_synced_commission_rate()
	{
		$balances = $this->get_balances_for_dashboard(0, null);
		$rate = $this->get_balance_commission_rate($balances);

		return $rate > 0 ? $rate : 0.0;
	}

	/**
	 * Flag a month whose effective commission is far from the configured rate.
	 *
	 * The fixed fee makes the effective percentage drift on small months, so
	 * only a large deviation is reported, and only under Debug Mode.
	 *
	 * @param array $totals Result of summarise_month_payments().
	 * @return void
	 */
	private function audit_month_commission($totals)
	{
		if (! $this->settings->is_debug_mode() || $totals['gross'] <= 0 || $totals['rate'] <= 0) {
			return;
		}

		$deviation = abs($totals['commission_pct'] - $totals['rate']) / $totals['rate'];

		if ($deviation > 0.25) {
			error_log(sprintf(
				'[rl-fsbi] commission sanity check: effective %.2f%% vs configured %.2f%% (gross %.2f, vat %.2f, fixed fee included)',
				$totals['commission_pct'],
				$totals['rate'],
				$totals['gross'],
				$totals['vat']
			)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}


	/**
	 * The three things the Overview tab needs and nothing else provides.
	 *
	 * - MRR movement as new / reactivation / churn. Expansion and contraction
	 *   are deliberately absent: they need plan-change history, which Freemius
	 *   does not expose, and deriving them from amount deltas would report a
	 *   coupon or an FX swing as expansion.
	 * - The "needs attention" list, most urgent first, capped at five.
	 * - A six-month net revenue series for the sparkline, converted per event at
	 *   the rate of the day it happened.
	 *
	 * @param array  $payments      Payments for the period.
	 * @param array  $subscriptions Subscriptions in scope.
	 * @param string $start_date    Period start.
	 * @param string $end_date      Period end.
	 * @param string $currency      Reporting currency.
	 * @param int    $plugin_id     Product scope, so the comparison windows read the same products.
	 * @param string $filter_currency Currency filter, so the comparison windows read the same rows.
	 * @return array{waterfall:array,attention:array,series:array}
	 */
	private function build_overview_extras($payments, $subscriptions, $start_date, $end_date, $currency, $plugin_id = 0, $filter_currency = null)
	{
		$repo = new RL_FSBI_Repository();
		$start_ts = strtotime($start_date . ' 00:00:00');
		$end_ts = strtotime($end_date . ' 23:59:59');
		$ended_by_user = $this->build_ended_subscription_index($subscriptions);
		$seen = array();
		$waterfall = array('new' => 0.0, 'reactivation' => 0.0, 'churn' => 0.0, 'net' => 0.0);

		foreach ($payments as $payment) {
			$paid_at = strtotime((string) ($payment->transaction_date ?? ''));
			if (false === $paid_at || $paid_at < $start_ts || $paid_at > $end_ts) {
				continue;
			}

			if (! $this->is_sale_payment($payment) || $this->is_renewal_payment($payment)) {
				continue;
			}

			$sub_id = (int) ($payment->subscription_id ?? 0);
			if ($sub_id > 0) {
				if (isset($seen[$sub_id])) {
					continue;
				}
				$seen[$sub_id] = true;
			}

			$amount = $this->monthlyised_payment_value($payment, $currency, $subscriptions);
			$user_id = (int) ($payment->user_id ?? 0);
			$returning = false;

			foreach ((array) ($ended_by_user[$user_id] ?? array()) as $prior_id => $ended_at) {
				if ($prior_id !== $sub_id && $ended_at < $paid_at) {
					$returning = true;
					break;
				}
			}

			if ($returning) {
				$waterfall['reactivation'] += $amount;
			} else {
				$waterfall['new'] += $amount;
			}
		}

		// Churn: the monthly value of what stopped during the period.
		foreach ($subscriptions as $subscription) {
			$meta = json_decode((string) ($subscription->metadata ?? ''), true);
			$canceled_at = is_array($meta) && ! empty($meta['canceled_at']) ? strtotime((string) $meta['canceled_at']) : false;
			$expired_at = ! empty($subscription->expires_at) ? strtotime((string) $subscription->expires_at) : false;
			$stopped_at = $canceled_at ?: $expired_at;

			if (false === $stopped_at || $stopped_at < $start_ts || $stopped_at > $end_ts) {
				continue;
			}

			$waterfall['churn'] += $this->monthlyised_subscription_value($subscription, $currency);
		}

		foreach ($waterfall as $key => $value) {
			if ('net' === $key) {
				continue;
			}
			$waterfall[$key] = round((float) $value, 2);
		}
		$waterfall['net'] = round($waterfall['new'] + $waterfall['reactivation'] - $waterfall['churn'], 2);

		$stats = $this->calculate_subscription_stats($subscriptions, $end_date, $start_date);
		$retention = $this->build_retention_snapshot($subscriptions, $stats);
		$now = current_time('timestamp');
		$week = strtotime('+7 days', $now);
		$cards_expiring = 0;
		$trials_ending = 0;

		foreach ($subscriptions as $subscription) {
			$renewal_at = ! empty($subscription->next_renewal) ? strtotime((string) $subscription->next_renewal) : false;
			if (false !== $renewal_at && $renewal_at >= $now && $renewal_at <= $week && ! empty($subscription->failed_payments)) {
				$cards_expiring++;
			}

			$trial_ends = ! empty($subscription->trial_ends) ? strtotime((string) $subscription->trial_ends) : false;
			if (false !== $trial_ends && $trial_ends >= $now && $trial_ends <= $week) {
				$trials_ending++;
			}
		}

		$attention = array();
		$push = function ($severity, $count, $label, $hash) use (&$attention) {
			if ($count > 0) {
				$attention[] = array('severity' => $severity, 'count' => (int) $count, 'label' => $label, 'hash' => $hash);
			}
		};

		$push('high', (int) $retention['failed_payments'], _n('subscription with a failed payment', 'subscriptions with a failed payment', (int) $retention['failed_payments'], 'rl-freemius-bi'), '#retention');
		$push('high', $cards_expiring, _n('renewal in the next 7 days with a failed payment', 'renewals in the next 7 days with a failed payment', $cards_expiring, 'rl-freemius-bi'), '#retention');
		$push('medium', $trials_ending, _n('trial ending in the next 7 days', 'trials ending in the next 7 days', $trials_ending, 'rl-freemius-bi'), '#growth');
		$push('medium', (int) $retention['cancellations_this_month'], _n('cancellation this month', 'cancellations this month', (int) $retention['cancellations_this_month'], 'rl-freemius-bi'), '#retention');

		$order = array('high' => 0, 'medium' => 1, 'low' => 2);
		usort($attention, static function ($a, $b) use ($order) {
			$bySeverity = $order[$a['severity']] <=> $order[$b['severity']];
			return 0 !== $bySeverity ? $bySeverity : $b['count'] <=> $a['count'];
		});

		// A partial period cannot be compared with a whole one: on the 11th, eleven
		// days are set against a full month and every figure looks like a collapse.
		// Compare the same day-span of the previous month instead, and say so.
		// The selected range usually ends on the last day of the month ("this
		// month", "last 30 days"), so the part that has actually happened is what
		// must be counted, not the day number of the requested end date.
		$today_date = current_time('Y-m-d');
		$covered_end = strtotime($end_date) > strtotime($today_date) ? $today_date : $end_date;
		$period_end_ts = strtotime($covered_end);
		$month_end_day = (int) wp_date('t', $period_end_ts);
		$is_partial = (int) wp_date('j', $period_end_ts) < $month_end_day;
		$comparison = array('partial' => false, 'label' => '', 'net_previous' => 0.0, 'net_current' => 0.0);

		if ($is_partial) {
			$prev_end = wp_date('Y-m-d', strtotime($covered_end . ' -1 month'));
			$prev_start = wp_date('Y-m-d', strtotime($start_date . ' -1 month'));
			$prev_payments = $repo->get_payments($plugin_id, $prev_start, $prev_end, $filter_currency);
			$prev_row = $this->summarise_month_payments($prev_payments, $currency, $prev_start);

			$comparison = array(
				'partial' => true,
				'label' => sprintf(
					/* translators: 1: start day/month, 2: end day/month */
					__('vs %1$s to %2$s', 'rl-freemius-bi'),
					wp_date('j M', strtotime($prev_start)),
					wp_date('j M', strtotime($prev_end))
				),
				'net_previous' => (float) $prev_row['net'],
				'net_current' => 0.0,
			);
		}

		// Six months of history for the five sparklines, oldest first. Each card
		// gets the series its own figure is built from, so a shape can never
		// contradict the number it sits under.
		$series = array('net' => array(), 'newsubs' => array(), 'mrr' => array(), 'churn' => array(), 'trialpaid' => array());
		$recent_months_payments = array();
		$now_ts = current_time('timestamp');

		// The oldest subscription on record. A month that opened before it cannot
		// have a complete MRR snapshot, and reporting the partial one as if it were
		// the whole truth is how a gap turns into a fake collapse.
		$earliest_subscription = null;
		foreach ($subscriptions as $earliest_candidate) {
			$created_stamp = ! empty($earliest_candidate->created_at) ? strtotime((string) $earliest_candidate->created_at) : false;
			if (false !== $created_stamp && (null === $earliest_subscription || $created_stamp < $earliest_subscription)) {
				$earliest_subscription = $created_stamp;
			}
		}

		for ($i = 5; $i >= 0; $i--) {
			$month = wp_date('Y-m', strtotime('-' . $i . ' months', strtotime($end_date)));
			$m_start = $month . '-01';
			$m_end = wp_date('Y-m-t', strtotime($m_start));
			$label = wp_date('M', strtotime($m_start));
			$m_payments = $repo->get_payments($plugin_id, $m_start, $m_end, $filter_currency);
			if ($i <= 2) {
				$recent_months_payments = array_merge($recent_months_payments, $m_payments);
			}

			$row = $this->summarise_month_payments($m_payments, $currency, $m_start);
			$series['net'][] = array('label' => $label, 'value' => $row['net']);

			$month_subs = array();
			$new_count = 0;
			foreach ($m_payments as $m_payment) {
				if (! $this->is_sale_payment($m_payment) || $this->is_renewal_payment($m_payment)) {
					continue;
				}

				$m_sub_id = (int) ($m_payment->subscription_id ?? 0);
				if ($m_sub_id > 0) {
					if (isset($month_subs[$m_sub_id])) {
						continue;
					}
					$month_subs[$m_sub_id] = true;
				}

				$new_count++;
			}
			$series['newsubs'][] = array('label' => $label, 'value' => $new_count);

			// MRR is a point-in-time figure: measure it at the close of each month,
			// but never past today, or the last point would claim future state.
			$mrr_as_of = strtotime($m_end) > $now_ts ? current_time('Y-m-d') : $m_end;
			$mrr_known = null !== $earliest_subscription && $earliest_subscription <= strtotime($m_start . ' 00:00:00');
			$mrr_point = $mrr_known ? $this->calculate_period_mrr($subscriptions, $mrr_as_of, $currency) : array('converted' => null);
			$series['mrr'][] = array(
				'label' => $label,
				'value' => null === $mrr_point['converted'] ? null : round((float) $mrr_point['converted'], 2),
			);

			// The same window rule as the Churn KPI, month by month, and null where
			// the local copy has no base yet so the line breaks instead of reading 0.
			$churn_window = $this->count_churn_window(
				$subscriptions,
				strtotime($m_start . ' 00:00:00'),
				min(strtotime($m_end . ' 23:59:59'), $now_ts)
			);
			$series['churn'][] = array(
				'label' => $label,
				'value' => $churn_window['base'] > 0 ? round((float) $churn_window['rate'], 1) : null,
			);

			// Trial to paid is a rate, so a month with no trials started has no
			// value to plot - it is not 0%.
			$funnel = $repo->get_trial_funnel($plugin_id, $m_start, $m_end);
			$series['trialpaid'][] = array(
				'label' => $label,
				'value' => $funnel['started'] > 0 ? round((float) $funnel['rate'], 1) : null,
			);
		}

		$period_funnel = $repo->get_trial_funnel($plugin_id, $start_date, $end_date);

		// The compact table and the full breakdown are built by one function, so
		// a month cannot read one figure here and another there.
		$recent_table = $this->build_monthly_breakdown($recent_months_payments, $currency, 0.0, 3);
		$recent_months = array_slice((array) ($recent_table['months'] ?? array()), 0, 3);
		$next_payout = array();
		foreach ($recent_months as $month_entry) {
			if ('current' === ($month_entry['payout_status'] ?? '')) {
				$next_payout = array(
					'amount' => (float) ($month_entry['payout_total'] ?? 0),
					'date' => (string) ($month_entry['payout_label'] ?? ''),
					'month' => (string) ($month_entry['period'] ?? ''),
				);
			}
		}

		// What renews in the next 30 days: the same query the Retention tab runs,
		// so the two tabs cannot report 19 against 15 for the same window.
		$renewals_due = (int) ($retention['upcoming_30d'] ?? 0);
		$renewals_due_amount = (float) ($retention['upcoming_amount'] ?? 0);

		// The last five payments of the period, with the same new / renewal /
		// reactivation rule the MRR movement uses.
		$recent_activity = array();
		$activity_payments = array_values(array_filter($payments, function ($payment) use ($start_ts, $end_ts) {
			$at = strtotime((string) ($payment->transaction_date ?? ''));
			return false !== $at && $at >= $start_ts && $at <= $end_ts && $this->is_sale_payment($payment);
		}));
		usort($activity_payments, function ($a, $b) {
			return strtotime((string) $b->transaction_date) <=> strtotime((string) $a->transaction_date);
		});
		foreach (array_slice($activity_payments, 0, 5) as $payment) {
			$amount = $this->convert_currency_amount(
				abs((float) ($payment->gross ?? 0)),
				strtoupper((string) ($payment->currency ?? $currency)),
				$currency,
				(string) ($payment->transaction_date ?? '')
			);
			$kind = $this->is_renewal_payment($payment) ? 'renewal' : 'new';
			if ('new' === $kind) {
				$user_id = (int) ($payment->user_id ?? 0);
				foreach ((array) ($ended_by_user[$user_id] ?? array()) as $prior_id => $ended_at) {
					if ($prior_id !== (int) ($payment->subscription_id ?? 0) && $ended_at < strtotime((string) $payment->transaction_date)) {
						$kind = 'reactivation';
						break;
					}
				}
			}
			$recent_activity[] = array(
				'date'    => wp_date('j M', strtotime((string) $payment->transaction_date)),
				'country' => strtoupper((string) ($payment->country_code ?? '—')),
				'kind'    => $kind,
				'amount'  => round($amount, 2),
				'currency' => $currency,
			);
		}

		return array(
			'waterfall' => $waterfall,
			'attention' => array_slice($attention, 0, 5),
			'series' => $series,
			'comparison' => $comparison,
			'day_of_month' => (int) wp_date('j', current_time('timestamp')),
			'days_in_month' => (int) wp_date('t', current_time('timestamp')),
			'recent_months' => $recent_months,
			'next_payout' => $next_payout,
			'renewals_due' => array('count' => (int) $renewals_due, 'amount' => round((float) $renewals_due_amount, 2)),
			'recent_activity' => $recent_activity,
			'trials_started' => (int) $period_funnel['started'],
			'trials_converted' => (int) $period_funnel['converted'],
		);
	}

	/**
	 * Monthly value of a payment, monthlyised by the subscription's cycle.
	 *
	 * @param object $payment  Payment row.
	 * @param string $currency Reporting currency.
	 * @return float
	 */
	private function monthlyised_payment_value($payment, $currency, $subscriptions = array())
	{
		$amounts = $this->resolve_payment_amounts($payment, 0.0);
		$source = strtoupper((string) ($payment->currency ?? $currency));
		$date = (string) ($payment->transaction_date ?? '');
		$amount = (float) $this->convert_currency_amount(abs((float) $amounts['gross']), $source, $currency, $date);

		// MRR movement has to be a monthly figure: an annual first payment is one
		// twelfth of it. Without this the waterfall compared a year's cash with a
		// month's churn.
		// The payment carries the Freemius subscription id, which is the
		// subscription_id column - not the table's own primary key. Matching on
		// id silently found nothing and left annual payments unmonthlyised.
		$sub_id = (int) ($payment->subscription_id ?? 0);
		foreach ((array) $subscriptions as $subscription) {
			$row_sub_id = (int) ($subscription->subscription_id ?? 0);
			if ($row_sub_id <= 0) {
				$row_sub_id = (int) ($subscription->id ?? 0);
			}

			if ($row_sub_id !== $sub_id) {
				continue;
			}

			$months = $this->subscription_cycle_months($subscription);

			return $months > 1 ? $amount / $months : $amount;
		}

		return $amount;
	}

	/**
	 * Billing-cycle length in months for a subscription row.
	 *
	 * @param object $subscription Subscription row.
	 * @return int
	 */
	private function subscription_cycle_months($subscription)
	{
		$meta = json_decode((string) ($subscription->metadata ?? ''), true);
		$cycle = $subscription->billing_cycle ?? null;
		if (is_array($meta) && isset($meta['billing_cycle'])) {
			$cycle = $meta['billing_cycle'];
		}

		$months = $this->billing_cycle_to_months($cycle);

		return $months > 0 ? $months : 1;
	}

	/**
	 * Monthly value of a subscription, monthlyised by its billing cycle.
	 *
	 * @param object $subscription Subscription row.
	 * @param string $currency     Reporting currency.
	 * @return float
	 */
	private function monthlyised_subscription_value($subscription, $currency)
	{
		$months = $this->subscription_cycle_months($subscription);
		$amount = $this->get_forecast_renewal_amount($subscription);
		$source = strtoupper((string) ($subscription->currency ?? $currency));

		return (float) $this->convert_currency_amount($amount, $source, $currency, (string) ($subscription->created_at ?? '')) / $months;
	}

	/**
	 * Estimated date the revenue of a month is paid out.
	 *
	 * The public Freemius API exposes neither payouts nor a payout schedule, so
	 * this applies the configured rule (day of month, months of lag) instead of
	 * asserting a date nobody can verify. Always reported as an estimate.
	 *
	 * @param string $revenue_month Revenue month as Y-m.
	 * @return array{date:string,label:string,estimated:bool}
	 */
	private function estimate_payout_date($revenue_month)
	{
		// Freemius pays a month's earnings on the 10th, two months later: the
		// revenue earned in September is paid on 10 November, October on
		// 10 December, November on 10 January. The defaults follow that rule;
		// the settings only exist for an account with a different arrangement.
		// The day is not a setting any more: a stored 15 from an earlier default
		// kept overriding the rule and dated October's payout 15 December.
		$day = 10;
		$lag = trim((string) $this->settings->get_option('rl_fsbi_payout_lag_months', ''));
		$lag = '' === $lag ? 2 : (int) $lag;
		$lag = min(6, max(0, $lag));

		if (! preg_match('/^\d{4}-\d{2}$/', (string) $revenue_month)) {
			return array('date' => '', 'label' => '', 'estimated' => true);
		}

		$target_month = wp_date('Y-m', strtotime($revenue_month . '-01 +' . $lag . ' months'));
		$date = sprintf('%s-%02d', $target_month, $day);

		return array(
			'date' => $date,
			'label' => wp_date('j M Y', strtotime($date)),
			'estimated' => true,
		);
	}

	/**
	 * Render the admin page.
	 */
	public function display_admin_page()
	{
		$this->display_plugin_dashboard(0);
	}

	/**
	 * Render dashboard page, optionally forcing one plugin context.
	 *
	 * @param int $forced_plugin_id Plugin ID to force, or 0 for all.
	 */
	public function display_plugin_dashboard($forced_plugin_id = 0)
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'rl-freemius-bi'));
		}

		$forced_plugin_id = (int) $forced_plugin_id;
		// Decides whether the WordPress.org card is rendered at all.
		$GLOBALS['rl_fsbi_has_wporg'] = $forced_plugin_id > 0 && '' !== $this->settings->get_wporg_slug($forced_plugin_id);
		$GLOBALS['rl_fsbi_forced_plugin_id'] = $forced_plugin_id;
		$GLOBALS['rl_fsbi_plugins_catalog']  = $this->get_plugins_catalog();
		$GLOBALS['rl_fsbi_raw_catalog']      = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
		$GLOBALS['rl_fsbi_plugin_version']   = $forced_plugin_id > 0 ? $this->get_plugin_latest_version($forced_plugin_id) : '';

		require RL_FSBI_PLUGIN_DIR . 'admin/partials/rl-fsbi-dashboard.php';
	}

	/**
	 * Handle AJAX sync request.
	 */
	public function handle_sync_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		$developer_id = $this->settings->get_option('rl_fsbi_developer_id');
		$public_key   = $this->settings->get_option('rl_fsbi_public_key');
		$secret_key   = $this->settings->get_option('rl_fsbi_secret_key');

		if (! $developer_id || ! $public_key || ! $secret_key) {
			wp_send_json_error(esc_html__('Sync failed: missing API credentials in settings.', 'rl-freemius-bi'));
		}

		$api = new RL_FSBI_API($developer_id, $public_key, $secret_key);
		$plugins = $api->retrieve_plugins();
		$plugin_ids = $this->get_sync_plugin_ids($plugins);
		if (empty($plugin_ids)) {
			wp_send_json_error(esc_html__('Sync failed: no plugins returned by Freemius and no saved plugin selection is available.', 'rl-freemius-bi'));
		}

		$state = $this->init_sync_state($plugin_ids);

		wp_send_json_success(
			array(
				'message' => esc_html__('Sync started. Processing in batches...', 'rl-freemius-bi'),
				'state'   => $state,
			)
		);
	}

	/**
	 * Process one or more batched sync steps.
	 */
	public function handle_sync_batch_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		$state_raw = isset($_POST['sync_state']) ? wp_unslash($_POST['sync_state']) : '';
		$state = json_decode((string) $state_raw, true);

		if (! is_array($state)) {
			wp_send_json_error(esc_html__('Invalid sync state.', 'rl-freemius-bi'));
		}

		$developer_id = $this->settings->get_option('rl_fsbi_developer_id');
		$public_key   = $this->settings->get_option('rl_fsbi_public_key');
		$secret_key   = $this->settings->get_option('rl_fsbi_secret_key');

		if (! $developer_id || ! $public_key || ! $secret_key) {
			wp_send_json_error(esc_html__('Sync failed: missing API credentials in settings.', 'rl-freemius-bi'));
		}

		$api = new RL_FSBI_API($developer_id, $public_key, $secret_key);
		$repo = new RL_FSBI_Repository();

		$result = $this->process_sync_batch($state, $api, $repo);
		if (isset($result['error']) && $result['error']) {
			wp_send_json_error($result['message']);
		}

		wp_send_json_success($result);
	}

	/**
	 * Cancel a pending batch sync before the next batch starts.
	 */
	public function handle_sync_cancel_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		$sync_id = isset($_POST['sync_id']) ? sanitize_key(wp_unslash($_POST['sync_id'])) : '';
		if (empty($sync_id)) {
			wp_send_json_error(esc_html__('Missing sync identifier.', 'rl-freemius-bi'));
		}

		set_transient('rl_fsbi_cancel_' . $sync_id, 1, HOUR_IN_SECONDS);
		wp_send_json_success(array('message' => esc_html__('Sync cancellation requested.', 'rl-freemius-bi')));
	}

	/**
	 * Refresh only newly-created records since the latest stored payment.
	 */
	public function handle_refresh_latest_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		$developer_id = $this->settings->get_option('rl_fsbi_developer_id');
		$public_key   = $this->settings->get_option('rl_fsbi_public_key');
		$secret_key   = $this->settings->get_option('rl_fsbi_secret_key');

		if (! $developer_id || ! $public_key || ! $secret_key) {
			wp_send_json_error(esc_html__('Refresh skipped: missing API credentials in settings.', 'rl-freemius-bi'));
		}

		$result = $this->refresh_latest_freemius_data();
		if (isset($result['error']) && $result['error']) {
			wp_send_json_error($result['message']);
		}

		wp_send_json_success(
			array(
				'saved_payments' => (int) $result['saved_payments'],
				'debug'          => isset($result['debug']) ? $result['debug'] : array(),
			)
		);
	}

	/**
	 * Scheduled sync callback.
	 */
	public function scheduled_sync()
	{
		$this->refresh_latest_freemius_data();
		$this->sync_daily_newsletter_optins_delta();
		$this->maybe_run_daily_full_sync();
	}

	/**
	 * Run the complete sync once per day.
	 *
	 * The hourly event only refreshes the newest payments, so trials, installs
	 * and uninstall feedback would otherwise go stale until someone clicked
	 * "Sync Now". The timestamp is written before work starts so a fatal error
	 * cannot trigger a retry storm on every hourly run.
	 */
	private function maybe_run_daily_full_sync()
	{
		$last_full_sync = (int) get_option('rl_fsbi_last_full_sync_utc', 0);

		if ($last_full_sync > 0 && (time() - $last_full_sync) < DAY_IN_SECONDS) {
			return;
		}

		update_option('rl_fsbi_last_full_sync_utc', time(), false);
		$this->sync_freemius_data();
	}

	/**
	 * Run automated delta synchronization for newsletter opt-ins in background cron.
	 *
	 * Syncs new contacts created since the last sync or CSV export checkpoint.
	 */
	/**
	 * Whether a Freemius user record carries marketing consent for this site.
	 *
	 * Freemius exposes a single opt-in checkbox plus an address-verification
	 * flag, so the ladder below is as strict as the data allows. The tier is a
	 * setting; "none" refuses everything and leaves the CSV export as the only
	 * route, which is what a site with its own consent records should choose.
	 *
	 * @param array|object $user Freemius user payload.
	 * @return bool
	 */
	private function user_passes_consent($user)
	{
		$data = is_object($user) ? (array) $user : (array) $user;
		$tier = trim((string) $this->settings->get_option('rl_fsbi_consent_field', 'verified_optin'));

		if ('none' === $tier) {
			return false;
		}

		if (empty($data['is_marketing_allowed'])) {
			return false;
		}

		if ('optin' === $tier) {
			return true;
		}

		if (empty($data['is_verified'])) {
			return false;
		}

		if ('verified_optin_customer' === $tier) {
			return $this->user_has_paid($data);
		}

		return true;
	}

	/**
	 * Whether a Freemius user has at least one non-refund payment on record.
	 *
	 * @param array $data Freemius user payload.
	 * @return bool
	 */
	private function user_has_paid($data)
	{
		global $wpdb;

		$user_id = (int) ($data['id'] ?? 0);
		if ($user_id <= 0) {
			return false;
		}

		$paid = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}rl_fsbi_payments WHERE user_id = %d AND is_refund = 0",
			$user_id
		));

		return $paid > 0;
	}

	public function sync_daily_newsletter_optins_delta(): array
	{
		$last_sync_utc = (string) get_option('rl_fsbi_newsletter_last_sync_utc', '');
		if (empty($last_sync_utc)) {
			// Don't auto-run bulk historical backlog in cron without an initial export/sync checkpoint
			return array('skipped' => true, 'reason' => 'No initial sync checkpoint found.');
		}

		$provider = (string) $this->settings->get_option('rl_fsbi_newsletter_provider', '');
		if (empty($provider)) {
			$has_kit = ! empty(trim((string) $this->settings->get_option('rl_fsbi_kit_api_key', '')));
			$has_mc  = ! empty(trim((string) $this->settings->get_option('rl_fsbi_mailchimp_api_key', '')));
			$provider = $has_kit ? 'kit' : ($has_mc ? 'mailchimp' : '');
		}

		if (empty($provider)) {
			return array('skipped' => true, 'reason' => 'No newsletter provider configured.');
		}

		$kit = null;
		$mc  = null;
		$list_id = '';
		$kit_tags = array();

		if ('kit' === $provider) {
			$kit_key = trim((string) $this->settings->get_option('rl_fsbi_kit_api_key', ''));
			if (empty($kit_key)) {
				return array('skipped' => true, 'reason' => 'Kit API key missing.');
			}
			$kit = new RL_FSBI_Kit($kit_key);
			if (! $kit->is_configured()) {
				return array('skipped' => true, 'reason' => 'Kit API key invalid.');
			}
			$kit_tags = $kit->get_tags();
		} else {
			$mc_key  = trim((string) $this->settings->get_option('rl_fsbi_mailchimp_api_key', ''));
			$list_id = trim((string) $this->settings->get_option('rl_fsbi_mailchimp_list_id', ''));
			if (empty($mc_key) || empty($list_id)) {
				return array('skipped' => true, 'reason' => 'Mailchimp credentials missing.');
			}
			$mc = new RL_FSBI_Mailchimp($mc_key);
			if (! $mc->is_configured()) {
				return array('skipped' => true, 'reason' => 'Mailchimp API key invalid.');
			}
		}

		$api = $this->get_api_client();
		if (! $api) {
			return array('skipped' => true, 'reason' => 'Freemius API client not configured.');
		}

		$catalog = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
		$plugin_ids = is_array($catalog) ? array_values(array_filter(array_map('intval', array_keys($catalog)))) : array();
		if (empty($plugin_ids)) {
			return array('skipped' => true, 'reason' => 'No plugins found in catalog.');
		}

		global $wpdb;
		$sub_table = $wpdb->prefix . 'rl_fsbi_subscriptions';
		$lic_table = $wpdb->prefix . 'rl_fsbi_licenses';

		$default_tag_mc = trim((string) $this->settings->get_option('rl_fsbi_mc_default_tag', 'Freemius Opt-in')) ?: 'Freemius Opt-in';
		$default_tag_kit_raw = trim((string) $this->settings->get_option('rl_fsbi_kit_default_tag', 'Freemius Opt-in'));
		$default_kit_tag_id = 0;
		if (is_numeric($default_tag_kit_raw) && isset($kit_tags[(int) $default_tag_kit_raw])) {
			$default_kit_tag_id = (int) $default_tag_kit_raw;
		} else {
			foreach ($kit_tags as $t_id => $t_name) {
				if (strcasecmp($t_name, $default_tag_kit_raw) === 0) {
					$default_kit_tag_id = (int) $t_id;
					break;
				}
			}
			if ($default_kit_tag_id <= 0 && ! empty($kit_tags)) {
				$default_kit_tag_id = (int) array_key_first($kit_tags);
			}
		}

		$total_synced = 0;
		$total_scanned = 0;
		$kit_skipped   = 0;
		$kit_remaining = -1;
		$last_sync_time = strtotime($last_sync_utc);

		if ('kit' === $provider && $kit) {
			$kit_quota     = $this->get_kit_quota_state($kit);
			$kit_remaining = $kit_quota['enforced'] ? (int) $kit_quota['remaining'] : -1;
		}

		foreach ($plugin_ids as $pid) {
			$users = $api->retrieve_users($pid, array('count' => 50, 'offset' => 0));
			if (empty($users) || ! is_array($users)) {
				continue;
			}

			foreach ($users as $user) {
				$u_arr = is_array($user) ? $user : (array) $user;
				$created = $u_arr['created'] ?? '';
				if (! empty($created) && strtotime($created) <= $last_sync_time) {
					// Reached checkpoint of users synced or exported previously!
					break;
				}

				$total_scanned++;
				$is_optin = $this->user_passes_consent($u_arr);
				$email = sanitize_email($u_arr['email'] ?? '');
				if (! $is_optin || empty($email)) {
					continue;
				}

				$fname   = sanitize_text_field($u_arr['first'] ?? ($u_arr['first_name'] ?? ''));
				$lname   = sanitize_text_field($u_arr['last'] ?? ($u_arr['last_name'] ?? ''));
				$user_id = (int) ($u_arr['id'] ?? 0);

				$plan_id = null;
				if ($user_id > 0) {
					$plan_id = $wpdb->get_var($wpdb->prepare(
						"SELECT plan_id FROM {$sub_table} WHERE user_id = %d AND plan_id > 0 ORDER BY id DESC LIMIT 1",
						$user_id
					));
					if (! $plan_id) {
						$plan_id = $wpdb->get_var($wpdb->prepare(
							"SELECT plan_id FROM {$lic_table} WHERE user_id = %d AND plan_id > 0 ORDER BY id DESC LIMIT 1",
							$user_id
						));
					}
				}

				$site_url = '';
				if ($user_id > 0) {
					$cached_site = get_transient('rl_fsbi_user_site_' . (int) $pid . '_' . (int) $user_id);
					if (false !== $cached_site && is_array($cached_site)) {
						$site_url = $cached_site['url'] ?? '';
					}
				}

				if ('kit' === $provider && $kit) {
					$assigned_tag_id = $default_kit_tag_id;
					if (! empty($plan_id)) {
						$mapped_raw = trim((string) $this->settings->get_option('rl_fsbi_kit_plan_tag_' . (int) $plan_id, ''));
						if (! empty($mapped_raw) && is_numeric($mapped_raw) && isset($kit_tags[(int) $mapped_raw])) {
							$assigned_tag_id = (int) $mapped_raw;
						}
					}
					if ($assigned_tag_id > 0) {
						if (0 === $kit_remaining) {
							$kit_skipped++;
							continue;
						}

						$res = $kit->upsert_subscriber($assigned_tag_id, $email, $fname, array(), $site_url);
						if (! is_wp_error($res)) {
							$total_synced++;
							if ($kit_remaining > 0) {
								$kit_remaining--;
							}
						}
					}
				} elseif ($mc) {
					$tags = array($default_tag_mc);
					$res = $mc->upsert_subscriber($list_id, $email, $fname, $lname, $tags, $site_url);
					if (! is_wp_error($res)) {
						$total_synced++;
					}
				}
			}
		}

		$now_utc = gmdate('Y-m-d H:i:s');
		update_option('rl_fsbi_newsletter_last_sync_utc', $now_utc);
		$cron_status = sprintf('Daily sync completed at %s UTC: %d new opt-in(s) synced.', $now_utc, $total_synced);
		if ($kit_skipped > 0) {
			$cron_status .= sprintf(' %d skipped: Kit subscriber limit (%s) reached.', $kit_skipped, number_format_i18n($this->get_kit_subscriber_limit()));
		}
		update_option('rl_fsbi_newsletter_cron_status', $cron_status);

		return array(
			'success'      => true,
			'total_synced' => $total_synced,
			'scanned'      => $total_scanned,
			'checkpoint'   => $now_utc,
		);
	}

	private function refresh_latest_freemius_data()
	{
		$developer_id = $this->settings->get_option('rl_fsbi_developer_id');
		$public_key   = $this->settings->get_option('rl_fsbi_public_key');
		$secret_key   = $this->settings->get_option('rl_fsbi_secret_key');

		if (! $developer_id || ! $public_key || ! $secret_key) {
			return array('error' => true, 'message' => esc_html__('Refresh skipped: missing API credentials in settings.', 'rl-freemius-bi'));
		}

		$api = new RL_FSBI_API($developer_id, $public_key, $secret_key);
		$repo = new RL_FSBI_Repository();
		$plugin_ids = $this->get_sync_plugin_ids($api->retrieve_plugins());

		if (empty($plugin_ids)) {
			return array('error' => true, 'message' => esc_html__('Refresh skipped: no plugin IDs available.', 'rl-freemius-bi'));
		}

		$saved = 0;
		$debug = array();
		foreach ($plugin_ids as $plugin_id) {
			$plugin_debug = array();
			$saved += $this->refresh_latest_payments_for_plugin((int) $plugin_id, $api, $repo, $plugin_debug);
			$debug[] = $plugin_debug;
			$subscriptions = $api->retrieve_subscriptions((int) $plugin_id, array('offset' => 0, 'count' => 10));
			if (! empty($subscriptions)) {
				$this->save_subscription_batch((int) $plugin_id, $subscriptions, $repo);
			}
		}

		$this->settings->update_option('rl_fsbi_last_sync_status', sprintf('Latest refresh completed: %d new payment(s).', $saved));
		$this->settings->update_option('rl_fsbi_last_sync_at_utc', gmdate('Y-m-d H:i:s'));

		return array('error' => false, 'saved_payments' => $saved, 'debug' => $debug);
	}

	/**
	 * Ensure the automatic Freemius sync event exists and runs hourly.
	 */
	public function ensure_hourly_sync_cron()
	{
		$hook = 'rl_fsbi_scheduled_sync';
		$timestamp = wp_next_scheduled($hook);
		$cron = _get_cron_array();
		$current_schedule = '';

		if ($timestamp && isset($cron[$timestamp][$hook]) && is_array($cron[$timestamp][$hook])) {
			$event = reset($cron[$timestamp][$hook]);
			$current_schedule = isset($event['schedule']) ? (string) $event['schedule'] : '';
		}

		if ($timestamp && 'hourly' === $current_schedule) {
			return;
		}

		if ($timestamp) {
			wp_clear_scheduled_hook($hook);
		}

		wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', $hook);
	}

	/**
	 * Sync data from Freemius API.
	 *
	 * @return bool Success status.
	 */
	private function sync_freemius_data()
	{
		$developer_id = $this->settings->get_option('rl_fsbi_developer_id');
		$public_key   = $this->settings->get_option('rl_fsbi_public_key');
		$secret_key   = $this->settings->get_option('rl_fsbi_secret_key');

		if (! $developer_id || ! $public_key || ! $secret_key) {
			$this->settings->update_option('rl_fsbi_last_sync_status', esc_html__('Sync failed: missing API credentials in settings.', 'rl-freemius-bi'));
			$this->settings->update_option('rl_fsbi_last_sync_at_utc', gmdate('Y-m-d H:i:s'));
			return false;
		}

		$api = new RL_FSBI_API($developer_id, $public_key, $secret_key);
		$repo = new RL_FSBI_Repository();
		$selected_plugin_ids = $this->get_selected_plugin_ids();

		// Fetch plugins
		$plugins = $api->retrieve_plugins();
		$plugin_ids = $this->get_sync_plugin_ids($plugins);
		if (empty($plugin_ids)) {
			$this->settings->update_option('rl_fsbi_last_sync_status', esc_html__('Sync failed: no plugins returned by Freemius and no saved plugin selection is available.', 'rl-freemius-bi'));
			$this->settings->update_option('rl_fsbi_last_sync_at_utc', gmdate('Y-m-d H:i:s'));
			return false;
		}

		$processed_plugins = 0;
		foreach ($plugin_ids as $plugin_id) {

			$processed_plugins++;

			// Sync payments with pagination
			$this->sync_payments($plugin_id, $api, $repo);

			// Sync subscriptions with pagination
			$this->sync_subscriptions($plugin_id, $api, $repo);

			// Sync licenses with pagination
			$this->sync_licenses($plugin_id, $api, $repo);

			// Sync plans
			$this->sync_plans($plugin_id, $api, $repo);

			// Sync trials, uninstalled sites, and uninstall feedback
			$this->sync_trials($plugin_id, $api, $repo);
			$this->sync_installs($plugin_id, $api, $repo);
			$this->sync_coupons($plugin_id, $api);

			// Sync account balances/payout metadata
			$this->sync_balance($plugin_id, $developer_id, $api, $repo);
		}

		if (0 === $processed_plugins) {
			$this->settings->update_option('rl_fsbi_last_sync_status', esc_html__('Sync skipped: no selected plugins matched current discovery catalog.', 'rl-freemius-bi'));
			$this->settings->update_option('rl_fsbi_last_sync_at_utc', gmdate('Y-m-d H:i:s'));
			return false;
		}

		$this->settings->update_option(
			'rl_fsbi_last_sync_status',
			sprintf(
				/* translators: %d: number of synced plugins */
				esc_html__('Sync completed: %d plugin(s) processed.', 'rl-freemius-bi'),
				$processed_plugins
			)
		);
		$this->settings->update_option('rl_fsbi_last_sync_at_utc', gmdate('Y-m-d H:i:s'));

		// Retire every cached dashboard payload: the numbers behind them changed.
		update_option('rl_fsbi_cache_generation', time(), false);

		return true;
	}

	/**
	 * Sync payments with pagination.
	 *
	 * @param int                $plugin_id Plugin ID.
	 * @param RL_FSBI_API        $api API instance.
	 * @param RL_FSBI_Repository $repo Repository instance.
	 */
	private function sync_payments($plugin_id, $api, $repo)
	{
		$offset = 0;
		$count  = 50;

		while (true) {
			$payments = $api->retrieve_payments($plugin_id, array(
				'count'  => $count,
				'offset' => $offset,
			));

			if (empty($payments)) {
				break;
			}

			foreach ($payments as $payment) {
				if (! is_array($payment)) {
					continue;
				}

				$repo->upsert_payment($this->build_payment_row($plugin_id, $payment));
			}

			$offset += $count;
		}
	}

	/**
	 * Sync trials with pagination.
	 *
	 * @param int                $plugin_id Plugin ID.
	 * @param RL_FSBI_API        $api       API instance.
	 * @param RL_FSBI_Repository $repo      Repository instance.
	 * @return void
	 */
	private function sync_trials($plugin_id, $api, $repo)
	{
		$offset = 0;
		$count  = 50;

		while (true) {
			$trials = $api->retrieve_trials($plugin_id, array(
				'count'  => $count,
				'offset' => $offset,
			));

			if (empty($trials)) {
				break;
			}

			$this->save_trial_batch($plugin_id, $trials, $repo);
			$offset += $count;
		}
	}

	/**
	 * Sync uninstalled sites and their uninstall feedback.
	 *
	 * Installs are filtered to uninstalled sites because they are the only ones
	 * that carry churn signal, which keeps the call count bounded.
	 *
	 * @param int                $plugin_id Plugin ID.
	 * @param RL_FSBI_API        $api       API instance.
	 * @param RL_FSBI_Repository $repo      Repository instance.
	 * @return void
	 */
	/**
	 * Cache the plugin's coupon definitions.
	 *
	 * Usage and revenue come from payment rows; the definition (discount, cap,
	 * expiry) only exists on the coupon endpoint, so it is refreshed here and
	 * kept in an option rather than a new table.
	 *
	 * @param int             $plugin_id Plugin ID.
	 * @param RL_FSBI_API     $api       API client.
	 * @return void
	 */
	private function sync_coupons($plugin_id, $api)
	{
		$offset = 0;
		$count  = 50;
		$all    = array();

		while (true) {
			$batch = $api->retrieve_coupons($plugin_id, array('count' => $count, 'offset' => $offset));

			if (empty($batch) || ! is_array($batch)) {
				break;
			}

			foreach ($batch as $coupon) {
				if (! is_array($coupon) || empty($coupon['id'])) {
					continue;
				}

				$all[] = array(
					'id'                => (int) $coupon['id'],
					'code'              => isset($coupon['code']) ? sanitize_text_field((string) $coupon['code']) : '',
					'discount'          => isset($coupon['discount']) ? (float) $coupon['discount'] : 0.0,
					'discount_type'     => isset($coupon['discount_type']) ? sanitize_text_field((string) $coupon['discount_type']) : '',
					'redemptions'       => isset($coupon['redemptions']) ? (int) $coupon['redemptions'] : 0,
					'redemptions_limit' => isset($coupon['redemptions_limit']) && '' !== $coupon['redemptions_limit'] ? (int) $coupon['redemptions_limit'] : null,
					'end_date'          => ! empty($coupon['end_date']) ? sanitize_text_field((string) $coupon['end_date']) : '',
					'is_active'         => ! empty($coupon['is_active']) ? 1 : 0,
				);
			}

			if (count($batch) < $count) {
				break;
			}

			$offset += $count;
		}

		update_option('rl_fsbi_coupons_' . (int) $plugin_id, $all, false);
	}

	private function sync_installs($plugin_id, $api, $repo)
	{
		$offset = 0;
		$count  = 50;

		while (true) {
			// No filter: filtering to 'uninstalled' stored only sites that had
			// already gone, so every install looked uninstalled and the active
			// population was invisible. The unfiltered collection carries both
			// states and each row says which it is.
			$installs = $api->retrieve_installs(
				$plugin_id,
				0,
				array(
					'count'  => $count,
					'offset' => $offset,
				)
			);

			if (empty($installs)) {
				break;
			}

			$this->save_install_batch($plugin_id, $installs, $repo);
			$offset += $count;
		}

		$pending = $repo->get_installs_missing_uninstall_reason($plugin_id, 100);

		if (! empty($pending)) {
			$this->sync_install_uninstalls($plugin_id, $api, $repo, $pending);
		}
	}

	/**
	 * Sync subscriptions with pagination.
	 *
	 * @param int                $plugin_id Plugin ID.
	 * @param RL_FSBI_API        $api API instance.
	 * @param RL_FSBI_Repository $repo Repository instance.
	 */
	private function sync_subscriptions($plugin_id, $api, $repo)
	{
		$offset = 0;
		$count  = 50;

		while (true) {
			$subscriptions = $api->retrieve_subscriptions($plugin_id, array(
				'count'  => $count,
				'offset' => $offset,
			));

			if (empty($subscriptions)) {
				break;
			}

			foreach ($subscriptions as $subscription) {
				if (! is_array($subscription)) {
					continue;
				}

				$repo->upsert_subscription($this->build_subscription_row($plugin_id, $subscription));
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
	public function sync_licenses($plugin_id, $api, $repo)
	{
		$offset = 0;
		$count  = 50;

		while (true) {
			$licenses = $api->retrieve_licenses($plugin_id, array(
				'count'  => $count,
				'offset' => $offset,
			));

			if (empty($licenses)) {
				break;
			}

			foreach ($licenses as $license) {
				if (! is_array($license)) {
					continue;
				}

				$repo->upsert_license($this->map_license_row($plugin_id, $license));
			}

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
	private function sync_plans($plugin_id, $api, $repo)
	{
		$plans = $api->retrieve_plans($plugin_id);

		foreach ($plans as $plan) {
			if (! is_array($plan)) {
				continue;
			}

			$pricing = isset($plan['pricing']) && is_array($plan['pricing']) ? $plan['pricing'] : array();

			$repo->upsert_plan(
				array(
					'plugin_id'     => $plugin_id,
					'plan_id'       => (int) ($plan['id'] ?? 0),
					'plan_name'     => sanitize_text_field($plan['title'] ?? ''),
					'description'   => isset($plan['description']) ? wp_kses_post($plan['description']) : null,
					'price_usd'     => isset($pricing['usd']) ? (float) $pricing['usd'] : null,
					'price_eur'     => isset($pricing['eur']) ? (float) $pricing['eur'] : null,
					'price_gbp'     => isset($pricing['gbp']) ? (float) $pricing['gbp'] : null,
					'billing_cycle' => isset($plan['billing_cycle']) ? sanitize_text_field($plan['billing_cycle']) : null,
					'trial_days'    => isset($plan['trial_period']) ? (int) $plan['trial_period'] : null,
					'features'      => wp_json_encode($plan),
				)
			);
		}
	}

	/**
	 * Sync payout/balance data per app.
	 *
	 * @param int                $plugin_id Plugin ID.
	 * @param int|string         $developer_id Developer ID.
	 * @param RL_FSBI_API        $api API instance.
	 * @param RL_FSBI_Repository $repo Repository instance.
	 */
	private function sync_balance($plugin_id, $developer_id, $api, $repo)
	{
		$balance = $api->retrieve_balance($plugin_id);

		if (! is_array($balance) || empty($balance)) {
			return;
		}

		$repo->upsert_balance(
			array(
				'app_id'            => $plugin_id,
				'developer_id'      => (int) $developer_id,
				'processed_balance' => isset($balance['processed_balance']) ? (float) $balance['processed_balance'] : 0,
				'pending_balance'   => isset($balance['pending_balance']) ? (float) $balance['pending_balance'] : 0,
				'commission_rate'   => isset($balance['commission_rate']) ? (float) $balance['commission_rate'] : 0,
				'commission_amount' => isset($balance['commission_amount']) ? (float) $balance['commission_amount'] : 0,
				'net_payout'        => isset($balance['net_payout']) ? (float) $balance['net_payout'] : 0,
				'currency'          => sanitize_text_field($balance['currency'] ?? 'USD'),
			)
		);
	}

	/**
	 * Initialize sync state for batched syncing.
	 *
	 * @param int[] $plugin_ids Plugins to process.
	 * @return array
	 */
	private function init_sync_state($plugin_ids)
	{
		return array(
			'sync_id'        => wp_generate_password(20, false, false),
			'plugin_ids'     => array_values(array_map('intval', $plugin_ids)),
			'plugin_cursor'  => 0,
			'stage'          => 'payments',
			'offsets'        => array(
				'payments'      => 0,
				'subscriptions' => 0,
				'licenses'      => 0,
				'trials'        => 0,
				'installs'      => 0,
			),
			'batch_size'     => 50,
			'processed'      => array(
				'payments'      => 0,
				'subscriptions' => 0,
				'licenses'      => 0,
				'plans'         => 0,
				'balances'      => 0,
				'trials'        => 0,
				'installs'      => 0,
				'uninstalls'    => 0,
			),
			'processed_apps' => 0,
			'progress_ticks' => 0,
			'display_progress' => 0,
			'started_at_utc' => gmdate('Y-m-d H:i:s'),
		);
	}

	/**
	 * Run a bounded number of API calls and return progress.
	 *
	 * @param array             $state State payload.
	 * @param RL_FSBI_API       $api API client.
	 * @param RL_FSBI_Repository $repo Repository.
	 * @return array
	 */
	private function process_sync_batch($state, $api, $repo)
	{
		$state['plugin_ids'] = isset($state['plugin_ids']) && is_array($state['plugin_ids']) ? array_values(array_map('intval', $state['plugin_ids'])) : array();
		$state['plugin_cursor'] = isset($state['plugin_cursor']) ? (int) $state['plugin_cursor'] : 0;
		$state['stage'] = isset($state['stage']) ? (string) $state['stage'] : 'payments';
		$state['offsets'] = isset($state['offsets']) && is_array($state['offsets']) ? $state['offsets'] : array();
		$state['batch_size'] = isset($state['batch_size']) ? max(10, min(50, (int) $state['batch_size'])) : 50;
		$state['processed'] = isset($state['processed']) && is_array($state['processed']) ? $state['processed'] : array();
		$state['progress_ticks'] = isset($state['progress_ticks']) ? (int) $state['progress_ticks'] : 0;
		$state['display_progress'] = isset($state['display_progress']) ? (int) $state['display_progress'] : 0;
		$sync_id = isset($state['sync_id']) ? sanitize_key((string) $state['sync_id']) : '';

		if ($sync_id && get_transient('rl_fsbi_cancel_' . $sync_id)) {
			delete_transient('rl_fsbi_cancel_' . $sync_id);
			return array(
				'done'    => true,
				'canceled' => true,
				'message' => esc_html__('Sync canceled. Data saved before cancellation was preserved.', 'rl-freemius-bi'),
			);
		}

		if (empty($state['plugin_ids'])) {
			return array(
				'done'    => true,
				'error'   => true,
				'message' => esc_html__('Sync state has no plugin IDs to process.', 'rl-freemius-bi'),
			);
		}

		$max_api_calls = 3;
		$api_calls = 0;

		while ($api_calls < $max_api_calls) {
			if ($state['plugin_cursor'] >= count($state['plugin_ids'])) {
				$this->settings->update_option(
					'rl_fsbi_last_sync_status',
					sprintf(
						/* translators: %d: number of synced plugins */
						esc_html__('Sync completed: %d plugin(s) processed in batches.', 'rl-freemius-bi'),
						(int) $state['processed_apps']
					)
				);
				$this->settings->update_option('rl_fsbi_last_sync_at_utc', gmdate('Y-m-d H:i:s'));

				return array(
					'done'           => true,
					'error'          => false,
					'message'        => esc_html__('Batch sync completed.', 'rl-freemius-bi'),
					'progress_label' => esc_html__('Completed 100%', 'rl-freemius-bi'),
					'state'          => $state,
				);
			}

			$plugin_id = (int) $state['plugin_ids'][$state['plugin_cursor']];
			$stage = (string) $state['stage'];
			$batch_size = (int) $state['batch_size'];

			if ('payments' === $stage) {
				$offset = isset($state['offsets']['payments']) ? (int) $state['offsets']['payments'] : 0;
				$payments = $api->retrieve_payments($plugin_id, array('offset' => $offset, 'count' => $batch_size));
				$api_calls++;
				$state['progress_ticks']++;

				if (empty($payments)) {
					$state['stage'] = 'subscriptions';
					$state['offsets']['subscriptions'] = 0;
					continue;
				}

				$saved_count = $this->save_payment_batch($plugin_id, $payments, $repo);
				$state['processed']['payments'] = (int) ($state['processed']['payments'] ?? 0) + $saved_count;
				$state['offsets']['payments'] = $offset + $batch_size;
				continue;
			}

			if ('subscriptions' === $stage) {
				$offset = isset($state['offsets']['subscriptions']) ? (int) $state['offsets']['subscriptions'] : 0;
				$subscriptions = $api->retrieve_subscriptions($plugin_id, array('offset' => $offset, 'count' => $batch_size));
				$api_calls++;
				$state['progress_ticks']++;

				if (empty($subscriptions)) {
					$state['stage'] = 'licenses';
					$state['offsets']['licenses'] = 0;
					continue;
				}

				$saved_count = $this->save_subscription_batch($plugin_id, $subscriptions, $repo);
				$state['processed']['subscriptions'] = (int) ($state['processed']['subscriptions'] ?? 0) + $saved_count;
				$state['offsets']['subscriptions'] = $offset + $batch_size;
				continue;
			}

			if ('licenses' === $stage) {
				$offset = isset($state['offsets']['licenses']) ? (int) $state['offsets']['licenses'] : 0;
				$licenses = $api->retrieve_licenses($plugin_id, array('offset' => $offset, 'count' => $batch_size));
				$api_calls++;
				$state['progress_ticks']++;

				if (empty($licenses)) {
					$state['stage'] = 'plans';
					continue;
				}

				$saved_count = $this->save_license_batch($plugin_id, $licenses, $repo);
				$state['processed']['licenses'] = (int) ($state['processed']['licenses'] ?? 0) + $saved_count;
				$state['offsets']['licenses'] = $offset + $batch_size;
				continue;
			}

			if ('plans' === $stage) {
				$plans = $api->retrieve_plans($plugin_id);
				$api_calls++;
				$state['progress_ticks']++;

				$saved_count = $this->save_plan_batch($plugin_id, $plans, $repo);
				$state['processed']['plans'] = (int) ($state['processed']['plans'] ?? 0) + $saved_count;
				$state['stage'] = 'trials';
				$state['offsets']['trials'] = 0;
				continue;
			}

			if ('trials' === $stage) {
				$offset = isset($state['offsets']['trials']) ? (int) $state['offsets']['trials'] : 0;
				$trials = $api->retrieve_trials($plugin_id, array('offset' => $offset, 'count' => $batch_size));
				$api_calls++;
				$state['progress_ticks']++;

				if (empty($trials)) {
					$state['stage'] = 'installs';
					$state['offsets']['installs'] = 0;
					continue;
				}

				$saved_count = $this->save_trial_batch($plugin_id, $trials, $repo);
				$state['processed']['trials'] = (int) ($state['processed']['trials'] ?? 0) + $saved_count;
				$state['offsets']['trials'] = $offset + $batch_size;
				continue;
			}

			if ('installs' === $stage) {
				$offset = isset($state['offsets']['installs']) ? (int) $state['offsets']['installs'] : 0;
				$installs = $api->retrieve_installs(
					$plugin_id,
					0,
					array(
						'offset' => $offset,
						'count'  => $batch_size,
						'filter' => 'uninstalled',
					)
				);
				$api_calls++;
				$state['progress_ticks']++;

				if (empty($installs)) {
					$state['stage'] = 'uninstalls';
					continue;
				}

				$saved_count = $this->save_install_batch($plugin_id, $installs, $repo);
				$state['processed']['installs'] = (int) ($state['processed']['installs'] ?? 0) + $saved_count;
				$state['offsets']['installs'] = $offset + $batch_size;
				continue;
			}

			if ('uninstalls' === $stage) {
				// Resolve the work list once per plugin so the stage always advances,
				// even when a particular write fails.
				if (! isset($state['pending_uninstalls']) || ! is_array($state['pending_uninstalls'])) {
					$state['pending_uninstalls'] = $repo->get_installs_missing_uninstall_reason($plugin_id, 25);
					$state['uninstall_index']    = 0;
				}

				$uninstall_index = (int) ($state['uninstall_index'] ?? 0);

				if ($uninstall_index >= count($state['pending_uninstalls'])) {
					$state['stage']              = 'balance';
					$state['pending_uninstalls'] = array();
					$state['uninstall_index']    = 0;
					continue;
				}

				$install_id  = (int) $state['pending_uninstalls'][ $uninstall_index ];
				$saved_count = $this->sync_install_uninstalls($plugin_id, $api, $repo, array( $install_id ));

				$api_calls++;
				$state['progress_ticks']++;
				$state['uninstall_index'] = $uninstall_index + 1;
				$state['processed']['uninstalls'] = (int) ($state['processed']['uninstalls'] ?? 0) + $saved_count;
				continue;
			}

			if ('balance' === $stage) {
				$developer_id = (int) $this->settings->get_option('rl_fsbi_developer_id');
				$balance = $api->retrieve_balance($plugin_id);
				$api_calls++;
				$state['progress_ticks']++;

				if (is_array($balance) && ! empty($balance)) {
					$repo->upsert_balance(
						array(
							'app_id'            => $plugin_id,
							'developer_id'      => $developer_id,
							'processed_balance' => isset($balance['processed_balance']) ? (float) $balance['processed_balance'] : 0,
							'pending_balance'   => isset($balance['pending_balance']) ? (float) $balance['pending_balance'] : 0,
							'commission_rate'   => isset($balance['commission_rate']) ? (float) $balance['commission_rate'] : 0,
							'commission_amount' => isset($balance['commission_amount']) ? (float) $balance['commission_amount'] : 0,
							'net_payout'        => isset($balance['net_payout']) ? (float) $balance['net_payout'] : 0,
							'currency'          => sanitize_text_field($balance['currency'] ?? 'USD'),
						)
					);
					$state['processed']['balances'] = (int) ($state['processed']['balances'] ?? 0) + 1;
				}

				$state['plugin_cursor']++;
				$state['processed_apps'] = (int) ($state['processed_apps'] ?? 0) + 1;
				$state['stage'] = 'payments';
				$state['offsets']['payments'] = 0;
				$state['offsets']['subscriptions'] = 0;
				$state['offsets']['licenses'] = 0;
				$state['offsets']['trials'] = 0;
				$state['offsets']['installs'] = 0;
				$state['pending_uninstalls'] = array();
				$state['uninstall_index'] = 0;
				continue;
			}

			return array(
				'done'    => true,
				'error'   => true,
				'message' => esc_html__('Sync failed: invalid stage in sync state.', 'rl-freemius-bi'),
			);
		}

		$total_plugins = max(1, count($state['plugin_ids']));
		$current_plugin = min($total_plugins, $state['plugin_cursor'] + 1);
		$stage_order = array('payments', 'subscriptions', 'licenses', 'plans', 'trials', 'installs', 'uninstalls', 'balance');
		$stage_index = array_search((string) $state['stage'], $stage_order, true);
		$stage_index = false === $stage_index ? 0 : (int) $stage_index;

		$stage_progress = 0.0;
		if ('payments' === $state['stage']) {
			$stage_progress = min(0.95, ((int) ($state['offsets']['payments'] ?? 0) / max(1, $state['batch_size'])) / 20);
		} elseif ('subscriptions' === $state['stage']) {
			$stage_progress = min(0.95, ((int) ($state['offsets']['subscriptions'] ?? 0) / max(1, $state['batch_size'])) / 20);
		} elseif ('licenses' === $state['stage']) {
			$stage_progress = min(0.95, ((int) ($state['offsets']['licenses'] ?? 0) / max(1, $state['batch_size'])) / 20);
		} else {
			$stage_progress = 0.5;
		}

		$fraction = ((float) $state['plugin_cursor'] + (($stage_index + $stage_progress) / count($stage_order))) / $total_plugins;
		$computed_percent = (int) floor($fraction * 100);
		$tick_floor = min(99, (int) floor((int) $state['progress_ticks'] * 0.7));
		$percent = max((int) $state['display_progress'], max($computed_percent, $tick_floor));
		$percent = min(99, max(1, $percent));
		$state['display_progress'] = $percent;

		$this->settings->update_option(
			'rl_fsbi_last_sync_status',
			sprintf(
				/* translators: 1: stage name, 2: plugin number, 3: plugin total */
				esc_html__('Sync in progress (%1$s) - plugin %2$d of %3$d', 'rl-freemius-bi'),
				(string) $state['stage'],
				$current_plugin,
				$total_plugins
			)
		);

		return array(
			'done'           => false,
			'error'          => false,
			'message'        => esc_html__('Batch processed.', 'rl-freemius-bi'),
			'progress_label' => sprintf(
				'Syncing %1$d%% (%2$s) - app %3$d/%4$d - p:%5$d s:%6$d l:%7$d',
				$percent,
				(string) $state['stage'],
				$current_plugin,
				$total_plugins,
				(int) ($state['processed']['payments'] ?? 0),
				(int) ($state['processed']['subscriptions'] ?? 0),
				(int) ($state['processed']['licenses'] ?? 0)
			),
			'state'          => $state,
		);
	}

	/**
	 * Save a payments API page.
	 *
	 * @param int                $plugin_id Plugin ID.
	 * @param array              $payments Payment records.
	 * @param RL_FSBI_Repository $repo Repository.
	 * @return int
	 */
	private function refresh_latest_payments_for_plugin($plugin_id, $api, $repo, &$debug = array())
	{
		$last_payment_id = $this->get_latest_payment_id($plugin_id);
		$offset = 0;
		$count = 25;
		$new_payments = array();
		$found_existing = false;
		$remote_seen = array();
		$scanned = 0;

		while (! $found_existing) {
			$payments = $api->retrieve_payments($plugin_id, array('offset' => $offset, 'count' => $count));

			if (empty($payments)) {
				break;
			}

			foreach ($payments as $payment) {
				$payment_id = (int) ($payment['id'] ?? 0);
				$scanned++;
				if (count($remote_seen) < 5) {
					$remote_seen[] = array(
						'id'       => $payment_id,
						'created'  => isset($payment['created']) ? (string) $payment['created'] : '',
						'gross'    => isset($payment['gross']) ? (float) $payment['gross'] : 0.0,
						'currency' => isset($payment['currency']) ? (string) $payment['currency'] : '',
					);
				}
				if ($last_payment_id > 0 && $payment_id === $last_payment_id) {
					$found_existing = true;
					break;
				}
				$new_payments[] = $payment;
			}

			if (count($payments) < $count) {
				break;
			}

			$offset += $count;
		}

		if (empty($new_payments)) {
			$debug = array(
				'plugin_id'             => $plugin_id,
				'latest_local_payment'  => $last_payment_id,
				'remote_first_payments' => $remote_seen,
				'scanned_remote_count'  => $scanned,
				'found_existing'        => $found_existing,
				'new_remote_count'      => 0,
				'saved_count'           => 0,
			);
			return 0;
		}

		$saved_count = $this->save_payment_batch($plugin_id, array_reverse($new_payments), $repo);
		$debug = array(
			'plugin_id'             => $plugin_id,
			'latest_local_payment'  => $last_payment_id,
			'remote_first_payments' => $remote_seen,
			'scanned_remote_count'  => $scanned,
			'found_existing'        => $found_existing,
			'new_remote_count'      => count($new_payments),
			'saved_count'           => $saved_count,
		);

		return $saved_count;
	}

	private function get_latest_payment_id($plugin_id)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'rl_fsbi_payments';
		$payment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT payment_id FROM {$table} WHERE plugin_id = %d ORDER BY transaction_date DESC, payment_id DESC LIMIT 1",
				$plugin_id
			)
		);

		return $payment_id ? (int) $payment_id : 0;
	}

	private function save_payment_batch($plugin_id, $payments, $repo)
	{
		$saved = 0;

		foreach ($payments as $payment) {
			if (! is_array($payment)) {
				continue;
			}

			$repo->upsert_payment($this->build_payment_row($plugin_id, $payment));

			$saved++;
		}

		return $saved;
	}

	/**
	 * Save a subscriptions API page.
	 */
	private function save_subscription_batch($plugin_id, $subscriptions, $repo)
	{
		$saved = 0;

		foreach ($subscriptions as $subscription) {
			if (! is_array($subscription)) {
				continue;
			}

			$repo->upsert_subscription($this->build_subscription_row($plugin_id, $subscription));

			$saved++;
		}

		return $saved;
	}

	/**
	 * Save a licenses API page.
	 */
	private function save_license_batch($plugin_id, $licenses, $repo)
	{
		$saved = 0;

		foreach ($licenses as $license) {
			if (! is_array($license)) {
				continue;
			}

			$repo->upsert_license($this->map_license_row($plugin_id, $license));

			$saved++;
		}

		return $saved;
	}

	/**
	 * Map one Freemius licence payload onto the local row.
	 *
	 * The licences endpoint returns no `status` key at all, so storing
	 * `$license['status'] ?? 'valid'` labelled every licence "valid". Derive the
	 * status from the fields the API actually returns.
	 *
	 * @param int   $plugin_id Plugin ID.
	 * @param array $license   Licence payload.
	 * @return array
	 */
	private function map_license_row($plugin_id, $license)
	{
		$expires_at = isset($license['expiration']) && '' !== (string) $license['expiration']
			? sanitize_text_field((string) $license['expiration'])
			: null;

		$expires_ts = $expires_at ? strtotime($expires_at) : false;

		if (false !== $expires_ts && $expires_ts < time()) {
			$status = 'expired';
		} elseif (! empty($license['is_cancelled'])) {
			$status = 'cancelled';
		} else {
			$status = 'active';
		}

		return array(
			'plugin_id'     => (int) $plugin_id,
			'license_id'    => (int) ($license['id'] ?? 0),
			'user_id'       => (int) ($license['user_id'] ?? 0),
			'plan_id'       => isset($license['plan_id']) ? (int) $license['plan_id'] : null,
			'pricing_id'    => isset($license['pricing_id']) ? (int) $license['pricing_id'] : null,
			'license_key'   => isset($license['secret_key']) ? sanitize_text_field((string) $license['secret_key']) : null,
			'status'        => $status,
			'quota_sites'   => isset($license['quota']) ? (int) $license['quota'] : null,
			'activated'     => ! empty($license['activated']) ? (int) $license['activated'] : 0,
			'is_cancelled'  => ! empty($license['is_cancelled']) ? 1 : 0,
			'expires_at'    => $expires_at,
			'fs_created_at' => ! empty($license['created']) ? sanitize_text_field((string) $license['created']) : null,
			'fs_updated_at' => ! empty($license['updated']) ? sanitize_text_field((string) $license['updated']) : null,
			// Bumped on every sync so `updated_since` can be served from the local
			// row timestamp, which is what the REST contract documents.
			'updated_at'    => current_time('mysql'),
		);
	}

	/**
	 * Save plan collection.
	 */
	private function save_plan_batch($plugin_id, $plans, $repo)
	{
		$saved = 0;

		if (! is_array($plans)) {
			return 0;
		}

		foreach ($plans as $plan) {
			if (! is_array($plan)) {
				continue;
			}

			$pricing = isset($plan['pricing']) && is_array($plan['pricing']) ? $plan['pricing'] : array();
			$repo->upsert_plan(
				array(
					'plugin_id'     => $plugin_id,
					'plan_id'       => (int) ($plan['id'] ?? 0),
					'plan_name'     => sanitize_text_field($plan['title'] ?? ''),
					'description'   => isset($plan['description']) ? wp_kses_post($plan['description']) : null,
					'price_usd'     => isset($pricing['usd']) ? (float) $pricing['usd'] : null,
					'price_eur'     => isset($pricing['eur']) ? (float) $pricing['eur'] : null,
					'price_gbp'     => isset($pricing['gbp']) ? (float) $pricing['gbp'] : null,
					'billing_cycle' => isset($plan['billing_cycle']) ? sanitize_text_field($plan['billing_cycle']) : null,
					'trial_days'    => isset($plan['trial_period']) ? (int) $plan['trial_period'] : null,
					'features'      => wp_json_encode($plan),
				)
			);
			$saved++;
		}

		return $saved;
	}

	/**
	 * Map a raw Freemius payment payload onto the payments table columns.
	 *
	 * Freemius returns far more than the dashboard used to persist, so the
	 * extra keys are promoted to real columns instead of living only inside
	 * the metadata blob.
	 *
	 * @param int   $plugin_id Plugin ID.
	 * @param array $payment   Raw payment payload.
	 * @return array
	 */
	private function build_payment_row($plugin_id, array $payment)
	{
		$status = strtolower((string) ($payment['status'] ?? ''));
		$is_refund = isset($payment['refund_id'])
			|| (float) ($payment['gross'] ?? 0) < 0
			|| (float) ($payment['net'] ?? 0) < 0
			|| 'refunded' === $status;

		return array(
			'plugin_id'        => (int) $plugin_id,
			'payment_id'       => (int) ($payment['id'] ?? 0),
			'user_id'          => (int) ($payment['user_id'] ?? 0),
			'subscription_id'  => isset($payment['subscription_id']) ? (int) $payment['subscription_id'] : null,
			'transaction_date' => $this->resolve_freemius_payment_transaction_date($payment),
			'gross'            => (float) ($payment['gross'] ?? 0),
			'net'              => (float) ($payment['net'] ?? 0),
			'fee'              => (float) ($payment['fee'] ?? 0),
			'currency'         => sanitize_text_field($payment['currency'] ?? 'USD'),
			'payment_method'   => isset($payment['payment_method']) ? sanitize_text_field($payment['payment_method']) : null,
			'status'           => '' !== $status ? sanitize_text_field($status) : 'completed',
			'country_code'     => isset($payment['country_code']) ? sanitize_text_field($payment['country_code']) : null,
			'is_refund'        => $is_refund ? 1 : 0,
			'refund_id'        => isset($payment['refund_id']) ? (int) $payment['refund_id'] : null,
			'metadata'         => wp_json_encode($payment),
			'payment_type'     => isset($payment['type']) && '' !== (string) $payment['type'] ? sanitize_text_field((string) $payment['type']) : null,
			'is_renewal'       => ! empty($payment['is_renewal']) ? 1 : 0,
			'gateway'          => isset($payment['gateway']) ? sanitize_text_field((string) $payment['gateway']) : null,
			'gateway_fee'      => isset($payment['gateway_fee']) ? (float) $payment['gateway_fee'] : null,
			'vat'              => isset($payment['vat']) ? (float) $payment['vat'] : null,
			'coupon_id'        => isset($payment['coupon_id']) ? (int) $payment['coupon_id'] : null,
			'plan_id'          => isset($payment['plan_id']) ? (int) $payment['plan_id'] : null,
			'pricing_id'       => isset($payment['pricing_id']) ? (int) $payment['pricing_id'] : null,
			'license_id'       => isset($payment['license_id']) ? (int) $payment['license_id'] : null,
			'install_id'       => isset($payment['install_id']) ? (int) $payment['install_id'] : null,
			'bound_payment_id' => isset($payment['bound_payment_id']) ? (int) $payment['bound_payment_id'] : null,
			'refund_reason'    => isset($payment['refund_reason']) ? sanitize_text_field((string) $payment['refund_reason']) : null,
		);
	}

	/**
	 * Map a raw Freemius subscription payload onto the subscriptions columns.
	 *
	 * @param int   $plugin_id    Plugin ID.
	 * @param array $subscription Raw subscription payload.
	 * @return array
	 */
	private function build_subscription_row($plugin_id, array $subscription)
	{
		return array(
			'plugin_id'           => (int) $plugin_id,
			'subscription_id'     => (int) ($subscription['id'] ?? 0),
			'user_id'             => (int) ($subscription['user_id'] ?? 0),
			'plan_id'             => isset($subscription['plan_id']) ? (int) $subscription['plan_id'] : null,
			'status'              => sanitize_text_field($subscription['status'] ?? 'active'),
			'billing_cycle'       => isset($subscription['billing_cycle']) ? sanitize_text_field($subscription['billing_cycle']) : null,
			'trial_ends'          => isset($subscription['trial_ends']) ? $this->format_api_datetime($subscription['trial_ends']) : null,
			'next_renewal'        => isset($subscription['next_billing']) ? $this->format_api_datetime($subscription['next_billing']) : (isset($subscription['next_payment']) ? $this->format_api_datetime($subscription['next_payment']) : null),
			'expires_at'          => isset($subscription['end_date']) ? $this->format_api_datetime($subscription['end_date']) : null,
			'outstanding_balance' => (float) ($subscription['outstanding_balance'] ?? 0),
			'currency'            => sanitize_text_field($subscription['currency'] ?? 'USD'),
			'metadata'            => wp_json_encode($subscription),
			'failed_payments'     => isset($subscription['failed_payments']) ? (int) $subscription['failed_payments'] : 0,
			'canceled_at'         => ! empty($subscription['canceled_at']) ? $this->format_api_datetime($subscription['canceled_at']) : null,
			'amount_per_cycle'    => isset($subscription['amount_per_cycle']) ? (float) $subscription['amount_per_cycle'] : null,
			'renewal_amount'      => isset($subscription['renewal_amount']) ? (float) $subscription['renewal_amount'] : null,
			'initial_amount'      => isset($subscription['initial_amount']) ? (float) $subscription['initial_amount'] : null,
			'total_gross'         => isset($subscription['total_gross']) ? (float) $subscription['total_gross'] : null,
			'tax_rate'            => isset($subscription['tax_rate']) ? (float) $subscription['tax_rate'] : null,
			'pricing_id'          => isset($subscription['pricing_id']) ? (int) $subscription['pricing_id'] : null,
			'license_id'          => isset($subscription['license_id']) ? (int) $subscription['license_id'] : null,
			'install_id'          => isset($subscription['install_id']) ? (int) $subscription['install_id'] : null,
		);
	}

	/**
	 * Normalize an API timestamp into a MySQL DATETIME string.
	 *
	 * @param mixed $value Raw API value.
	 * @return string|null
	 */
	private function format_api_datetime($value)
	{
		if (empty($value)) {
			return null;
		}

		$timestamp = $this->normalize_freemius_date_timestamp($value);

		if (false === $timestamp || null === $timestamp) {
			$timestamp = strtotime((string) $value);
		}

		return false === $timestamp ? null : gmdate('Y-m-d H:i:s', (int) $timestamp);
	}

	/**
	 * Save a trials API page.
	 *
	 * @param int                $plugin_id Plugin ID.
	 * @param array              $trials    Trial records.
	 * @param RL_FSBI_Repository $repo      Repository.
	 * @return int
	 */
	private function save_trial_batch($plugin_id, $trials, $repo)
	{
		$saved = 0;

		if (! is_array($trials)) {
			return 0;
		}

		foreach ($trials as $trial) {
			if (! is_array($trial)) {
				continue;
			}

			$trial_id = (int) ($trial['id'] ?? 0);
			if ($trial_id <= 0) {
				continue;
			}

			$repo->upsert_trial(
				array(
					'plugin_id'           => (int) $plugin_id,
					'trial_id'            => $trial_id,
					'user_id'             => (int) ($trial['user_id'] ?? 0),
					'payment_id'          => isset($trial['payment_id']) ? (int) $trial['payment_id'] : null,
					'subscription_id'     => isset($trial['subscription_id']) ? (int) $trial['subscription_id'] : null,
					'license_id'          => isset($trial['license_id']) ? (int) $trial['license_id'] : null,
					'started_at'          => ! empty($trial['created']) ? $this->format_api_datetime($trial['created']) : null,
					'trial_ends_at'       => ! empty($trial['trial_ends_at']) ? $this->format_api_datetime($trial['trial_ends_at']) : null,
					'with_payment_method' => ! empty($trial['with_payment_method']) ? 1 : 0,
					'canceled_at'         => ! empty($trial['canceled_at']) ? $this->format_api_datetime($trial['canceled_at']) : null,
					'converted_at'        => ! empty($trial['converted_at']) ? $this->format_api_datetime($trial['converted_at']) : null,
					'environment'         => isset($trial['environment']) ? sanitize_text_field((string) $trial['environment']) : null,
					'metadata'            => wp_json_encode($trial),
				)
			);

			$saved++;
		}

		return $saved;
	}

	/**
	 * Save an installs API page.
	 *
	 * @param int                $plugin_id Plugin ID.
	 * @param array              $installs  Install records.
	 * @param RL_FSBI_Repository $repo      Repository.
	 * @return int
	 */
	private function save_install_batch($plugin_id, $installs, $repo)
	{
		$saved = 0;

		if (! is_array($installs)) {
			return 0;
		}

		foreach ($installs as $install) {
			if (! is_array($install)) {
				continue;
			}

			$install_id = (int) ($install['id'] ?? 0);
			if ($install_id <= 0) {
				continue;
			}

			$repo->upsert_install(
				array(
					'plugin_id'      => (int) $plugin_id,
					'install_id'     => $install_id,
					'user_id'        => (int) ($install['user_id'] ?? 0),
					'license_id'     => isset($install['license_id']) ? (int) $install['license_id'] : null,
					'plan_id'        => isset($install['plan_id']) ? (int) $install['plan_id'] : null,
					'site_id'        => isset($install['site_id']) ? sanitize_text_field((string) $install['site_id']) : null,
					'title'          => isset($install['title']) ? sanitize_text_field((string) $install['title']) : null,
					'url'            => isset($install['url']) ? esc_url_raw((string) $install['url']) : null,
					'version'        => isset($install['version']) ? sanitize_text_field((string) $install['version']) : null,
					'is_active'      => ! empty($install['is_active']) ? 1 : 0,
					'is_premium'     => ! empty($install['is_premium']) ? 1 : 0,
					'is_uninstalled' => ! empty($install['is_uninstalled']) ? 1 : 0,
					'last_seen_at'   => ! empty($install['last_seen_at']) ? $this->format_api_datetime($install['last_seen_at']) : null,
					'environment'    => isset($install['environment']) ? sanitize_text_field((string) $install['environment']) : null,
					'metadata'       => wp_json_encode($install),
				)
			);

			$saved++;
		}

		return $saved;
	}

	/**
	 * Fetch and store uninstall feedback for installs that are still missing it.
	 *
	 * An empty result is stored as reason 0 so the same install is not
	 * requested again on every batch.
	 *
	 * @param int                $plugin_id   Plugin ID.
	 * @param RL_FSBI_API        $api         API client.
	 * @param RL_FSBI_Repository $repo        Repository.
	 * @param int[]              $install_ids Install IDs to resolve.
	 * @return int
	 */
	private function sync_install_uninstalls($plugin_id, $api, $repo, array $install_ids)
	{
		$saved = 0;

		foreach ($install_ids as $install_id) {
			$details = $api->retrieve_install_uninstall($plugin_id, $install_id);

			if (isset($details['uninstall']) && is_array($details['uninstall'])) {
				$details = $details['uninstall'];
			}

			$repo->upsert_uninstall(
				array(
					'plugin_id'      => (int) $plugin_id,
					'install_id'     => (int) $install_id,
					'uninstall_id'   => isset($details['id']) ? (int) $details['id'] : 0,
					'site_id'        => isset($details['site_id']) ? sanitize_text_field((string) $details['site_id']) : null,
					'reason_id'      => isset($details['reason_id']) ? (int) $details['reason_id'] : 0,
					'reason_label'   => isset($details['reason']) && is_string($details['reason']) ? sanitize_text_field($details['reason']) : null,
					'reason_info'    => isset($details['reason_info']) ? sanitize_textarea_field((string) $details['reason_info']) : null,
					'uninstalled_at' => ! empty($details['created']) ? $this->format_api_datetime($details['created']) : null,
					'metadata'       => is_array($details) ? wp_json_encode($details) : null,
				)
			);

			$saved++;
		}

		return $saved;
	}

	/**
	 * Configured Kit subscriber limit. 0 means unlimited.
	 *
	 * @return int
	 */
	private function get_kit_subscriber_limit()
	{
		$raw = trim((string) $this->settings->get_option('rl_fsbi_kit_subscriber_limit', ''));

		if ('' === $raw) {
			return 0;
		}

		return max(0, (int) $raw);
	}

	/**
	 * Which subscribers the prune tool may remove.
	 *
	 * @return string 'all' or 'tagged'.
	 */
	private function get_kit_prune_scope()
	{
		$scope = trim((string) $this->settings->get_option('rl_fsbi_kit_prune_scope', 'tagged'));

		return 'all' === $scope ? 'all' : 'tagged';
	}

	/**
	 * Subscriber IDs the client asked to remove in this chunk.
	 *
	 * @return int[] Unique, positive IDs in the order supplied.
	 */
	private function parse_requested_kit_ids()
	{
		if (! isset($_POST['subscriber_ids'])) {
			return array();
		}

		$raw = wp_unslash($_POST['subscriber_ids']);
		$ids = is_array($raw) ? $raw : json_decode((string) $raw, true);

		if (! is_array($ids)) {
			return array();
		}

		$clean = array();

		foreach ($ids as $id) {
			$id = (int) $id;

			if ($id > 0) {
				$clean[$id] = $id;
			}
		}

		return array_values($clean);
	}

	/**
	 * Current quota position against the configured Kit limit.
	 *
	 * @param RL_FSBI_Kit $kit Kit client.
	 * @return array{limit:int,total:int,remaining:int,enforced:bool}
	 */
	private function get_kit_quota_state($kit)
	{
		$limit = $this->get_kit_subscriber_limit();

		if ($limit <= 0) {
			return array(
				'limit'     => 0,
				'total'     => 0,
				'remaining' => -1,
				'enforced'  => false,
			);
		}

		$total = (int) $kit->get_total_subscribers();

		return array(
			'limit'     => $limit,
			'total'     => $total,
			'remaining' => max(0, $limit - $total),
			'enforced'  => true,
		);
	}

	/**
	 * Cached quota position used by the admin notice.
	 *
	 * @param bool $allow_refresh Query Kit when the cache is empty.
	 * @return array{limit:int,total:int,checked_at:int}|null
	 */
	private function get_kit_quota_snapshot($allow_refresh = true)
	{
		$cached = get_transient(self::KIT_QUOTA_TRANSIENT);

		if (is_array($cached) && isset($cached['limit'], $cached['total'])) {
			return $cached;
		}

		if (! $allow_refresh) {
			return null;
		}

		$limit   = $this->get_kit_subscriber_limit();
		$kit_key = trim((string) $this->settings->get_option('rl_fsbi_kit_api_key', ''));

		if ($limit <= 0 || '' === $kit_key) {
			return null;
		}

		if (false !== get_transient(self::KIT_QUOTA_LOCK)) {
			return null;
		}

		set_transient(self::KIT_QUOTA_LOCK, 1, 5 * MINUTE_IN_SECONDS);

		// Short timeout: a slow Kit API must not stall the admin page.
		$kit   = new RL_FSBI_Kit($kit_key);
		$total = (int) $kit->get_total_subscribers(8);

		delete_transient(self::KIT_QUOTA_LOCK);

		if ($total <= 0) {
			return null;
		}

		return $this->store_kit_quota_snapshot($limit, $total);
	}

	/**
	 * Persist the quota position so later page loads render without an API call.
	 *
	 * @param int $limit Configured subscriber limit.
	 * @param int $total Active subscribers in Kit.
	 * @return array{limit:int,total:int,checked_at:int}
	 */
	private function store_kit_quota_snapshot($limit, $total)
	{
		$snapshot = array(
			'limit'      => max(0, (int) $limit),
			'total'      => max(0, (int) $total),
			'checked_at' => time(),
		);

		set_transient(self::KIT_QUOTA_TRANSIENT, $snapshot, 6 * HOUR_IN_SECONDS);

		return $snapshot;
	}

	/**
	 * Warn on every Freemius BI screen when Kit is over the configured limit.
	 */
	/**
	 * Warn when a non-numeric Developer ID was rejected on save.
	 *
	 * Stripping the digits out of an autofilled email used to save something
	 * plausible-looking and then fail silently on every API call, so the value
	 * is now refused and the reason is stated here.
	 *
	 * @return void
	 */
	/**
	 * Warn while verbose debug logging is switched on.
	 *
	 * Debug mode writes a line for every framework call, which is useful while
	 * troubleshooting and should not be left running on a live site.
	 *
	 * @return void
	 */
	/**
	 * Warn when the deployed copy is missing its bundled vendor files.
	 *
	 * A file-by-file upload that skips `assets/lib/` leaves a plugin that
	 * looks current — same version header, dashboard working — while the
	 * tooltip assets 404. The runtime already falls back to the framework copy,
	 * but the operator should know the upload was incomplete rather than find
	 * out from the browser console.
	 *
	 * @return void
	 */
	public function render_incomplete_upload_notice()
	{
		if (! current_user_can('manage_options') || ! $this->is_plugin_page()) {
			return;
		}

		$missing = array();
		foreach (array('tippy/tippy.umd.min.js', 'tippy/tippy.css', 'popper/popper.min.js') as $relative) {
			if (! file_exists(RL_FSBI_PLUGIN_DIR . 'assets/lib/' . $relative)) {
				$missing[] = $relative;
			}
		}

		if (empty($missing)) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <code>%s</code>. %s</p></div>',
			esc_html__('This upload is incomplete.', 'rl-freemius-bi'),
			esc_html__('Missing from assets/lib/:', 'rl-freemius-bi'),
			esc_html(implode(', ', $missing)),
			esc_html__('The settings framework copy is being used instead so tooltips keep working. Re-upload the plugin folder, including assets/lib/.', 'rl-freemius-bi')
		);
	}

	/**
	 * One-time "Setup detected" summary, with Accept and Re-detect.
	 *
	 * Read-only links rather than a form: the settings form must stay the only
	 * form on these pages.
	 *
	 * @return void
	 */
	public function render_detection_notice()
	{
		if (! current_user_can('manage_options') || ! $this->is_plugin_page()) {
			return;
		}

		$summary = $this->settings->get_option(RL_FSBI_Detection::OPTION_SUMMARY, array());
		$accepted = (string) $this->settings->get_option(RL_FSBI_Detection::OPTION_ACCEPTED, '');

		// It is a one-time screen: once accepted there is nothing left to decide,
		// and the values live in Settings.
		if ('' !== $accepted) {
			return;
		}

		$accept_url = wp_nonce_url(add_query_arg('action', 'rl_fsbi_detection_accept', admin_url('admin-post.php')), 'rl_fsbi_detection_accept');
		$redetect_url = wp_nonce_url(add_query_arg('action', 'rl_fsbi_detection_redetect', admin_url('admin-post.php')), 'rl_fsbi_detection_redetect');
		$edit_url = admin_url('admin.php?page=rl-freemius-bi-settings&tab=plugin_scope');

		if (! is_array($summary) || empty($summary)) {
			// Detection only ever ran on a first full sync, so an account that had
			// already synced before this version never produced a summary and never
			// saw this screen at all. Offer it instead of hiding it.
			if ('' === trim((string) $this->settings->get_option('rl_fsbi_developer_id', ''))) {
				return;
			}

			echo '<div class="notice notice-info rl-fsbi-detection-notice"><p><strong>' . esc_html__('Setup not detected yet', 'rl-freemius-bi') . '</strong> ';
			echo '<span class="description">' . esc_html__('The commission model, currencies, plans and WordPress.org slug can be read from your own Freemius data. Nothing is applied until you accept the result.', 'rl-freemius-bi') . '</span></p>';
			echo '<p><a href="' . esc_url($redetect_url) . '" class="button button-primary rl-fsbi-detection-run" data-detection-run="1">' . esc_html__('Run detection', 'rl-freemius-bi') . '</a> ';
			echo '<a href="' . esc_url($edit_url) . '" class="button">' . esc_html__('Set it manually', 'rl-freemius-bi') . '</a></p>';
			echo '<p class="rl-fsbi-detection-status" data-detection-status hidden><span class="spinner is-active" style="float:none;margin:0 8px 0 0"></span><span data-detection-text></span></p></div>';

			return;
		}

		$rows = array(
			__('Commission model', 'rl-freemius-bi') => (string) ($summary['model_label'] ?? '—'),
			__('Marginal rate', 'rl-freemius-bi') => number_format_i18n((float) ($summary['marginal_rate'] ?? 0), 2) . '%',
			__('Lifetime average', 'rl-freemius-bi') => number_format_i18n((float) ($summary['lifetime_average'] ?? 0), 2) . '%',
			__('Rate reported by Freemius', 'rl-freemius-bi') => number_format_i18n((float) ($summary['observed_rate'] ?? 0), 2) . '%',
			__('Confidence', 'rl-freemius-bi') => number_format_i18n((float) ($summary['confidence'] ?? 0), 1) . '%',
			__('Lifetime gross', 'rl-freemius-bi') => number_format_i18n((float) ($summary['lifetime_gross_usd'] ?? 0), 2) . ' USD',
			__('Currencies', 'rl-freemius-bi') => implode(', ', (array) ($summary['currencies'] ?? array())),
			__('Plans / billing cycles', 'rl-freemius-bi') => implode(', ', (array) ($summary['plans'] ?? array())) . ' / ' . implode(', ', (array) ($summary['cycles'] ?? array())),
			__('Active installs (Freemius)', 'rl-freemius-bi') => number_format_i18n((int) ($summary['active_installs'] ?? 0)),
		);

		foreach ((array) ($summary['wporg'] ?? array()) as $plugin_id => $probe) {
			$rows[sprintf(/* translators: %d: product id */ __('WordPress.org slug (product %d)', 'rl-freemius-bi'), (int) $plugin_id)] = ! empty($probe['found'])
				? sprintf('%s — %s', $probe['slug'], $probe['version'])
				: sprintf('%s — %s', $probe['slug'] ?: '—', $probe['reason'] ?? __('not found', 'rl-freemius-bi'));
		}

		$class = '' !== $accepted ? 'notice notice-success' : 'notice notice-info';
		echo '<div class="' . esc_attr($class) . ' rl-fsbi-detection-notice"><p><strong>' . esc_html__('Setup detected', 'rl-freemius-bi') . '</strong>';
		echo ' <span class="description">' . esc_html(sprintf(/* translators: %s: date */ __('Read on %s from your Freemius account and the WordPress.org API.', 'rl-freemius-bi'), (string) ($summary['ran_at'] ?? ''))) . '</span></p>';

		echo '<table class="widefat striped" style="max-width:760px;margin:0 0 8px"><tbody>';
		foreach ($rows as $label => $value) {
			echo '<tr><th scope="row" style="width:38%">' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
		}
		echo '</tbody></table>';

		if (! empty($summary['comparison'])) {
			echo '<p><strong>' . esc_html__('Model comparison', 'rl-freemius-bi') . '</strong></p><ul style="margin:0 0 8px 18px;list-style:disc">';
			foreach ((array) $summary['comparison'] as $key => $row) {
				echo '<li>' . esc_html(sprintf(
					/* translators: 1: model, 2: average, 3: difference */
					__('%1$s: lifetime average %2$s%%, %3$s points from the reported rate', 'rl-freemius-bi'),
					(string) ($row['label'] ?? $key),
					number_format_i18n((float) ($row['average'] ?? 0), 2),
					number_format_i18n((float) ($row['difference'] ?? 0), 2)
				)) . '</li>';
			}
			echo '</ul>';
		}

		if (! empty($summary['basis'])) {
			echo '<p><span class="dashicons dashicons-info" aria-hidden="true"></span> <strong>'
				. esc_html__('Assumed, not reported by Freemius:', 'rl-freemius-bi') . '</strong> '
				. esc_html((string) $summary['basis']) . '</p>';
		}

		foreach ((array) ($summary['warnings'] ?? array()) as $warning) {
			echo '<p><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html((string) $warning) . '</p>';
		}

		echo '<p>';
		if ('' === $accepted) {
			echo '<a class="button button-primary" href="' . esc_url($accept_url) . '">' . esc_html__('Accept detected values', 'rl-freemius-bi') . '</a> ';
		} else {
			echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true" style="color:#22c89b"></span> ' . esc_html__('Detected values accepted. Manual values always win.', 'rl-freemius-bi') . ' ';
		}
		echo '<a class="button" href="' . esc_url($edit_url) . '">' . esc_html__('Edit manually', 'rl-freemius-bi') . '</a> ';
		echo '<a class="button rl-fsbi-detection-run" href="' . esc_url($redetect_url) . '" data-detection-run="1">' . esc_html__('Re-detect', 'rl-freemius-bi') . '</a>';
		echo '<p class="rl-fsbi-detection-status" data-detection-status hidden><span class="spinner is-active" style="float:none;margin:0 8px 0 0"></span><span data-detection-text></span></p>';
		echo '</p></div>';
	}

	/**
	 * Apply the detected values the administrator accepted.
	 *
	 * Only empty settings are written: a value typed by hand is never replaced.
	 *
	 * @return void
	 */
	public function handle_detection_accept()
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'rl-freemius-bi'));
		}

		check_admin_referer('rl_fsbi_detection_accept');

		$summary = $this->settings->get_option(RL_FSBI_Detection::OPTION_SUMMARY, array());
		$rate = is_array($summary) ? (float) ($summary['marginal_rate'] ?? 0) : 0.0;

		$model = is_array($summary) ? (string) ($summary['model'] ?? '') : '';
		if ('' !== $model && '' === trim((string) $this->settings->get_option('rl_fsbi_commission_model', ''))) {
			$this->settings->update_option('rl_fsbi_commission_model', $model);
		}

		// Persist the rate for every model, legacy included. Leaving the setting
		// empty on a legacy account is what made the WordPress dashboard widget
		// read "revenue share not set" while the dashboard deducted the tiers:
		// both now read the same setting. Under the legacy model the per-payment
		// tiers still decide each row, so this value is the marginal rate that
		// the settings screen shows, not a flat charge on everything.
		if ($rate > 0 && '' === trim((string) $this->settings->get_option('rl_fsbi_fs_revenue_share', ''))) {
			$this->settings->update_option('rl_fsbi_fs_revenue_share', (string) $rate);
		}

		// The legacy plan carries no per-transaction fee, and an empty setting
		// would otherwise let the "current" default of EUR 0.45 apply.
		if ('legacy' === $model && '' === trim((string) $this->settings->get_option('rl_fsbi_fs_fixed_fee', ''))) {
			$this->settings->update_option('rl_fsbi_fs_fixed_fee', '0');
		}

		$this->settings->update_option(RL_FSBI_Detection::OPTION_ACCEPTED, current_time('mysql'));
		update_option('rl_fsbi_cache_generation', time(), false);

		wp_safe_redirect(add_query_arg('rl_fsbi_detected', 'accepted', wp_get_referer() ?: admin_url('admin.php?page=rl-freemius-bi')));
		exit;
	}

	/**
	 * Progress of a detection run, for the notice's live readout.
	 *
	 * @return void
	 */
	public function handle_detection_progress_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		$progress = get_option('rl_fsbi_detection_progress', array());
		$started = (int) get_option('rl_fsbi_detection_started', 0);

		wp_send_json_success(array(
			'stage' => is_array($progress) ? (string) ($progress['stage'] ?? '') : '',
			'plugin_id' => is_array($progress) ? (int) ($progress['plugin_id'] ?? 0) : 0,
			'fetched' => is_array($progress) ? (int) ($progress['fetched'] ?? 0) : 0,
			'elapsed' => $started > 0 ? max(0, time() - $started) : 0,
		));
	}

	/**
	 * Run detection again on demand.
	 *
	 * @return void
	 */
	public function handle_detection_redetect()
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'rl-freemius-bi'));
		}

		check_admin_referer('rl_fsbi_detection_redetect');

		update_option('rl_fsbi_detection_started', time(), false);
		update_option('rl_fsbi_detection_progress', array('stage' => 'starting', 'fetched' => 0, 'started' => time(), 'at' => time()), false);

		$detection = new RL_FSBI_Detection($this->settings);
		$detection->run($this->get_selected_plugin_ids());

		update_option('rl_fsbi_detection_progress', array('stage' => 'complete', 'at' => time()), false);

		wp_safe_redirect(add_query_arg('rl_fsbi_detected', 'redetected', wp_get_referer() ?: admin_url('admin.php?page=rl-freemius-bi')));
		exit;
	}

	public function render_debug_notice()
	{
		if (! current_user_can('manage_options') || ! $this->is_plugin_page()) {
			return;
		}

		if (! $this->settings->is_debug_mode()) {
			return;
		}

		$settings_url = admin_url('admin.php?page=' . $this->plugin_name . '-settings&tab=support');

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__('Debug mode is on.', 'rl-freemius-bi'),
			esc_html__('Verbose logging is written for every request, which slows the dashboard and fills the log quickly. Switch it off once you have finished troubleshooting.', 'rl-freemius-bi'),
			esc_url($settings_url),
			esc_html__('Open Support settings', 'rl-freemius-bi')
		);
	}

	public function render_credential_notice()
	{
		if (! current_user_can('manage_options') || ! $this->is_plugin_page()) {
			return;
		}

		if (! get_option('rl_fsbi_developer_id_invalid')) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__('Developer ID not saved.', 'rl-freemius-bi'),
			esc_html__('The Freemius Developer ID must be numbers only, so the previous value was kept. Check the field for an autofilled email address.', 'rl-freemius-bi')
		);
	}

	public function render_kit_quota_notice()
	{
		if (! current_user_can('manage_options') || ! $this->is_plugin_page()) {
			return;
		}

		$tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';
		if ('newsletter_settings' === $tab) {
			return;
		}

		$snapshot = $this->get_kit_quota_snapshot();
		if (! is_array($snapshot)) {
			return;
		}

		$limit  = $this->get_kit_subscriber_limit();
		$total  = (int) $snapshot['total'];
		$excess = max(0, $total - $limit);

		if ($limit <= 0 || $excess <= 0) {
			return;
		}

		$settings_url = admin_url('admin.php?page=' . $this->plugin_name . '-settings&tab=newsletter_settings#kit_config');

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__('Kit subscriber quota:', 'rl-freemius-bi'),
			esc_html(
				sprintf(
					/* translators: 1: excess count, 2: active subscriber total, 3: configured limit */
					__('%1$s subscriber(s) over the %3$s limit (%2$s active in Kit). New subscribers are no longer pushed until you prune.', 'rl-freemius-bi'),
					number_format_i18n($excess),
					number_format_i18n($total),
					number_format_i18n($limit)
				)
			),
			esc_url($settings_url),
			esc_html__('Review and prune now', 'rl-freemius-bi')
		);
	}

	/**
	 * Remaining subscriber capacity, or -1 when no limit is configured.
	 *
	 * @param RL_FSBI_Kit $kit Kit client.
	 * @return int
	 */
	private function resolve_kit_remaining($kit)
	{
		$quota = $this->get_kit_quota_state($kit);

		return $quota['enforced'] ? (int) $quota['remaining'] : -1;
	}

	/**
	 * Kit tag IDs this plugin assigns to subscribers.
	 *
	 * @return int[]
	 */
	private function get_kit_prune_tag_ids()
	{
		$tag_ids    = array();
		$kit_tags   = $this->settings->get_kit_tags();
		$candidates = array( trim((string) $this->settings->get_option('rl_fsbi_kit_default_tag', 'Freemius Opt-in')) );

		// Plan tag mappings are stored per plan ID (rl_fsbi_kit_plan_tag_{plan_id}),
		// so read them from the settings array rather than by tag ID.
		foreach ( (array) $this->settings->get_all_settings() as $setting_key => $setting_value ) {
			if (0 !== strpos((string) $setting_key, 'rl_fsbi_kit_plan_tag_')) {
				continue;
			}

			$mapped = trim((string) $setting_value);
			if ('' !== $mapped) {
				$candidates[] = $mapped;
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}

			if ( is_numeric( $candidate ) ) {
				$tag_ids[] = (int) $candidate;
				continue;
			}

			foreach ( (array) $kit_tags as $tag_id => $tag_name ) {
				if ( strcasecmp( (string) $tag_name, $candidate ) === 0 ) {
					$tag_ids[] = (int) $tag_id;
					break;
				}
			}
		}

		return array_values( array_unique( array_filter( $tag_ids ) ) );
	}

	/**
	 * Whether a Kit subscriber payload carries one of the given tags.
	 *
	 * @param array $subscriber Subscriber row from the Kit API.
	 * @param int[] $tag_ids    Eligible tag IDs.
	 * @return bool
	 */
	private function is_kit_subscriber_tagged($subscriber, $tag_ids)
	{
		if (empty($tag_ids) || ! is_array($subscriber) || empty($subscriber['tags']) || ! is_array($subscriber['tags'])) {
			return false;
		}

		foreach ($subscriber['tags'] as $tag) {
			$id = is_array($tag) ? (int) ($tag['id'] ?? 0) : (int) $tag;
			if (in_array($id, $tag_ids, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Collect the oldest active subscribers that carry this plugin's tags.
	 *
	 * Pages through Kit ordered by creation date ascending and stops as soon as
	 * enough candidates are found, so the work stays bounded.
	 *
	 * @param RL_FSBI_Kit $kit     Kit client.
	 * @param int         $wanted  How many candidates to return.
	 * @param int[]       $tag_ids Eligible tag IDs.
	 * @param int         $scanned Out: number of subscribers inspected.
	 * @return array{0:array,1:string} Candidates and an error message ('' on success).
	 */
	private function collect_oldest_kit_subscribers($kit, $wanted, $tag_ids, &$scanned = 0)
	{
		$scanned = 0;
		$wanted  = max(1, (int) $wanted);
		$scope   = $this->get_kit_prune_scope();

		if ('tagged' === $scope && empty($tag_ids)) {
			return array(array(), esc_html__('No Kit tags are configured for this plugin, so no subscriber was treated as removable. Switch the prune scope to "Any active subscriber" or configure the tags first.', 'rl-freemius-bi'));
		}

		$found    = array();
		$cursor   = '';
		$max_pages = 10;

		for ($page = 0; $page < $max_pages; $page++) {
			$query = array(
				'per_page'   => 500,
				'status'     => 'active',
				'sort_field' => 'created_at',
				'sort_order' => 'asc',
				'include'    => 'tags',
			);

			if ('' !== $cursor) {
				$query['after'] = $cursor;
			}

			$result = $kit->list_subscribers($query);

			if ('' !== (string) $result['error']) {
				return array(array(), $result['error']);
			}

			foreach ($result['subscribers'] as $subscriber) {
				$scanned++;

				if ('tagged' === $scope && ! $this->is_kit_subscriber_tagged($subscriber, $tag_ids)) {
					continue;
				}

				$found[] = array(
					'id'         => (int) ($subscriber['id'] ?? 0),
					'email'      => (string) ($subscriber['email_address'] ?? ''),
					'created_at' => (string) ($subscriber['created_at'] ?? ''),
				);

				if (count($found) >= $wanted) {
					return array($found, '');
				}
			}

			if (empty($result['pagination']['has_next_page']) || empty($result['pagination']['end_cursor'])) {
				break;
			}

			$cursor = (string) $result['pagination']['end_cursor'];
		}

		return array($found, '');
	}

	/**
	 * Preview how many subscribers are over the limit and who would go first.
	 */
	public function handle_kit_prune_preview_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		if (! $this->settings->get_option('rl_fsbi_kit_prune_enabled', false)) {
			wp_send_json_error(esc_html__('Removing Kit subscribers is switched off. Enable "Allow Removing Kit Subscribers" in Settings first.', 'rl-freemius-bi'));
		}

		$kit_key = trim((string) $this->settings->get_option('rl_fsbi_kit_api_key', ''));
		if ('' === $kit_key) {
			wp_send_json_error(esc_html__('Kit API key is not configured in Settings.', 'rl-freemius-bi'));
		}

		$limit  = $this->get_kit_subscriber_limit();
		$kit    = new RL_FSBI_Kit($kit_key);
		$total  = (int) $kit->get_total_subscribers();
		$excess = $limit > 0 ? max(0, $total - $limit) : 0;
		$batch  = 25;

		// The drain loop asks for a larger candidate list so it does not need a
		// round trip between every 25 removals.
		$requested_size = isset($_POST['preview_size']) ? (int) $_POST['preview_size'] : $batch;
		$preview_size   = min(max(1, $requested_size), 500);

		if ($total > 0) {
			$this->store_kit_quota_snapshot($limit, $total);
		}

		$tag_ids = $this->get_kit_prune_tag_ids();
		$scanned = 0;
		list($candidates, $error) = $this->collect_oldest_kit_subscribers($kit, $preview_size, $tag_ids, $scanned);

		if ('' !== $error) {
			wp_send_json_error($error);
		}

		$list = array();
		foreach ($candidates as $candidate) {
			$list[] = array(
				'id'         => (int) $candidate['id'],
				'email'      => $this->mask_email_for_display($candidate['email']),
				'created_at' => $candidate['created_at'],
			);
		}

		wp_send_json_success(
			array(
				'limit'          => $limit,
				'total'          => $total,
				'excess'         => $excess,
				'scope'          => $this->get_kit_prune_scope(),
				'batch_size'     => $batch,
				'preview_size'   => $preview_size,
				'candidate_count'=> count($list),
				'tag_count'      => count($tag_ids),
				'scanned'        => $scanned,
				'candidates'     => $list,
				'message'        => $limit <= 0
					? esc_html__('No limit configured, so nothing needs removing. Set a limit to see how many are over it.', 'rl-freemius-bi')
					: ( $excess > 0
						? sprintf(
							/* translators: 1: excess count, 2: subscriber limit */
							esc_html__('%1$s subscriber(s) over the %2$s limit.', 'rl-freemius-bi'),
							number_format_i18n($excess),
							number_format_i18n($limit)
						)
						: esc_html__('You are within the limit. Nothing needs removing.', 'rl-freemius-bi') ),
			)
		);
	}

	/**
	 * Unsubscribe one batch of the oldest tagged subscribers.
	 *
	 * Requires an explicit confirmation flag; it never runs unattended.
	 */
	public function handle_kit_prune_batch_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		if (! $this->settings->get_option('rl_fsbi_kit_prune_enabled', false)) {
			wp_send_json_error(esc_html__('Removing Kit subscribers is switched off. Enable "Allow Removing Kit Subscribers" in Settings first.', 'rl-freemius-bi'));
		}

		$confirm = isset($_POST['confirm']) ? sanitize_text_field(wp_unslash($_POST['confirm'])) : '';
		if ('yes' !== $confirm) {
			wp_send_json_error(esc_html__('Confirmation missing. No subscriber was changed.', 'rl-freemius-bi'));
		}

		$kit_key = trim((string) $this->settings->get_option('rl_fsbi_kit_api_key', ''));
		if ('' === $kit_key) {
			wp_send_json_error(esc_html__('Kit API key is not configured in Settings.', 'rl-freemius-bi'));
		}

		$limit = $this->get_kit_subscriber_limit();
		if ($limit <= 0) {
			wp_send_json_error(esc_html__('No subscriber limit is configured, so nothing is pruned.', 'rl-freemius-bi'));
		}

		$kit   = new RL_FSBI_Kit($kit_key);
		$total = (int) $kit->get_total_subscribers();
		$excess = max(0, $total - $limit);

		if ($excess <= 0) {
			wp_send_json_success(
				array(
					'done'      => true,
					'removed'   => 0,
					'failed'    => 0,
					'total'     => $total,
					'excess'    => 0,
					'message'   => esc_html__('You are within the limit. Nothing was removed.', 'rl-freemius-bi'),
				)
			);
		}

		// Small batches keep each request short; the UI drives the progress bar.
		$requested_batch = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 25;
		$batch   = min(max(1, $requested_batch), 25, $excess);
		$scanned = 0;
		$candidates = array();

		// The client sends the exact IDs it previewed. Re-deriving "the oldest N"
		// on every chunk re-selects the same people while Kit's list catches up,
		// so a 25-item run could touch only a handful of subscribers.
		$requested_ids = $this->parse_requested_kit_ids();

		if (! empty($requested_ids)) {
			foreach (array_slice($requested_ids, 0, $batch) as $requested_id) {
				$candidates[] = array(
					'id'         => (int) $requested_id,
					'email'      => '',
					'created_at' => '',
				);
			}
		} else {
			$tag_ids = $this->get_kit_prune_tag_ids();
			list($candidates, $error) = $this->collect_oldest_kit_subscribers($kit, $batch, $tag_ids, $scanned);

			if ('' !== $error) {
				wp_send_json_error($error);
			}
		}

		$removed = 0;
		$failed  = 0;
		$errors  = array();

		foreach ($candidates as $candidate) {
			$result = $kit->unsubscribe_subscriber($candidate['id']);

			if (is_wp_error($result)) {
				$failed++;
				$label    = '' !== (string) $candidate['email']
					? $this->mask_email_for_display($candidate['email'])
					: '#' . (int) $candidate['id'];
				$errors[] = $label . ': ' . $result->get_error_message();
				continue;
			}

			$removed++;
		}

		// Read Kit's own count instead of computing "total - removed": the derived
		// figure hides duplicates and lagging deletes, which is how a 25-item run
		// reported 25 removed while only a few subscribers actually changed.
		$remaining_total = (int) $kit->get_total_subscribers();

		if ($remaining_total <= 0) {
			$remaining_total = max(0, $total - $removed);
		}

		$this->store_kit_quota_snapshot($limit, $remaining_total);

		wp_send_json_success(
			array(
				'done'    => ($remaining_total <= $limit) || (0 === $removed && 0 === $failed),
				'removed' => $removed,
				'failed'  => $failed,
				'total'   => $remaining_total,
				'limit'   => $limit,
				'excess'  => max(0, $remaining_total - $limit),
				'errors'  => $errors,
				'message' => sprintf(
					/* translators: 1: removed count, 2: remaining excess */
					esc_html__('Removed %1$s subscriber(s). %2$s still over the limit.', 'rl-freemius-bi'),
					number_format_i18n($removed),
					number_format_i18n(max(0, $remaining_total - $limit))
				),
			)
		);
	}

	/**
	 * Mask an email for display so the audit output does not print full addresses.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function mask_email_for_display($email)
	{
		$email = (string) $email;
		$at    = strpos($email, '@');

		if (false === $at || $at < 1) {
			return '***';
		}

		$local  = substr($email, 0, $at);
		$domain = substr($email, $at);

		$visible = substr($local, 0, 1);

		return $visible . str_repeat('*', max(1, strlen($local) - 1)) . $domain;
	}

	/**
	 * Manual "run user backfill now" action from the Settings panel.
	 */
	public function handle_user_backfill_action()
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		check_admin_referer('rl_fsbi_user_backfill');

		$sync   = new RL_FSBI_User_Sync();
		$result = $sync->run_batch();

		set_transient(
			'rl_fsbi_backfill_notice',
			sprintf(
				/* translators: 1: pages fetched, 2: user rows written */
				esc_html__('Fetched %1$d page(s) and wrote %2$d user row(s).', 'rl-freemius-bi'),
				(int) $result['pages'],
				(int) $result['users']
			),
			60
		);

		$redirect = wp_get_referer();

		wp_safe_redirect($redirect ? $redirect : admin_url('admin.php?page=rl-freemius-bi-settings'));
		exit;
	}

	/**
	 * Whether the current admin page belongs to this plugin.
	 *
	 * @return bool True if on plugin admin page.
	 */
	private function is_plugin_page()
	{
		$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
		if (0 === strpos($page, 'rl-freemius-bi')) {
			return true;
		}

		$screen = get_current_screen();
		if (! $screen) {
			return false;
		}

		if (in_array($screen->base, array('toplevel_page_' . $this->plugin_name, $this->plugin_name . '_page_' . $this->plugin_name . '-settings'), true)) {
			return true;
		}

		return false !== strpos($screen->base, $this->plugin_name . '_page_rl-freemius-bi-plugin-');
	}

	/**
	 * Execute a one-time initial sweep after credentials discovery.
	 */
	public function maybe_run_initial_sweep()
	{
		$should_run = (bool) $this->settings->get_option('rl_fsbi_run_initial_sweep', false);
		if (! $should_run) {
			return;
		}

		// Clear the flag so it never runs synchronously on web request
		$this->settings->update_option('rl_fsbi_run_initial_sweep', false);
	}

	/**
	 * Get forced plugin context from current admin page slug.
	 *
	 * @return int
	 */
	private function get_forced_plugin_id_from_request()
	{
		$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
		if (1 === preg_match('/^rl-freemius-bi-plugin-(\d+)$/', $page, $matches)) {
			return (int) $matches[1];
		}

		return 0;
	}

	/**
	 * Get discovered plugins catalog [plugin_id => title].
	 *
	 * @return array
	 */
	private function get_plugins_catalog()
	{
		$catalog = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
		if (! is_array($catalog)) {
			return array();
		}

		$out = array();
		foreach ($catalog as $plugin_id => $plugin_data) {
			$plugin_id = (string) $plugin_id;
			if (is_array($plugin_data) && ! empty($plugin_data['title'])) {
				$out[$plugin_id] = sanitize_text_field((string) $plugin_data['title']) . ' (#' . $plugin_id . ')';
			} else {
				$out[$plugin_id] = 'Plugin #' . $plugin_id;
			}
		}

		return $out;
	}

	/**
	 * Get clean plugin title without (#ID).
	 *
	 * @param int|string $plugin_id Plugin ID.
	 * @return string Plugin title.
	 */
	public function get_plugin_clean_title($plugin_id)
	{
		$plugin_id = (string) $plugin_id;
		$catalog   = $this->settings->get_option('rl_fsbi_plugins_catalog', array());

		if (is_array($catalog) && ! empty($catalog[$plugin_id]['title'])) {
			return sanitize_text_field((string) $catalog[$plugin_id]['title']);
		}

		$formatted = $this->get_plugins_catalog();
		if (isset($formatted[$plugin_id])) {
			return preg_replace('/\s*\(#\d+\)$/', '', (string) $formatted[$plugin_id]);
		}

		return sprintf(esc_html__('Plugin #%d', 'rl-freemius-bi'), (int) $plugin_id);
	}

	/**
	 * Get latest deployed version for a plugin.
	 *
	 * Checks transient cache, catalog option, and queries Freemius API tags if needed.
	 *
	 * @param int|string $plugin_id Plugin ID.
	 * @return string Version string or empty string if not found.
	 */
	public function get_plugin_latest_version($plugin_id)
	{
		$plugin_id = (int) $plugin_id;
		if ($plugin_id <= 0) {
			return '';
		}

		// Key is versioned: bumping it discards values cached by older, buggy
		// lookups (which could pin an outdated release for 12 hours).
		$transient_key = 'rl_fsbi_plugin_ver_v2_' . $plugin_id;
		$cached = get_transient($transient_key);
		if (false !== $cached && '' !== (string) $cached) {
			return (string) $cached;
		}

		$catalog = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
		$catalog_version = '';
		if (is_array($catalog) && ! empty($catalog[(string) $plugin_id]['version'])) {
			$catalog_version = sanitize_text_field((string) $catalog[(string) $plugin_id]['version']);
		}

		$developer_id = $this->settings->get_option('rl_fsbi_developer_id');
		$public_key   = $this->settings->get_option('rl_fsbi_public_key');
		$secret_key   = $this->settings->get_option('rl_fsbi_secret_key');

		// Ask Freemius first: the stored catalog only refreshes on this path, so
		// trusting it up front kept an old release on screen indefinitely.
		if ($developer_id && $public_key && $secret_key) {
			$api  = new RL_FSBI_API($developer_id, $public_key, $secret_key);
			$tags = $api->retrieve_plugin_tags($plugin_id);

			if (! empty($tags) && is_array($tags)) {
				// Tags arrive in arbitrary order; pick the highest semantic
				// version instead of the first entry in the list.
				$version = '';
				foreach ($tags as $tag) {
					$candidate = is_array($tag) ? ($tag['version'] ?? '') : ($tag->version ?? '');

					if (! is_scalar($candidate)) {
						continue;
					}

					$candidate = trim((string) $candidate);

					if ('' !== $candidate && ('' === $version || version_compare($candidate, $version, '>'))) {
						$version = $candidate;
					}
				}

				if ('' !== $version) {
					$version = sanitize_text_field($version);
					set_transient($transient_key, $version, 12 * HOUR_IN_SECONDS);

					if (is_array($catalog) && isset($catalog[(string) $plugin_id])) {
						$catalog[(string) $plugin_id]['version'] = $version;
						$this->settings->update_option('rl_fsbi_plugins_catalog', $catalog);
					}

					return $version;
				}
			}
		}

		if ('' !== $catalog_version) {
			set_transient($transient_key, $catalog_version, 12 * HOUR_IN_SECONDS);
			return $catalog_version;
		}

		// Fallback: check installed plugin files if slug matches
		if (is_array($catalog) && ! empty($catalog[(string) $plugin_id]['slug'])) {
			$slug = (string) $catalog[(string) $plugin_id]['slug'];
			if (! function_exists('get_plugins')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed = get_plugins();
			foreach ($installed as $plugin_file => $plugin_meta) {
				if ((strpos($plugin_file, $slug) === 0 || strpos($plugin_file, '/' . $slug) !== false) && ! empty($plugin_meta['Version'])) {
					$version = sanitize_text_field((string) $plugin_meta['Version']);
					set_transient($transient_key, $version, 12 * HOUR_IN_SECONDS);
					return $version;
				}
			}
		}

		// Cache empty result for 1 hour to prevent excessive remote requests
		set_transient($transient_key, '', HOUR_IN_SECONDS);
		return '';
	}

	/**
	 * Resolve plugin IDs to sync, falling back to saved configuration when discovery fails.
	 *
	 * @param array $plugins Latest Freemius plugin discovery response.
	 * @return int[]
	 */
	private function get_sync_plugin_ids($plugins)
	{
		$selected_ids = $this->get_selected_plugin_ids();
		$plugin_ids = array();

		if (! empty($plugins)) {
			$this->store_plugins_catalog_from_api($plugins);

			foreach ($plugins as $plugin) {
				$plugin_id = (int) (is_array($plugin) ? ($plugin['id'] ?? 0) : ($plugin->id ?? 0));
				if ($plugin_id <= 0) {
					continue;
				}
				if (! empty($selected_ids) && ! in_array($plugin_id, $selected_ids, true)) {
					continue;
				}
				$plugin_ids[] = $plugin_id;
			}
		}

		if (empty($plugin_ids) && ! empty($selected_ids)) {
			$plugin_ids = $selected_ids;
		}

		if (empty($plugin_ids)) {
			$catalog = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
			if (is_array($catalog)) {
				$plugin_ids = array_map('intval', array_keys($catalog));
			}
		}

		$plugin_ids = array_filter(array_map('intval', $plugin_ids), function ($plugin_id) {
			return $plugin_id > 0;
		});

		return array_values(array_unique($plugin_ids));
	}

	/**
	 * Get selected plugin IDs from settings.
	 *
	 * @return int[]
	 */
	private function get_selected_plugin_ids()
	{
		$selected = $this->settings->get_option('rl_fsbi_selected_plugins', array());
		if (! is_array($selected)) {
			return array();
		}

		$selected = array_map('intval', $selected);
		$selected = array_values(array_filter($selected, function ($plugin_id) {
			return $plugin_id > 0;
		}));

		return array_values(array_unique($selected));
	}

	/**
	 * Persist plugin catalog from latest API response.
	 *
	 * @param array $plugins Freemius plugins response.
	 */
	private function store_plugins_catalog_from_api($plugins)
	{
		$catalog = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
		if (! is_array($catalog)) {
			$catalog = array();
		}

		foreach ($plugins as $plugin) {
			$plugin_id = (int) (is_array($plugin) ? ($plugin['id'] ?? 0) : ($plugin->id ?? 0));
			if ($plugin_id <= 0) {
				continue;
			}

			$title = is_array($plugin) ? ($plugin['title'] ?? '') : ($plugin->title ?? '');
			$slug  = is_array($plugin) ? ($plugin['slug'] ?? '') : ($plugin->slug ?? '');
			$version = is_array($plugin)
				? ($plugin['version'] ?? $plugin['latest_version'] ?? '')
				: ($plugin->version ?? $plugin->latest_version ?? '');

			$existing_version = ! empty($catalog[(string) $plugin_id]['version']) ? (string) $catalog[(string) $plugin_id]['version'] : '';

			$catalog[(string) $plugin_id] = array(
				'id'      => $plugin_id,
				// Freemius reports the live install count on the product itself;
				// counting rows in the installs table gives 0 active / thousands
				// uninstalled, which describes records, not installs.
				'active_installs' => isset($plugin['active_installs_count'])
					? (int) $plugin['active_installs_count']
					: (isset($plugin->active_installs_count) ? (int) $plugin->active_installs_count : 0),
				// All-time installs, which is a different figure from the active
				// count and from the number of rows the install crawl reached.
				'installs_count' => isset($plugin['installs_count'])
					? (int) $plugin['installs_count']
					: (isset($plugin->installs_count) ? (int) $plugin->installs_count : 0),
				'premium_installs' => isset($plugin['premium_installs_count'])
					? (int) $plugin['premium_installs_count']
					: (isset($plugin->premium_installs_count) ? (int) $plugin->premium_installs_count : 0),
				'title'   => sanitize_text_field((string) $title),
				'slug'    => sanitize_key((string) $slug),
				'version' => ! empty($version) ? sanitize_text_field((string) $version) : $existing_version,
			);
		}

		if (! empty($catalog)) {
			$this->settings->update_option('rl_fsbi_plugins_catalog', $catalog);
		}
	}

	/**
	 * Get configured Freemius API client instance.
	 *
	 * @return RL_FSBI_API|null
	 */
	private function get_api_client()
	{
		$developer_id = $this->settings->get_option('rl_fsbi_developer_id');
		$public_key   = $this->settings->get_option('rl_fsbi_public_key');
		$secret_key   = $this->settings->get_option('rl_fsbi_secret_key');

		if (! $developer_id || ! $public_key || ! $secret_key) {
			return null;
		}

		return new RL_FSBI_API($developer_id, $public_key, $secret_key);
	}

	/**
	 * Handle AJAX request for marketing opt-ins.
	 */
	public function handle_get_marketing_optins_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		$api = $this->get_api_client();
		if (! $api) {
			wp_send_json_error(esc_html__('API client not configured.', 'rl-freemius-bi'));
		}

		$plugin_id_raw = isset($_POST['plugin_id']) ? sanitize_text_field(wp_unslash($_POST['plugin_id'])) : 'all';
		$plugin_id     = 'all' === $plugin_id_raw ? 0 : max(0, (int) $plugin_id_raw);

		$offset       = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
		$count        = isset($_POST['count']) ? max(0, (int) $_POST['count']) : 0;
		$plugin_index = isset($_POST['plugin_index']) ? max(0, (int) $_POST['plugin_index']) : 0;

		$plugins_to_check = array();
		if ($plugin_id > 0) {
			$plugins_to_check[] = $plugin_id;
		} else {
			$catalog = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
			if (is_array($catalog) && ! empty($catalog)) {
				$plugins_to_check = array_values(array_filter(array_map('intval', array_keys($catalog))));
			}
		}

		if (empty($plugins_to_check) || ! isset($plugins_to_check[$plugin_index])) {
			wp_send_json_success(array(
				'count'        => $count,
				'offset'       => 0,
				'plugin_index' => 0,
				'done'         => true,
			));
		}

		$current_plugin_id = $plugins_to_check[$plugin_index];
		$batch_size        = 50;

		$users = $api->retrieve_users($current_plugin_id, array(
			'count'  => $batch_size,
			'offset' => $offset,
		));

		if (empty($users) || ! is_array($users)) {
			// Move to next plugin
			$next_index = $plugin_index + 1;
			$done       = ! isset($plugins_to_check[$next_index]);

			if ($done) {
				$cache_key = 'rl_fsbi_optins_count_' . ($plugin_id > 0 ? (int) $plugin_id : 'all');
				update_option($cache_key, $count);
				update_option($cache_key . '_updated', current_time('mysql'));
			}

			wp_send_json_success(array(
				'count'        => $count,
				'offset'       => 0,
				'plugin_index' => $next_index,
				'done'         => $done,
			));
		}

		$fetched_count = count($users);

		foreach ($users as $user) {
			$is_optin = false;
			if (is_array($user)) {
				$is_optin = $this->user_passes_consent($user);
			} elseif (is_object($user)) {
				$is_optin = $this->user_passes_consent($user);
			}

			if ($is_optin) {
				$count++;
			}
		}

		if ($fetched_count < $batch_size) {
			$next_index = $plugin_index + 1;
			$done       = ! isset($plugins_to_check[$next_index]);

			if ($done) {
				$cache_key = 'rl_fsbi_optins_count_' . ($plugin_id > 0 ? (int) $plugin_id : 'all');
				update_option($cache_key, $count);
				update_option($cache_key . '_updated', current_time('mysql'));
			}

			wp_send_json_success(array(
				'count'        => $count,
				'offset'       => 0,
				'plugin_index' => $next_index,
				'done'         => $done,
			));
		}

		wp_send_json_success(array(
			'count'        => $count,
			'offset'       => $offset + $fetched_count,
			'plugin_index' => $plugin_index,
			'done'         => false,
		));
	}

	/**
	 * Handle AJAX request for dashboard data.
	 */
	public function handle_get_dashboard_data_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		$repo = new RL_FSBI_Repository();

		$plugin_id_raw = isset($_POST['plugin_id']) ? sanitize_text_field(wp_unslash($_POST['plugin_id'])) : 'all';
		$currency_raw  = isset($_POST['currency']) ? sanitize_text_field(wp_unslash($_POST['currency'])) : 'all';
		$plugin_id     = 'all' === $plugin_id_raw ? 0 : max(0, (int) $plugin_id_raw);
		$currency      = 'all' === strtolower($currency_raw) ? null : strtoupper($currency_raw);
		$plan_id_raw   = isset($_POST['plan_id']) ? sanitize_text_field(wp_unslash($_POST['plan_id'])) : 'all';
		$plan_filter   = ('all' === $plan_id_raw || '' === $plan_id_raw) ? 0 : max(0, (int) $plan_id_raw);

		$today = current_time('Y-m-d');
		$current_month_end = wp_date('Y-m-t', current_time('timestamp'));
		$start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : current_time('Y-m-01');
		$end_date   = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : $current_month_end;

		if (empty($start_date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
			$start_date = current_time('Y-m-01');
		}

		if (empty($end_date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
			$end_date = $today;
		}

		if (strtotime($end_date) < strtotime($start_date)) {
			$end_date = $start_date;
		}

		// A tab asks for its own slice. 'all' keeps the previous behaviour for any
		// caller that predates sections.
		$section_raw = isset($_POST['section']) ? sanitize_text_field(wp_unslash($_POST['section'])) : 'all';
		$sections    = array_values(array_unique(array_filter(array_map('sanitize_key', explode(',', $section_raw)))));
		if (empty($sections) || in_array('all', $sections, true)) {
			$sections = array('all');
		}

		// Which optional blocks this slice needs. Each one is skipped outright
		// rather than computed and discarded.
		$needs = static function ($key) use ($sections) {
			return in_array('all', $sections, true) || in_array($key, $sections, true);
		};

		// The assembled payload is the expensive part: several passes over every
		// payment in the period, each needing an FX rate lookup. It only changes
		// when the sync writes new rows, and the generation counter is bumped
		// there, so the cache cannot serve stale figures after an import. The
		// section list is part of the key, so each slice caches on its own.
		$payload_cache_key = 'rl_fsbi_dash_' . md5(implode('|', array(
			$plugin_id,
			(string) $currency,
			$plan_filter,
			$start_date,
			$end_date,
			implode(',', $sections),
			(int) get_option('rl_fsbi_cache_generation', 1),
			// Included so a code update cannot serve a payload assembled by the
			// previous version, which may not contain the newest keys.
			defined('RL_FSBI_VERSION') ? RL_FSBI_VERSION : '',
		)));

		$cached_payload = get_transient($payload_cache_key);
		if (is_array($cached_payload) && ! empty($cached_payload)) {
			$cached_payload['from_cache'] = true;
			$cached_payload['payments']   = array();
			wp_send_json_success($cached_payload);
		}

		$payments = $repo->get_payments($plugin_id, $start_date, $end_date, $currency);
		$gross_revenue = $repo->calculate_gross_revenue($plugin_id, $currency, $start_date, $end_date);
		$net_revenue = $repo->calculate_net_revenue($plugin_id, $currency, $start_date, $end_date);
		$active_subscriptions = $repo->get_active_subscriptions_count($plugin_id, $currency);

		$period_days = max(1, (int) ceil((strtotime($end_date) - strtotime($start_date)) / DAY_IN_SECONDS) + 1);
		$previous_end = wp_date('Y-m-d', strtotime($start_date . ' -1 day'));
		$previous_start = wp_date('Y-m-d', strtotime($previous_end . ' -' . ($period_days - 1) . ' days'));

		// The comparison must be built the same way as the figure it compares
		// against: per-payment net, converted at the transaction day's rate. Read
		// straight from the stored net column instead and a product whose net is
		// not populated reports zero, so every period looks like the first one.
		$previous_payments = $repo->get_payments($plugin_id, $previous_start, $previous_end, $currency);

		$annual_start = wp_date('Y-m-01', strtotime('-11 months', strtotime($end_date)));
		$annual_payments = $repo->get_payments($plugin_id, $annual_start, $end_date, $currency);
		$subscriptions = $this->get_subscriptions_for_dashboard($plugin_id, $currency);
		$balances = $this->get_balances_for_dashboard($plugin_id, $currency);

		// Narrow the row sets rather than the SQL, so every figure downstream
		// (blocks, charts, table) narrows together instead of drifting apart.
		if ($plan_filter > 0) {
			$plan_match = static function ($row) use ($plan_filter) {
				return (int) ($row->plan_id ?? 0) === $plan_filter;
			};

			$payments             = array_values(array_filter($payments, $plan_match));
			$annual_payments      = array_values(array_filter($annual_payments, $plan_match));
			$previous_payments    = array_values(array_filter($previous_payments, $plan_match));
			$subscriptions        = array_values(array_filter($subscriptions, $plan_match));

			$active_subscriptions = count(array_filter($subscriptions, static function ($sub) {
				return in_array(strtolower((string) ($sub->status ?? '')), array('active', 'paid', 'trialing', 'trial_ended'), true);
			}));
		}

		$data = $this->build_dashboard_payload(
			$payments,
			$annual_payments,
			$subscriptions,
			$balances,
			$start_date,
			$end_date,
			$gross_revenue,
			$net_revenue,
			$active_subscriptions,
			$previous_payments,
			$currency
		);

		// Only the customers work list renders payment rows, and they are the bulk
		// of the payload, so no other slice carries them.
		$data['payments'] = $needs('customers') ? array_map(
			function ($payment) {
				return (array) $payment;
			},
			$payments
		) : array();

		$data['trial_funnel']  = $needs('growth') ? $this->resolve_trial_funnel($repo, $plugin_id, $start_date, $end_date, $data) : null;
		$data['churn_reasons'] = $needs('retention') ? $repo->get_churn_reasons($plugin_id, $this->resolve_churn_window_start($end_date)) : array();
		$data['uninstalls']    = $needs('retention') ? $repo->count_uninstalls($plugin_id, $start_date, $end_date) : 0;
		$data['installs']      = $needs('growth') ? $repo->get_install_snapshot($plugin_id) : array();
		// Prefer the count Freemius reports for the product; the snapshot counts
		// install records, thousands of which are historical uninstalls.
		$catalog_entry = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
		$entry = ($plugin_id > 0 && is_array($catalog_entry) && isset($catalog_entry[(string) $plugin_id]))
			? $catalog_entry[(string) $plugin_id]
			: array();
		if (! empty($entry['active_installs'])) {
			$data['installs']['reported_active'] = (int) $entry['active_installs'];
		}
		// Freemius reports the all-time install count on the product; the local
		// table only holds the records the crawl reached, so it cannot be
		// presented as the total.
		if (! empty($entry['installs_count'])) {
			$data['installs']['reported_total'] = (int) $entry['installs_count'];
		}
		if (! empty($entry['premium_installs'])) {
			$data['installs']['reported_premium'] = (int) $entry['premium_installs'];
		}
		$coupon_rate = $this->get_balance_commission_rate($balances);
		if ($coupon_rate <= 0) {
			$coupon_rate = $this->get_legacy_commission_rate($annual_payments, $payments);
		}

		if ($needs('marketing')) {
			$this->ensure_coupon_definitions($plugin_id);
		}

		$data['coupons']       = $needs('marketing') ? $this->build_coupon_report(
			$payments,
			$this->collect_cached_coupon_definitions($plugin_id),
			$coupon_rate,
			$this->settings->get_option('rl_fsbi_conversion_currency', 'EUR')
		) : array();
		$data['plans']         = $needs('growth') || $needs('retention') ? $this->get_plans_for_filter($plugin_id) : array();
		// No slug means this product is not on WordPress.org: no widget, and no
		// outbound request to look up figures nobody asked to see.
		$wporg_slug = $plugin_id > 0 ? $this->settings->get_wporg_slug($plugin_id) : '';
		$data['wporg'] = ($needs('wporg') && '' !== $wporg_slug) ? $this->get_wporg_snapshot($wporg_slug) : null;
		$data['plan_filter']   = $plan_filter;

		$last_sync_utc = (string) $this->settings->get_option('rl_fsbi_last_sync_at_utc', (string) get_option('rl_fsbi_last_sync_at_utc', ''));
		$data['last_synced'] = '' !== $last_sync_utc
			? get_date_from_gmt($last_sync_utc, 'Y-m-d H:i')
			: '';

		$plugin_title = '';
		$plugin_version = '';
		if ($plugin_id > 0) {
			$plugin_title   = $this->get_plugin_clean_title($plugin_id);
			$plugin_version = $this->get_plugin_latest_version($plugin_id);
		} else {
			$plugin_title = esc_html__('All Plugins', 'rl-freemius-bi');
		}

		$data['plugin_id']      = $plugin_id;
		$data['plugin_title']   = $plugin_title;
		$data['plugin_version'] = $plugin_version;

		// Include cached marketing opt-in count for instant widget display
		$cache_key    = 'rl_fsbi_optins_count_' . ($plugin_id > 0 ? (int) $plugin_id : 'all');
		$optins_count = (int) get_option($cache_key, 0);

		$provider = (string) $this->settings->get_option('rl_fsbi_newsletter_provider', 'mailchimp');
		if ($optins_count <= 0) {
			if ('kit' === $provider) {
				$kit_key = trim((string) $this->settings->get_option('rl_fsbi_kit_api_key', ''));
				if (! empty($kit_key)) {
					$kit = new RL_FSBI_Kit($kit_key);
					if ($kit->is_configured()) {
						$count = $kit->get_total_subscribers();
						if ($count > 0) {
							$optins_count = $count;
							update_option($cache_key, $count);
							update_option($cache_key . '_updated', current_time('mysql'));
						}
					}
				}
			} elseif ('mailchimp' === $provider) {
				$mc_key  = trim((string) $this->settings->get_option('rl_fsbi_mailchimp_api_key', ''));
				$list_id = trim((string) $this->settings->get_option('rl_fsbi_mailchimp_list_id', ''));
				if (! empty($mc_key) && ! empty($list_id)) {
					$mc = new RL_FSBI_Mailchimp($mc_key);
					if ($mc->is_configured()) {
						$count = $mc->get_member_count($list_id);
						if ($count > 0) {
							$optins_count = $count;
							update_option($cache_key, $count);
							update_option($cache_key . '_updated', current_time('mysql'));
						}
					}
				}
			}
		}

		$data['marketing_optins']         = $optins_count;
		$data['marketing_optins_updated'] = (string) get_option($cache_key . '_updated', '');
		$data['newsletter_provider']      = $provider;
		$data['overview'] = $needs('overview')
			? $this->build_overview_extras($payments, $subscriptions, $start_date, $end_date, (string) $this->settings->get_option('rl_fsbi_conversion_currency', 'EUR'), $plugin_id, $currency)
			: array();

		$data['from_cache']               = false;
		$data['sections']                 = $sections;

		// Payment rows are not rendered by the dashboard and are large enough to
		// bloat an option row, so they are left out of the cached copy.
		$for_cache = $data;
		$for_cache['payments'] = array();
		set_transient($payload_cache_key, $for_cache, 15 * MINUTE_IN_SECONDS);

		wp_send_json_success($data);
	}

	/**
	 * Handle AJAX request to fetch Mailchimp lists and audience tags.
	 */
	public function handle_fetch_mailchimp_data_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		$api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
		if (empty($api_key)) {
			$api_key = (string) $this->settings->get_option('rl_fsbi_mailchimp_api_key', '');
		}

		if (empty($api_key)) {
			wp_send_json_error(esc_html__('Missing Mailchimp API key.', 'rl-freemius-bi'));
		}

		$mc = new RL_FSBI_Mailchimp($api_key);
		if (! $mc->is_configured()) {
			wp_send_json_error(esc_html__('Invalid Mailchimp API key format. Ensure key includes data center suffix (e.g. -us1).', 'rl-freemius-bi'));
		}

		$lists = $mc->get_lists(true);

		$list_id = isset($_POST['list_id']) ? sanitize_text_field(wp_unslash($_POST['list_id'])) : '';
		if (empty($list_id)) {
			$list_id = (string) $this->settings->get_option('rl_fsbi_mailchimp_list_id', '');
		}

		$tags = array();
		if (! empty($list_id)) {
			$tags = $mc->get_tags($list_id, true);
		}

		wp_send_json_success(array(
			'lists' => $lists,
			'tags'  => $tags,
		));
	}

	/**
	 * Handle AJAX request to fetch current member count from Mailchimp audience.
	 */
	public function handle_fetch_mailchimp_list_stats_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		$mc_key  = trim((string) $this->settings->get_option('rl_fsbi_mailchimp_api_key', ''));
		$list_id = isset($_POST['list_id']) ? sanitize_text_field(wp_unslash($_POST['list_id'])) : '';
		if (empty($list_id)) {
			$list_id = trim((string) $this->settings->get_option('rl_fsbi_mailchimp_list_id', ''));
		}

		if (empty($mc_key) || empty($list_id)) {
			wp_send_json_error(esc_html__('Mailchimp API Key or Default Audience is not configured in Settings.', 'rl-freemius-bi'));
		}

		$mc = new RL_FSBI_Mailchimp($mc_key);
		if (! $mc->is_configured()) {
			wp_send_json_error(esc_html__('Mailchimp API key is invalid.', 'rl-freemius-bi'));
		}

		$stats = $mc->get_list_stats($list_id);
		if (empty($stats)) {
			wp_send_json_error(esc_html__('Failed to fetch Mailchimp audience stats.', 'rl-freemius-bi'));
		}

		$member_count  = (int) ($stats['member_count'] ?? 0);
		$plugin_id_raw = isset($_POST['plugin_id']) ? sanitize_text_field(wp_unslash($_POST['plugin_id'])) : 'all';
		$cache_key     = 'rl_fsbi_optins_count_' . ('all' === $plugin_id_raw ? 'all' : (int) $plugin_id_raw);

		update_option($cache_key, $member_count);
		update_option($cache_key . '_updated', current_time('mysql'));

		wp_send_json_success(array(
			'stats'        => $stats,
			'member_count' => $member_count,
			'message'      => sprintf(esc_html__('Audience stats updated: %d members.', 'rl-freemius-bi'), $member_count),
		));
	}

	/**
	 * Handle AJAX request to run a sample synchronization test.
	 */
	public function handle_sample_mailchimp_test_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		$logs = array();
		$add_log = function(string $level, string $message, array $context = array()) use (&$logs) {
			$entry = array(
				'time'    => current_time('H:i:s'),
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			);
			$logs[] = $entry;

			if (class_exists('RL_FSBI_Logger')) {
				if ('error' === $level) {
					RL_FSBI_Logger::error($message, $context);
				} elseif ('warn' === $level) {
					RL_FSBI_Logger::warn($message, $context);
				} elseif ('info' === $level) {
					RL_FSBI_Logger::info($message, $context);
				} else {
					RL_FSBI_Logger::debug($message, $context);
				}
			}
		};

		$sample_count  = isset($_POST['sample_count']) ? max(1, min(50, (int) $_POST['sample_count'])) : 5;
		$plugin_id_raw = isset($_POST['plugin_id']) ? sanitize_text_field(wp_unslash($_POST['plugin_id'])) : 'all';
		$plugin_id     = 'all' === $plugin_id_raw ? 0 : max(0, (int) $plugin_id_raw);
		$dry_run       = ! empty($_POST['dry_run']);

		$add_log('info', sprintf('Starting sample test run: sample_size=%d, plugin_id=%s, dry_run=%s', $sample_count, $plugin_id_raw, $dry_run ? 'true' : 'false'));

		// Check Freemius API Client
		$api = $this->get_api_client();
		if (! $api) {
			$add_log('error', 'Freemius API client is not configured. Check Developer ID, Public Key, and Secret Key.');
			wp_send_json_error(array('logs' => $logs, 'message' => esc_html__('Freemius API client not configured.', 'rl-freemius-bi')));
		}
		$add_log('info', 'Freemius API client authenticated successfully.');

		// Check Mailchimp API
		$mc_key  = trim((string) $this->settings->get_option('rl_fsbi_mailchimp_api_key', ''));
		$list_id = trim((string) $this->settings->get_option('rl_fsbi_mailchimp_list_id', ''));
		if (empty($mc_key) || empty($list_id)) {
			$add_log('error', 'Mailchimp API Key or Default Audience is missing in Settings.');
			wp_send_json_error(array('logs' => $logs, 'message' => esc_html__('Mailchimp configuration incomplete in Settings.', 'rl-freemius-bi')));
		}

		$mc = new RL_FSBI_Mailchimp($mc_key);
		if (! $mc->is_configured()) {
			$add_log('error', 'Mailchimp API key is invalid format (missing datacenter suffix like -us1).');
			wp_send_json_error(array('logs' => $logs, 'message' => esc_html__('Invalid Mailchimp API key.', 'rl-freemius-bi')));
		}
		$add_log('info', sprintf('Mailchimp API configured with target audience list ID: %s', $list_id));

		// Resolve plugin(s) to fetch from
		$plugins_to_check = array();
		$catalog = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
		if ($plugin_id > 0) {
			$plugins_to_check[] = $plugin_id;
		} elseif (is_array($catalog) && ! empty($catalog)) {
			$plugins_to_check = array_values(array_filter(array_map('intval', array_keys($catalog))));
		}

		if (empty($plugins_to_check)) {
			$add_log('error', 'No tracked plugins found in catalog.');
			wp_send_json_error(array('logs' => $logs, 'message' => esc_html__('No tracked plugins available.', 'rl-freemius-bi')));
		}

		$sampled_users   = array();
		$remaining_count = $sample_count;

		foreach ($plugins_to_check as $pid) {
			if ($remaining_count <= 0) {
				break;
			}
			$title = $catalog[(string) $pid]['title'] ?? ('Plugin #' . $pid);
			$add_log('info', sprintf('Querying Freemius API for %s (ID: %d), requesting up to %d users...', $title, $pid, $remaining_count));

			$fetched = $api->retrieve_users($pid, array(
				'count'  => $remaining_count,
				'offset' => 0,
			));

			if (! empty($fetched) && is_array($fetched)) {
				$add_log('info', sprintf(' -> Received %d users from %s.', count($fetched), $title));
				foreach ($fetched as $u) {
					$sampled_users[] = array(
						'user'      => $u,
						'plugin_id' => $pid,
					);
				}
				$remaining_count -= count($fetched);
			} else {
				$add_log('info', sprintf(' -> 0 users returned for %s.', $title));
			}

			if ($plugin_id > 0) {
				break;
			}
		}

		if (empty($sampled_users)) {
			$add_log('warn', 'Freemius API returned 0 users across the queried plugin(s).');
			wp_send_json_success(array('logs' => $logs, 'summary' => array('scanned' => 0, 'optins' => 0, 'synced' => 0)));
		}

		$add_log('info', sprintf('Successfully received %d total users from Freemius. Beginning per-user inspection...', count($sampled_users)));

		global $wpdb;
		$default_tag = trim((string) $this->settings->get_option('rl_fsbi_mc_default_tag', 'Freemius Opt-in'));
		if (empty($default_tag)) {
			$default_tag = 'Freemius Opt-in';
		}
		$sub_table = $wpdb->prefix . 'rl_fsbi_subscriptions';
		$lic_table = $wpdb->prefix . 'rl_fsbi_licenses';

		$scanned = 0;
		$optins  = 0;
		$synced  = 0;
		$skipped = 0;
		$errors  = 0;

		foreach ($sampled_users as $sample_item) {
			$user       = $sample_item['user'];
			$target_pid = $sample_item['plugin_id'];
			$scanned++;
			$u_arr = is_array($user) ? $user : (array) $user;
			$u_id  = (int) ($u_arr['id'] ?? 0);
			$email = sanitize_email($u_arr['email'] ?? '');
			$fname = sanitize_text_field($u_arr['first'] ?? ($u_arr['first_name'] ?? ''));
			$lname = sanitize_text_field($u_arr['last'] ?? ($u_arr['last_name'] ?? ''));
			$is_optin = $this->user_passes_consent($u_arr);

			// Mask email for display in logs
			$masked_email = $email;
			if (strpos($email, '@') !== false) {
				$parts = explode('@', $email, 2);
				$masked_email = substr($parts[0], 0, 2) . '***@' . $parts[1];
			}

			$add_log('debug', sprintf('[User #%d] ID: %d | Email: %s | is_marketing_allowed: %s', $scanned, $u_id, $masked_email, $is_optin ? 'TRUE' : 'FALSE'));

			if (! $is_optin) {
				$skipped++;
				$add_log('debug', ' -> Skipped: user opted out of marketing communications.');
				continue;
			}

			if (empty($email)) {
				$skipped++;
				$add_log('warn', ' -> Skipped: user does not have a valid email address.');
				continue;
			}

			$optins++;
			$add_log('info', sprintf(' -> Marketing Opt-in confirmed for user #%d (%s). Checking plan ownership...', $u_id, $masked_email));

			// Plan resolution
			$plan_id = null;
			if ($u_id > 0) {
				$plan_id = $wpdb->get_var($wpdb->prepare(
					"SELECT plan_id FROM {$sub_table} WHERE user_id = %d AND plan_id > 0 ORDER BY id DESC LIMIT 1",
					$u_id
				));
				if (! $plan_id) {
					$plan_id = $wpdb->get_var($wpdb->prepare(
						"SELECT plan_id FROM {$lic_table} WHERE user_id = %d AND plan_id > 0 ORDER BY id DESC LIMIT 1",
						$u_id
					));
				}
			}

			$assigned_tag = $default_tag;
			if (! empty($plan_id)) {
				$mapped_tag = trim((string) $this->settings->get_option('rl_fsbi_mc_plan_tag_' . (int) $plan_id, ''));
				if (! empty($mapped_tag)) {
					$assigned_tag = $mapped_tag;
					$add_log('debug', sprintf('    Found active plan ID #%d -> Mapped tag: "%s"', $plan_id, $assigned_tag));
				} else {
					$plan_name = $wpdb->get_var($wpdb->prepare(
						"SELECT plan_name FROM {$wpdb->prefix}rl_fsbi_plans WHERE plan_id = %d LIMIT 1",
						(int) $plan_id
					));
					$assigned_tag = ! empty($plan_name) ? sanitize_text_field($plan_name) : $default_tag;
					$add_log('debug', sprintf('    Found active plan ID #%d (%s) -> Plan name tag: "%s"', $plan_id, $plan_name ?: 'Unnamed', $assigned_tag));
				}
			} else {
				$add_log('debug', sprintf('    No active paid plan found in database -> Assigned fallback tag: "%s"', $assigned_tag));
			}

			// Site origin lookup
			$site_url   = '';
			$site_title = '';
			if ($u_id > 0) {
				$site_cache_key = 'rl_fsbi_user_site_' . (int) $target_pid . '_' . (int) $u_id;
				$cached_site    = get_transient($site_cache_key);
				if (false !== $cached_site && is_array($cached_site)) {
					$site_url   = $cached_site['url'] ?? '';
					$site_title = $cached_site['title'] ?? '';
				} else {
					$installs = $api->retrieve_installs($target_pid, $u_id, array('count' => 1));
					if (! empty($installs) && is_array($installs)) {
						$first_install = is_array($installs[0]) ? $installs[0] : (array) $installs[0];
						$site_url   = sanitize_text_field($first_install['url'] ?? '');
						$site_title = sanitize_text_field($first_install['title'] ?? '');
						set_transient($site_cache_key, array('url' => $site_url, 'title' => $site_title), WEEK_IN_SECONDS);
					} else {
						set_transient($site_cache_key, array('url' => '', 'title' => ''), WEEK_IN_SECONDS);
					}
				}
			}

			if (! empty($site_url)) {
				$site_display = ! empty($site_title) ? sprintf('%s ("%s")', $site_url, $site_title) : $site_url;
				$add_log('info', sprintf('    Opt-in Site Origin: %s', $site_display));
			} else {
				$add_log('debug', '    Opt-in Site Origin: None found or install record not available.');
			}

			if ($dry_run) {
				$add_log('info', sprintf(' -> [DRY RUN] Would upsert to Mailchimp audience "%s" with tag ["%s"]%s.', 
					$list_id, 
					$assigned_tag,
					! empty($site_url) ? (' and website "' . $site_url . '"') : ''
				));
				$synced++;
			} else {
				$res = $mc->upsert_subscriber($list_id, $email, $fname, $lname, array($assigned_tag), $site_url);
				if (is_wp_error($res)) {
					$errors++;
					$add_log('error', sprintf(' -> Mailchimp API Error: %s', $res->get_error_message()));
				} else {
					$synced++;
					$add_log('info', sprintf(' -> [SUCCESS] Upserted to Mailchimp list %s with tag "%s"%s.', 
						$list_id, 
						$assigned_tag,
						! empty($site_url) ? (' and website ' . $site_url) : ''
					));
				}
			}
		}

		// Audience stats verification
		$audience_stats = $mc->get_list_stats($list_id);
		if (! empty($audience_stats)) {
			$add_log('info', sprintf('Current Mailchimp Audience "%s": %d active members, %d unsubscribed, %d cleaned/bounced.', 
				$audience_stats['name'] ?? $list_id,
				$audience_stats['member_count'],
				$audience_stats['unsubscribe_count'],
				$audience_stats['cleaned_count']
			));
		}

		$add_log('info', sprintf('Sample run completed: Scanned %d users, Found %d opt-ins, Synced %d, Skipped %d, Errors %d.', $scanned, $optins, $synced, $skipped, $errors));

		wp_send_json_success(array(
			'logs'    => $logs,
			'summary' => array(
				'scanned'        => $scanned,
				'optins'         => $optins,
				'synced'         => $synced,
				'skipped'        => $skipped,
				'errors'         => $errors,
				'audience_stats' => $audience_stats,
			),
		));
	}

	/**
	 * Handle AJAX request to fetch Kit tags.
	 */
	public function handle_fetch_kit_data_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		$api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
		if (empty($api_key)) {
			$api_key = (string) $this->settings->get_option('rl_fsbi_kit_api_key', '');
		}

		$api_secret = isset($_POST['api_secret']) ? sanitize_text_field(wp_unslash($_POST['api_secret'])) : '';
		if (empty($api_secret)) {
			$api_secret = (string) $this->settings->get_option('rl_fsbi_kit_api_secret', '');
		}

		if (empty($api_key)) {
			wp_send_json_error(esc_html__('Missing Kit V4 API key.', 'rl-freemius-bi'));
		}

		$kit = new RL_FSBI_Kit($api_key, $api_secret);
		if (! $kit->is_configured()) {
			wp_send_json_error(esc_html__('Invalid Kit API key.', 'rl-freemius-bi'));
		}

		$tags = $kit->get_tags(true);
		$kit->ensure_custom_field('Website');

		wp_send_json_success(array(
			'tags' => $tags,
		));
	}

	/**
	 * Handle AJAX request to fetch current member count from Kit.
	 */
	public function handle_fetch_kit_stats_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		$kit_key    = trim((string) $this->settings->get_option('rl_fsbi_kit_api_key', ''));
		$kit_secret = trim((string) $this->settings->get_option('rl_fsbi_kit_api_secret', ''));

		if (empty($kit_key)) {
			wp_send_json_error(esc_html__('Kit API Key is not configured in Settings.', 'rl-freemius-bi'));
		}

		$kit = new RL_FSBI_Kit($kit_key, $kit_secret);
		if (! $kit->is_configured()) {
			wp_send_json_error(esc_html__('Kit API key is invalid.', 'rl-freemius-bi'));
		}

		$count = $kit->get_total_subscribers();
		$plugin_id_raw = isset($_POST['plugin_id']) ? sanitize_text_field(wp_unslash($_POST['plugin_id'])) : 'all';
		$cache_key     = 'rl_fsbi_optins_count_' . ('all' === $plugin_id_raw ? 'all' : (int) $plugin_id_raw);

		update_option($cache_key, $count);
		update_option($cache_key . '_updated', current_time('mysql'));

		wp_send_json_success(array(
			'count'        => $count,
			'member_count' => $count,
			'updated'      => get_option($cache_key . '_updated', current_time('mysql')),
			'plugin_id'    => $plugin_id_raw,
			'message'      => sprintf(esc_html__('Kit subscriber count updated: %d subscribers.', 'rl-freemius-bi'), $count),
		));
	}

	/**
	 * Handle AJAX request to run a sample synchronization test for Kit.com.
	 */
	public function handle_sample_kit_test_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		$logs = array();
		$add_log = function(string $level, string $message, array $context = array()) use (&$logs) {
			$entry = array(
				'time'    => current_time('H:i:s'),
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			);
			$logs[] = $entry;

			if (class_exists('RL_FSBI_Logger')) {
				if ('error' === $level) {
					RL_FSBI_Logger::error($message, $context);
				} elseif ('warn' === $level) {
					RL_FSBI_Logger::warn($message, $context);
				} elseif ('info' === $level) {
					RL_FSBI_Logger::info($message, $context);
				} else {
					RL_FSBI_Logger::debug($message, $context);
				}
			}
		};

		$sample_count  = isset($_POST['sample_count']) ? max(1, min(50, (int) $_POST['sample_count'])) : 5;
		$plugin_id_raw = isset($_POST['plugin_id']) ? sanitize_text_field(wp_unslash($_POST['plugin_id'])) : 'all';
		$plugin_id     = 'all' === $plugin_id_raw ? 0 : max(0, (int) $plugin_id_raw);
		$dry_run       = ! empty($_POST['dry_run']);

		$add_log('info', sprintf('Starting Kit.com sample test run: sample_size=%d, plugin_id=%s, dry_run=%s', $sample_count, $plugin_id_raw, $dry_run ? 'true' : 'false'));

		// Check Freemius API Client
		$api = $this->get_api_client();
		if (! $api) {
			$add_log('error', 'Freemius API client is not configured. Check Developer ID, Public Key, and Secret Key.');
			wp_send_json_error(array('logs' => $logs, 'message' => esc_html__('Freemius API client not configured.', 'rl-freemius-bi')));
		}
		$add_log('info', 'Freemius API client authenticated successfully.');

		// Check Kit API
		$kit_key    = trim((string) $this->settings->get_option('rl_fsbi_kit_api_key', ''));
		$kit_secret = trim((string) $this->settings->get_option('rl_fsbi_kit_api_secret', ''));
		if (empty($kit_key)) {
			$add_log('error', 'Kit V4 API Key is missing in Settings.');
			wp_send_json_error(array('logs' => $logs, 'message' => esc_html__('Kit configuration incomplete in Settings.', 'rl-freemius-bi')));
		}

		$kit = new RL_FSBI_Kit($kit_key, $kit_secret);
		if (! $kit->is_configured()) {
			$add_log('error', 'Kit API key is invalid.');
			wp_send_json_error(array('logs' => $logs, 'message' => esc_html__('Invalid Kit API key.', 'rl-freemius-bi')));
		}
		$add_log('info', 'Kit.com API v4 client initialized.');

		$account = $kit->get_account();
		if (! is_wp_error($account) && is_array($account)) {
			$acct_name = $account['primary_email_address'] ?? ($account['name'] ?? '');
			if (! empty($acct_name)) {
				$add_log('info', sprintf('Authenticated with Kit API v4 account: %s', $acct_name));
			}
		}

		$kit_tags = $kit->get_tags();
		$add_log('info', sprintf('Discovered %d tags in Kit.com account.', count($kit_tags)));

		$cf_ok = $kit->ensure_custom_field('Website');
		$add_log('debug', sprintf('Kit "Website" custom field verified: %s', $cf_ok ? 'TRUE' : 'FALSE'));

		// Resolve plugin(s) to fetch from
		$plugins_to_check = array();
		$catalog = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
		if ($plugin_id > 0) {
			$plugins_to_check[] = $plugin_id;
		} elseif (is_array($catalog) && ! empty($catalog)) {
			$plugins_to_check = array_values(array_filter(array_map('intval', array_keys($catalog))));
		}

		if (empty($plugins_to_check)) {
			$add_log('error', 'No tracked plugins found in catalog.');
			wp_send_json_error(array('logs' => $logs, 'message' => esc_html__('No tracked plugins available.', 'rl-freemius-bi')));
		}

		$sampled_users   = array();
		$remaining_count = $sample_count;

		foreach ($plugins_to_check as $pid) {
			if ($remaining_count <= 0) {
				break;
			}
			$title = $catalog[(string) $pid]['title'] ?? ('Plugin #' . $pid);
			$add_log('info', sprintf('Querying Freemius API for %s (ID: %d), requesting up to %d users...', $title, $pid, $remaining_count));

			$fetched = $api->retrieve_users($pid, array(
				'count'  => $remaining_count,
				'offset' => 0,
			));

			if (! empty($fetched) && is_array($fetched)) {
				$add_log('info', sprintf(' -> Received %d users from %s.', count($fetched), $title));
				foreach ($fetched as $u) {
					$sampled_users[] = array(
						'user'      => $u,
						'plugin_id' => $pid,
					);
				}
				$remaining_count -= count($fetched);
			} else {
				$add_log('info', sprintf(' -> 0 users returned for %s.', $title));
			}

			if ($plugin_id > 0) {
				break;
			}
		}

		if (empty($sampled_users)) {
			$add_log('warn', 'Freemius API returned 0 users across the queried plugin(s).');
			wp_send_json_success(array('logs' => $logs, 'summary' => array('scanned' => 0, 'optins' => 0, 'synced' => 0)));
		}

		$add_log('info', sprintf('Successfully received %d total users from Freemius. Beginning per-user inspection...', count($sampled_users)));

		global $wpdb;
		$default_tag_raw = trim((string) $this->settings->get_option('rl_fsbi_kit_default_tag', 'Freemius Opt-in'));
		if (empty($default_tag_raw)) {
			$default_tag_raw = 'Freemius Opt-in';
		}

		// Resolve default tag ID
		$default_tag_id = 0;
		if (is_numeric($default_tag_raw) && isset($kit_tags[(int) $default_tag_raw])) {
			$default_tag_id = (int) $default_tag_raw;
		} else {
			foreach ($kit_tags as $t_id => $t_name) {
				if (strcasecmp($t_name, $default_tag_raw) === 0) {
					$default_tag_id = (int) $t_id;
					break;
				}
			}
			if ($default_tag_id <= 0 && ! empty($kit_tags)) {
				$default_tag_id = (int) array_key_first($kit_tags);
			}
		}

		$default_tag_label = $kit_tags[$default_tag_id] ?? ('Tag #' . $default_tag_id);

		$sub_table = $wpdb->prefix . 'rl_fsbi_subscriptions';
		$lic_table = $wpdb->prefix . 'rl_fsbi_licenses';

		$scanned = 0;
		$optins  = 0;
		$synced  = 0;
		$skipped = 0;
		$errors  = 0;
		$kit_skipped   = 0;
		$kit_remaining = $kit ? $this->resolve_kit_remaining($kit) : -1;

		foreach ($sampled_users as $sample_item) {
			$user       = $sample_item['user'];
			$target_pid = $sample_item['plugin_id'];
			$scanned++;
			$u_arr = is_array($user) ? $user : (array) $user;
			$u_id  = (int) ($u_arr['id'] ?? 0);
			$email = sanitize_email($u_arr['email'] ?? '');
			$fname = sanitize_text_field($u_arr['first'] ?? ($u_arr['first_name'] ?? ''));
			$lname = sanitize_text_field($u_arr['last'] ?? ($u_arr['last_name'] ?? ''));
			$is_optin = $this->user_passes_consent($u_arr);

			$masked_email = $email;
			if (strpos($email, '@') !== false) {
				$parts = explode('@', $email, 2);
				$masked_email = substr($parts[0], 0, 2) . '***@' . $parts[1];
			}

			$add_log('debug', sprintf('[User #%d] ID: %d | Email: %s | is_marketing_allowed: %s', $scanned, $u_id, $masked_email, $is_optin ? 'TRUE' : 'FALSE'));

			if (! $is_optin) {
				$skipped++;
				$add_log('debug', ' -> Skipped: user opted out of marketing communications.');
				continue;
			}

			if (empty($email)) {
				$skipped++;
				$add_log('warn', ' -> Skipped: user does not have a valid email address.');
				continue;
			}

			$optins++;
			$add_log('info', sprintf(' -> Marketing Opt-in confirmed for user #%d (%s). Checking plan ownership...', $u_id, $masked_email));

			// Plan resolution
			$plan_id = null;
			if ($u_id > 0) {
				$plan_id = $wpdb->get_var($wpdb->prepare(
					"SELECT plan_id FROM {$sub_table} WHERE user_id = %d AND plan_id > 0 ORDER BY id DESC LIMIT 1",
					$u_id
				));
				if (! $plan_id) {
					$plan_id = $wpdb->get_var($wpdb->prepare(
						"SELECT plan_id FROM {$lic_table} WHERE user_id = %d AND plan_id > 0 ORDER BY id DESC LIMIT 1",
						$u_id
					));
				}
			}

			$assigned_tag_id = $default_tag_id;
			$assigned_tag_label = $default_tag_label;

			if (! empty($plan_id)) {
				$mapped_tag_raw = trim((string) $this->settings->get_option('rl_fsbi_kit_plan_tag_' . (int) $plan_id, ''));
				if (! empty($mapped_tag_raw)) {
					if (is_numeric($mapped_tag_raw) && isset($kit_tags[(int) $mapped_tag_raw])) {
						$assigned_tag_id = (int) $mapped_tag_raw;
						$assigned_tag_label = $kit_tags[$assigned_tag_id];
					} else {
						foreach ($kit_tags as $t_id => $t_name) {
							if (strcasecmp($t_name, $mapped_tag_raw) === 0) {
								$assigned_tag_id = (int) $t_id;
								$assigned_tag_label = $t_name;
								break;
							}
						}
					}
					$add_log('debug', sprintf('    Found active plan ID #%d -> Mapped Kit tag: "%s" (ID: %d)', $plan_id, $assigned_tag_label, $assigned_tag_id));
				} else {
					$add_log('debug', sprintf('    Found active plan ID #%d -> Assigned default Kit tag: "%s" (ID: %d)', $plan_id, $assigned_tag_label, $assigned_tag_id));
				}
			} else {
				$add_log('debug', sprintf('    No active paid plan in database -> Assigned fallback Kit tag: "%s" (ID: %d)', $assigned_tag_label, $assigned_tag_id));
			}

			// Site origin lookup
			$site_url   = '';
			$site_title = '';
			if ($u_id > 0) {
				$site_cache_key = 'rl_fsbi_user_site_' . (int) $target_pid . '_' . (int) $u_id;
				$cached_site    = get_transient($site_cache_key);
				if (false !== $cached_site && is_array($cached_site)) {
					$site_url   = $cached_site['url'] ?? '';
					$site_title = $cached_site['title'] ?? '';
				} else {
					$installs = $api->retrieve_installs($target_pid, $u_id, array('count' => 1));
					if (! empty($installs) && is_array($installs)) {
						$first_install = is_array($installs[0]) ? $installs[0] : (array) $installs[0];
						$site_url   = sanitize_text_field($first_install['url'] ?? '');
						$site_title = sanitize_text_field($first_install['title'] ?? '');
						set_transient($site_cache_key, array('url' => $site_url, 'title' => $site_title), WEEK_IN_SECONDS);
					} else {
						set_transient($site_cache_key, array('url' => '', 'title' => ''), WEEK_IN_SECONDS);
					}
				}
			}

			if (! empty($site_url)) {
				$site_display = ! empty($site_title) ? sprintf('%s ("%s")', $site_url, $site_title) : $site_url;
				$add_log('info', sprintf('    Opt-in Site Origin: %s', $site_display));
			} else {
				$add_log('debug', '    Opt-in Site Origin: None found or install record not available.');
			}

			if ($dry_run) {
				$add_log('info', sprintf(' -> [DRY RUN] Would upsert to Kit.com with tag "%s" (ID: %d)%s.', 
					$assigned_tag_label, 
					$assigned_tag_id,
					! empty($site_url) ? (' and website "' . $site_url . '"') : ''
				));
				$synced++;
			} else {
				if ($assigned_tag_id <= 0) {
					$errors++;
					$add_log('error', ' -> Cannot sync: no valid Kit tag ID available. Create at least one tag in Kit.com first.');
				} elseif ($kit_remaining === 0) {
					$kit_skipped++;
					$add_log('warning', ' -> Skipped: Kit subscriber limit reached. Increase the limit in Settings or prune subscribers.');
				} else {
					$res = $kit->upsert_subscriber($assigned_tag_id, $email, $fname, array(), $site_url);
					if (is_wp_error($res)) {
						$errors++;
						$add_log('error', sprintf(' -> Kit API Error: %s', $res->get_error_message()));
					} else {
						$synced++;
						if ($kit_remaining > 0) {
							$kit_remaining--;
						}
						$add_log('info', sprintf(' -> [SUCCESS] Upserted to Kit.com with tag "%s" (ID: %d)%s.', 
							$assigned_tag_label, 
							$assigned_tag_id,
							! empty($site_url) ? (' and website ' . $site_url) : ''
						));
					}
				}
			}
		}

		$subscriber_total = $kit->get_total_subscribers();
		if ($subscriber_total > 0) {
			$add_log('info', sprintf('Current Kit.com Account Total: %d active subscribers.', $subscriber_total));
		}

		$add_log('info', sprintf('Kit.com sample run completed: Scanned %d users, Found %d opt-ins, Synced %d, Skipped %d, Errors %d.', $scanned, $optins, $synced, $skipped, $errors));

		wp_send_json_success(array(
			'logs'    => $logs,
			'summary' => array(
				'scanned'          => $scanned,
				'optins'           => $optins,
				'synced'           => $synced,
				'skipped'          => $skipped,
				'errors'           => $errors,
				'subscriber_total' => $subscriber_total,
			),
		));
	}

	/**
	 * Handle AJAX request to batch-sync Freemius marketing opt-ins to active newsletter provider (Mailchimp or Kit.com).
	 */
	public function handle_sync_mailchimp_optins_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		@set_time_limit(120);

		$provider = (string) $this->settings->get_option('rl_fsbi_newsletter_provider', '');
		if (empty($provider)) {
			$has_kit = ! empty(trim((string) $this->settings->get_option('rl_fsbi_kit_api_key', '')));
			$has_mc  = ! empty(trim((string) $this->settings->get_option('rl_fsbi_mailchimp_api_key', '')));
			$provider = $has_kit ? 'kit' : ($has_mc ? 'mailchimp' : 'kit');
		}

		$mc  = null;
		$kit = null;
		$list_id = '';
		$kit_tags = array();

		if ('kit' === $provider) {
			$kit_key    = trim((string) $this->settings->get_option('rl_fsbi_kit_api_key', ''));
			$kit_secret = trim((string) $this->settings->get_option('rl_fsbi_kit_api_secret', ''));

			if (empty($kit_key)) {
				wp_send_json_error(esc_html__('Kit API Key is not configured in Settings.', 'rl-freemius-bi'));
			}

			$kit = new RL_FSBI_Kit($kit_key, $kit_secret);
			if (! $kit->is_configured()) {
				wp_send_json_error(esc_html__('Kit API key is invalid.', 'rl-freemius-bi'));
			}
			$kit_tags = $kit->get_tags();
		} else {
			$mc_key  = trim((string) $this->settings->get_option('rl_fsbi_mailchimp_api_key', ''));
			$list_id = trim((string) $this->settings->get_option('rl_fsbi_mailchimp_list_id', ''));

			if (empty($mc_key) || empty($list_id)) {
				wp_send_json_error(esc_html__('Mailchimp API Key or Default Audience is not configured in Settings.', 'rl-freemius-bi'));
			}

			$mc = new RL_FSBI_Mailchimp($mc_key);
			if (! $mc->is_configured()) {
				wp_send_json_error(esc_html__('Mailchimp API key is invalid.', 'rl-freemius-bi'));
			}
		}

		$api = $this->get_api_client();
		if (! $api) {
			wp_send_json_error(esc_html__('Freemius API client is not configured.', 'rl-freemius-bi'));
		}

		$plugin_id_raw = isset($_POST['plugin_id']) ? sanitize_text_field(wp_unslash($_POST['plugin_id'])) : 'all';
		$plugin_id     = 'all' === $plugin_id_raw ? 0 : max(0, (int) $plugin_id_raw);

		$offset        = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
		$synced_count  = isset($_POST['synced_count']) ? max(0, (int) $_POST['synced_count']) : 0;
		$scanned_count = isset($_POST['scanned_count']) ? max(0, (int) $_POST['scanned_count']) : 0;
		$optins_count  = isset($_POST['optins_count']) ? max(0, (int) $_POST['optins_count']) : 0;
		$errors_count  = isset($_POST['errors_count']) ? max(0, (int) $_POST['errors_count']) : 0;
		$plugin_index  = isset($_POST['plugin_index']) ? max(0, (int) $_POST['plugin_index']) : 0;
		$delta_only    = ! empty($_POST['delta_only']);
		$last_sync_utc = (string) get_option('rl_fsbi_newsletter_last_sync_utc', '');
		$last_sync_ts  = ! empty($last_sync_utc) ? strtotime($last_sync_utc) : 0;

		$kit_limit_skipped = 0;
		$kit_remaining     = ('kit' === $provider && $kit) ? $this->resolve_kit_remaining($kit) : -1;

		$plugins_to_check = array();
		$catalog = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
		if ($plugin_id > 0) {
			$plugins_to_check[] = $plugin_id;
		} elseif (is_array($catalog) && ! empty($catalog)) {
			$plugins_to_check = array_values(array_filter(array_map('intval', array_keys($catalog))));
		}

		$total_plugins = count($plugins_to_check);

		if (empty($plugins_to_check) || ! isset($plugins_to_check[$plugin_index])) {
			$member_count = $synced_count;
			if ('kit' === $provider && $kit) {
				$kit_total = $kit->get_total_subscribers();
				if ($kit_total > 0) {
					$member_count = $kit_total;
				}
			} elseif ($mc && ! empty($list_id)) {
				$mc_stats = $mc->get_list_stats($list_id);
				if (! empty($mc_stats['member_count'])) {
					$member_count = (int) $mc_stats['member_count'];
				}
			}

			$cache_key = 'rl_fsbi_optins_count_' . ($plugin_id > 0 ? (int) $plugin_id : 'all');
			update_option($cache_key, $member_count);
			update_option($cache_key . '_updated', current_time('mysql'));
			update_option('rl_fsbi_newsletter_last_sync_utc', gmdate('Y-m-d H:i:s'));

			$provider_name = 'kit' === $provider ? 'Kit.com' : 'Mailchimp';
			wp_send_json_success(array(
				'scanned_count'         => $scanned_count,
				'optins_count'          => $optins_count,
				'synced_count'          => $synced_count,
				'errors_count'          => $errors_count,
				'offset'                => 0,
				'plugin_index'          => 0,
				'total_plugins'         => $total_plugins,
				'current_plugin_title'  => '',
				'audience_member_count' => $member_count,
				'done'                  => true,
				'logs'                  => array(
					sprintf('[%s] ✓ Sync complete: %d contacts synced to %s (Total subscribers: %d).', current_time('H:i:s'), $synced_count, $provider_name, $member_count),
				),
				'message'               => sprintf(esc_html__('Sync complete: %d contacts synced to %s (Total: %d).', 'rl-freemius-bi'), $synced_count, $provider_name, $member_count),
			));
		}

		$current_plugin_id    = $plugins_to_check[$plugin_index];
		$current_plugin_title = $catalog[(string) $current_plugin_id]['title'] ?? ('Plugin #' . $current_plugin_id);
		$batch_size           = 15;
		$provider_name        = 'kit' === $provider ? 'Kit.com' : 'Mailchimp';
		$batch_logs           = array();

		$users = $api->retrieve_users($current_plugin_id, array(
			'count'  => $batch_size,
			'offset' => $offset,
		));

		global $wpdb;
		$sub_table = $wpdb->prefix . 'rl_fsbi_subscriptions';
		$lic_table = $wpdb->prefix . 'rl_fsbi_licenses';

		$default_tag_mc = trim((string) $this->settings->get_option('rl_fsbi_mc_default_tag', 'Freemius Opt-in'));
		if (empty($default_tag_mc)) {
			$default_tag_mc = 'Freemius Opt-in';
		}

		$default_tag_kit_raw = trim((string) $this->settings->get_option('rl_fsbi_kit_default_tag', 'Freemius Opt-in'));
		$default_kit_tag_id = 0;
		if (is_numeric($default_tag_kit_raw) && isset($kit_tags[(int) $default_tag_kit_raw])) {
			$default_kit_tag_id = (int) $default_tag_kit_raw;
		} else {
			foreach ($kit_tags as $t_id => $t_name) {
				if (strcasecmp($t_name, $default_tag_kit_raw) === 0) {
					$default_kit_tag_id = (int) $t_id;
					break;
				}
			}
			if ($default_kit_tag_id <= 0 && ! empty($kit_tags)) {
				$default_kit_tag_id = (int) array_key_first($kit_tags);
			}
		}

		$hit_delta_cutoff = false;

		if (! empty($users) && is_array($users)) {
			foreach ($users as $user) {
				$scanned_count++;
				$is_optin = false;
				$email    = '';
				$fname    = '';
				$lname    = '';
				$user_id  = 0;
				$created  = '';

				if (is_array($user)) {
					$is_optin = $this->user_passes_consent($user);
					$email    = sanitize_email($user['email'] ?? '');
					$fname    = sanitize_text_field($user['first'] ?? ($user['first_name'] ?? ''));
					$lname    = sanitize_text_field($user['last'] ?? ($user['last_name'] ?? ''));
					$user_id  = (int) ($user['id'] ?? 0);
					$created  = sanitize_text_field($user['created'] ?? '');
				} elseif (is_object($user)) {
					$is_optin = $this->user_passes_consent($user);
					$email    = sanitize_email($user->email ?? '');
					$fname    = sanitize_text_field($user->first ?? ($user->first_name ?? ''));
					$lname    = sanitize_text_field($user->last ?? ($user->last_name ?? ''));
					$user_id  = (int) ($user->id ?? 0);
					$created  = sanitize_text_field($user->created ?? '');
				}

				if ($delta_only && $last_sync_ts > 0 && ! empty($created) && strtotime($created) <= $last_sync_ts) {
					// Reached checkpoint of previously synced or exported users
					$hit_delta_cutoff = true;
					$batch_logs[] = sprintf('[%s] %s: Reached sync checkpoint (%s). Advancing...', current_time('H:i:s'), $current_plugin_title, $last_sync_utc);
					break;
				}

				if (! $is_optin || empty($email)) {
					continue;
				}

				$optins_count++;

				// Find user's plan from subscriptions or licenses
				$plan_id = null;
				if ($user_id > 0) {
					$plan_id = $wpdb->get_var($wpdb->prepare(
						"SELECT plan_id FROM {$sub_table} WHERE user_id = %d AND plan_id > 0 ORDER BY id DESC LIMIT 1",
						$user_id
					));
					if (! $plan_id) {
						$plan_id = $wpdb->get_var($wpdb->prepare(
							"SELECT plan_id FROM {$lic_table} WHERE user_id = %d AND plan_id > 0 ORDER BY id DESC LIMIT 1",
							$user_id
						));
					}
				}

				// Site origin lookup
				$site_url = '';
				if ($user_id > 0) {
					$site_cache_key = 'rl_fsbi_user_site_' . (int) $current_plugin_id . '_' . (int) $user_id;
					$cached_site    = get_transient($site_cache_key);
					if (false !== $cached_site && is_array($cached_site)) {
						$site_url = $cached_site['url'] ?? '';
					} else {
						$installs = $api->retrieve_installs($current_plugin_id, $user_id, array('count' => 1));
						if (! empty($installs) && is_array($installs)) {
							$first_install = is_array($installs[0]) ? $installs[0] : (array) $installs[0];
							$site_url = sanitize_text_field($first_install['url'] ?? '');
							$site_title = sanitize_text_field($first_install['title'] ?? '');
							set_transient($site_cache_key, array('url' => $site_url, 'title' => $site_title), WEEK_IN_SECONDS);
						} else {
							set_transient($site_cache_key, array('url' => '', 'title' => ''), WEEK_IN_SECONDS);
						}
					}
				}

				if ('kit' === $provider && $kit) {
					$assigned_tag_id = $default_kit_tag_id;
					if (! empty($plan_id)) {
						$mapped_tag_raw = trim((string) $this->settings->get_option('rl_fsbi_kit_plan_tag_' . (int) $plan_id, ''));
						if (! empty($mapped_tag_raw)) {
							if (is_numeric($mapped_tag_raw) && isset($kit_tags[(int) $mapped_tag_raw])) {
								$assigned_tag_id = (int) $mapped_tag_raw;
							} else {
								foreach ($kit_tags as $t_id => $t_name) {
									if (strcasecmp($t_name, $mapped_tag_raw) === 0) {
										$assigned_tag_id = (int) $t_id;
										break;
									}
								}
							}
						}
					}

					if ($assigned_tag_id > 0 && 0 === $kit_remaining) {
						$kit_limit_skipped++;
						$batch_logs[] = sprintf('[%s] ⏭ %s: Skipped %s — Kit subscriber limit reached (%s).', current_time('H:i:s'), $current_plugin_title, $email, number_format_i18n($this->get_kit_subscriber_limit()));
					} elseif ($assigned_tag_id > 0) {
						$res = $kit->upsert_subscriber($assigned_tag_id, $email, $fname, array(), $site_url);
						if (is_wp_error($res)) {
							$errors_count++;
							$batch_logs[] = sprintf('[%s] ⚠ %s: Error syncing %s to Kit: %s', current_time('H:i:s'), $current_plugin_title, $email, $res->get_error_message());
						} else {
							$synced_count++;
							if ($kit_remaining > 0) {
								$kit_remaining--;
							}
							$tag_label = $kit_tags[$assigned_tag_id] ?? ('Tag #' . $assigned_tag_id);
							$batch_logs[] = sprintf('[%s] ✓ %s: Synced %s (%s) to Kit [Tag: %s]', current_time('H:i:s'), $current_plugin_title, $email, $fname ?: 'Contact', $tag_label);
						}
					} else {
						$errors_count++;
						$batch_logs[] = sprintf('[%s] ⚠ %s: No Kit tag configured for %s', current_time('H:i:s'), $current_plugin_title, $email);
					}
				} elseif ($mc) {
					// Resolve tag for user's plan in Mailchimp
					$tags_to_apply = array();
					if (! empty($plan_id)) {
						$mapped_tag = trim((string) $this->settings->get_option('rl_fsbi_mc_plan_tag_' . (int) $plan_id, ''));
						if (! empty($mapped_tag)) {
							$tags_to_apply[] = $mapped_tag;
						} else {
							$plan_name = $wpdb->get_var($wpdb->prepare(
								"SELECT plan_name FROM {$wpdb->prefix}rl_fsbi_plans WHERE plan_id = %d LIMIT 1",
								(int) $plan_id
							));
							if (! empty($plan_name)) {
								$tags_to_apply[] = sanitize_text_field($plan_name);
							} else {
								$tags_to_apply[] = $default_tag_mc;
							}
						}
					} else {
						$tags_to_apply[] = $default_tag_mc;
					}

					$res = $mc->upsert_subscriber($list_id, $email, $fname, $lname, $tags_to_apply, $site_url);
					if (is_wp_error($res)) {
						$errors_count++;
						$batch_logs[] = sprintf('[%s] ⚠ %s: Error syncing %s to Mailchimp: %s', current_time('H:i:s'), $current_plugin_title, $email, $res->get_error_message());
					} else {
						$synced_count++;
						$batch_logs[] = sprintf('[%s] ✓ %s: Synced %s to Mailchimp [Tags: %s]', current_time('H:i:s'), $current_plugin_title, $email, implode(', ', $tags_to_apply));
					}
				}
			}
		}

		if (empty($batch_logs)) {
			$batch_logs[] = sprintf(
				'[%s] %s: Scanned %d user(s) (Scanned: %d | Opt-ins: %d | Synced: %d).',
				current_time('H:i:s'),
				$current_plugin_title,
				! empty($users) ? count($users) : 0,
				$scanned_count,
				$optins_count,
				$synced_count
			);
		}

		if ($hit_delta_cutoff || empty($users) || count($users) < $batch_size) {
			$next_index = $plugin_index + 1;
			$done       = ! isset($plugins_to_check[$next_index]);

			$member_count = $synced_count;
			if ($done) {
				if ('kit' === $provider && $kit) {
					$kit_total = $kit->get_total_subscribers();
					if ($kit_total > 0) {
						$member_count = $kit_total;
					}
				} elseif ($mc && ! empty($list_id)) {
					$mc_stats = $mc->get_list_stats($list_id);
					if (! empty($mc_stats['member_count'])) {
						$member_count = (int) $mc_stats['member_count'];
					}
				}
				$cache_key   = 'rl_fsbi_optins_count_' . ($plugin_id > 0 ? (int) $plugin_id : 'all');
				update_option($cache_key, $member_count);
				update_option($cache_key . '_updated', current_time('mysql'));
				update_option('rl_fsbi_newsletter_last_sync_utc', gmdate('Y-m-d H:i:s'));

				$batch_logs[] = sprintf(
					'[%s] ✓ Sync complete: %d contacts synced to %s (Total subscribers: %d).',
					current_time('H:i:s'),
					$synced_count,
					$provider_name,
					$member_count
				);
			} else {
				$batch_logs[] = sprintf(
					'[%s] Finished scanning %s. Advancing to next plugin...',
					current_time('H:i:s'),
					$current_plugin_title
				);
			}

			wp_send_json_success(array(
				'scanned_count'         => $scanned_count,
				'optins_count'          => $optins_count,
				'synced_count'          => $synced_count,
				'errors_count'          => $errors_count,
				'offset'                => 0,
				'plugin_index'          => $next_index,
				'total_plugins'         => $total_plugins,
				'current_plugin_title'  => $current_plugin_title,
				'audience_member_count' => $member_count,
				'done'                  => $done,
				'logs'                  => $batch_logs,
				'kit_limit_skipped'     => $kit_limit_skipped,
				'message'               => $done
					? sprintf(esc_html__('Sync complete: %d contacts synced to %s (Total: %d).', 'rl-freemius-bi'), $synced_count, $provider_name, $member_count)
						. ( $kit_limit_skipped > 0 ? ' ' . sprintf(esc_html__('%d skipped: Kit subscriber limit (%s) reached.', 'rl-freemius-bi'), $kit_limit_skipped, number_format_i18n($this->get_kit_subscriber_limit())) : '' )
					: sprintf(esc_html__('Finished %s. Moving to next plugin...', 'rl-freemius-bi'), $current_plugin_title),
			));
		}

		wp_send_json_success(array(
			'scanned_count'        => $scanned_count,
			'optins_count'         => $optins_count,
			'synced_count'         => $synced_count,
			'errors_count'         => $errors_count,
			'offset'               => $offset + $batch_size,
			'plugin_index'         => $plugin_index,
			'total_plugins'        => $total_plugins,
			'current_plugin_title' => $current_plugin_title,
			'kit_limit_skipped'    => $kit_limit_skipped,
			'done'                 => false,
			'logs'                 => $batch_logs,
			'message'              => sprintf(esc_html__('Scanning %s... %d users scanned (%d opt-ins, %d synced).', 'rl-freemius-bi'), $current_plugin_title, $scanned_count, $optins_count, $synced_count)
				. ( $kit_limit_skipped > 0 ? ' ' . sprintf(esc_html__('%d skipped: Kit subscriber limit reached.', 'rl-freemius-bi'), $kit_limit_skipped ) : '' ),
		));
	}

	/**
	 * Handle AJAX request for exporting monthly revenue breakdown to CSV.
	 */
	public function handle_export_monthly_csv_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized', 'rl-freemius-bi'), 403);
		}

		$repo = new RL_FSBI_Repository();

		$plugin_id_raw = isset($_REQUEST['plugin_id']) ? sanitize_text_field(wp_unslash($_REQUEST['plugin_id'])) : 'all';
		$currency_raw  = isset($_REQUEST['currency']) ? sanitize_text_field(wp_unslash($_REQUEST['currency'])) : 'all';
		$plugin_id     = 'all' === $plugin_id_raw ? 0 : max(0, (int) $plugin_id_raw);
		$currency      = 'all' === strtolower($currency_raw) ? null : strtoupper($currency_raw);
		$plan_id_raw   = isset($_REQUEST['plan_id']) ? sanitize_text_field(wp_unslash($_REQUEST['plan_id'])) : 'all';
		$plan_filter   = ('all' === $plan_id_raw || '' === $plan_id_raw) ? 0 : max(0, (int) $plan_id_raw);

		$today = current_time('Y-m-d');
		$end_date = isset($_REQUEST['end_date']) ? sanitize_text_field(wp_unslash($_REQUEST['end_date'])) : $today;
		if (empty($end_date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
			$end_date = $today;
		}

		$period = isset($_REQUEST['period']) ? sanitize_text_field(wp_unslash($_REQUEST['period'])) : '12m';
		$months_count = in_array(strtolower($period), array('3y', '36m', '3years', '3_years'), true) ? 36 : 12;

		$annual_start = wp_date('Y-m-01', strtotime('-' . ($months_count - 1) . ' months', strtotime($end_date)));
		$annual_payments = $repo->get_payments($plugin_id, $annual_start, $end_date, $currency);
		$balances = $this->get_balances_for_dashboard($plugin_id, $currency);

		if ($plan_filter > 0) {
			$annual_payments = array_values(array_filter($annual_payments, static function ($row) use ($plan_filter) {
				return (int) ($row->plan_id ?? 0) === $plan_filter;
			}));
		}

		$commission_rate = $this->get_balance_commission_rate($balances);
		if ($commission_rate <= 0) {
			$commission_rate = $this->get_legacy_commission_rate($annual_payments);
		}
		$default_currency = strtoupper((string) $this->settings->get_option('rl_fsbi_conversion_currency', $this->settings->get_option('rl_fsbi_default_currency', 'USD')));
		if (! in_array($default_currency, array('USD', 'EUR', 'GBP'), true)) {
			$default_currency = strtoupper((string) $this->conversion_currency);
		}
		$report_currency = $currency ? strtoupper((string) $currency) : $default_currency;

		$monthly_table = $this->build_monthly_breakdown($annual_payments, $report_currency, $commission_rate, $months_count);
		$rows = isset($monthly_table['rows']) ? $monthly_table['rows'] : array();
		$totals = isset($monthly_table['totals']) ? $monthly_table['totals'] : array();

		$plugin_slug = $plugin_id > 0 ? 'plugin-' . $plugin_id : 'all-plugins';
		$period_slug = 36 === $months_count ? '3years' : '12months';
		$filename = sprintf('fsbi-monthly-revenue-%s-%s-%s-%s.csv', $plugin_slug, $period_slug, strtolower($report_currency), gmdate('Y-m-d'));

		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="' . esc_attr($filename) . '"');
		header('Access-Control-Expose-Headers: Content-Disposition');
		header('Pragma: no-cache');
		header('Expires: 0');

		$output = fopen('php://output', 'w');
		// Output UTF-8 BOM for Excel compatibility
		fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

		fputcsv($output, array(
			'Period',
			'Month',
			'New Subscriptions',
			'Renewal Subscriptions',
			'Total Subscriptions',
			'Gross Revenue',
			'Refunds',
			'Fees',
			'Net Revenue',
			'Currency',
			'Payout Total',
			'Status',
		));

		foreach ($rows as $row) {
			$payout_total = (float) ($row['payout_total'] ?? 0);
			$payout_text = $payout_total > 0
				? number_format($payout_total, 2, '.', '')
				: (! empty($row['show_payout']) ? 'Carryover' : '');

			$status = '';
			if (! empty($row['is_current_payout'])) {
				$status = 'CURRENT PAYOUT';
			} elseif (! empty($row['is_next_payout'])) {
				$status = 'NEXT PAYOUT';
			}

			fputcsv($output, array(
				$row['period'] ?? '',
				$row['month'] ?? '',
				(int) ($row['new'] ?? 0),
				(int) ($row['renewals'] ?? 0),
				(int) ($row['subscriptions'] ?? 0),
				number_format((float) ($row['gross'] ?? 0), 2, '.', ''),
				number_format((float) ($row['refunds'] ?? 0), 2, '.', ''),
				number_format((float) ($row['fees'] ?? 0), 2, '.', ''),
				number_format((float) ($row['net'] ?? 0), 2, '.', ''),
				$row['currency'] ?? $report_currency,
				$payout_text,
				$status,
			));
		}

		fputcsv($output, array(
			sprintf('Total (%dm Converted)', $months_count),
			'',
			'',
			'',
			(int) ($totals['transactions'] ?? 0),
			number_format((float) ($totals['gross'] ?? 0), 2, '.', ''),
			number_format((float) ($totals['refunds'] ?? 0), 2, '.', ''),
			number_format((float) ($totals['fees'] ?? 0), 2, '.', ''),
			number_format((float) ($totals['net'] ?? 0), 2, '.', ''),
			$totals['currency'] ?? $report_currency,
			'',
			'',
		));

		fclose($output);
		exit;
	}

	/**
	 * Handle AJAX request to export Freemius marketing opt-ins to a Kit.com-compatible CSV file.
	 */
	public function handle_export_optins_csv_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized', 'rl-freemius-bi'), 403);
		}

		@ini_set('memory_limit', '512M');
		@set_time_limit(0);
		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', 1);
		}
		@ini_set('zlib.output_compression', 0);
		@ini_set('implicit_flush', 1);

		$api = $this->get_api_client();
		if (! $api) {
			wp_die(esc_html__('Freemius API client is not configured.', 'rl-freemius-bi'), 400);
		}

		$plugin_id_raw = isset($_REQUEST['plugin_id']) ? sanitize_text_field(wp_unslash($_REQUEST['plugin_id'])) : 'all';
		$plugin_id     = 'all' === $plugin_id_raw ? 0 : max(0, (int) $plugin_id_raw);

		$catalog = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
		$plugins_to_export = array();
		if ($plugin_id > 0) {
			$plugins_to_export[] = $plugin_id;
		} elseif (is_array($catalog) && ! empty($catalog)) {
			$plugins_to_export = array_values(array_filter(array_map('intval', array_keys($catalog))));
		}

		if (empty($plugins_to_export)) {
			wp_die(esc_html__('No plugins found to export.', 'rl-freemius-bi'), 400);
		}

		global $wpdb;
		$sub_table  = $wpdb->prefix . 'rl_fsbi_subscriptions';
		$lic_table  = $wpdb->prefix . 'rl_fsbi_licenses';
		$plan_table = $wpdb->prefix . 'rl_fsbi_plans';

		// Preload plan names for fast tag assignment
		$plans_cache = array();
		$plans_results = $wpdb->get_results("SELECT plan_id, plan_name FROM {$plan_table}");
		if (! empty($plans_results)) {
			foreach ($plans_results as $p_row) {
				$plans_cache[(int) $p_row->plan_id] = sanitize_text_field($p_row->plan_name);
			}
		}

		// Preload all user plans in memory to avoid tens of thousands of database queries during bulk export
		$user_plans = array();
		$sub_rows = $wpdb->get_results("SELECT user_id, plan_id FROM {$sub_table} WHERE user_id > 0 AND plan_id > 0 ORDER BY id ASC");
		if (! empty($sub_rows)) {
			foreach ($sub_rows as $row) {
				$user_plans[(int) $row->user_id] = (int) $row->plan_id;
			}
		}
		$lic_rows = $wpdb->get_results("SELECT user_id, plan_id FROM {$lic_table} WHERE user_id > 0 AND plan_id > 0 ORDER BY id ASC");
		if (! empty($lic_rows)) {
			foreach ($lic_rows as $row) {
				if (! isset($user_plans[(int) $row->user_id])) {
					$user_plans[(int) $row->user_id] = (int) $row->plan_id;
				}
			}
		}

		// Preload cached install URLs if available
		$cached_sites = array();
		$site_transients = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '_transient_rl_fsbi_user_site_%'");
		if (! empty($site_transients)) {
			foreach ($site_transients as $st) {
				$val = maybe_unserialize($st->option_value);
				if (is_array($val) && ! empty($val['url'])) {
					$key = str_replace('_transient_rl_fsbi_user_site_', '', $st->option_name);
					$cached_sites[$key] = sanitize_text_field($val['url']);
				}
			}
		}

		// Fetch Kit tags map to resolve numeric tag IDs to human-readable names for CSV import
		$kit_tags = array();
		$kit_key  = trim((string) $this->settings->get_option('rl_fsbi_kit_api_key', ''));
		$kit_secret = trim((string) $this->settings->get_option('rl_fsbi_kit_api_secret', ''));
		if (! empty($kit_key)) {
			$kit = new RL_FSBI_Kit($kit_key, $kit_secret);
			if ($kit->is_configured()) {
				$kit_tags = $kit->get_tags();
			}
		}

		$default_tag = trim((string) $this->settings->get_option('rl_fsbi_kit_default_tag', 'Freemius Opt-in'));
		if (empty($default_tag)) {
			$default_tag = 'Freemius Opt-in';
		} elseif (is_numeric($default_tag)) {
			$default_tag = $kit_tags[(int) $default_tag] ?? 'Freemius Opt-in';
		}

		$plugin_slug = $plugin_id > 0 ? 'plugin-' . $plugin_id : 'all-plugins';
		$filename = sprintf('freemius-optins-kit-%s-%s.csv', $plugin_slug, gmdate('Y-m-d'));

		// Clean any existing output buffer immediately before sending attachment headers
		while (ob_get_level() > 0) {
			@ob_end_clean();
		}

		// Send CSV download headers
		nocache_headers();
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="' . esc_attr($filename) . '"');
		header('Access-Control-Expose-Headers: Content-Disposition');
		header('Pragma: no-cache');
		header('Expires: 0');

		$output = fopen('php://output', 'w');
		// UTF-8 BOM for Kit & Excel compatibility
		fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

		// Kit.com Recommended CSV Header structure
		fputcsv($output, array(
			'Email Address',
			'First Name',
			'Last Name',
			'Tags',
			'Website',
			'Freemius User ID',
			'Plugin',
			'Opted In Date',
		));

		$seen_emails  = array();
		$total_optins = 0;
		$batch_size   = 50; // Freemius API returns maximum 50 users per page

		foreach ($plugins_to_export as $pid) {
			$plugin_title = $catalog[(string) $pid]['title'] ?? ('Plugin #' . $pid);
			$offset = 0;

			while (true) {
				$users = $api->retrieve_users($pid, array(
					'count'  => $batch_size,
					'offset' => $offset,
				));

				if (empty($users) || ! is_array($users)) {
					break;
				}

				$fetched_count = count($users);
				if ($fetched_count === 0) {
					break;
				}

				foreach ($users as $user) {
					$u_arr = is_array($user) ? $user : (array) $user;
					$is_optin = $this->user_passes_consent($u_arr);
					$email    = sanitize_email($u_arr['email'] ?? '');

					if (! $is_optin || empty($email)) {
						continue;
					}

					// Deduplicate across plugins if exporting all
					$email_lower = strtolower($email);
					if (isset($seen_emails[$email_lower])) {
						continue;
					}
					$seen_emails[$email_lower] = true;

					$fname   = sanitize_text_field($u_arr['first'] ?? ($u_arr['first_name'] ?? ''));
					$lname   = sanitize_text_field($u_arr['last'] ?? ($u_arr['last_name'] ?? ''));
					$user_id = (int) ($u_arr['id'] ?? 0);
					$created = sanitize_text_field($u_arr['created'] ?? '');

					// Instant in-memory plan tag lookup with Kit tag name resolution
					$tag = $default_tag;
					if ($user_id > 0 && isset($user_plans[$user_id])) {
						$p_id = (int) $user_plans[$user_id];
						$mapped_plan_tag = trim((string) $this->settings->get_option('rl_fsbi_kit_plan_tag_' . $p_id, ''));
						if (! empty($mapped_plan_tag)) {
							$tag = is_numeric($mapped_plan_tag) ? ($kit_tags[(int) $mapped_plan_tag] ?? $mapped_plan_tag) : $mapped_plan_tag;
						} elseif (isset($plans_cache[$p_id])) {
							$tag = $plans_cache[$p_id];
						}
					}

					// Instant in-memory site lookup
					$site_url = $user_id > 0 ? ($cached_sites[$pid . '_' . $user_id] ?? '') : '';

					fputcsv($output, array(
						$email,
						$fname,
						$lname,
						$tag,
						$site_url,
						$user_id > 0 ? (string) $user_id : '',
						$plugin_title,
						$created,
					));

					$total_optins++;
				}

				$offset += $fetched_count;

				// If Freemius returned fewer users than requested (50), we reached the end of records
				if ($fetched_count < $batch_size) {
					break;
				}

				if (function_exists('ob_flush') && ob_get_level() > 0) {
					@ob_flush();
				}
				flush();
			}
		}

		fclose($output);

		// Record last sync checkpoint and cache count
		$now_utc = gmdate('Y-m-d H:i:s');
		update_option('rl_fsbi_newsletter_last_sync_utc', $now_utc);
		if ($total_optins > 0) {
			$cache_key = 'rl_fsbi_optins_count_' . ($plugin_id > 0 ? (int) $plugin_id : 'all');
			update_option($cache_key, $total_optins);
			update_option($cache_key . '_updated', current_time('mysql'));
		}

		exit;
	}

	/**
	 * Handle chunked AJAX generation of the complete opt-ins CSV directly on the server.
	 *
	 * Writes batches incrementally to disk, emails the administrator upon completion,
	 * and returns real-time progress for the dashboard UI.
	 */
	public function handle_generate_optins_csv_batch_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(esc_html__('Unauthorized', 'rl-freemius-bi'));
		}

		@set_time_limit(120);

		$api = $this->get_api_client();
		if (! $api) {
			wp_send_json_error(esc_html__('Freemius API client is not configured.', 'rl-freemius-bi'));
		}

		$plugin_id_raw = isset($_POST['plugin_id']) ? sanitize_text_field(wp_unslash($_POST['plugin_id'])) : 'all';
		$plugin_id     = 'all' === $plugin_id_raw ? 0 : max(0, (int) $plugin_id_raw);

		$offset        = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
		$scanned_count = isset($_POST['scanned_count']) ? max(0, (int) $_POST['scanned_count']) : 0;
		$optins_count  = isset($_POST['optins_count']) ? max(0, (int) $_POST['optins_count']) : 0;
		$plugin_index  = isset($_POST['plugin_index']) ? max(0, (int) $_POST['plugin_index']) : 0;
		$file_token    = isset($_POST['file_token']) ? sanitize_text_field(wp_unslash($_POST['file_token'])) : '';
		$email_admin   = ! empty($_POST['email_admin']);

		if (! preg_match('/^[A-Za-z0-9]{32}$/', $file_token)) {
			$file_token = wp_generate_password(32, false, false);
		}

		$catalog = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
		$plugins_to_export = array();
		if ($plugin_id > 0) {
			$plugins_to_export[] = $plugin_id;
		} elseif (is_array($catalog) && ! empty($catalog)) {
			$plugins_to_export = array_values(array_filter(array_map('intval', array_keys($catalog))));
		}

		$total_plugins = count($plugins_to_export);

		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/fsbi-exports';
		$this->prepare_export_directory($export_dir);

		$file_path = $export_dir . '/' . $file_token . '.csv';

		// On first batch, initialize file with UTF-8 BOM and headers
		if (0 === $offset && 0 === $plugin_index) {
			$this->prune_export_directory($export_dir);

			$fp_init = fopen($file_path, 'w');
			if ($fp_init) {
				fprintf($fp_init, chr(0xEF) . chr(0xBB) . chr(0xBF));
				fputcsv($fp_init, array(
					'Email Address',
					'First Name',
					'Last Name',
					'Tags',
					'Website',
					'Freemius User ID',
					'Plugin',
					'Opted In Date',
				));
				fclose($fp_init);
			}
		}

		// When all plugins are completed
		if (empty($plugins_to_export) || ! isset($plugins_to_export[$plugin_index])) {
			$now_utc = gmdate('Y-m-d H:i:s');
			update_option('rl_fsbi_newsletter_last_sync_utc', $now_utc);
			update_option('rl_fsbi_optins_count_' . ($plugin_id > 0 ? (int) $plugin_id : 'all'), $optins_count);
			update_option('rl_fsbi_latest_optins_csv_export', array(
				'token'        => $file_token,
				'path'         => $file_path,
				'filename'     => 'freemius-optins-kit-' . gmdate('Y-m-d') . '.csv',
				'optins_count' => $optins_count,
				'created_at'   => $now_utc,
				'size'         => file_exists($file_path) ? filesize($file_path) : 0,
			));

			$email_sent = false;
			$admin_email = get_option('admin_email');
			if ($email_admin && ! empty($admin_email) && file_exists($file_path)) {
				$subject = sprintf(esc_html__('[Freemius BI] Your Kit.com Opt-ins CSV Export (%d contacts)', 'rl-freemius-bi'), $optins_count);
				$body    = sprintf(
					esc_html__("Hello,\n\nYour Freemius Opt-ins CSV export has finished generating on your server.\n\nTotal Opted-in Contacts: %d\nTotal Users Scanned: %d\nExport File: %s\nGenerated At: %s UTC\n\nThe CSV file is attached to this email and ready for manual import into Kit.com.\n\nBest regards,\nRL Freemius BI", 'rl-freemius-bi'),
					$optins_count,
					$scanned_count,
					basename($file_path),
					$now_utc
				);
				$headers = array('Content-Type: text/plain; charset=UTF-8');
				$attachments = array($file_path);
				$email_sent = wp_mail($admin_email, $subject, $body, $headers, $attachments);
			}

			$download_url = admin_url('admin-ajax.php?action=rl_fsbi_download_generated_csv&token=' . urlencode($file_token) . '&nonce=' . wp_create_nonce('rl_fsbi_nonce'));

			wp_send_json_success(array(
				'done'          => true,
				'scanned_count' => $scanned_count,
				'optins_count'  => $optins_count,
				'offset'        => 0,
				'plugin_index'  => 0,
				'file_token'    => $file_token,
				'download_url'  => $download_url,
				'filename'      => 'freemius-optins-kit-' . gmdate('Y-m-d') . '.csv',
				'email_sent'    => $email_sent,
				'admin_email'   => $admin_email,
				'message'       => sprintf(esc_html__('Export completed! %d opt-ins generated.', 'rl-freemius-bi'), $optins_count),
			));
		}

		$current_pid   = $plugins_to_export[$plugin_index];
		$current_title = $catalog[(string) $current_pid]['title'] ?? ('Plugin #' . $current_pid);
		$batch_size    = 50;

		$users = $api->retrieve_users($current_pid, array(
			'count'  => $batch_size,
			'offset' => $offset,
		));

		global $wpdb;
		$sub_table  = $wpdb->prefix . 'rl_fsbi_subscriptions';
		$lic_table  = $wpdb->prefix . 'rl_fsbi_licenses';
		$plan_table = $wpdb->prefix . 'rl_fsbi_plans';

		$plans_cache = array();
		$plans_results = $wpdb->get_results("SELECT plan_id, plan_name FROM {$plan_table}");
		if (! empty($plans_results)) {
			foreach ($plans_results as $p_row) {
				$plans_cache[(int) $p_row->plan_id] = sanitize_text_field($p_row->plan_name);
			}
		}

		$user_plans = array();
		$sub_rows = $wpdb->get_results("SELECT user_id, plan_id FROM {$sub_table} WHERE user_id > 0 AND plan_id > 0 ORDER BY id ASC");
		if (! empty($sub_rows)) {
			foreach ($sub_rows as $row) {
				$user_plans[(int) $row->user_id] = (int) $row->plan_id;
			}
		}
		$lic_rows = $wpdb->get_results("SELECT user_id, plan_id FROM {$lic_table} WHERE user_id > 0 AND plan_id > 0 ORDER BY id ASC");
		if (! empty($lic_rows)) {
			foreach ($lic_rows as $row) {
				if (! isset($user_plans[(int) $row->user_id])) {
					$user_plans[(int) $row->user_id] = (int) $row->plan_id;
				}
			}
		}

		$kit_tags = array();
		$kit_key  = trim((string) $this->settings->get_option('rl_fsbi_kit_api_key', ''));
		$kit_secret = trim((string) $this->settings->get_option('rl_fsbi_kit_api_secret', ''));
		if (! empty($kit_key)) {
			$kit = new RL_FSBI_Kit($kit_key, $kit_secret);
			if ($kit->is_configured()) {
				$kit_tags = $kit->get_tags();
			}
		}

		$default_tag = trim((string) $this->settings->get_option('rl_fsbi_kit_default_tag', 'Freemius Opt-in'));
		if (empty($default_tag)) {
			$default_tag = 'Freemius Opt-in';
		} elseif (is_numeric($default_tag)) {
			$default_tag = $kit_tags[(int) $default_tag] ?? 'Freemius Opt-in';
		}

		$fetched_count = ! empty($users) && is_array($users) ? count($users) : 0;
		$new_optins_batch = 0;

		if ($fetched_count > 0) {
			$fp = fopen($file_path, 'a');
			if ($fp) {
				foreach ($users as $user) {
					$scanned_count++;
					$u_arr    = (array) $user;
					$is_optin = $this->user_passes_consent($u_arr);
					$email    = sanitize_email($u_arr['email'] ?? '');

					if (! $is_optin || empty($email)) {
						continue;
					}

					$fname   = sanitize_text_field($u_arr['first'] ?? ($u_arr['first_name'] ?? ''));
					$lname   = sanitize_text_field($u_arr['last'] ?? ($u_arr['last_name'] ?? ''));
					$user_id = (int) ($u_arr['id'] ?? 0);
					$created = sanitize_text_field($u_arr['created'] ?? '');

					$tag = $default_tag;
					if ($user_id > 0 && isset($user_plans[$user_id])) {
						$p_id = (int) $user_plans[$user_id];
						$mapped_plan_tag = trim((string) $this->settings->get_option('rl_fsbi_kit_plan_tag_' . $p_id, ''));
						if (! empty($mapped_plan_tag)) {
							$tag = is_numeric($mapped_plan_tag) ? ($kit_tags[(int) $mapped_plan_tag] ?? $mapped_plan_tag) : $mapped_plan_tag;
						} elseif (isset($plans_cache[$p_id])) {
							$tag = $plans_cache[$p_id];
						}
					}

					fputcsv($fp, array(
						$email,
						$fname,
						$lname,
						$tag,
						'',
						$user_id > 0 ? (string) $user_id : '',
						$current_title,
						$created,
					));

					$optins_count++;
					$new_optins_batch++;
				}
				fclose($fp);
			}
		}

		if ($fetched_count < $batch_size) {
			$next_index = $plugin_index + 1;
			wp_send_json_success(array(
				'done'          => false,
				'scanned_count' => $scanned_count,
				'optins_count'  => $optins_count,
				'offset'        => 0,
				'plugin_index'  => $next_index,
				'total_plugins' => $total_plugins,
				'file_token'    => $file_token,
				'message'       => sprintf(esc_html__('Finished %s. Advancing...', 'rl-freemius-bi'), $current_title),
			));
		}

		wp_send_json_success(array(
			'done'          => false,
			'scanned_count' => $scanned_count,
			'optins_count'  => $optins_count,
			'offset'        => $offset + $fetched_count,
			'plugin_index'  => $plugin_index,
			'total_plugins' => $total_plugins,
			'file_token'    => $file_token,
			'message'       => sprintf(esc_html__('Scanning %s... (%d opt-ins found)', 'rl-freemius-bi'), $current_title, $optins_count),
		));
	}

	/**
	 * Handle secure download of generated CSV export file.
	 */
	public function handle_download_generated_csv_ajax()
	{
		check_ajax_referer('rl_fsbi_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized', 'rl-freemius-bi'), 403);
		}

		$token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
		if (! preg_match('/^[A-Za-z0-9]{32}$/', $token)) {
			wp_die(esc_html__('Invalid download token.', 'rl-freemius-bi'), 400);
		}

		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/fsbi-exports';
		$file_path  = $export_dir . '/' . $token . '.csv';
		$real_dir   = realpath($export_dir);
		$real_file  = realpath($file_path);

		if (false === $real_dir || false === $real_file || 0 !== strpos($real_file, $real_dir . DIRECTORY_SEPARATOR)) {
			wp_die(esc_html__('Export file not found or expired.', 'rl-freemius-bi'), 404);
		}

		$file_path = $real_file;

		$filename = sprintf('freemius-optins-kit-%s.csv', gmdate('Y-m-d'));

		nocache_headers();
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="' . esc_attr($filename) . '"');
		header('Content-Length: ' . filesize($file_path));
		header('Pragma: no-cache');
		header('Expires: 0');

		readfile($file_path);
		exit;
	}

	/**
	 * Expose the latest generated export to the dashboard without server paths.
	 *
	 * Legacy tokens created before the random-token change are dropped so the UI
	 * offers a fresh generation instead of a download that can no longer be served.
	 *
	 * @return array
	 */
	private function get_latest_export_for_ui()
	{
		$export = get_option('rl_fsbi_latest_optins_csv_export', array());

		if (! is_array($export) || empty($export['token']) || ! preg_match('/^[A-Za-z0-9]{32}$/', (string) $export['token'])) {
			return array();
		}

		unset($export['path']);

		return $export;
	}

	/**
	 * Create the export directory and deny direct web access to generated files.
	 *
	 * Exports contain customer email addresses, so the directory is blocked on
	 * Apache and listed-silenced on every other server.
	 *
	 * @param string $export_dir Absolute export directory path.
	 */
	private function prepare_export_directory($export_dir)
	{
		if (! is_dir($export_dir)) {
			wp_mkdir_p($export_dir);
		}

		if (! is_dir($export_dir)) {
			return;
		}

		$htaccess_path = $export_dir . '/.htaccess';
		if (! file_exists($htaccess_path)) {
			$rules = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

			file_put_contents($htaccess_path, $rules);
		}

		$index_path = $export_dir . '/index.php';
		if (! file_exists($index_path)) {
			file_put_contents($index_path, "<?php\n// Silence is golden.\n");
		}
	}

	/**
	 * Remove generated exports older than one day.
	 *
	 * @param string $export_dir Absolute export directory path.
	 */
	private function prune_export_directory($export_dir)
	{
		if (! is_dir($export_dir)) {
			return;
		}

		$files = glob($export_dir . '/*.csv');
		if (! is_array($files)) {
			return;
		}

		$cutoff = time() - DAY_IN_SECONDS;

		foreach ($files as $file) {
			if (is_file($file) && filemtime($file) < $cutoff) {
				@unlink($file);
			}
		}
	}

	/**
	 * Load subscriptions for dashboard analytics.
	 *
	 * @param int         $plugin_id Plugin scope.
	 * @param string|null $currency Currency filter.
	 * @return array
	 */
	private function get_subscriptions_for_dashboard($plugin_id, $currency)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'rl_fsbi_subscriptions';

		$query = "SELECT * FROM {$table} WHERE 1=1";
		if ($plugin_id > 0) {
			$query .= $wpdb->prepare(' AND plugin_id = %d', $plugin_id);
		}
		if ($currency) {
			$query .= $wpdb->prepare(' AND currency = %s', $currency);
		}

		return $wpdb->get_results($query);
	}

	/**
	 * Load balance rows for payout analytics.
	 *
	 * @param int         $plugin_id Plugin scope.
	 * @param string|null $currency Currency filter.
	 * @return array
	 */
	private function get_balances_for_dashboard($plugin_id, $currency)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'rl_fsbi_balances';

		$query = "SELECT * FROM {$table} WHERE 1=1";
		if ($plugin_id > 0) {
			$query .= $wpdb->prepare(' AND app_id = %d', $plugin_id);
		}
		if ($currency) {
			$query .= $wpdb->prepare(' AND currency = %s', $currency);
		}

		return $wpdb->get_results($query);
	}

	/**
	 * Trial funnel figures, preferring the dedicated trials table.
	 *
	 * Falls back to the subscription-derived counts already in the payload when
	 * the trials endpoint has not been synced yet, so the panel never regresses
	 * to zero for existing installations.
	 *
	 * @param RL_FSBI_Repository $repo       Repository.
	 * @param int                $plugin_id  Plugin scope.
	 * @param string             $start_date Period start.
	 * @param string             $end_date   Period end.
	 * @param array              $data       Dashboard payload built so far.
	 * @return array
	 */
	private function resolve_trial_funnel($repo, $plugin_id, $start_date, $end_date, array $data)
	{
		$funnel = $repo->get_trial_funnel($plugin_id, $start_date, $end_date);

		if ((int) $funnel['started'] > 0) {
			$funnel['source'] = 'trials';
			return $funnel;
		}

		return array(
			'started'             => (int) ($data['trial_conversion']['total_trials'] ?? 0),
			'with_payment_method' => 0,
			'converted'           => (int) ($data['trial_conversion']['converted'] ?? 0),
			'canceled'            => 0,
			'rate'                => (float) ($data['trial_conversion']['rate'] ?? 0),
			'source'              => 'subscriptions',
		);
	}

	/**
	 * Start date for the churn-reason window: the 12 months before the period end.
	 *
	 * @param string $end_date Period end (YYYY-MM-DD).
	 * @return string
	 */
	private function resolve_churn_window_start($end_date)
	{
		$timestamp = strtotime($end_date . ' -12 months');

		return false === $timestamp ? gmdate('Y-m-d', strtotime('-12 months')) : gmdate('Y-m-d', $timestamp);
	}

	/**
	 * When the dashboard data actually reflects: the last successful sync,
	 * never the (possibly future) end of the selected period.
	 *
	 * @param string $end_date Period end (YYYY-MM-DD).
	 * @return string
	 */
	private function resolve_data_as_of($end_date)
	{
		$now       = gmdate('Y-m-d H:i:s');
		$last_sync = trim((string) $this->settings->get_option('rl_fsbi_last_sync_at_utc', ''));

		if ('' === $last_sync || false === strtotime($last_sync)) {
			return $now;
		}

		return strtotime($last_sync) > strtotime($now) ? $now : $last_sync;
	}

	/**
	 * Consolidated MRR in EUR, reusing the dashboard calculation.
	 *
	 * Exposed so the REST layer cannot drift from the dashboard and cannot
	 * regress into "report only the EUR bucket".
	 *
	 * @return float
	 */
	public function rest_mrr_eur()
	{
		$subscriptions = $this->get_subscriptions_for_dashboard(0, null);
		$snapshot      = $this->calculate_period_mrr($subscriptions, gmdate('Y-m-d'), 'EUR');

		return (float) $snapshot['converted'];
	}

	/**
	 * Build complete analytics payload for v2 dashboard.
	 */
	private function build_dashboard_payload($payments, $annual_payments, $subscriptions, $balances, $start_date, $end_date, $gross_revenue, $net_revenue, $active_subscriptions, $previous_payments, $currency)
	{
		$commission_rate = $this->get_balance_commission_rate($balances);
		if ($commission_rate <= 0) {
			$commission_rate = $this->get_legacy_commission_rate($annual_payments, $payments);
		}
		$default_currency = strtoupper((string) $this->settings->get_option('rl_fsbi_conversion_currency', $this->settings->get_option('rl_fsbi_default_currency', 'USD')));
		if (! in_array($default_currency, array('USD', 'EUR', 'GBP'), true)) {
			$default_currency = strtoupper((string) $this->conversion_currency);
		}
		$report_currency = $currency ? strtoupper((string) $currency) : $default_currency;
		$data_as_of      = $this->resolve_data_as_of($end_date);

		// Gross and net come from the function that knows how commission, VAT and
		// gateway fees are netted, not from a column sum. The stored net column is
		// empty for products imported without a settled payout, and a plain gross
		// sum counted chargebacks as sales, so the headline, the Growth chart and
		// the monthly table could each report a different number for one month.
		$period_summary = $this->summarise_month_payments($payments, $report_currency, $start_date);
		$gross_revenue = (float) $period_summary['gross'];
		$net_revenue = (float) $period_summary['net'];

		$purchase_count = 0;
		$refund_count = 0;
		$renewal_count = 0;
		$refund_total = 0.0;
		$fees_total = 0.0;
		$effective_gross = 0.0;
		$effective_net = 0.0;

		foreach ($payments as $payment) {
			$amounts = $this->resolve_payment_amounts_for_display($payment, $commission_rate, $report_currency);
			$payment_gross = (float) $amounts['gross'];
			$payment_net = (float) $amounts['net'];
			$payment_fee = (float) $amounts['fee'];

			$effective_gross += $payment_gross;
			$effective_net += $payment_net;

			$is_refund = $this->is_refund_payment($payment);
			if ($is_refund) {
				$refund_count++;
				$refund_total += abs($payment_net) > 0 ? abs($payment_net) : abs($payment_gross);
			} elseif ($this->is_renewal_payment($payment)) {
				$renewal_count++;
			} else {
				$purchase_count++;
			}

			$fees_total += $payment_fee;
		}

		if ($effective_gross > 0) {
			$gross_revenue = $effective_gross;
		}
		if (abs($effective_net) > 0) {
			$net_revenue = $effective_net;
		}

		// Same source and same day-rate rule as $net_revenue above, so the two
		// figures in the comparison are always computed alike.
		$previous_net = 0.0;
		$fx_converted = false;
		foreach ($previous_payments as $previous_payment) {
			$previous_net += (float) $this->resolve_payment_amounts_for_display($previous_payment, $commission_rate, $report_currency)['net'];
		}

		foreach ($payments as $payment) {
			if (strtoupper((string) ($payment->currency ?? '')) !== $report_currency) {
				$fx_converted = true;
				break;
			}
		}

		$expected_payout = 0.0;
		foreach ($balances as $balance) {
			$balance_amount = (float) $balance->net_payout;
			$balance_currency = isset($balance->currency) ? strtoupper((string) $balance->currency) : $report_currency;
			$balance_date = isset($balance->updated_at) ? (string) $balance->updated_at : gmdate('Y-m-d H:i:s');
			$expected_payout += $this->convert_currency_amount($balance_amount, $balance_currency, $report_currency, $balance_date);
		}

		$total_refundable = max(1, $purchase_count + $refund_count);
		$refund_rate = ($refund_count / $total_refundable) * 100;

		$subscription_stats = $this->calculate_subscription_stats($subscriptions, $end_date, $start_date);
		$mrr_snapshot = $this->calculate_period_mrr($subscriptions, $end_date, $report_currency);
		$mrr_by_currency = $mrr_snapshot['native'];

		$canceled_this_month = (int) $subscription_stats['canceled_this_month'];
		$trial_count = 0;
		$converted_trials = 0;

		foreach ($subscriptions as $subscription) {
			$status = strtolower((string) $subscription->status);
			$trial_date = ! empty($subscription->trial_ends) ? substr((string) $subscription->trial_ends, 0, 10) : '';
			if (! empty($trial_date) && $trial_date >= $start_date && $trial_date <= $end_date) {
				$trial_count++;
				if (in_array($status, array('active', 'trial_ended', 'paid'), true)) {
					$converted_trials++;
				}
			}
		}

		$active_subscriptions = (int) $subscription_stats['active'];
		$churn_rate = (float) $subscription_stats['churn_rate'];
		$mrr = (float) $mrr_snapshot['converted'];
		$arr = $mrr * 12;

		// AOV spreads revenue over every payment taken in the period (new and
		// renewal). Dividing by first payments alone produced 0.00 whenever a
		// period only had renewals.
		$payment_total = $purchase_count + $renewal_count;
		$aov = $payment_total > 0 ? ($net_revenue / $payment_total) : null;
		$trial_rate = $trial_count > 0 ? ($converted_trials / $trial_count) * 100 : 0;

		// Criteria the product can actually be judged on. A product that runs no
		// trials scores nothing for trial conversion, which reads as a 25-point
		// penalty for a business model it does not use, so the criterion is
		// dropped and the remaining ones are scaled to fill the range.
		$health_criteria = array(
			'refunds' => array(
				'label' => __('Refund Control', 'rl-freemius-bi'),
				'score' => $refund_rate < 5 ? 30 : max(0, 30 - ($refund_rate * 2)),
				'max' => 30,
			),
			'churn' => array(
				'label' => __('Subscriber Churn', 'rl-freemius-bi'),
				'score' => $churn_rate < 3 ? 25 : max(0, 25 - ($churn_rate * 4)),
				'max' => 25,
			),
			'momentum' => array(
				'label' => __('Revenue Momentum', 'rl-freemius-bi'),
				'score' => $net_revenue > $previous_net ? 20 : 10,
				'max' => 20,
			),
		);

		if ($trial_count > 0) {
			$health_criteria['trials'] = array(
				'label' => __('Trial Conversion', 'rl-freemius-bi'),
				'score' => $trial_rate > 25 ? 25 : $trial_rate,
				'max' => 25,
			);
		}

		$health_earned = 0.0;
		$health_available = 0;
		foreach ($health_criteria as $criterion) {
			$health_earned += (float) $criterion['score'];
			$health_available += (int) $criterion['max'];
		}

		$health_score = $health_available > 0
			? min(100, max(0, ($health_earned / $health_available) * 100))
			: 0;

		$monthly_table = $this->build_monthly_breakdown($annual_payments, $report_currency, $commission_rate);
		$sales_activity = $this->build_sales_activity_series($payments, $subscriptions, $start_date, $end_date, $commission_rate, $report_currency);
		$expected_renewals = $this->build_expected_renewals_series($subscriptions, $payments, $start_date, $end_date, $commission_rate, $report_currency);
		$revenue_overview = $this->build_revenue_overview_series($annual_payments, $commission_rate, $report_currency);
		$revenue_forecast = $this->build_revenue_forecast_series($subscriptions, $mrr, $report_currency);
		$churn_trend = $this->build_churn_trend_series($subscriptions);
		$new_subscriptions = $this->build_new_subscription_series($annual_payments, $subscriptions);

		$ended_index   = $this->build_ended_subscription_index($subscriptions);
		$period_split  = $this->classify_first_payments($payments, $ended_index);
		$sales_origin  = $this->build_sales_origin_series($payments);
		$retention     = $this->build_retention_snapshot($subscriptions, $subscription_stats);

		$currency_distribution = $this->build_distribution_series($payments, 'currency', 'gross', $commission_rate, $report_currency);
		$country_distribution = $this->build_distribution_series($payments, 'country_code', 'net', $commission_rate, $report_currency);
		$plan_distribution = $this->build_plan_distribution_series($subscriptions, $subscription_stats['active_ids'] ?? array());
		$daily_sales_volume = $this->build_daily_sales_volume_series($annual_payments);

		$raw_currency_distribution = array();
		for ($i = 0; $i < count($currency_distribution['labels']); $i++) {
			$raw_currency_distribution[$currency_distribution['labels'][$i]] = $currency_distribution['values'][$i];
		}

		$raw_revenue_trend = array();
		for ($i = 0; $i < count($sales_activity['labels']); $i++) {
			$raw_revenue_trend[$sales_activity['labels'][$i]] = (float) $sales_activity['gross'][$i];
		}

		$next_payout_amount = isset($monthly_table['next_payout_amount']) ? (float) $monthly_table['next_payout_amount'] : 0.0;
		$next_payout_eligible = ! empty($monthly_table['next_payout_eligible']);

		return array(
			'period' => array(
				'start' => $start_date,
				'end'   => $end_date,
			),
			'summary' => array(
				'net_revenue'          => round($net_revenue, 2),
				'previous_net_revenue' => round($previous_net, 2),
				'expected_payout'      => round($next_payout_amount > 0 ? $next_payout_amount : ($expected_payout > 0 ? $expected_payout : $mrr), 2),
				'refunds_total'        => round(abs($refund_total), 2),
				'refunds_count'        => $refund_count,
				'payout_note'          => $next_payout_eligible
					? esc_html__('Eligible for next payout cycle (10th)', 'rl-freemius-bi')
					: esc_html__('Below 100 minimum. Balance carries over to next cycle.', 'rl-freemius-bi'),
				'report_currency'      => $report_currency,
				'fx_converted'         => $fx_converted,
			),
			'kpis' => array(
				'mrr'                 => round($mrr, 2),
				'arr'                 => round($arr, 2),
				'mrr_native_by_currency' => $mrr_snapshot['native'],
				'mrr_converted'       => round($mrr_snapshot['converted'], 2),
				'arr_converted'       => round($arr, 2),
				'metric_as_of'        => $data_as_of,
				'aov'                 => null === $aov ? null : round($aov, 2),
				'refund_rate'         => round($refund_rate, 2),
				'churn_rate'          => round($churn_rate, 2),
				'health_score'        => round($health_score, 0),
				'health_criteria'    => array_map(
					static function ($criterion) {
						return array(
							'label' => $criterion['label'],
							'score' => round((float) $criterion['score'], 1),
							'max'   => (int) $criterion['max'],
						);
					},
					$health_criteria
				),
				'active_subscriptions' => $active_subscriptions,
				'monthly_subscriptions' => (int) $subscription_stats['monthly'],
				'annual_subscriptions'  => (int) $subscription_stats['annual'],
			),
			'stats' => array(
				'purchases'   => $purchase_count,
				'trials'      => $trial_count,
				'conversions' => $converted_trials,
				'refunds'     => $refund_count,
				'renewals'    => $renewal_count,
			),
			'trial_conversion' => array(
				'total_trials' => $trial_count,
				'converted'    => $converted_trials,
				'rate'         => round($trial_rate, 2),
			),
			'charts' => array(
				'sales_activity'       => $sales_activity,
				'expected_renewals'    => $expected_renewals,
				'revenue_overview'     => $revenue_overview,
				'revenue_forecast'     => $revenue_forecast,
				'churn_trend'          => $churn_trend,
				'currency_distribution' => $currency_distribution,
				'country_distribution' => $country_distribution,
				'plan_distribution'    => $plan_distribution,
				'wporg_growth'         => $daily_sales_volume,
				'daily_sales_volume'   => $daily_sales_volume,
			),
			'growth' => $new_subscriptions,
			// Three audiences need three paragraphs, not one number soup:
			// who arrived, who stayed, what it earned.
			'blocks' => array(
				'acquisition' => array(
					'new_customers'   => $period_split['new'],
					'reactivations'   => $period_split['reactivation'],
					'trials'          => $trial_count,
					'conversions'     => $converted_trials,
					'conversion_rate' => round($trial_rate, 2),
					'origins'         => $sales_origin,
				),
				'retention' => $retention,
				'revenue' => array(
					'mrr'     => round($mrr, 2),
					'arr'     => round($arr, 2),
					'gross'   => round($gross_revenue, 2),
					'refunds' => round(abs($refund_total), 2),
					'fees'    => round($fees_total, 2),
					'net'     => round($net_revenue, 2),
				),
			),
			'table' => $monthly_table,

			// Compatibility keys kept for existing integrations.
			'gross_revenue'          => round($gross_revenue, 2),
			'net_revenue'            => round($net_revenue, 2),
			'mrr'                    => round($mrr, 2),
			'active_subscriptions'   => $active_subscriptions,
			'revenue_trend'          => $raw_revenue_trend,
			'currency_distribution'  => $raw_currency_distribution,
			'fees_total'             => round($fees_total, 2),
			'report_currency'        => $report_currency,
			'metrics'                => array(
				'mrr_native_by_currency' => $mrr_snapshot['native'],
				'mrr_converted'          => round($mrr_snapshot['converted'], 2),
				'arr_converted'          => round($arr, 2),
				'as_of'                  => $data_as_of,
				'conversion_currency'    => $report_currency,
			),
		);
	}

	/**
	 * Calculate MRR from the subscription state at the selected period end.
	 * Native currencies are monthlyized before historical conversion.
	 *
	 * @param array  $subscriptions Subscription rows.
	 * @param string $as_of_date Period end date.
	 * @param string $report_currency Conversion target.
	 * @return array
	 */
	private function calculate_period_mrr($subscriptions, $as_of_date, $report_currency)
	{
		$native = array();
		$as_of_timestamp = strtotime($as_of_date . ' 23:59:59');

		foreach ($subscriptions as $subscription) {
			$status = strtolower((string) ($subscription->status ?? ''));
			$meta = json_decode((string) ($subscription->metadata ?? ''), true);
			if (in_array($status, array('cancelled', 'canceled', 'expired', 'inactive'), true)) {
				continue;
			}
			$canceled_at = ! empty($subscription->canceled_at)
				? strtotime((string) $subscription->canceled_at)
				: (is_array($meta) && ! empty($meta['canceled_at']) ? strtotime((string) $meta['canceled_at']) : false);
			if (false !== $canceled_at && $canceled_at <= $as_of_timestamp) {
				continue;
			}

			$created_at = ! empty($subscription->created_at) ? strtotime((string) $subscription->created_at) : false;
			if (false !== $created_at && $created_at > $as_of_timestamp) {
				continue;
			}

			$expires_at = ! empty($subscription->expires_at) ? strtotime((string) $subscription->expires_at) : false;
			if (false !== $expires_at && $expires_at < strtotime($as_of_date . ' 00:00:00')) {
				continue;
			}

			$currency = strtoupper((string) ($subscription->currency ?? 'USD'));
			$renewal_amount = $this->get_forecast_renewal_amount($subscription);
			if ($renewal_amount <= 0) {
				continue;
			}

			$cycle_value = $subscription->billing_cycle ?? null;
			$meta = json_decode((string) ($subscription->metadata ?? ''), true);
			if (is_array($meta) && isset($meta['billing_cycle'])) {
				$cycle_value = $meta['billing_cycle'];
			}
			$months = $this->billing_cycle_to_months($cycle_value);
			if ($months <= 0) {
				continue;
			}

			if (! isset($native[$currency])) {
				$native[$currency] = 0.0;
			}
			$native[$currency] += $renewal_amount / $months;
		}

		// Consolidate every currency bucket into the reporting currency. Keeping
		// only the report-currency bucket silently dropped the others, so USD MRR
		// vanished whenever EUR was the reporting currency.
		$converted = 0.0;
		foreach ($native as $bucket_currency => $bucket_amount) {
			$converted += $this->convert_currency_amount((float) $bucket_amount, (string) $bucket_currency, (string) $report_currency, $as_of_date);
		}

		ksort($native);
		return array(
			'native'    => $native,
			'converted' => round($converted, 2),
		);
	}

	/**
	 * Calculate MRR by currency based on subscription billing cycles.
	 *
	 * @param array $subscriptions Subscription rows.
	 * @return array
	 */
	private function calculate_mrr_from_subscriptions($subscriptions)
	{
		$mrr = array(
			'USD' => 0.0,
			'EUR' => 0.0,
			'GBP' => 0.0,
			'TOTAL' => 0.0,
		);

		foreach ($subscriptions as $subscription) {
			$status = strtolower((string) ($subscription->status ?? ''));
			if (in_array($status, array('cancelled', 'canceled', 'expired', 'inactive'), true)) {
				continue;
			}

			$meta = json_decode((string) ($subscription->metadata ?? ''), true);
			$currency = strtoupper((string) ($subscription->currency ?? 'USD'));
			if (! isset($mrr[$currency])) {
				$mrr[$currency] = 0.0;
			}

			$renewal_amount = 0.0;
			if (is_array($meta)) {
				if (isset($meta['renewal_amount'])) {
					$renewal_amount = (float) $meta['renewal_amount'];
				} elseif (isset($meta['outstanding_balance'])) {
					$renewal_amount = (float) $meta['outstanding_balance'];
				}
			}
			if ($renewal_amount <= 0) {
				$renewal_amount = (float) ($subscription->outstanding_balance ?? 0);
			}
			if ($renewal_amount <= 0) {
				continue;
			}

			$cycle_value = $subscription->billing_cycle ?? null;
			if (is_array($meta) && isset($meta['billing_cycle'])) {
				$cycle_value = $meta['billing_cycle'];
			}

			$months = $this->billing_cycle_to_months($cycle_value);
			if ($months <= 0) {
				continue;
			}

			$monthly_amount = $renewal_amount / $months;
			$mrr[$currency] += $monthly_amount;
		}

		$total = 0.0;
		foreach ($mrr as $code => $value) {
			if ('TOTAL' === $code) {
				continue;
			}
			$total += (float) $value;
		}
		$mrr['TOTAL'] = $total;

		return $mrr;
	}

	/**
	 * Convert subscription billing cycle values to month count.
	 *
	 * @param mixed $cycle Billing cycle value.
	 * @return int
	 */
	private function billing_cycle_to_months($cycle)
	{
		if (is_numeric($cycle)) {
			$value = (int) $cycle;
			return $value > 0 ? $value : 0;
		}

		$normalized = strtolower(trim((string) $cycle));
		if (in_array($normalized, array('month', 'monthly'), true)) {
			return 1;
		}
		if (in_array($normalized, array('year', 'annual', 'annually', 'yearly'), true)) {
			return 12;
		}
		if ('quarterly' === $normalized) {
			return 3;
		}
		if ('biannual' === $normalized || 'semiannual' === $normalized) {
			return 6;
		}

		if (preg_match('/(\d+)/', $normalized, $matches)) {
			$value = (int) $matches[1];
			return $value > 0 ? $value : 0;
		}

		return 0;
	}

	/**
	 * Select display MRR for requested currency.
	 *
	 * @param array       $mrr_by_currency MRR map.
	 * @param string|null $currency Requested currency.
	 * @return float
	 */
	private function select_mrr_for_currency($mrr_by_currency, $currency, $report_currency = 'EUR')
	{
		if ($currency && isset($mrr_by_currency[$currency])) {
			return (float) $mrr_by_currency[$currency];
		}

		$report_currency = strtoupper((string) $report_currency);
		if (! empty($this->transferwise_token)) {
			$total_converted = 0.0;
			foreach ($mrr_by_currency as $source_currency => $amount) {
				if ('TOTAL' === $source_currency) {
					continue;
				}
				$total_converted += $this->convert_currency_amount((float) $amount, strtoupper((string) $source_currency), $report_currency, gmdate('Y-m-d H:i:s'));
			}
			if ($total_converted > 0) {
				return (float) $total_converted;
			}
		}

		if (isset($mrr_by_currency['TOTAL']) && $mrr_by_currency['TOTAL'] > 0) {
			return (float) $mrr_by_currency['TOTAL'];
		}

		return 0.0;
	}

	/**
	 * Derive active/monthly/annual/churn stats from subscription rows.
	 *
	 * @param array $subscriptions Subscription rows.
	 * @return array
	 */
	private function calculate_subscription_stats($subscriptions, $as_of_date = null, $period_start = null)
	{
		$active = 0;
		$active_ids = array();
		$monthly = 0;
		$annual = 0;
		$canceled_this_month = 0;
		$current_month = gmdate('Y-m');
		$as_of_timestamp = $as_of_date ? strtotime($as_of_date . ' 23:59:59') : time();

		foreach ($subscriptions as $subscription) {
			$status = strtolower((string) ($subscription->status ?? ''));
			$meta = json_decode((string) ($subscription->metadata ?? ''), true);
			$created_at = ! empty($subscription->created_at) ? strtotime((string) $subscription->created_at) : false;
			$expires_at = ! empty($subscription->expires_at) ? strtotime((string) $subscription->expires_at) : false;
			if (false !== $created_at && $created_at > $as_of_timestamp) {
				continue;
			}
			if (false !== $expires_at && $expires_at < strtotime(($as_of_date ?: gmdate('Y-m-d')) . ' 00:00:00')) {
				continue;
			}
			$canceled_at = is_array($meta) && ! empty($meta['canceled_at']) ? strtotime((string) $meta['canceled_at']) : false;
			if (false !== $canceled_at && $canceled_at <= $as_of_timestamp) {
				continue;
			}
			$cycle_value = $subscription->billing_cycle ?? null;
			if (is_array($meta) && isset($meta['billing_cycle'])) {
				$cycle_value = $meta['billing_cycle'];
			}
			$months = $this->billing_cycle_to_months($cycle_value);

			// A subscription only counts as a subscriber when it has something to
			// renew: counting one with no renewal amount gave 190 active against a
			// 189-subscription MRR, and the two figures sat side by side.
			$payable = $this->get_forecast_renewal_amount($subscription) > 0;

			if (in_array($status, array('active', 'paid', 'trialing', 'trial_ended'), true) && $months > 0 && $payable) {
				$active++;
				$active_ids[] = (int) ($subscription->id ?? 0);
				if (1 === $months) {
					$monthly++;
				}
				if (12 === $months) {
					$annual++;
				}
			}

			if (in_array($status, array('cancelled', 'canceled'), true)) {
				$updated_month = ! empty($subscription->updated_at) ? substr((string) $subscription->updated_at, 0, 7) : '';
				if ($updated_month === $current_month) {
					$canceled_this_month++;
				}
			}
		}

		$churn_rate = $active > 0 ? round(($canceled_this_month / $active) * 100, 1) : 0;

		// Churn is measured against the base that existed at the START of the
		// selected period, using cancellations that happened INSIDE it. The
		// previous formula divided the current calendar month's cancellations by
		// the closing base, so a period with one cancellation still read 0,0%.
		$churn_base          = 0;
		$churn_cancellations = 0;
		$period_start_stamp  = $period_start ? strtotime($period_start . ' 00:00:00') : false;

		if (false !== $period_start_stamp) {
			$churn_window       = $this->count_churn_window($subscriptions, $period_start_stamp, $as_of_timestamp);
			$churn_base         = $churn_window['base'];
			$churn_cancellations = $churn_window['cancellations'];
			$churn_rate         = $churn_window['rate'];
		}

		return array(
			'active' => $active,
			'active_ids' => $active_ids,
			'monthly' => $monthly,
			'annual' => $annual,
			'canceled_this_month' => $canceled_this_month,
			'churn_rate' => $churn_rate,
			'churn_base' => $churn_base,
			'churn_cancellations' => $churn_cancellations,
		);
	}

	/**
	 * Build daily activity lines for the selected range.
	 */
	private function build_sales_activity_series($payments, $subscriptions, $start_date, $end_date, $commission_rate = 0.0, $report_currency = 'EUR')
	{
		$labels = array();
		$purchases = array();
		$renewals = array();
		$trials = array();
		$refunds = array();
		$conversions = array();
		$gross = array();

		$wp_timezone = wp_timezone();
		$start_dt = date_create_immutable($start_date . ' 00:00:00', $wp_timezone);
		$end_dt = date_create_immutable($end_date . ' 00:00:00', $wp_timezone);

		if (! $start_dt || ! $end_dt || $start_dt > $end_dt) {
			return array(
				'labels'      => array(),
				'purchases'   => array(),
				'renewals'    => array(),
				'trials'      => array(),
				'refunds'     => array(),
				'conversions' => array(),
				'gross'       => array(),
			);
		}

		for ($day = $start_dt; $day <= $end_dt; $day = $day->modify('+1 day')) {
			$key = $day->format('Y-m-d');
			$labels[] = $key;
			$purchases[$key] = 0;
			$renewals[$key] = 0;
			$trials[$key] = 0;
			$refunds[$key] = 0;
			$conversions[$key] = 0;
			$gross[$key] = 0.0;
		}

		foreach ($payments as $payment) {
			$amounts = $this->resolve_payment_amounts_for_display($payment, $commission_rate, $report_currency);
			$tx_ts = $this->get_payment_activity_timestamp($payment);
			if (false === $tx_ts) {
				continue;
			}
			$key = wp_date('Y-m-d', $tx_ts);
			if (! isset($purchases[$key])) {
				continue;
			}
			if ($this->is_refund_payment($payment)) {
				$refunds[$key]++;
			} elseif ($this->is_renewal_payment($payment)) {
				$renewals[$key]++;
			} else {
				$purchases[$key]++;
			}
			$gross[$key] += (float) $amounts['gross'];
		}

		foreach ($subscriptions as $subscription) {
			$trial_date = null;
			if (! empty($subscription->trial_ends)) {
				$trial_ts = strtotime((string) $subscription->trial_ends);
				if (false !== $trial_ts) {
					$trial_date = wp_date('Y-m-d', $trial_ts);
				}
			}
			if ($trial_date && isset($trials[$trial_date])) {
				$trials[$trial_date]++;
				$status = strtolower((string) $subscription->status);
				if (in_array($status, array('active', 'trial_ended', 'paid'), true)) {
					$conversions[$trial_date]++;
				}
			}
		}

		return array(
			'labels'      => $labels,
			'purchases'   => array_values($purchases),
			'renewals'    => array_values($renewals),
			'trials'      => array_values($trials),
			'refunds'     => array_values($refunds),
			'conversions' => array_values($conversions),
			'gross'       => array_values($gross),
		);
	}

	/**
	 * Build daily expected renewals series for the current month.
	 *
	 * Aggregates completed renewals from payments and upcoming scheduled renewals from active subscriptions.
	 *
	 * @param array  $subscriptions Subscriptions list.
	 * @param array  $payments Payments list.
	 * @param string $start_date Period start date.
	 * @param string $end_date Period end date.
	 * @param float  $commission_rate Balance commission percentage.
	 * @param string $report_currency Display/target currency.
	 * @return array
	 */
	private function build_expected_renewals_series($subscriptions, $payments, $start_date, $end_date, $commission_rate = 0.0, $report_currency = 'EUR')
	{
		$wp_timezone = wp_timezone();
		$ref_date = ! empty($start_date) ? strtotime($start_date) : time();
		if (false === $ref_date) {
			$ref_date = time();
		}
		$month_start_str = wp_date('Y-m-01 00:00:00', $ref_date);
		$month_end_str   = wp_date('Y-m-t 23:59:59', $ref_date);
		$month_key       = wp_date('Y-m', $ref_date);

		$start_dt = date_create_immutable($month_start_str, $wp_timezone);
		$end_dt   = date_create_immutable($month_end_str, $wp_timezone);

		$labels            = array();
		$upcoming_amounts  = array();
		$upcoming_counts   = array();
		$completed_amounts = array();
		$completed_counts  = array();
		$total_amounts     = array();
		$total_counts      = array();

		for ($day = $start_dt; $day <= $end_dt; $day = $day->modify('+1 day')) {
			$key = $day->format('Y-m-d');
			$labels[] = $key;
			$upcoming_amounts[$key]  = 0.0;
			$upcoming_counts[$key]   = 0;
			$completed_amounts[$key] = 0.0;
			$completed_counts[$key]  = 0;
			$total_amounts[$key]     = 0.0;
			$total_counts[$key]      = 0;
		}

		// 1. Process completed renewal payments in this month.
		$processed_subs_in_payments = array();
		foreach ($payments as $payment) {
			if (! $this->is_renewal_payment($payment)) {
				continue;
			}
			$amounts = $this->resolve_payment_amounts_for_display($payment, $commission_rate, $report_currency);
			$tx_ts = $this->get_payment_activity_timestamp($payment);
			if (false === $tx_ts) {
				continue;
			}
			$key = wp_date('Y-m-d', $tx_ts);
			if (isset($completed_amounts[$key])) {
				$gross_amt = (float) $amounts['gross'];
				$completed_amounts[$key] += $gross_amt;
				$completed_counts[$key]++;
				$total_amounts[$key]     += $gross_amt;
				$total_counts[$key]++;
				if (! empty($payment->subscription_id)) {
					$processed_subs_in_payments[(int) $payment->subscription_id] = true;
				}
			}
		}

		// 2. Process upcoming expected renewals from active subscriptions.
		foreach ($subscriptions as $subscription) {
			$status = strtolower((string) ($subscription->status ?? ''));
			if (! in_array($status, array('active', 'trialing', 'paid'), true)) {
				continue;
			}

			$sub_id = isset($subscription->subscription_id) ? (int) $subscription->subscription_id : 0;
			if ($sub_id > 0 && isset($processed_subs_in_payments[$sub_id])) {
				continue;
			}

			$next_renewal_value = $this->get_subscription_next_renewal($subscription);
			if (empty($next_renewal_value)) {
				continue;
			}
			$next_renewal = new DateTime((string) $next_renewal_value, $wp_timezone);
			if ($next_renewal->format('Y-m') !== $month_key) {
				continue;
			}

			$day_key = $next_renewal->format('Y-m-d');
			if (! isset($upcoming_amounts[$day_key])) {
				continue;
			}

			$renewal_amount = $this->get_subscription_renewal_amount($subscription);
			if ($renewal_amount <= 0) {
				$renewal_amount = $this->get_forecast_renewal_amount($subscription);
			}
			if ($renewal_amount <= 0) {
				continue;
			}

			$source_currency = ! empty($subscription->currency) ? strtoupper((string) $subscription->currency) : $report_currency;
			$converted_amount = $this->convert_currency_amount((float) $renewal_amount, $source_currency, $report_currency, $day_key . ' 12:00:00');

			$upcoming_amounts[$day_key] += (float) $converted_amount;
			$upcoming_counts[$day_key]++;
			$total_amounts[$day_key]    += (float) $converted_amount;
			$total_counts[$day_key]++;
		}

		$total_expected_revenue  = array_sum($total_amounts);
		$total_expected_count    = array_sum($total_counts);
		$completed_revenue_total = array_sum($completed_amounts);
		$completed_count_total   = array_sum($completed_counts);
		$upcoming_revenue_total  = array_sum($upcoming_amounts);
		$upcoming_count_total    = array_sum($upcoming_counts);

		return array(
			'labels'            => $labels,
			'upcoming_amounts'  => array_map(function($v) { return round((float) $v, 2); }, array_values($upcoming_amounts)),
			'upcoming_counts'   => array_values($upcoming_counts),
			'completed_amounts' => array_map(function($v) { return round((float) $v, 2); }, array_values($completed_amounts)),
			'completed_counts'  => array_values($completed_counts),
			'total_amounts'     => array_map(function($v) { return round((float) $v, 2); }, array_values($total_amounts)),
			'total_counts'      => array_values($total_counts),
			'month_label'       => wp_date('F Y', $ref_date),
			'summary'           => array(
				'total_revenue'     => round((float) $total_expected_revenue, 2),
				'total_count'       => (int) $total_expected_count,
				'completed_revenue' => round((float) $completed_revenue_total, 2),
				'completed_count'   => (int) $completed_count_total,
				'upcoming_revenue'  => round((float) $upcoming_revenue_total, 2),
				'upcoming_count'    => (int) $upcoming_count_total,
			),
		);
	}

	/**
	 * Build monthly revenue aggregates for chart.
	 */
	private function build_revenue_overview_series($payments, $commission_rate = 0.0, $report_currency = 'EUR')
	{
		$months = array();

		foreach ($payments as $payment) {
			$amounts = $this->resolve_payment_amounts_for_display($payment, $commission_rate, $report_currency);
			$key = substr((string) $payment->transaction_date, 0, 7);
			if (! isset($months[$key])) {
				$months[$key] = array(
					'gross'   => 0.0,
					'net'     => 0.0,
					'refunds' => 0.0,
					'fees'    => 0.0,
				);
			}

			$months[$key]['gross'] += (float) $amounts['gross'];
			$months[$key]['net'] += (float) $amounts['net'];
			$months[$key]['fees'] += (float) $amounts['fee'];

			if ($this->is_refund_payment($payment)) {
				$refund_value = abs((float) $amounts['net']) > 0 ? abs((float) $amounts['net']) : abs((float) $amounts['gross']);
				$months[$key]['refunds'] += $refund_value;
			}
		}

		ksort($months);
		$months = array_slice($months, -6, 6, true);

		return array(
			'labels'  => array_keys($months),
			'gross'   => array_map('round', array_column($months, 'gross'), array_fill(0, count($months), 2)),
			'net'     => array_map('round', array_column($months, 'net'), array_fill(0, count($months), 2)),
			'refunds' => array_map('round', array_column($months, 'refunds'), array_fill(0, count($months), 2)),
			'fees'    => array_map('round', array_column($months, 'fees'), array_fill(0, count($months), 2)),
		);
	}

	/**
	 * Build 12-month forecast from next renewal dates.
	 */
	private function build_revenue_forecast_series($subscriptions, $fallback_mrr, $report_currency = 'EUR')
	{
		$labels = array();
		$buckets = array(
			'USD' => array(),
			'EUR' => array(),
			'GBP' => array(),
		);
		$merged_bucket = array();
		$forecast_start = new DateTime('first day of this month 00:00:00', wp_timezone());

		for ($i = 0; $i < 12; $i++) {
			$month_date = clone $forecast_start;
			$month_date->modify('+' . $i . ' months');
			$key = $month_date->format('Y-m');
			$labels[] = $key;
			foreach ($buckets as $currency => &$bucket) {
				$bucket[$key] = 0.0;
			}
			$merged_bucket[$key] = 0.0;
			unset($bucket);
		}

		foreach ($subscriptions as $subscription) {
			$status = strtolower((string) $subscription->status);
			if (! in_array($status, array('active', 'trialing', 'paid'), true)) {
				continue;
			}

			$next_renewal_value = $this->get_subscription_next_renewal($subscription);
			$next_renewal = ! empty($next_renewal_value) ? new DateTime((string) $next_renewal_value, wp_timezone()) : null;
			if (! $next_renewal) {
				continue;
			}

			$renewal_amount = $this->get_subscription_renewal_amount($subscription);
			$cycle_months = $this->get_subscription_cycle_months($subscription);
			if ($renewal_amount <= 0 || $cycle_months <= 0) {
				continue;
			}

			$source_currency = ! empty($subscription->currency) ? strtoupper((string) $subscription->currency) : $report_currency;
			for ($i = 0; $i < 12; $i++) {
				$renewal_date = clone $next_renewal;
				if (12 === $cycle_months) {
					$renewal_date->modify('+' . floor($i / 12) . ' years');
				} elseif (1 === $cycle_months) {
					$renewal_date->modify('+' . $i . ' months');
				} else {
					$renewal_date->modify('+' . (floor($i / $cycle_months) * $cycle_months) . ' months');
				}

				$projection_date = clone $forecast_start;
				$projection_date->modify('+' . $i . ' months');
				$projection_key = $projection_date->format('Y-m');
				if ($renewal_date->format('Y-m') === $projection_key) {
					if (! isset($buckets[$source_currency])) {
						$buckets[$source_currency] = array_fill_keys($labels, 0.0);
					}
					$buckets[$source_currency][$projection_key] += $renewal_amount;
					$merged_bucket[$projection_key] += $this->convert_currency_amount($renewal_amount, $source_currency, $report_currency, $renewal_date->format('Y-m-d H:i:s'));
				}
			}
		}

		$series = array();
		foreach ($buckets as $currency => $bucket) {
			$series[strtolower($currency)] = array_map('round', array_values($bucket), array_fill(0, count($bucket), 2));
		}

		return array(
			'labels' => $labels,
			'currency' => $series,
			'values' => array_map('round', array_values($merged_bucket), array_fill(0, count($merged_bucket), 2)),
		);
	}

	private function get_subscription_renewal_amount($subscription)
	{
		$meta = json_decode((string) ($subscription->metadata ?? ''), true);
		if (is_array($meta)) {
			foreach (array('renewal_amount', 'amount_per_cycle', 'amount', 'outstanding_balance') as $key) {
				if (isset($meta[$key]) && (float) $meta[$key] > 0) {
					return (float) $meta[$key];
				}
			}
		}

		return (float) ($subscription->outstanding_balance ?? 0);
	}

	private function get_forecast_renewal_amount($subscription)
	{
		$meta = json_decode((string) ($subscription->metadata ?? ''), true);
		if (is_array($meta) && isset($meta['renewal_amount']) && (float) $meta['renewal_amount'] > 0) {
			return (float) $meta['renewal_amount'];
		}

		if (isset($subscription->renewal_amount) && (float) $subscription->renewal_amount > 0) {
			return (float) $subscription->renewal_amount;
		}

		// List price, and only when Freemius reports no renewal amount: it
		// ignores kept discounts, so it overstates what will actually renew.
		return (float) ($subscription->amount_per_cycle ?? 0);
	}

	private function get_subscription_next_renewal($subscription)
	{
		if (! empty($subscription->next_renewal)) {
			return (string) $subscription->next_renewal;
		}

		$meta = json_decode((string) ($subscription->metadata ?? ''), true);
		if (is_array($meta)) {
			foreach (array('next_payment', 'next_billing', 'next_renewal') as $key) {
				if (! empty($meta[$key])) {
					return (string) $meta[$key];
				}
			}
		}

		return '';
	}

	private function get_subscription_cycle_months($subscription)
	{
		$meta = json_decode((string) ($subscription->metadata ?? ''), true);
		$cycle = $subscription->billing_cycle ?? null;
		if (is_array($meta) && isset($meta['billing_cycle'])) {
			$cycle = $meta['billing_cycle'];
		}

		return $this->billing_cycle_to_months($cycle);
	}

	/**
	 * Build 6-month churn trend.
	 */
	private function build_churn_trend_series($subscriptions)
	{
		$labels = array();
		$canceled = array();
		$rates = array();
		$now = time();

		for ($i = 5; $i >= 0; $i--) {
			$month_start = strtotime(gmdate('Y-m-01', strtotime('-' . $i . ' months')));
			$month_end   = strtotime(gmdate('Y-m-d', $month_start) . ' +1 month -1 second');
			$labels[]    = gmdate('Y-m', $month_start);

			// Same window rule as the Churn KPI: cancellations inside the window
			// divided by the base that existed when it opened. The two figures
			// are read side by side, so they must not be computed differently.
			$window = $this->count_churn_window($subscriptions, $month_start, min($month_end, $now));
			$canceled[] = $window['cancellations'];

			// No base means the local copy had not been populated yet for that
			// month, so the rate is unknown rather than zero. Left as null so the
			// chart draws a gap instead of a reassuring flat line at 0%.
			$rates[] = $window['base'] > 0 ? $window['rate'] : null;
		}

		return array(
			'labels' => $labels,
			'canceled' => $canceled,
			'churn_rate' => $rates,
		);
	}

	/**
	 * Count cancellations in a window against the base that existed at its start.
	 *
	 * @param array $subscriptions Subscription rows.
	 * @param int   $window_start  Window start timestamp.
	 * @param int   $window_end    Window end timestamp.
	 * @return array{base:int,cancellations:int,rate:float}
	 */
	private function count_churn_window($subscriptions, $window_start, $window_end)
	{
		$base = 0;
		$cancellations = 0;

		foreach ($subscriptions as $subscription) {
			$created_at = ! empty($subscription->created_at) ? strtotime((string) $subscription->created_at) : false;
			$expires_at = ! empty($subscription->expires_at) ? strtotime((string) $subscription->expires_at) : false;
			$meta       = json_decode((string) ($subscription->metadata ?? ''), true);
			$status     = strtolower((string) ($subscription->status ?? ''));

			// The sync writes canceled_at to its own column; reading only the stored
			// payload missed cancellations set after the row was first imported.
			$cancel_stamp = ! empty($subscription->canceled_at)
				? strtotime((string) $subscription->canceled_at)
				: (is_array($meta) && ! empty($meta['canceled_at']) ? strtotime((string) $meta['canceled_at']) : false);
			if (false === $cancel_stamp && in_array($status, array('cancelled', 'canceled'), true) && ! empty($subscription->updated_at)) {
				$cancel_stamp = strtotime((string) $subscription->updated_at);
			}

			// Existence is decided by creation and cancellation, not by expiry:
			// expires_at is rewritten on every sync to the current period end, so
			// using it as a lower bound emptied the base for every earlier month
			// and reported 0.00% churn beside 5-11 cancellations.
			$existed_at_start = (false === $created_at || $created_at <= $window_start)
				&& (false === $cancel_stamp || $cancel_stamp > $window_start);

			if ($existed_at_start) {
				$base++;
			}

			if (false !== $cancel_stamp && $cancel_stamp >= $window_start && $cancel_stamp <= $window_end) {
				$cancellations++;
			}
		}

		return array(
			'base' => $base,
			'cancellations' => $cancellations,
			'rate' => $base > 0 ? round(($cancellations / $base) * 100, 2) : 0.0,
		);
	}

	/**
	 * Build distributions from payment rows.
	 */
	private function build_distribution_series($payments, $dimension, $metric, $commission_rate = 0.0, $report_currency = 'EUR')
	{
		$bucket = array();

		foreach ($payments as $payment) {
			$amounts = $this->resolve_payment_amounts_for_display($payment, $commission_rate, $report_currency);
			$key = isset($payment->{$dimension}) && ! empty($payment->{$dimension}) ? strtoupper((string) $payment->{$dimension}) : 'N/A';
			if (! isset($bucket[$key])) {
				$bucket[$key] = 0.0;
			}

			if ('net' === $metric) {
				$bucket[$key] += (float) $amounts['net'];
			} elseif ('fee' === $metric || 'fees' === $metric) {
				$bucket[$key] += (float) $amounts['fee'];
			} else {
				$bucket[$key] += (float) $amounts['gross'];
			}
		}

		arsort($bucket);
		$bucket = array_slice($bucket, 0, 8, true);

		return array(
			'labels' => array_keys($bucket),
			'values' => array_map(
				function ($value) {
					return round((float) $value, 2);
				},
				array_values($bucket)
			),
		);
	}

	/**
	 * Build plan distribution from subscriptions.
	 */
	/**
	 * Coupon usage and revenue for the period, joined to their definitions.
	 *
	 * @param array  $payments        Period payments.
	 * @param array  $coupon_rows     Cached definitions from sync_coupons().
	 * @param float  $commission_rate Commission rate for net derivation.
	 * @param string $report_currency Display currency.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_coupon_report($payments, $coupon_rows, $commission_rate, $report_currency)
	{
		$usage = array();

		foreach ($payments as $payment) {
			$coupon_id = (int) ($payment->coupon_id ?? 0);
			if ($coupon_id <= 0) {
				continue;
			}

			if (! isset($usage[$coupon_id])) {
				$usage[$coupon_id] = array('uses' => 0, 'revenue' => 0.0, 'gross' => 0.0);
			}

			if ($this->is_refund_payment($payment)) {
				continue;
			}

			$amounts = $this->resolve_payment_amounts_for_display($payment, $commission_rate, $report_currency);

			$usage[$coupon_id]['uses']++;
			$usage[$coupon_id]['revenue'] += (float) $amounts['net'];
			$usage[$coupon_id]['gross']   += (float) $amounts['gross'];
		}

		$definitions = array();
		foreach ($coupon_rows as $row) {
			$definitions[(int) $row['id']] = $row;
		}

		// Period-scoped, like every other widget: a coupon that was not redeemed
		// in the selected range does not belong in this table, and its lifetime
		// redemption count would read as if it happened now.
		$report = array();
		$now    = current_time('timestamp');

		foreach ($usage as $coupon_id => $totals) {
			if ($totals['uses'] <= 0) {
				continue;
			}

			$definition = $definitions[$coupon_id] ?? array();

			$type   = (string) ($definition['discount_type'] ?? '');
			$value  = isset($definition['discount']) ? (float) $definition['discount'] : 0.0;

			$discount_label = '';
			if ($value > 0) {
				$discount_label = 'percentage' === $type
					? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%'
					: $value;
			}

			// Discount given is derived from the amount actually charged: for a
			// percentage coupon the undiscounted price is gross / (1 - rate).
			$discount_given = 0.0;
			if ($value > 0) {
				if ('percentage' === $type && $value < 100) {
					$discount_given = $totals['gross'] * ($value / (100 - $value));
				} elseif ('percentage' !== $type) {
					$discount_given = $value * $totals['uses'];
				}
			}

			$limit     = array_key_exists('redemptions_limit', $definition) ? $definition['redemptions_limit'] : null;
			$remaining = null === $limit ? null : max(0, (int) $limit - (int) ($definition['redemptions'] ?? 0));
			$expires   = (string) ($definition['end_date'] ?? '');
			$is_active = (int) ($definition['is_active'] ?? 0);

			$status = 'active';
			if (! $is_active) {
				$status = 'inactive';
			} elseif ('' !== $expires && strtotime($expires) !== false && strtotime($expires) < $now) {
				$status = 'expired';
			} elseif (null !== $remaining && $remaining <= 0) {
				$status = 'exhausted';
			}

			$report[] = array(
				'id'             => $coupon_id,
				'code'           => (string) ($definition['code'] ?? ('Coupon #' . $coupon_id)),
				'discount'       => $discount_label,
				'status'         => $status,
				'uses'           => (int) $totals['uses'],
				'limit'          => null === $limit ? null : (int) $limit,
				'remaining'      => $remaining,
				'expires'        => $expires,
				'revenue'        => round((float) $totals['revenue'], 2),
				'discount_given' => round($discount_given, 2),
				'is_active'      => $is_active,
				'known'          => ! empty($definition),
			);
		}

		usort($report, static function ($a, $b) {
			return $b['uses'] <=> $a['uses'];
		});

		return array_slice($report, 0, 20);
	}

	/**
	 * Make sure coupon definitions exist for a scope, fetching them if needed.
	 *
	 * The definitions used to arrive only on a full sync, so a site that
	 * upgraded saw every coupon as "Coupon #id" until the next crawl. They are
	 * now fetched on demand and cached, with a short failure lock so a broken
	 * API key cannot turn every dashboard load into an outbound request.
	 *
	 * @param int $plugin_id Plugin scope, 0 for all tracked plugins.
	 * @return void
	 */
	private function ensure_coupon_definitions($plugin_id)
	{
		$plugin_id = (int) $plugin_id;
		if ($plugin_id > 0 && ! empty($this->collect_cached_coupon_definitions($plugin_id))) {
			return;
		}

		if ($plugin_id <= 0) {
			global $wpdb;
			$has_any = (bool) $wpdb->get_var("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rl_fsbi_coupons\_%' LIMIT 1");
			if ($has_any) {
				return;
			}
		}

		if (get_transient('rl_fsbi_coupon_fetch_lock')) {
			return;
		}

		// Scheduled, never inline. This used to call the Freemius API while the
		// dashboard request was waiting, so a cold cache turned every first page
		// load in an hour into an outbound round-trip per plugin. The table
		// renders with "definition unavailable" until the fetch lands.
		set_transient('rl_fsbi_coupon_fetch_lock', 1, HOUR_IN_SECONDS);
		wp_schedule_single_event(time() + 5, 'rl_fsbi_fetch_coupons');
	}

	/**
	 * Background handler for the scheduled coupon fetch.
	 *
	 * @return void
	 */
	public function run_scheduled_coupon_fetch()
	{
		$api = $this->get_api_client();
		if (! $api) {
			return;
		}

		global $wpdb;
		$has_any = (bool) $wpdb->get_var("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rl_fsbi_coupons\\_%' LIMIT 1");
		$targets = $has_any ? array() : $this->get_selected_plugin_ids();

		// Always refresh the scope the dashboard needs most: every selected
		// plugin, so the all-plugin view resolves names too.
		foreach ($this->get_selected_plugin_ids() as $target) {
			$this->sync_coupons((int) $target, $api);
		}

		// Cached payloads were built with "definition unavailable" rows.
		update_option('rl_fsbi_cache_generation', time(), false);
	}

	/**
	 * Coupon definitions cached per plugin, merged for an all-plugin view.
	 *
	 * @param int $plugin_id Plugin scope, 0 for all.
	 * @return array
	 */
	private function collect_cached_coupon_definitions($plugin_id)
	{
		global $wpdb;

		$plugin_id = (int) $plugin_id;
		if ($plugin_id > 0) {
			return (array) get_option('rl_fsbi_coupons_' . $plugin_id, array());
		}

		$definitions = array();
		foreach ((array) $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rl_fsbi_coupons\_%'") as $option_name) {
			foreach ((array) get_option($option_name, array()) as $row) {
				if (! empty($row['id'])) {
					$definitions[(int) $row['id']] = $row;
				}
			}
		}

		return array_values($definitions);
	}

	/**
	 * Aggregated, non-personal summary for the read-only REST endpoint.
	 *
	 * Deliberately contains no identifiers: no emails, no user ids, no API
	 * keys. Coupon codes are included because a discount code is marketing
	 * collateral, not personal data.
	 *
	 * @param int    $plugin_id Plugin scope, 0 for all.
	 * @param int    $months    Trailing months to report.
	 * @param string $currency  Report currency for revenue figures.
	 * @return array
	 */
	public function get_summary_report($plugin_id = 0, $months = 12, $currency = 'EUR')
	{
		$months  = max(1, min(36, (int) $months));
		$currency = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $currency));
		if ('' === $currency) {
			$currency = 'EUR';
		}

		$repo = new RL_FSBI_Repository();
		$now  = current_time('timestamp');
		$end_date = wp_date('Y-m-d', $now);
		$start_ts = strtotime(wp_date('Y-m-01', $now) . ' -' . ($months - 1) . ' months');
		$start_date = wp_date('Y-m-d', $start_ts);

		$payments      = $repo->get_payments($plugin_id, $start_date, $end_date, null);
		$subscriptions = $this->get_subscriptions_for_dashboard($plugin_id, null);
		$balances      = $this->get_balances_for_dashboard($plugin_id, null);

		$commission_rate = $this->get_balance_commission_rate($balances);
		if ($commission_rate <= 0) {
			$commission_rate = $this->get_legacy_commission_rate($payments);
		}

		$labels = array();
		for ($i = $months - 1; $i >= 0; $i--) {
			$labels[] = wp_date('Y-m', strtotime('-' . $i . ' months', $now));
		}

		$per_month = array();
		foreach ($labels as $label) {
			$per_month[$label] = array(
				'net_revenue' => 0.0,
				'gross'       => 0.0,
				'refunds'     => 0.0,
				'refund_count' => 0,
				'renewals'    => 0,
			);
		}

		foreach ($payments as $payment) {
			$month = substr((string) $payment->transaction_date, 0, 7);
			if (! isset($per_month[$month])) {
				continue;
			}

			$amounts = $this->resolve_payment_amounts_for_display($payment, $commission_rate, $currency);

			if ($this->is_refund_payment($payment)) {
				$per_month[$month]['refund_count']++;
				$per_month[$month]['refunds'] += abs((float) $amounts['gross']);
				continue;
			}

			$per_month[$month]['net_revenue'] += (float) $amounts['net'];
			$per_month[$month]['gross']       += (float) $amounts['gross'];

			if ($this->is_renewal_payment($payment)) {
				$per_month[$month]['renewals']++;
			}
		}

		$growth = $this->build_new_subscription_series($payments, $subscriptions, $months);

		$cancellations = array();
		foreach ($labels as $label) {
			$month_start = strtotime($label . '-01 00:00:00');
			$month_end   = min(strtotime($label . '-01 +1 month -1 second'), $now);
			$window      = $this->count_churn_window($subscriptions, $month_start, $month_end);
			$cancellations[] = $window['cancellations'];
		}

		$mrr_snapshot = $this->calculate_period_mrr($subscriptions, $end_date, $currency);

		$this->ensure_coupon_definitions($plugin_id);

		$coupons = $this->build_coupon_report(
			$payments,
			$this->collect_cached_coupon_definitions($plugin_id),
			$commission_rate,
			$currency
		);

		$net_by_month = array();
		$gross_by_month = array();
		$refunds_by_month = array();
		foreach ($labels as $label) {
			$net_by_month[$label]     = round($per_month[$label]['net_revenue'], 2);
			$gross_by_month[$label]   = round($per_month[$label]['gross'], 2);
			$refunds_by_month[$label] = round($per_month[$label]['refunds'], 2);
		}

		return array(
			'generated_at'  => gmdate('c'),
			'plugin_id'     => (int) $plugin_id,
			'currency'      => $currency,
			'period'        => array('start' => $start_date, 'end' => $end_date, 'months' => $months),
			'new_subscriptions' => array(
				'months'       => $growth['labels'],
				'new'          => $growth['new'],
				'reactivation' => $growth['reactivation'],
				'total'        => $growth['total'],
			),
			'renewals'      => array(
				'months' => $labels,
				'counts' => array_values(array_map(static function ($row) {
					return (int) $row['renewals'];
				}, $per_month)),
			),
			'cancellations' => array(
				'months' => $labels,
				'counts' => $cancellations,
			),
			'refunds'       => array(
				'months'  => $labels,
				'amounts' => array_values($refunds_by_month),
				'counts'  => array_values(array_map(static function ($row) {
					return (int) $row['refund_count'];
				}, $per_month)),
			),
			'mrr'           => round((float) $mrr_snapshot['converted'], 2),
			'net_revenue_by_month' => $net_by_month,
			'gross_revenue_by_month' => $gross_by_month,
			'coupons'       => array_map(static function ($coupon) {
				return array(
					'code'           => (string) $coupon['code'],
					'discount'       => $coupon['discount'],
					'status'         => (string) $coupon['status'],
					'uses'           => (int) $coupon['uses'],
					'limit'          => $coupon['limit'],
					'remaining'      => $coupon['remaining'],
					'expires'        => (string) $coupon['expires'],
					'revenue'        => (float) $coupon['revenue'],
					'discount_given' => (float) $coupon['discount_given'],
				);
			}, $coupons),
		);
	}

	/**
	 * WordPress.org install and download figures, cached for a day.
	 *
	 * Public API, no key. These numbers describe free-plugin installs, which is
	 * a different population from the Freemius install records used elsewhere
	 * on the dashboard, so they are kept apart and never summed.
	 *
	 * @param string $slug WordPress.org plugin slug.
	 * @return array{available:bool,slug:string,reason?:string,active_installs?:int,total_downloads?:int,version?:string,last_updated?:string,downloads?:array}
	 */
	public function get_wporg_snapshot($slug)
	{
		$slug = sanitize_title($slug);
		if ('' === $slug) {
			return array('available' => false, 'slug' => '', 'reason' => 'no_slug');
		}

		$cache_key = 'rl_fsbi_wporg_' . md5($slug);
		$cached    = get_transient($cache_key);
		if (is_array($cached)) {
			return $cached;
		}

		// 1.2 reports active installs but never the download total, and 1.0 is the
		// other way round, so both are read. The pair is cached for a day.
		$info_url = add_query_arg(
			array(
				'action'        => 'plugin_information',
				'request[slug]' => $slug,
			),
			'https://api.wordpress.org/plugins/info/1.2/'
		);

		$response = wp_remote_get($info_url, array('timeout' => 15, 'headers' => array('Accept' => 'application/json')));

		$snapshot = array('available' => false, 'slug' => $slug, 'reason' => 'unreachable');

		if (is_wp_error($response)) {
			$snapshot['detail'] = $response->get_error_message();
		} else {
			$snapshot['detail'] = 'HTTP ' . (int) wp_remote_retrieve_response_code($response);
		}

		$this->log_wporg('info request', $info_url, $snapshot['detail']);

		if (! is_wp_error($response) && 200 === (int) wp_remote_retrieve_response_code($response)) {
			$body = json_decode((string) wp_remote_retrieve_body($response), true);

			if (is_array($body) && empty($body['error'])) {
				$snapshot = array(
					'available'        => true,
					'slug'             => $slug,
					'name'             => isset($body['name']) ? sanitize_text_field((string) $body['name']) : '',
					'active_installs'  => isset($body['active_installs']) ? (int) $body['active_installs'] : 0,
					'total_downloads'  => 0,
					'version'          => isset($body['version']) ? sanitize_text_field((string) $body['version']) : '',
					'last_updated'     => isset($body['last_updated']) ? sanitize_text_field((string) $body['last_updated']) : '',
					'fetched_at'       => gmdate('Y-m-d H:i:s'),
					'downloads'        => array('labels' => array(), 'values' => array()),
				);

				$totals = wp_remote_get(
					'https://api.wordpress.org/plugins/info/1.0/' . rawurlencode($slug) . '.json',
					array('timeout' => 15, 'headers' => array('Accept' => 'application/json'))
				);

				if (! is_wp_error($totals) && 200 === (int) wp_remote_retrieve_response_code($totals)) {
					$totals_body = json_decode((string) wp_remote_retrieve_body($totals), true);
					if (is_array($totals_body) && isset($totals_body['downloaded'])) {
						$snapshot['total_downloads'] = (int) $totals_body['downloaded'];
					}
				}

				$stats_url = add_query_arg(
					array('slug' => $slug, 'limit' => 90),
					'https://api.wordpress.org/stats/plugin/1.0/downloads.php'
				);
				$stats = wp_remote_get($stats_url, array('timeout' => 15, 'headers' => array('Accept' => 'application/json')));

				if (! is_wp_error($stats) && 200 === (int) wp_remote_retrieve_response_code($stats)) {
					$raw = json_decode((string) wp_remote_retrieve_body($stats), true);
					if (is_array($raw) && ! empty($raw)) {
						ksort($raw);
						$snapshot['downloads'] = array(
							'labels' => array_keys($raw),
							'values' => array_map('intval', array_values($raw)),
						);
					}
				}
			} elseif (is_array($body) && ! empty($body['error'])) {
				$snapshot['reason'] = 'not_found';
			}
		}

		// A failure is not worth a day. Caching it that long left the widget
		// empty until the cache expired, with no way to retry but re-saving the
		// slug, so a single blip at api.wordpress.org lasted 24 hours.
		set_transient($cache_key, $snapshot, ! empty($snapshot['available']) ? DAY_IN_SECONDS : 5 * MINUTE_IN_SECONDS);

		return $snapshot;
	}

	/**
	 * Record a WordPress.org lookup when Debug Mode is on.
	 *
	 * @param string $label  What was requested.
	 * @param string $url    Request URL.
	 * @param string $detail Outcome, e.g. "HTTP 200".
	 * @return void
	 */
	private function log_wporg($label, $url, $detail)
	{
		if (! $this->settings->is_debug_mode()) {
			return;
		}

		error_log(sprintf('[rl-fsbi] wp.org %s -> %s (%s)', $label, $detail, $url)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Plans that have data, for the toolbar filter.
	 *
	 * @param int $plugin_id Optional plugin scope.
	 * @return array<int,array{id:int,name:string}>
	 */
	private function get_plans_for_filter($plugin_id = 0)
	{
		global $wpdb;

		$subscriptions_table = $wpdb->prefix . 'rl_fsbi_subscriptions';
		$plans_table         = $wpdb->prefix . 'rl_fsbi_plans';

		$query = "SELECT DISTINCT s.plan_id, p.plan_name
			FROM {$subscriptions_table} s
			LEFT JOIN {$plans_table} p ON p.plan_id = s.plan_id
			WHERE s.plan_id > 0";

		if ($plugin_id > 0) {
			$query .= $wpdb->prepare(' AND s.plugin_id = %d', $plugin_id);
		}

		$query .= ' ORDER BY p.plan_name ASC, s.plan_id ASC';

		$plans = array();
		foreach ((array) $wpdb->get_results($query) as $row) {
			$name = trim((string) ($row->plan_name ?? ''));
			$plans[] = array(
				'id'   => (int) $row->plan_id,
				'name' => '' !== $name ? $name : 'Plan ' . (int) $row->plan_id,
			);
		}

		return $plans;
	}

	/**
	 * Plan id to plan name, for display and for the plan filter.
	 *
	 * @param int $plugin_id Optional plugin scope.
	 * @return array<string,string>
	 */
	private function get_plan_name_map($plugin_id = 0)
	{
		global $wpdb;

		$table = $wpdb->prefix . 'rl_fsbi_plans';
		$query = "SELECT plan_id, plan_name FROM {$table} WHERE plan_name <> ''";

		if ($plugin_id > 0) {
			$query .= $wpdb->prepare(' AND plugin_id = %d', $plugin_id);
		}

		$map = array();
		foreach ((array) $wpdb->get_results($query) as $row) {
			$map[(string) $row->plan_id] = (string) $row->plan_name;
		}

		return $map;
	}

	/**
	 * Count first payments per month, split into new customers and reactivations.
	 *
	 * A reactivation is a first payment from someone who already held a
	 * subscription that had ended — they came back rather than arrived.
	 *
	 * @param array $payments      Payment rows (12 months recommended).
	 * @param array $subscriptions All subscription rows for the scope.
	 * @param int   $months        Trailing months to report.
	 * @return array{labels:array,new:array,reactivation:array,total:array,target:int,current_total:int,current_new:int,current_reactivation:int}
	 */
	private function build_new_subscription_series($payments, $subscriptions, $months = 6)
	{
		$labels       = array();
		$new_counts   = array();
		$re_counts    = array();
		$now          = current_time('timestamp');

		for ($i = $months - 1; $i >= 0; $i--) {
			$key = wp_date('Y-m', strtotime('-' . $i . ' months', $now));
			$labels[]      = $key;
			$new_counts[$key] = 0;
			$re_counts[$key]  = 0;
		}

		$ended_by_user = $this->build_ended_subscription_index($subscriptions);

		$seen_subscriptions = array();

		foreach ($payments as $payment) {
			if ($this->is_refund_payment($payment) || $this->is_renewal_payment($payment)) {
				continue;
			}

			$month = substr((string) $payment->transaction_date, 0, 7);
			if (! isset($new_counts[$month])) {
				continue;
			}

			$subscription_key = (int) ($payment->subscription_id ?? 0);
			if ($subscription_key > 0) {
				if (isset($seen_subscriptions[$subscription_key])) {
					continue;
				}
				$seen_subscriptions[$subscription_key] = true;
			}

			$paid_at = strtotime((string) $payment->transaction_date);
			$user_id = (int) ($payment->user_id ?? 0);
			$is_returning = false;

			if ($user_id > 0 && ! empty($ended_by_user[$user_id])) {
				foreach ($ended_by_user[$user_id] as $prior_id => $ended_at) {
					if ($prior_id === $subscription_key) {
						continue;
					}
					if ($ended_at < $paid_at) {
						$is_returning = true;
						break;
					}
				}
			}

			if ($is_returning) {
				$re_counts[$month]++;
			} else {
				$new_counts[$month]++;
			}
		}

		$target = (int) $this->settings->get_option('rl_fsbi_monthly_subscription_target', 5);
		$total  = array();
		foreach ($labels as $label) {
			$total[] = $new_counts[$label] + $re_counts[$label];
		}

		$current = end($labels);

		return array(
			'labels'       => $labels,
			'new'          => array_values($new_counts),
			'reactivation' => array_values($re_counts),
			'total'        => $total,
			'target'       => max(0, $target),
			'current_total' => (int) ($total ? $total[count($total) - 1] : 0),
			'current_new'  => (int) ($new_counts[$current] ?? 0),
			'current_reactivation' => (int) ($re_counts[$current] ?? 0),
		);
	}

	/**
	 * Renewal load, payment trouble and cancellations for the retention block.
	 *
	 * @param array $subscriptions     Subscription rows.
	 * @param array $subscription_stats Result of calculate_subscription_stats().
	 * @return array
	 */
	private function build_retention_snapshot($subscriptions, $subscription_stats)
	{
		$now      = current_time('timestamp');
		$horizon  = strtotime('+30 days', $now);
		$upcoming = 0;
		$upcoming_amount = 0.0;
		$at_risk  = 0;
		$at_risk_amount = 0.0;
		$failed   = 0;

		// Restricted to the rows the Active Subscriptions KPI counted. Reading
		// the raw status instead produced 337 "failed payment" subscriptions
		// against a KPI of 190, because the KPI also requires a known billing
		// cycle and excludes expired subscriptions.
		$active_ids = isset($subscription_stats['active_ids']) ? array_flip((array) $subscription_stats['active_ids']) : array();

		foreach ($subscriptions as $subscription) {
			if (! empty($active_ids) && ! isset($active_ids[(int) ($subscription->id ?? 0)])) {
				continue;
			}

			$failed_count = isset($subscription->failed_payments) ? (int) $subscription->failed_payments : 0;
			if ($failed_count > 0) {
				$failed++;
			}

			$renewal_at = ! empty($subscription->next_renewal)
				? strtotime((string) $subscription->next_renewal)
				: (! empty($subscription->expires_at) ? strtotime((string) $subscription->expires_at) : false);

			if (false === $renewal_at || $renewal_at < $now || $renewal_at > $horizon) {
				continue;
			}

			$upcoming++;
			$upcoming_amount += (float) $this->convert_currency_amount(
				$this->get_forecast_renewal_amount($subscription),
				strtoupper((string) ($subscription->currency ?? 'EUR')),
				(string) $this->conversion_currency,
				(string) ($subscription->next_renewal ?: '')
			);

			$outstanding = isset($subscription->outstanding_balance) ? (float) $subscription->outstanding_balance : 0.0;
			if ($failed_count > 0 || $outstanding > 0) {
				$at_risk++;
				$at_risk_amount += isset($subscription->renewal_amount) ? (float) $subscription->renewal_amount : 0.0;
			}
		}

		return array(
			'renewals'                 => (int) ($subscription_stats['monthly'] + $subscription_stats['annual']),
			'active'                   => (int) $subscription_stats['active'],
			'upcoming_30d'             => $upcoming,
			'upcoming_amount'          => round((float) $upcoming_amount, 2),
			'at_risk_30d'              => $at_risk,
			'at_risk_amount'           => round($at_risk_amount, 2),
			'failed_payments'          => $failed,
			// Counted with the same window the churn rate uses, which is the
			// figure the card sits next to. Reading the status column's update
			// month instead reported 0 cancellations beside a 1.6% churn rate.
			'cancellations_this_month' => (int) $this->count_churn_window(
				$subscriptions,
				strtotime(wp_date('Y-m-01', $now) . ' 00:00:00'),
				$now
			)['cancellations'],
			'churn_rate'               => round((float) $subscription_stats['churn_rate'], 2),
		);
	}

	/**
	 * Map user id to the end timestamps of their finished subscriptions.
	 *
	 * A first payment from a user who already has an ended subscription is a
	 * reactivation, not a new customer.
	 *
	 * @param array $subscriptions Subscription rows.
	 * @return array<int,array<int,int>>
	 */
	private function build_ended_subscription_index($subscriptions)
	{
		$ended_by_user = array();

		foreach ($subscriptions as $subscription) {
			$user_id = isset($subscription->user_id) ? (int) $subscription->user_id : 0;
			if ($user_id <= 0) {
				continue;
			}

			$meta   = json_decode((string) ($subscription->metadata ?? ''), true);
			$status = strtolower((string) ($subscription->status ?? ''));

			$ended = false;
			if (! empty($subscription->expires_at)) {
				$ended = strtotime((string) $subscription->expires_at);
			}
			if (is_array($meta) && ! empty($meta['canceled_at'])) {
				$canceled = strtotime((string) $meta['canceled_at']);
				$ended    = $ended ? min($ended, $canceled) : $canceled;
			} elseif (in_array($status, array('cancelled', 'canceled'), true) && ! empty($subscription->updated_at)) {
				$canceled = strtotime((string) $subscription->updated_at);
				$ended    = $ended ? min($ended, $canceled) : $canceled;
			}

			if (false === $ended) {
				continue;
			}

			$ended_by_user[$user_id][(int) $subscription->subscription_id] = $ended;
		}

		return $ended_by_user;
	}

	/**
	 * Split the first payments in a set into new customers and reactivations.
	 *
	 * @param array $payments      Payment rows for the window.
	 * @param array $ended_by_user Index from build_ended_subscription_index().
	 * @return array{new:int,reactivation:int}
	 */
	private function classify_first_payments($payments, $ended_by_user)
	{
		$new = 0;
		$reactivation = 0;
		$seen = array();

		foreach ($payments as $payment) {
			if ($this->is_refund_payment($payment) || $this->is_renewal_payment($payment)) {
				continue;
			}

			$subscription_key = (int) ($payment->subscription_id ?? 0);
			if ($subscription_key > 0) {
				if (isset($seen[$subscription_key])) {
					continue;
				}
				$seen[$subscription_key] = true;
			}

			$paid_at = strtotime((string) $payment->transaction_date);
			$user_id = (int) ($payment->user_id ?? 0);
			$is_returning = false;

			if ($user_id > 0 && ! empty($ended_by_user[$user_id])) {
				foreach ($ended_by_user[$user_id] as $prior_id => $ended_at) {
					if ($prior_id !== $subscription_key && $ended_at < $paid_at) {
						$is_returning = true;
						break;
					}
				}
			}

			if ($is_returning) {
				$reactivation++;
			} else {
				$new++;
			}
		}

		return array('new' => $new, 'reactivation' => $reactivation);
	}

	/**
	 * Bucket payments by the checkout entry point recorded on them.
	 *
	 * Freemius records a numeric `source` enum, which is not an origin, so only
	 * campaign or referral fields count. Everything else is honestly unknown.
	 *
	 * @param array $payments Payment rows.
	 * @return array{labels:array,values:array,unknown:int}
	 */
	private function build_sales_origin_series($payments)
	{
		$bucket = array();
		$unknown = 0;
		$keys = array('utm_source', 'utm_medium', 'utm_campaign', 'checkout_origin', 'referrer', 'landing_page');

		foreach ($payments as $payment) {
			if ($this->is_refund_payment($payment) || $this->is_renewal_payment($payment)) {
				continue;
			}

			$meta = ! empty($payment->metadata) ? json_decode((string) $payment->metadata, true) : array();
			$label = '';

			if (is_array($meta)) {
				foreach ($keys as $key) {
					if (! empty($meta[$key]) && is_string($meta[$key])) {
						$label = $key . ': ' . $meta[$key];
						break;
					}
				}
			}

			if ('' === $label) {
				$unknown++;
				continue;
			}

			$label = mb_substr($label, 0, 60);
			$bucket[$label] = ($bucket[$label] ?? 0) + 1;
		}

		arsort($bucket);
		$bucket = array_slice($bucket, 0, 6, true);

		return array(
			'labels' => array_keys($bucket),
			'values' => array_values($bucket),
			'unknown' => $unknown,
		);
	}

	private function build_plan_distribution_series($subscriptions, $active_ids = array())
	{
		$bucket = array();
		$names  = $this->get_plan_name_map();
		$allowed = ! empty($active_ids) ? array_flip((array) $active_ids) : array();

		foreach ($subscriptions as $subscription) {
			// Same population as the Active Subscriptions KPI, so the shares add
			// up to it instead of to every subscription ever recorded.
			if (! empty($allowed) && ! isset($allowed[(int) ($subscription->id ?? 0)])) {
				continue;
			}

			$plan_id = ! empty($subscription->plan_id) ? (string) $subscription->plan_id : 'unknown';
			$label = isset($names[$plan_id]) ? $names[$plan_id] : 'Plan ' . $plan_id;
			if (! isset($bucket[$label])) {
				$bucket[$label] = 0;
			}
			$bucket[$label]++;
		}

		arsort($bucket);
		$bucket = array_slice($bucket, 0, 8, true);

		return array(
			'labels' => array_keys($bucket),
			'values' => array_values($bucket),
		);
	}

	/**
	 * Count payment events per day over the last 90 days.
	 *
	 * This is Freemius billing activity. It is not a WordPress.org install
	 * count, and the two must never be presented as one series.
	 */
	private function build_daily_sales_volume_series($payments)
	{
		$bucket = array();
		$start_ts = strtotime('-90 days');

		for ($ts = $start_ts; $ts <= time(); $ts += DAY_IN_SECONDS) {
			$key = gmdate('Y-m-d', $ts);
			$bucket[$key] = 0;
		}

		foreach ($payments as $payment) {
			if ($this->is_refund_payment($payment)) {
				continue;
			}
			$key = substr((string) $payment->transaction_date, 0, 10);
			if (isset($bucket[$key])) {
				$bucket[$key]++;
			}
		}

		return array(
			'labels' => array_keys($bucket),
			'values' => array_values($bucket),
		);
	}

	/**
	 * Build monthly revenue breakdown table rows and totals.
	 */
	private function build_monthly_breakdown($payments, $report_currency, $commission_rate = 0.0, $months_count = 12)
	{
		$report_currency = strtoupper((string) $report_currency);
		$months_count = max(1, (int) $months_count);
		$months = array();
		$positive_payments = array();
		$refund_events = array();

		for ($i = $months_count - 1; $i >= 0; $i--) {
			$key = gmdate('Y-m', strtotime('-' . $i . ' months'));
			$months[$key] = array(
				'month'                   => $key,
				'currencies'              => array(),
				'payout_breakdown'        => array(),
				'payout_breakdown_native' => array(),
				'payout_total'            => 0.0,
				'payout_eligible'         => false,
			);
		}

		foreach ($payments as $payment) {
			$key = substr((string) $payment->transaction_date, 0, 7);
			if (! isset($months[$key])) {
				continue;
			}

			$source_currency = ! empty($payment->currency) ? strtoupper((string) $payment->currency) : $report_currency;
			$effective_date = isset($payment->transaction_date) ? (string) $payment->transaction_date : gmdate('Y-m-d H:i:s');
			$raw_amounts = $this->resolve_payment_amounts($payment, $commission_rate);
			$converted_amounts = array(
				'gross' => $this->convert_currency_amount((float) $raw_amounts['gross'], $source_currency, $report_currency, $effective_date),
				'fee'   => $this->convert_currency_amount((float) $raw_amounts['fee'], $source_currency, $report_currency, $effective_date),
				'net'   => $this->convert_currency_amount((float) $raw_amounts['net'], $source_currency, $report_currency, $effective_date),
			);

			if (! isset($months[$key]['currencies'][$source_currency])) {
				$months[$key]['currencies'][$source_currency] = array(
					'currency'          => $source_currency,
					'gross'             => 0.0,
					'fees'              => 0.0,
					'refunds'           => 0.0,
					'net'               => 0.0,
					'sales_net'         => 0.0,
					'gross_converted'   => 0.0,
					'fees_converted'    => 0.0,
					'refunds_converted' => 0.0,
					'net_converted'     => 0.0,
					'subscriptions'     => 0,
					'new'               => 0,
					'renewals'          => 0,
					'transactions'      => 0,
				);
			}

			$bucket = &$months[$key]['currencies'][$source_currency];
			$bucket['gross'] += (float) $raw_amounts['gross'];
			$bucket['fees'] += (float) $raw_amounts['fee'];
			$bucket['net'] += (float) $raw_amounts['net'];
			$bucket['gross_converted'] += (float) $converted_amounts['gross'];
			$bucket['fees_converted'] += (float) $converted_amounts['fee'];
			$bucket['net_converted'] += (float) $converted_amounts['net'];
			$bucket['transactions']++;

			$is_refund = $this->is_refund_payment($payment);
			if ($is_refund) {
				$refund_source_value = abs((float) $raw_amounts['net']) > 0 ? abs((float) $raw_amounts['net']) : abs((float) $raw_amounts['gross']);
				$refund_converted_value = abs((float) $converted_amounts['net']) > 0 ? abs((float) $converted_amounts['net']) : abs((float) $converted_amounts['gross']);
				$bucket['refunds'] += $refund_source_value;
				$bucket['refunds_converted'] += $refund_converted_value;
				$refund_events[] = array(
					'currency'        => $source_currency,
					'subscription_id' => (string) ($payment->subscription_id ?? ''),
					'payment_id'      => (string) ($payment->refund_id ?? ''),
					'month'           => $key,
					'date'            => (string) ($payment->transaction_date ?? ''),
					'amount'          => $refund_source_value,
					'metadata'        => json_decode((string) ($payment->metadata ?? ''), true),
				);
			} else {
				$bucket['subscriptions']++;
				$bucket['sales_net'] += (float) $raw_amounts['net'];
				$positive_payments[] = array(
					'payment_id'      => (string) ($payment->payment_id ?? ''),
					'currency'        => $source_currency,
					'subscription_id' => (string) ($payment->subscription_id ?? ''),
					'month'           => $key,
					'date'            => (string) ($payment->transaction_date ?? ''),
				);
				if ($this->is_renewal_payment($payment)) {
					$bucket['renewals']++;
				} else {
					$bucket['new']++;
				}
			}
			unset($bucket);
		}

		// Freemius calculates month X earnings after X+1 closes. Refunds in X/X+1
		// reduce X sales; older refunds are late refunds against the processing month.
		foreach ($refund_events as $refund) {
			$refund_month = $refund['month'];
			$original_month = '';
			$metadata = is_array($refund['metadata']) ? $refund['metadata'] : array();
			$bound_payment_id = ! empty($metadata['bound_payment_id']) ? (string) $metadata['bound_payment_id'] : $refund['payment_id'];

			foreach ($positive_payments as $positive) {
				if ($positive['currency'] !== $refund['currency'] || $positive['subscription_id'] !== $refund['subscription_id']) {
					continue;
				}
				if ($bound_payment_id && $positive['payment_id'] === $bound_payment_id) {
					$original_month = $positive['month'];
					break;
				}
				if ($positive['date'] <= $refund['date']) {
					$original_month = $positive['month'];
				}
			}

			$target_month = $refund_month;
			if ($original_month) {
				$original_timestamp = strtotime($original_month . '-01');
				$refund_timestamp = strtotime($refund_month . '-01');
				$month_difference = ((int) gmdate('Y', $refund_timestamp) - (int) gmdate('Y', $original_timestamp)) * 12 + (int) gmdate('n', $refund_timestamp) - (int) gmdate('n', $original_timestamp);
				if ($month_difference <= 1) {
					$target_month = $original_month;
				}
			}

			if (isset($months[$target_month]['currencies'][$refund['currency']])) {
				$months[$target_month]['currencies'][$refund['currency']]['refunds_for_payout'] = (float) ($months[$target_month]['currencies'][$refund['currency']]['refunds_for_payout'] ?? 0) + (float) $refund['amount'];
			}
		}

		$current_payout_month = gmdate('Y-m', strtotime('-2 months'));
		$next_payout_month = gmdate('Y-m', strtotime('-1 month'));
		$payout_threshold = 100.0;
		$carry_by_currency = array();

		ksort($months);
		foreach ($months as $month_key => $month_stats) {
			$months[$month_key]['payout_breakdown'] = array();
			$months[$month_key]['payout_breakdown_native'] = array();
			$months[$month_key]['payout_total'] = 0.0;
			$months[$month_key]['payout_eligible'] = false;

			foreach ($month_stats['currencies'] as $currency_code => $bucket) {
				$carry = isset($carry_by_currency[$currency_code]) ? (float) $carry_by_currency[$currency_code] : 0.0;
				$payout_basis_source = (float) $bucket['sales_net'] - (float) ($bucket['refunds_for_payout'] ?? 0);

				$payout_date = gmdate('Y-m-d H:i:s', strtotime($month_key . '-10 +2 months'));

				// Freemius applies the minimum independently per native currency.
				$eligible_pool_native = $carry + (float) $payout_basis_source;
				$is_eligible = $eligible_pool_native >= $payout_threshold;

				if ($is_eligible) {
					$payout_amount_native = round($eligible_pool_native, 2);
					$payout_amount = $this->convert_currency_amount($payout_amount_native, $currency_code, $report_currency, $payout_date);
					$months[$month_key]['payout_breakdown'][$currency_code] = $payout_amount;
					$months[$month_key]['payout_breakdown_native'][$currency_code] = $payout_amount_native;
					$months[$month_key]['payout_total'] += (float) $payout_amount;
					$months[$month_key]['payout_eligible'] = true;
					$months[$month_key]['currencies'][$currency_code]['payout_amount'] = $payout_amount;
					$months[$month_key]['currencies'][$currency_code]['carry_after'] = 0.0;
					$months[$month_key]['currencies'][$currency_code]['payout_basis_native'] = $payout_amount_native;
					$months[$month_key]['currencies'][$currency_code]['payout_basis_converted'] = $payout_amount;
					$carry_by_currency[$currency_code] = 0.0;
				} else {
					$months[$month_key]['currencies'][$currency_code]['payout_amount'] = 0.0;
					$months[$month_key]['currencies'][$currency_code]['carry_after'] = round($eligible_pool_native, 2);
					$months[$month_key]['currencies'][$currency_code]['payout_basis_native'] = round((float) $payout_basis_source, 2);
					$months[$month_key]['currencies'][$currency_code]['payout_basis_converted'] = 0.0;
					$carry_by_currency[$currency_code] = $eligible_pool_native;
				}
			}

			$months[$month_key]['payout_total'] = round((float) $months[$month_key]['payout_total'], 2);
		}

		$rows = array();
		$totals = array(
			'gross'        => 0.0,
			'fees'         => 0.0,
			'refunds'      => 0.0,
			'net'          => 0.0,
			'transactions' => 0,
		);
		$currency_rank = array('EUR' => 0, 'USD' => 1, 'GBP' => 2);

		foreach (array_reverse($months, true) as $month_key => $stats) {
			$period_label = gmdate('m/Y', strtotime($month_key . '-01'));
			$is_current_payout = $month_key === $current_payout_month;
			$is_next_payout = $month_key === $next_payout_month;

			if (empty($stats['currencies'])) {
				$rows[] = array(
					'month'              => $month_key,
					'period'             => $period_label,
					'subscriptions'      => 0,
					'new'                => 0,
					'renewals'           => 0,
					'gross'              => 0.0,
					'refunds'            => 0.0,
					'fees'               => 0.0,
					'net'                => 0.0,
					'currency'           => $report_currency,
					'show_period_badges' => true,
					'show_payout'        => true,
					'is_current_payout'       => $is_current_payout,
					'is_next_payout'          => $is_next_payout,
					'payout_breakdown'        => $stats['payout_breakdown'],
					'payout_breakdown_native' => $stats['payout_breakdown_native'] ?? array(),
					'payout_total'            => (float) $stats['payout_total'],
				);
				continue;
			}

			$currency_codes = array_keys($stats['currencies']);
			usort(
				$currency_codes,
				function ($a, $b) use ($currency_rank) {
					$rank_a = isset($currency_rank[$a]) ? (int) $currency_rank[$a] : 99;
					$rank_b = isset($currency_rank[$b]) ? (int) $currency_rank[$b] : 99;
					if ($rank_a === $rank_b) {
						return strcmp($a, $b);
					}
					return $rank_a - $rank_b;
				}
			);

			$first_row = true;
			foreach ($currency_codes as $currency_code) {
				$bucket = $stats['currencies'][$currency_code];
				$totals['gross'] += (float) $bucket['gross_converted'];
				$totals['fees'] += (float) $bucket['fees_converted'];
				$totals['refunds'] += (float) $bucket['refunds_converted'];
				$totals['net'] += (float) $bucket['net_converted'];
				$totals['transactions'] += (int) $bucket['transactions'];

				$rows[] = array(
					'month'              => $month_key,
					'period'             => $period_label,
					'subscriptions'      => (int) $bucket['subscriptions'],
					'new'                => (int) $bucket['new'],
					'renewals'           => (int) $bucket['renewals'],
					'gross'              => round((float) $bucket['gross'], 2),
					'refunds'            => round((float) $bucket['refunds'], 2),
					'fees'               => round((float) $bucket['fees'], 2),
					'net'                => round((float) $bucket['net'], 2),
					'currency'           => $currency_code,
					'show_period_badges' => $first_row,
					'show_payout'        => $first_row,
					'is_current_payout'       => $is_current_payout,
					'is_next_payout'          => $is_next_payout,
					'payout_breakdown'        => $first_row ? $stats['payout_breakdown'] : array(),
					'payout_breakdown_native' => $first_row ? ($stats['payout_breakdown_native'] ?? array()) : array(),
					'payout_total'            => $first_row ? (float) $stats['payout_total'] : 0.0,
				);

				$first_row = false;
			}
		}

		// One row per month for the report table: amounts converted into the
		// report currency, counts summed across every currency (a month with an
		// EUR and a USD renewal has two renewals, not one per row), the
		// per-currency detail kept alongside so it can be expanded, and the
		// payout state according to the month X -> 10th of X+2 rule.
		// Grouped so each month can be summarised by the one function that knows
		// how commission, VAT and gateway fees are netted. Reading the currency
		// buckets instead produced gross minus gateway with no commission in it,
		// which is why the table's net disagreed with the KPI above it.
		$payments_by_month = array();
		foreach ($payments as $payment) {
			$by_month = substr((string) ($payment->transaction_date ?? ''), 0, 7);
			if ('' === $by_month) {
				continue;
			}
			$payments_by_month[$by_month][] = $payment;
		}

		$month_rows = array();
		foreach (array_reverse($months, true) as $month_key => $stats) {
			$sum_gross = 0.0;
			$sum_refunds = 0.0;
			$sum_fees = 0.0;
			$sum_net = 0.0;
			$sum_new = 0;
			$sum_renewals = 0;
			$sum_sales = 0;
			$split = array();

			foreach ($stats['currencies'] as $currency_code => $bucket) {
				$sum_gross += (float) $bucket['gross_converted'];
				$sum_refunds += (float) $bucket['refunds_converted'];
				$sum_fees += (float) $bucket['fees_converted'];
				$sum_net += (float) $bucket['net_converted'];
				$sum_new += (int) $bucket['new'];
				$sum_renewals += (int) $bucket['renewals'];
				$sum_sales += (int) $bucket['subscriptions'];

				$split[] = array(
					'currency'   => $currency_code,
					'gross'      => round((float) $bucket['gross'], 2),
					'refunds'    => round((float) $bucket['refunds'], 2),
					'fees'       => round((float) $bucket['fees'], 2),
					'net'        => round((float) $bucket['net'], 2),
					'gross_converted' => round((float) $bucket['gross_converted'], 2),
					'new'        => (int) $bucket['new'],
					'renewals'   => (int) $bucket['renewals'],
				);
			}

			$now_month = gmdate('Y-m');
			if ($month_key === gmdate('Y-m', strtotime('-1 month'))) {
				$payout_status = 'current';
			} elseif ($month_key === $now_month) {
				$payout_status = 'next';
			} elseif (strtotime($month_key . '-01') > strtotime($now_month . '-01')) {
				$payout_status = 'future';
			} else {
				$payout_status = 'settled';
			}
			$payout_date = $this->estimate_payout_date($month_key);

			$month_summary = $this->summarise_month_payments(
				$payments_by_month[$month_key] ?? array(),
				$report_currency,
				$month_key . '-01'
			);

			$month_rows[] = array(
				'month'         => $month_key,
				'period'        => gmdate('m/Y', strtotime($month_key . '-01')),
				'new'           => $sum_new,
				'renewals'      => $sum_renewals,
				'sales'         => $sum_sales,
				'gross'         => (float) $month_summary['gross'],
				'refunds'       => (float) $month_summary['refunds'],
				'fees'          => (float) $month_summary['gateway'],
				'net'           => (float) $month_summary['net'],
				'commission'    => (float) $month_summary['commission'],
				'payout_status' => $payout_status,
				// What Freemius pays for the month is what the month netted:
				// gross - refunds - gateway - commission. The old carry-over pool
				// produced a payout larger than the net it came from.
				'payout_total'  => (float) $month_summary['net'],
				'payout_date'   => (string) ($payout_date['date'] ?? ''),
				'payout_label'  => (string) ($payout_date['label'] ?? ''),
				'split'         => $split,
			);
		}

		$current_payout_amount = isset($months[$current_payout_month]['payout_total']) ? (float) $months[$current_payout_month]['payout_total'] : 0.0;
		$current_payout_eligible = ! empty($months[$current_payout_month]['payout_eligible']);
		$next_payout_amount = isset($months[$next_payout_month]['payout_total']) ? (float) $months[$next_payout_month]['payout_total'] : 0.0;
		$next_payout_eligible = ! empty($months[$next_payout_month]['payout_eligible']);

		return array(
			'rows' => $rows,
			'months' => $month_rows,
			'report_currency' => $report_currency,
			'current_payout_amount' => round($current_payout_amount, 2),
			'current_payout_eligible' => $current_payout_eligible,
			'next_payout_amount' => round($next_payout_amount, 2),
			'next_payout_eligible' => $next_payout_eligible,
			'totals' => array(
				'gross'        => round($totals['gross'], 2),
				'fees'         => round($totals['fees'], 2),
				'refunds'      => round($totals['refunds'], 2),
				'net'          => round($totals['net'], 2),
				'transactions' => (int) $totals['transactions'],
				'currency'     => $report_currency,
			),
		);
	}

	/**
	 * Resolve payment amounts and convert them to report currency when needed.
	 */
	private function is_refund_payment($payment)
	{
		$status = strtolower((string) ($payment->status ?? ''));
		return (int) ($payment->is_refund ?? 0) > 0
			|| (float) ($payment->gross ?? 0) < 0
			|| (float) ($payment->net ?? 0) < 0
			|| 'refunded' === $status
			|| 'refund' === $status;
	}

	private function is_renewal_payment($payment)
	{
		$meta = json_decode((string) ($payment->metadata ?? ''), true);
		if (is_array($meta) && array_key_exists('is_renewal', $meta)) {
			return (bool) $meta['is_renewal'];
		}

		if (isset($payment->is_renewal) && 1 === (int) $payment->is_renewal) {
			return true;
		}

		return ! empty($payment->subscription_id);
	}

	/**
	 * Use the Freemius activity date users see in their transactions list.
	 */
	private function resolve_freemius_payment_transaction_date($payment)
	{
		$timestamp = $this->get_payment_activity_timestamp($payment);

		return false !== $timestamp ? wp_date('Y-m-d H:i:s', $timestamp) : current_time('mysql');
	}

	private function get_payment_activity_timestamp($payment)
	{
		$data = is_array($payment) ? $payment : array();

		if (is_object($payment)) {
			$metadata = json_decode((string) ($payment->metadata ?? ''), true);
			if (is_array($metadata)) {
				$data = $metadata;
			}
		}

		$is_refund = is_array($data) && (
			! empty($data['refund_id'])
			|| (isset($data['gross']) && (float) $data['gross'] < 0)
			|| (isset($data['net']) && (float) $data['net'] < 0)
			|| in_array(strtolower((string) ($data['status'] ?? '')), array('refunded', 'refund'), true)
		);

		$date_keys = $is_refund
			? array('refunded_at', 'refund_date', 'refunded', 'updated', 'completed_at', 'paid_at', 'created')
			: array('paid_at', 'completed_at', 'charged_at', 'processed_at', 'payment_date', 'date', 'updated', 'created');

		foreach ($date_keys as $date_key) {
			if (is_array($data) && ! empty($data[$date_key])) {
				$timestamp = $this->normalize_freemius_date_timestamp($data[$date_key]);
				if (false !== $timestamp) {
					return $timestamp;
				}
			}
		}

		if (is_object($payment) && ! empty($payment->transaction_date)) {
			return $this->normalize_freemius_date_timestamp($payment->transaction_date);
		}

		return false;
	}

	private function normalize_freemius_date_timestamp($value)
	{
		if (is_numeric($value)) {
			$timestamp = (int) $value;
			if ($timestamp > 9999999999) {
				$timestamp = (int) floor($timestamp / 1000);
			}

			return $timestamp > 0 ? $timestamp : false;
		}

		$timestamp = strtotime((string) $value);

		return false !== $timestamp ? $timestamp : false;
	}

	/**
	 * Resolve payment amounts and convert them to report currency when needed.
	 */
	private function resolve_payment_amounts_for_display($payment, $commission_rate, $report_currency)
	{
		$amounts = $this->resolve_payment_amounts($payment, $commission_rate);
		$source_currency = ! empty($payment->currency) ? strtoupper((string) $payment->currency) : strtoupper((string) $report_currency);
		$target_currency = strtoupper((string) $report_currency);
		$effective_date = isset($payment->transaction_date) ? (string) $payment->transaction_date : gmdate('Y-m-d H:i:s');

		$amounts['gross'] = $this->convert_currency_amount((float) $amounts['gross'], $source_currency, $target_currency, $effective_date);
		$amounts['fee'] = $this->convert_currency_amount((float) $amounts['fee'], $source_currency, $target_currency, $effective_date);
		$amounts['net'] = $this->convert_currency_amount((float) $amounts['net'], $source_currency, $target_currency, $effective_date);

		return $amounts;
	}

	/**
	 * Convert an amount from source to target currency using configured provider (FreeCurrencyAPI, Wise, or None).
	 *
	 * @param float       $amount          Amount to convert.
	 * @param string      $source_currency Original currency code.
	 * @param string      $target_currency Target currency code.
	 * @param string|null $date_time       Optional transaction datetime.
	 * @return float Converted amount rounded to 2 decimals.
	 */
	public function convert_currency_amount($amount, $source_currency, $target_currency, $date_time = null)
	{
		$amount = (float) $amount;
		$source_currency = strtoupper((string) $source_currency);
		$target_currency = strtoupper((string) $target_currency);

		if (0.0 === $amount || '' === $source_currency || '' === $target_currency || $source_currency === $target_currency) {
			return $amount;
		}

		if ('none' === $this->conversion_provider) {
			return $amount;
		}

		$rate = 0.0;

		if ('freecurrencyapi' === $this->conversion_provider) {
			$rate = $this->get_freecurrencyapi_rate($source_currency, $target_currency, $date_time);
		} elseif ('wise' === $this->conversion_provider) {
			$rate = $this->get_wise_rate($source_currency, $target_currency, $date_time);
		} else {
			if (! empty($this->freecurrencyapi_key)) {
				$rate = $this->get_freecurrencyapi_rate($source_currency, $target_currency, $date_time);
			} elseif (! empty($this->transferwise_token)) {
				$rate = $this->get_wise_rate($source_currency, $target_currency, $date_time);
			}
		}

		if ($rate <= 0) {
			return $amount;
		}

		return round($amount * $rate, 2);
	}

	/**
	 * Fetch and cache FreeCurrencyAPI exchange rate.
	 *
	 * @param string      $source_currency Source currency code (e.g. USD, EUR, GBP).
	 * @param string      $target_currency Target currency code.
	 * @param string|null $date_time       Optional date context.
	 * @return float
	 */
	private function get_freecurrencyapi_rate($source_currency, $target_currency, $date_time = null)
	{
		$source_currency = strtoupper((string) $source_currency);
		$target_currency = strtoupper((string) $target_currency);

		if (empty($this->freecurrencyapi_key)) {
			return 0.0;
		}

		$cache_key = 'rl_fsbi_fx_fca_' . md5($source_currency . '|' . $target_currency);
		$cached_rate = get_transient($cache_key);
		if (false !== $cached_rate) {
			return (float) $cached_rate;
		}

		$request_url = add_query_arg(
			array(
				'apikey'        => $this->freecurrencyapi_key,
				'base_currency' => $source_currency,
				'currencies'    => $target_currency,
			),
			'https://api.freecurrencyapi.com/v1/latest'
		);

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if (is_wp_error($response)) {
			set_transient($cache_key, 0.0, HOUR_IN_SECONDS);
			return 0.0;
		}

		$status = wp_remote_retrieve_response_code($response);
		$body   = wp_remote_retrieve_body($response);
		if (200 !== (int) $status || empty($body)) {
			set_transient($cache_key, 0.0, HOUR_IN_SECONDS);
			return 0.0;
		}

		$decoded = json_decode($body, true);
		$rate    = 0.0;
		if (is_array($decoded) && isset($decoded['data'][$target_currency])) {
			$rate = (float) $decoded['data'][$target_currency];
		}

		if ($rate > 0) {
			set_transient($cache_key, $rate, DAY_IN_SECONDS);
		} else {
			set_transient($cache_key, 0.0, HOUR_IN_SECONDS);
		}

		return $rate;
	}

	/**
	 * Fetch and cache Wise rate (historical snapshot around payout day style).
	 */
	private function get_wise_rate($source_currency, $target_currency, $date_time = null)
	{
		$source_currency = strtoupper((string) $source_currency);
		$target_currency = strtoupper((string) $target_currency);

		$timestamp = $date_time ? strtotime((string) $date_time) : time();
		if (false === $timestamp) {
			$timestamp = time();
		}

		$year = gmdate('Y', $timestamp);
		$month = gmdate('m', $timestamp);
		$day = '10';
		$time_param = $year . '-' . $month . '-' . $day . 'T14:00:00';

		$cache_key = 'rl_fsbi_wise_' . md5($source_currency . '|' . $target_currency . '|' . $year . '-' . $month);
		$cached_rate = get_transient($cache_key);
		if (false !== $cached_rate) {
			return (float) $cached_rate;
		}

		$request_url = add_query_arg(
			array(
				'source' => $source_currency,
				'target' => $target_currency,
				'time'   => $time_param,
			),
			'https://api.transferwise.com/v1/rates'
		);

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->transferwise_token,
					'Application-Type' => 'application/json',
					'Accept'           => 'application/json',
				),
			)
		);

		if (is_wp_error($response)) {
			set_transient($cache_key, 0, HOUR_IN_SECONDS);
			return 0.0;
		}

		$status = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		if (200 !== (int) $status || empty($body)) {
			set_transient($cache_key, 0, HOUR_IN_SECONDS);
			return 0.0;
		}

		$decoded = json_decode($body, true);
		$rate = 0.0;
		if (is_array($decoded) && isset($decoded[0]['rate'])) {
			$rate = (float) $decoded[0]['rate'];
		}

		set_transient($cache_key, $rate, HOUR_IN_SECONDS);
		return $rate;
	}

	/**
	 * Get a usable commission rate from the latest balance rows.
	 *
	 * @param array $balances Balance rows.
	 * @return float
	 */
	private function get_balance_commission_rate($balances)
	{
		foreach ($balances as $balance) {
			$rate = isset($balance->commission_rate) ? (float) $balance->commission_rate : 0.0;
			if ($rate > 0) {
				return $rate;
			}
		}

		return 0.0;
	}

	/**
	 * Mirror the legacy FSBI progressive revenue-share tier.
	 *
	 * Legacy behavior uses the accumulated gross volume to select the rate:
	 * 27% up to 1,000, 17% above 1,000, and 7% above 5,000.
	 *
	 * @param array $annual_payments Annual payment rows.
	 * @param array $payments Current period payment rows.
	 * @return float
	 */
	private function get_legacy_commission_rate($annual_payments, $payments = array())
	{
		$gross_total = 0.0;
		$source_rows = ! empty($annual_payments) ? $annual_payments : $payments;

		foreach ($source_rows as $payment) {
			if ($this->is_refund_payment($payment)) {
				continue;
			}
			$gross_total += abs((float) ($payment->gross ?? 0));
		}

		if ($gross_total > 5000) {
			return 7.0;
		}
		if ($gross_total > 1000) {
			return 17.0;
		}

		return 27.0;
	}

	/**
	 * Resolve payment gross/fee/net amounts with fallbacks when source payload omits net.
	 *
	 * @param object $payment Payment row.
	 * @param float  $commission_rate Commission rate percentage.
	 * @return array
	 */
	private function resolve_payment_amounts($payment, $commission_rate = 0.0)
	{
		$gross = isset($payment->gross) ? (float) $payment->gross : 0.0;
		$fee = isset($payment->fee) ? (float) $payment->fee : 0.0;
		$net = isset($payment->net) ? (float) $payment->net : 0.0;

		$meta = array();
		if (! empty($payment->metadata)) {
			$decoded = json_decode((string) $payment->metadata, true);
			if (is_array($decoded)) {
				$meta = $decoded;
			}
		}

		if (abs($fee) < 0.00001) {
			if (isset($meta['gateway_fee'])) {
				$fee = (float) $meta['gateway_fee'];
			} elseif (isset($meta['fee'])) {
				$fee = (float) $meta['fee'];
			}
		}

		if (abs($net) < 0.00001) {
			if (isset($meta['net'])) {
				$net = (float) $meta['net'];
			} elseif (isset($meta['net_amount'])) {
				$net = (float) $meta['net_amount'];
			} elseif (isset($meta['amount_merchant'])) {
				$net = (float) $meta['amount_merchant'];
			}
		}

		if (abs($gross) < 0.00001 && isset($meta['gross'])) {
			$gross = (float) $meta['gross'];
		}

		if (abs($gross) > 0.00001 && abs($net) < 0.00001) {
			if ($commission_rate > 0) {
				$net = ($gross * ((100 - $commission_rate) / 100)) - $fee;
			} else {
				$net = $gross - $fee;
			}
		}

		return array(
			'gross' => (float) $gross,
			'fee'   => (float) $fee,
			'net'   => (float) $net,
		);
	}

	/**
	 * Get locale format setting
	 *
	 * @return string Locale format (e.g., en-US, pt-PT)
	 */
	public function get_locale_format()
	{
		return $this->locale_format;
	}

	/**
	 * Get Transferwise API token
	 *
	 * @return string Transferwise token
	 */
	public function get_transferwise_token()
	{
		return $this->transferwise_token;
	}

	/**
	 * Get conversion currency for Transferwise
	 *
	 * @return string Conversion currency (EUR, USD, GBP)
	 */
	public function get_conversion_currency()
	{
		return $this->conversion_currency;
	}
}
