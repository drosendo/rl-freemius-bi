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
	 * Register the stylesheets for the admin area.
	 */
	public function enqueue_styles()
	{
		if (! $this->is_plugin_page()) {
			return;
		}

		$css_path = RL_FSBI_PLUGIN_DIR . 'assets/css/admin/rl-fsbi-admin.css';
		$ver      = file_exists($css_path) ? filemtime($css_path) : $this->version;

		wp_enqueue_style(
			$this->plugin_name . '-admin',
			RL_FSBI_PLUGIN_URL . 'assets/css/admin/rl-fsbi-admin.css',
			array(),
			$ver,
			'all'
		);
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
			$settings_js_ver  = file_exists($settings_js_path) ? filemtime($settings_js_path) : $this->version;

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
					'descriptions' => $this->settings ? $this->settings->get_field_descriptions() : array(),
				)
			);
			return;
		}

		if (! $this->is_plugin_page()) {
			return;
		}

		wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@3/dist/chart.min.js', array(), '3.9.1', false);
		wp_enqueue_script('datatables-js', 'https://cdn.jsdelivr.net/npm/datatables.net@1/js/jquery.dataTables.min.js', array('jquery'), '1.13.4', false);
		wp_enqueue_style('datatables-css', 'https://cdn.jsdelivr.net/npm/datatables.net-dt@1/css/jquery.dataTables.min.css', array(), '1.13.4', 'all');

		wp_enqueue_script(
			$this->plugin_name . '-admin',
			RL_FSBI_PLUGIN_URL . 'assets/js/admin/rl-fsbi-admin.js',
			array('jquery', 'chart-js', 'datatables-js'),
			$this->version,
			false
		);

		wp_localize_script($this->plugin_name . '-admin', 'rlFsbiAdmin', array(
			'ajaxUrl'         => admin_url('admin-ajax.php'),
			'nonce'           => wp_create_nonce('rl_fsbi_nonce'),
			'localeFormat'    => $this->locale_format,
			'defaultCurrency' => strtoupper((string) $this->settings->get_option('rl_fsbi_conversion_currency', $this->settings->get_option('rl_fsbi_default_currency', 'USD'))),
			'tableRows'       => 12,
			'forcedPluginId'  => $this->get_forced_plugin_id_from_request(),
			'pluginsCatalog'  => $this->get_plugins_catalog(),
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
?>
		<style>
			#rl_fsbi_portfolio_widget .inside {
				background: #111521;
				padding: 14px 20px 20px 14px;
				color: #e7ebff
			}

			#rl_fsbi_portfolio_widget .rl-fsbi-wp-widget {
				display: grid;
				grid-template-columns: repeat(4, minmax(0, 1fr));
				gap: 10px;
				margin: 0 0 12px
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
				#rl_fsbi_portfolio_widget .rl-fsbi-wp-widget {
					grid-template-columns: repeat(2, minmax(0, 1fr))
				}

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
			<div class="rl-fsbi-wp-metric"><span><?php echo esc_html__('MRR', 'rl-freemius-bi'); ?></span><strong><?php echo $format($data['mrr']); ?></strong></div>
			<div class="rl-fsbi-wp-metric"><span><?php echo esc_html__('ARR', 'rl-freemius-bi'); ?></span><strong><?php echo $format($data['arr']); ?></strong></div>
			<div class="rl-fsbi-wp-metric"><span><?php echo esc_html__('Active Subscribers', 'rl-freemius-bi'); ?></span><strong><?php echo esc_html(number_format_i18n($data['active_subscriptions'])); ?></strong></div>
		</div>
		<div class="rl-fsbi-wp-chart-wrap" aria-label="<?php echo esc_attr__('Three month net revenue trend', 'rl-freemius-bi'); ?>">
			<div class="rl-fsbi-wp-chart">
				<?php foreach ($data['trend'] as $point) : ?>
					<div class="rl-fsbi-wp-bar <?php echo esc_attr('current' === $point['payout_status'] ? 'rl-fsbi-wp-current' : ('next' === $point['payout_status'] ? 'rl-fsbi-wp-next' : 'rl-fsbi-wp-neutral')); ?>" style="height: <?php echo esc_attr($point['height']); ?>%;" title="<?php echo esc_attr($point['month'] . ': ' . $format($point['value'])); ?>"><strong><?php echo esc_html($format($point['value'])); ?></strong><small><?php echo esc_html($point['month']); ?></small></div>
				<?php endforeach; ?>
			</div>
			<div class="rl-fsbi-wp-axis-x"><?php echo esc_html__('Net revenue by month', 'rl-freemius-bi'); ?></div>
		</div>
		<table class="widefat striped" style="margin-top:18px">
			<thead>
				<tr>
					<th><?php echo esc_html__('Month', 'rl-freemius-bi'); ?></th>
					<th><?php echo esc_html__('Gross', 'rl-freemius-bi'); ?></th>
					<th><?php echo esc_html__('Refunds', 'rl-freemius-bi'); ?></th>
					<th><?php echo esc_html__('Fees', 'rl-freemius-bi'); ?></th>
					<th><?php echo esc_html__('Net', 'rl-freemius-bi'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($data['trend'] as $point) : ?>
					<tr class="<?php echo esc_attr('current' === $point['payout_status'] ? 'rl-fsbi-wp-current' : ('next' === $point['payout_status'] ? 'rl-fsbi-wp-next' : '')); ?>">
						<td><?php echo esc_html($point['month']); ?><?php if ('current' === $point['payout_status']) : ?><span class="rl-fsbi-wp-payout-badge rl-fsbi-wp-payout-current">CURRENT PAYOUT</span><?php elseif ('next' === $point['payout_status']) : ?><span class="rl-fsbi-wp-payout-badge rl-fsbi-wp-payout-next">NEXT PAYOUT</span><?php endif; ?></td>
						<td><?php echo $format($point['gross']); ?></td>
						<td><?php echo $format($point['refunds']); ?></td>
						<td><?php echo $format($point['fees']); ?></td>
						<td><strong><?php echo $format($point['value']); ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
<?php
	}

	private function get_wordpress_dashboard_portfolio_data()
	{
		$plugin_ids = $this->get_selected_plugin_ids();
		$currency = strtoupper((string) $this->settings->get_option('rl_fsbi_conversion_currency', $this->settings->get_option('rl_fsbi_default_currency', 'EUR')));
		$currency = in_array($currency, array('USD', 'EUR', 'GBP'), true) ? $currency : 'EUR';
		$repo = new RL_FSBI_Repository();
		$today = current_time('Y-m-d');
		$month_start = wp_date('Y-m-01', current_time('timestamp'));
		$payments = array();
		$trend = array();

		for ($i = 0; $i <= 2; $i++) {
			$month = wp_date('Y-m', strtotime('-' . $i . ' months', current_time('timestamp')));
			$start = $month . '-01';
			$end = wp_date('Y-m-t', strtotime($start));
			$month_payments = array();
			foreach ($plugin_ids as $plugin_id) {
				$month_payments = array_merge($month_payments, $repo->get_payments($plugin_id, $start, $end, null));
			}
			$payments = array_merge($payments, $month_payments);
			$gross = 0.0;
			$refunds = 0.0;
			$fees = 0.0;
			$net = 0.0;
			foreach ($month_payments as $payment) {
				$amounts = $this->resolve_payment_amounts($payment, 7.0);
				$source_currency = strtoupper((string) ($payment->currency ?? $currency));
				$date = (string) ($payment->transaction_date ?? $start);
				$gross += $this->convert_currency_amount((float) $amounts['gross'], $source_currency, $currency, $date);
				$fees += $this->convert_currency_amount((float) $amounts['fee'], $source_currency, $currency, $date);
				$net += $this->convert_currency_amount((float) $amounts['net'], $source_currency, $currency, $date);
				if ($this->is_refund_payment($payment)) {
					$refund_value = abs((float) $amounts['net']) > 0 ? abs((float) $amounts['net']) : abs((float) $amounts['gross']);
					$refunds += $this->convert_currency_amount($refund_value, $source_currency, $currency, $date);
				}
			}
			$trend[] = array(
				'month' => wp_date('M', strtotime($start)),
				'value' => $net,
				'gross' => $gross,
				'refunds' => $refunds,
				'fees' => $fees,
				'payout_status' => $month === wp_date('Y-m', strtotime('-2 months', current_time('timestamp'))) ? 'current' : ($month === wp_date('Y-m', strtotime('-1 month', current_time('timestamp'))) ? 'next' : ''),
			);
		}

		$net_revenue = 0.0;
		foreach ($payments as $payment) {
			$net_revenue += (float) $this->resolve_payment_amounts($payment, 7.0)['net'];
		}

		$subscriptions = $this->get_subscriptions_for_dashboard(0, null);
		$mrr = $this->calculate_period_mrr($subscriptions, $today, $currency)['converted'];
		$stats = $this->calculate_subscription_stats($subscriptions, $today);
		$max = max(1, max(array_column($trend, 'value')));
		foreach ($trend as &$point) {
			$point['height'] = max(8, round(($point['value'] / $max) * 100, 1));
		}
		unset($point);

		return array(
			'currency' => $currency,
			'net_revenue' => $net_revenue,
			'mrr' => $mrr,
			'arr' => $mrr * 12,
			'active_subscriptions' => (int) $stats['active'],
			'trend' => $trend,
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

				$data = array(
					'plugin_id'        => $plugin_id,
					'payment_id'       => (int) ($payment['id'] ?? 0),
					'user_id'          => (int) ($payment['user_id'] ?? 0),
					'subscription_id'  => isset($payment['subscription_id']) ? intval($payment['subscription_id']) : null,
					'transaction_date' => $this->resolve_freemius_payment_transaction_date($payment),
					'gross'            => (float) ($payment['gross'] ?? 0),
					'net'              => (float) ($payment['net'] ?? 0),
					'fee'              => (float) (isset($payment['fee']) ? $payment['fee'] : 0),
					'currency'         => sanitize_text_field($payment['currency'] ?? 'USD'),
					'payment_method'   => isset($payment['payment_method']) ? sanitize_text_field($payment['payment_method']) : null,
					'status'           => sanitize_text_field($payment['status'] ?? 'completed'),
					'country_code'     => isset($payment['country_code']) ? sanitize_text_field($payment['country_code']) : null,
					'is_refund'        => (isset($payment['refund_id']) || (float) ($payment['gross'] ?? 0) < 0 || (float) ($payment['net'] ?? 0) < 0 || 'refunded' === strtolower((string) ($payment['status'] ?? ''))) ? 1 : 0,
					'refund_id'        => isset($payment['refund_id']) ? intval($payment['refund_id']) : null,
					'metadata'         => wp_json_encode($payment),
				);

				$repo->upsert_payment($data);
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

				$data = array(
					'plugin_id'           => $plugin_id,
					'subscription_id'     => (int) ($subscription['id'] ?? 0),
					'user_id'             => (int) ($subscription['user_id'] ?? 0),
					'plan_id'             => isset($subscription['plan_id']) ? (int) $subscription['plan_id'] : null,
					'status'              => sanitize_text_field($subscription['status'] ?? 'active'),
					'billing_cycle'       => isset($subscription['billing_cycle']) ? sanitize_text_field($subscription['billing_cycle']) : null,
					'trial_ends'          => isset($subscription['trial_ends']) ? sanitize_text_field($subscription['trial_ends']) : null,
					'next_renewal'        => isset($subscription['next_billing']) ? sanitize_text_field($subscription['next_billing']) : null,
					'expires_at'          => isset($subscription['end_date']) ? sanitize_text_field($subscription['end_date']) : null,
					'outstanding_balance' => (float) (isset($subscription['outstanding_balance']) ? $subscription['outstanding_balance'] : 0),
					'currency'            => sanitize_text_field($subscription['currency'] ?? 'USD'),
					'metadata'            => wp_json_encode($subscription),
				);

				$repo->upsert_subscription($data);
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
	private function sync_licenses($plugin_id, $api, $repo)
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

				$repo->upsert_license(
					array(
						'plugin_id'    => $plugin_id,
						'license_id'   => (int) ($license['id'] ?? 0),
						'user_id'      => (int) ($license['user_id'] ?? 0),
						'plan_id'      => isset($license['plan_id']) ? (int) $license['plan_id'] : null,
						'license_key'  => isset($license['secret_key']) ? sanitize_text_field($license['secret_key']) : null,
						'status'       => sanitize_text_field($license['status'] ?? 'valid'),
						'quota_sites'  => isset($license['quota']) ? (int) $license['quota'] : null,
						'active_sites' => isset($license['sites_count']) ? (int) $license['sites_count'] : 0,
						'expires_at'   => isset($license['expiration']) ? sanitize_text_field($license['expiration']) : null,
					)
				);
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
			),
			'batch_size'     => 50,
			'processed'      => array(
				'payments'      => 0,
				'subscriptions' => 0,
				'licenses'      => 0,
				'plans'         => 0,
				'balances'      => 0,
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
				$state['stage'] = 'balance';
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
		$stage_order = array('payments', 'subscriptions', 'licenses', 'plans', 'balance');
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

			$repo->upsert_payment(
				array(
					'plugin_id'        => $plugin_id,
					'payment_id'       => (int) ($payment['id'] ?? 0),
					'user_id'          => (int) ($payment['user_id'] ?? 0),
					'subscription_id'  => isset($payment['subscription_id']) ? (int) $payment['subscription_id'] : null,
					'transaction_date' => $this->resolve_freemius_payment_transaction_date($payment),
					'gross'            => (float) ($payment['gross'] ?? 0),
					'net'              => (float) ($payment['net'] ?? 0),
					'fee'              => (float) ($payment['fee'] ?? 0),
					'currency'         => sanitize_text_field($payment['currency'] ?? 'USD'),
					'payment_method'   => isset($payment['payment_method']) ? sanitize_text_field($payment['payment_method']) : null,
					'status'           => sanitize_text_field($payment['status'] ?? 'completed'),
					'country_code'     => isset($payment['country_code']) ? sanitize_text_field($payment['country_code']) : null,
					'is_refund'        => (isset($payment['refund_id']) || (float) ($payment['gross'] ?? 0) < 0 || (float) ($payment['net'] ?? 0) < 0 || 'refunded' === strtolower((string) ($payment['status'] ?? ''))) ? 1 : 0,
					'refund_id'        => isset($payment['refund_id']) ? (int) $payment['refund_id'] : null,
					'metadata'         => wp_json_encode($payment),
				)
			);

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

			$repo->upsert_subscription(
				array(
					'plugin_id'           => $plugin_id,
					'subscription_id'     => (int) ($subscription['id'] ?? 0),
					'user_id'             => (int) ($subscription['user_id'] ?? 0),
					'plan_id'             => isset($subscription['plan_id']) ? (int) $subscription['plan_id'] : null,
					'status'              => sanitize_text_field($subscription['status'] ?? 'active'),
					'billing_cycle'       => isset($subscription['billing_cycle']) ? sanitize_text_field($subscription['billing_cycle']) : null,
					'trial_ends'          => isset($subscription['trial_ends']) ? sanitize_text_field($subscription['trial_ends']) : null,
					'next_renewal'        => isset($subscription['next_billing']) ? sanitize_text_field($subscription['next_billing']) : null,
					'expires_at'          => isset($subscription['end_date']) ? sanitize_text_field($subscription['end_date']) : null,
					'outstanding_balance' => (float) ($subscription['outstanding_balance'] ?? 0),
					'currency'            => sanitize_text_field($subscription['currency'] ?? 'USD'),
					'metadata'            => wp_json_encode($subscription),
				)
			);

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

			$repo->upsert_license(
				array(
					'plugin_id'    => $plugin_id,
					'license_id'   => (int) ($license['id'] ?? 0),
					'user_id'      => (int) ($license['user_id'] ?? 0),
					'plan_id'      => isset($license['plan_id']) ? (int) $license['plan_id'] : null,
					'license_key'  => isset($license['secret_key']) ? sanitize_text_field($license['secret_key']) : null,
					'status'       => sanitize_text_field($license['status'] ?? 'valid'),
					'quota_sites'  => isset($license['quota']) ? (int) $license['quota'] : null,
					'active_sites' => isset($license['sites_count']) ? (int) $license['sites_count'] : 0,
					'expires_at'   => isset($license['expiration']) ? sanitize_text_field($license['expiration']) : null,
				)
			);

			$saved++;
		}

		return $saved;
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
	 * Check if current page is plugin page.
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

		$transient_key = 'rl_fsbi_plugin_ver_' . $plugin_id;
		$cached = get_transient($transient_key);
		if (false !== $cached && '' !== (string) $cached) {
			return (string) $cached;
		}

		$catalog = $this->settings->get_option('rl_fsbi_plugins_catalog', array());
		if (is_array($catalog) && ! empty($catalog[(string) $plugin_id]['version'])) {
			$version = (string) $catalog[(string) $plugin_id]['version'];
			set_transient($transient_key, $version, 12 * HOUR_IN_SECONDS);
			return $version;
		}

		$developer_id = $this->settings->get_option('rl_fsbi_developer_id');
		$public_key   = $this->settings->get_option('rl_fsbi_public_key');
		$secret_key   = $this->settings->get_option('rl_fsbi_secret_key');

		if ($developer_id && $public_key && $secret_key) {
			$api = new RL_FSBI_API($developer_id, $public_key, $secret_key);
			$tags = $api->retrieve_plugin_tags($plugin_id);
			if (! empty($tags) && is_array($tags)) {
				$first_tag = reset($tags);
				$version = is_array($first_tag) ? ($first_tag['version'] ?? '') : ($first_tag->version ?? '');
				if (! empty($version)) {
					$version = sanitize_text_field((string) $version);
					set_transient($transient_key, $version, 12 * HOUR_IN_SECONDS);

					if (is_array($catalog) && isset($catalog[(string) $plugin_id])) {
						$catalog[(string) $plugin_id]['version'] = $version;
						$this->settings->update_option('rl_fsbi_plugins_catalog', $catalog);
					}
					return $version;
				}
			}
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

		$payments = $repo->get_payments($plugin_id, $start_date, $end_date, $currency);
		$gross_revenue = $repo->calculate_gross_revenue($plugin_id, $currency, $start_date, $end_date);
		$net_revenue = $repo->calculate_net_revenue($plugin_id, $currency, $start_date, $end_date);
		$active_subscriptions = $repo->get_active_subscriptions_count($plugin_id, $currency);

		$period_days = max(1, (int) ceil((strtotime($end_date) - strtotime($start_date)) / DAY_IN_SECONDS) + 1);
		$previous_end = wp_date('Y-m-d', strtotime($start_date . ' -1 day'));
		$previous_start = wp_date('Y-m-d', strtotime($previous_end . ' -' . ($period_days - 1) . ' days'));
		$previous_net = $repo->calculate_net_revenue($plugin_id, $currency, $previous_start, $previous_end);

		$annual_start = wp_date('Y-m-01', strtotime('-11 months', strtotime($end_date)));
		$annual_payments = $repo->get_payments($plugin_id, $annual_start, $end_date, $currency);
		$subscriptions = $this->get_subscriptions_for_dashboard($plugin_id, $currency);
		$balances = $this->get_balances_for_dashboard($plugin_id, $currency);

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
			$previous_net,
			$currency
		);

		$data['payments'] = array_map(
			function ($payment) {
				return (array) $payment;
			},
			$payments
		);

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

		wp_send_json_success($data);
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
	 * Build complete analytics payload for v2 dashboard.
	 */
	private function build_dashboard_payload($payments, $annual_payments, $subscriptions, $balances, $start_date, $end_date, $gross_revenue, $net_revenue, $active_subscriptions, $previous_net, $currency)
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

		$expected_payout = 0.0;
		foreach ($balances as $balance) {
			$balance_amount = (float) $balance->net_payout;
			$balance_currency = isset($balance->currency) ? strtoupper((string) $balance->currency) : $report_currency;
			$balance_date = isset($balance->updated_at) ? (string) $balance->updated_at : gmdate('Y-m-d H:i:s');
			$expected_payout += $this->convert_currency_amount($balance_amount, $balance_currency, $report_currency, $balance_date);
		}

		$total_refundable = max(1, $purchase_count + $refund_count);
		$refund_rate = ($refund_count / $total_refundable) * 100;

		$subscription_stats = $this->calculate_subscription_stats($subscriptions, $end_date);
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
		$aov = $purchase_count > 0 ? ($net_revenue / $purchase_count) : 0;
		$trial_rate = $trial_count > 0 ? ($converted_trials / $trial_count) * 100 : 0;

		$health_score = min(
			100,
			max(
				0,
				($refund_rate < 5 ? 30 : max(0, 30 - ($refund_rate * 2))) +
					($churn_rate < 3 ? 25 : max(0, 25 - ($churn_rate * 4))) +
					($trial_rate > 25 ? 25 : ($trial_rate)) +
					($net_revenue > $previous_net ? 20 : 10)
			)
		);

		$monthly_table = $this->build_monthly_breakdown($annual_payments, $report_currency, $commission_rate);
		$sales_activity = $this->build_sales_activity_series($payments, $subscriptions, $start_date, $end_date, $commission_rate, $report_currency);
		$expected_renewals = $this->build_expected_renewals_series($subscriptions, $payments, $start_date, $end_date, $commission_rate, $report_currency);
		$revenue_overview = $this->build_revenue_overview_series($annual_payments, $commission_rate, $report_currency);
		$revenue_forecast = $this->build_revenue_forecast_series($subscriptions, $mrr, $report_currency);
		$churn_trend = $this->build_churn_trend_series($subscriptions);

		$currency_distribution = $this->build_distribution_series($payments, 'currency', 'gross', $commission_rate, $report_currency);
		$country_distribution = $this->build_distribution_series($payments, 'country_code', 'net', $commission_rate, $report_currency);
		$plan_distribution = $this->build_plan_distribution_series($subscriptions);
		$wporg_growth = $this->build_wporg_growth_series($annual_payments);

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
			),
			'kpis' => array(
				'mrr'                 => round($mrr, 2),
				'arr'                 => round($arr, 2),
				'mrr_native_by_currency' => $mrr_snapshot['native'],
				'mrr_converted'       => round($mrr_snapshot['converted'], 2),
				'arr_converted'       => round($arr, 2),
				'metric_as_of'        => $end_date,
				'aov'                 => round($aov, 2),
				'refund_rate'         => round($refund_rate, 2),
				'churn_rate'          => round($churn_rate, 2),
				'health_score'        => round($health_score, 0),
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
				'wporg_growth'         => $wporg_growth,
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
				'as_of'                  => $end_date,
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
			$canceled_at = is_array($meta) && ! empty($meta['canceled_at']) ? strtotime((string) $meta['canceled_at']) : false;
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

		// Legacy FSBI displays the native MRR bucket for the selected currency.
		// Cross-currency conversion is reserved for payout/revenue aggregation.
		$converted = isset($native[$report_currency]) ? (float) $native[$report_currency] : 0.0;

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
	private function calculate_subscription_stats($subscriptions, $as_of_date = null)
	{
		$active = 0;
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

			if (in_array($status, array('active', 'paid', 'trialing', 'trial_ended'), true) && $months > 0) {
				$active++;
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

		return array(
			'active' => $active,
			'monthly' => $monthly,
			'annual' => $annual,
			'canceled_this_month' => $canceled_this_month,
			'churn_rate' => $churn_rate,
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

		return (float) ($subscription->renewal_amount ?? 0);
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
		$active = array();

		for ($i = 5; $i >= 0; $i--) {
			$key = gmdate('Y-m', strtotime('-' . $i . ' months'));
			$labels[] = $key;
			$canceled[$key] = 0;
			$active[$key] = 0;
		}

		foreach ($subscriptions as $subscription) {
			$status = strtolower((string) $subscription->status);
			$meta = json_decode((string) ($subscription->metadata ?? ''), true);
			$created_at = ! empty($subscription->created_at) ? strtotime((string) $subscription->created_at) : 0;
			$canceled_at = is_array($meta) && ! empty($meta['canceled_at']) ? strtotime((string) $meta['canceled_at']) : 0;
			$updated_month = ! empty($subscription->updated_at) ? substr((string) $subscription->updated_at, 0, 7) : '';

			foreach ($labels as $label) {
				$month_end = strtotime($label . '-01 +1 month -1 second');
				$exists = ! $created_at || $created_at <= $month_end;
				$was_canceled = $canceled_at && $canceled_at <= $month_end;

				if ($exists && ! $was_canceled && in_array($status, array('active', 'paid', 'trialing', 'trial_ended'), true)) {
					$active[$label]++;
				}
			}

			if (in_array($status, array('cancelled', 'canceled'), true) && isset($canceled[$updated_month])) {
				$canceled[$updated_month]++;
			}
		}

		$rates = array();
		foreach ($labels as $label) {
			$base = max(1, (int) $active[$label]);
			$rates[] = round(((int) $canceled[$label] / $base) * 100, 2);
		}

		return array(
			'labels' => $labels,
			'canceled' => array_values($canceled),
			'churn_rate' => $rates,
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
	private function build_plan_distribution_series($subscriptions)
	{
		$bucket = array();

		foreach ($subscriptions as $subscription) {
			$plan_id = ! empty($subscription->plan_id) ? (string) $subscription->plan_id : 'unknown';
			$label = 'Plan ' . $plan_id;
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
	 * Build WP.org growth placeholder from recent daily purchase activity.
	 */
	private function build_wporg_growth_series($payments)
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

		$current_payout_amount = isset($months[$current_payout_month]['payout_total']) ? (float) $months[$current_payout_month]['payout_total'] : 0.0;
		$current_payout_eligible = ! empty($months[$current_payout_month]['payout_eligible']);
		$next_payout_amount = isset($months[$next_payout_month]['payout_total']) ? (float) $months[$next_payout_month]['payout_total'] : 0.0;
		$next_payout_eligible = ! empty($months[$next_payout_month]['payout_eligible']);

		return array(
			'rows' => $rows,
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
	private function convert_currency_amount($amount, $source_currency, $target_currency, $date_time = null)
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
