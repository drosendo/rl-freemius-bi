<?php
/**
 * Admin Dashboard Template
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>

<div class="wrap rl-fsbi-dashboard is-loading" aria-busy="true">
	<?php /* Watchdog: a frozen spinner tells nobody anything. If the bundle never
	   started, or the request never came back, say which of the two it was. */ ?>
	<script>
	window.setTimeout(function () {
		var wrap = document.querySelector('.rl-fsbi-dashboard');
		if (!wrap || !wrap.classList.contains('is-loading')) {
			return;
		}

		var overlay = document.getElementById('rl-fsbi-loading');
		var started = typeof window.FSBI !== 'undefined';

		wrap.classList.remove('is-loading');
		wrap.removeAttribute('aria-busy');
		if (overlay) {
			overlay.innerHTML = '<div class="rl-fsbi-loading-card">'
				+ '<p class="rl-fsbi-loading-message"><strong>'
				+ (started ? 'The dashboard did not finish loading.' : 'The dashboard script did not start.')
				+ '</strong></p>'
				+ '<p class="rl-fsbi-loading-message" style="font-weight:400;font-size:12px;">'
				+ (started
					? 'The analytics request did not come back in time. Check the browser console and your server error log, then reload.'
					: 'assets/js/admin/rl-fsbi-admin.js either failed to load or threw before it could run. Confirm the plugin folder was uploaded completely, including assets/lib/ and assets/js/.')
				+ '</p>'
				+ '<p><a class="button" href="' + window.location.href + '">Reload</a></p>'
				+ '</div>';
		}
	}, 25000);
	</script>

	<div class="rl-fsbi-loading-overlay" id="rl-fsbi-loading" role="status" aria-live="polite">
		<div class="rl-fsbi-loading-card">
			<div class="rl-fsbi-loading-spinner" aria-hidden="true"></div>
			<p class="rl-fsbi-loading-message" id="rl-fsbi-loading-message"><?php echo esc_html__( 'Loading analytics…', 'rl-freemius-bi' ); ?></p>
			<div class="rl-fsbi-loading-bar">
				<span id="rl-fsbi-loading-fill"></span>
			</div>
			<p class="rl-fsbi-loading-pct" id="rl-fsbi-loading-pct">0%</p>
		</div>
	</div>
	<?php
	$forced_plugin_id = isset( $GLOBALS['rl_fsbi_forced_plugin_id'] ) ? (int) $GLOBALS['rl_fsbi_forced_plugin_id'] : 0;
	$plugins_catalog  = isset( $GLOBALS['rl_fsbi_plugins_catalog'] ) && is_array( $GLOBALS['rl_fsbi_plugins_catalog'] ) ? $GLOBALS['rl_fsbi_plugins_catalog'] : array();
	$raw_catalog      = isset( $GLOBALS['rl_fsbi_raw_catalog'] ) && is_array( $GLOBALS['rl_fsbi_raw_catalog'] ) ? $GLOBALS['rl_fsbi_raw_catalog'] : array();
	$initial_version  = isset( $GLOBALS['rl_fsbi_plugin_version'] ) ? (string) $GLOBALS['rl_fsbi_plugin_version'] : '';

	// The date inputs used to be rendered empty and only filled by JavaScript,
	// so anything that stopped the bundle from running left the toolbar showing
	// "Date Range: —". Render the current month up front, in the site timezone.
	$initial_period_start = wp_date( 'Y-m-01' );
	$initial_period_end   = wp_date( 'Y-m-t' );

	$initial_title = esc_html__( 'All Plugins', 'rl-freemius-bi' );
	if ( $forced_plugin_id > 0 ) {
		if ( ! empty( $raw_catalog[ (string) $forced_plugin_id ]['title'] ) ) {
			$initial_title = $raw_catalog[ (string) $forced_plugin_id ]['title'];
		} elseif ( ! empty( $plugins_catalog[ (string) $forced_plugin_id ] ) ) {
			$initial_title = preg_replace( '/\s*\(#\d+\)$/', '', (string) $plugins_catalog[ (string) $forced_plugin_id ] );
		} else {
			$initial_title = sprintf( esc_html__( 'Plugin #%d', 'rl-freemius-bi' ), $forced_plugin_id );
		}
	}
	?>

	<div class="rl-fsbi-page-header">
		<h1 class="rl-fsbi-page-title">
			<span id="rl-fsbi-active-plugin-title"><?php echo esc_html( $initial_title ); ?></span>
			<span id="rl-fsbi-active-plugin-version" class="rl-fsbi-version-badge"<?php echo empty( $initial_version ) ? ' style="display:none;"' : ''; ?>>
				<span class="rl-fsbi-version-badge-label"><?php echo esc_html__( 'Latest version', 'rl-freemius-bi' ); ?></span>
				<span class="rl-fsbi-version-badge-val" id="rl-fsbi-active-plugin-version-val"><?php echo esc_html( $initial_version ); ?></span>
			</span>
		</h1>
	</div>

	<div class="rl-fsbi-toolbar">
		<div class="rl-fsbi-controls">
			<span class="rl-fsbi-field">
			<label for="rl-fsbi-plugin-filter"><?php echo esc_html__( 'Plugin:', 'rl-freemius-bi' ); ?></label>
			<select id="rl-fsbi-plugin-filter" name="plugin_id"<?php echo $forced_plugin_id > 0 ? ' disabled="disabled"' : ''; ?>>
				<?php if ( $forced_plugin_id <= 0 ) : ?>
					<option value="all"><?php echo esc_html__( 'All Plugins', 'rl-freemius-bi' ); ?></option>
				<?php endif; ?>
				<?php foreach ( $plugins_catalog as $plugin_id => $plugin_title ) : ?>
					<option value="<?php echo esc_attr( $plugin_id ); ?>"<?php selected( (int) $plugin_id, $forced_plugin_id ); ?>><?php echo esc_html( $plugin_title ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( $forced_plugin_id > 0 ) : ?>
				<input type="hidden" id="rl-fsbi-plugin-filter-forced" value="<?php echo esc_attr( $forced_plugin_id ); ?>" />
			<?php endif; ?>
			</span>

			<span class="rl-fsbi-field">
			<label for="rl-fsbi-currency-filter"><?php echo esc_html__( 'Currency:', 'rl-freemius-bi' ); ?></label>
			<select id="rl-fsbi-currency-filter" name="currency">
				<option value="all"><?php echo esc_html__( 'All Currencies', 'rl-freemius-bi' ); ?></option>
				<option value="USD">USD</option>
				<option value="EUR">EUR</option>
				<option value="GBP">GBP</option>
			</select>
			</span>

			<span class="rl-fsbi-field">
			<label for="rl-fsbi-start-date"><?php echo esc_html__( 'Date Range:', 'rl-freemius-bi' ); ?></label>
			<input type="date" id="rl-fsbi-start-date" value="<?php echo esc_attr( $initial_period_start ); ?>" aria-label="<?php echo esc_attr__( 'Start date', 'rl-freemius-bi' ); ?>" />
			<span aria-hidden="true">—</span>
			<input type="date" id="rl-fsbi-end-date" value="<?php echo esc_attr( $initial_period_end ); ?>" aria-label="<?php echo esc_attr__( 'End date', 'rl-freemius-bi' ); ?>" />
			</span>

			<span class="rl-fsbi-field">
			<label for="rl-fsbi-plan-filter"><?php echo esc_html__( 'Plan:', 'rl-freemius-bi' ); ?></label>
			<select id="rl-fsbi-plan-filter" name="plan_id">
				<option value="all"><?php echo esc_html__( 'All Plans', 'rl-freemius-bi' ); ?></option>
			</select>
			</span>

			<button id="rl-fsbi-sync-btn" class="button button-primary"><?php echo esc_html__( 'Sync Now', 'rl-freemius-bi' ); ?></button>
			<button id="rl-fsbi-sync-cancel-btn" class="button" type="button" hidden><?php echo esc_html__( 'Cancel Sync', 'rl-freemius-bi' ); ?></button>
			<span class="rl-fsbi-last-synced" id="rl-fsbi-last-synced" title="<?php echo esc_attr__( 'When the local copy of your Freemius data was last refreshed, in the site timezone.', 'rl-freemius-bi' ); ?>"></span>
		</div>

		<?php
		$dashboard_tabs = array(
			'overview'  => __( 'Overview', 'rl-freemius-bi' ),
			'growth'    => __( 'Growth', 'rl-freemius-bi' ),
			'retention' => __( 'Retention', 'rl-freemius-bi' ),
			'customers' => __( 'Customers', 'rl-freemius-bi' ),
			'marketing' => __( 'Marketing', 'rl-freemius-bi' ),
		);
		// WordPress.org data is never mixed with Freemius figures, so it gets its
		// own tab - and no tab at all when the product has no slug.
		if ( ! empty( $GLOBALS['rl_fsbi_has_wporg'] ) ) {
			$dashboard_tabs['wporg'] = __( 'WordPress.org', 'rl-freemius-bi' );
		}
		?>
		<div class="rl-fsbi-tabs" role="tablist" aria-label="<?php echo esc_attr__( 'Dashboard sections', 'rl-freemius-bi' ); ?>">
			<?php foreach ( $dashboard_tabs as $tab_slug => $tab_label ) : ?>
				<button type="button" class="rl-fsbi-tab" role="tab" id="rl-fsbi-tab-<?php echo esc_attr( $tab_slug ); ?>"
					data-tab="<?php echo esc_attr( $tab_slug ); ?>"
					aria-controls="rl-fsbi-panel-<?php echo esc_attr( $tab_slug ); ?>"
					aria-selected="<?php echo 'overview' === $tab_slug ? 'true' : 'false'; ?>"
					tabindex="<?php echo 'overview' === $tab_slug ? '0' : '-1'; ?>"><?php echo esc_html( $tab_label ); ?></button>
			<?php endforeach; ?>
		</div>
	</div>

	<section class="rl-fsbi-panel" data-panel="overview" id="rl-fsbi-panel-overview" role="tabpanel" aria-labelledby="rl-fsbi-tab-overview" tabindex="0">

	<div class="rl-fsbi-kpi-row" id="rl-fsbi-kpi-row">
		<?php
		$overview_cards = array(
			'newsubs'   => array( __( 'New paid (excl. reactivations)', 'rl-freemius-bi' ), __( 'First-time paid subscriptions in the period, excluding renewals and trials. A customer whose earlier subscription had already ended is a reactivation and is counted separately, because the monthly goal on the Growth tab counts new plus reactivations. Pace compares progress against the target expected by today.', 'rl-freemius-bi' ) ),
			'mrr'       => array( __( 'MRR', 'rl-freemius-bi' ), __( 'The amount that will actually renew, monthlyised by billing cycle and converted at the rate of the day. Freemius renewal_amount is used, and amount_per_cycle (list price) only when it is missing - which would ignore kept discounts and overstate what renews.', 'rl-freemius-bi' ) ),
			'net'       => array( __( 'Net revenue', 'rl-freemius-bi' ), __( 'Gross minus refunds, commission and gateway fees for the selected period, each converted at the rate of its own day.', 'rl-freemius-bi' ) ),
			'churn'     => array( __( 'Churn rate', 'rl-freemius-bi' ), __( 'Cancellations in the period over the subscriber base that existed at its start.', 'rl-freemius-bi' ) ),
			'trialpaid' => array( __( 'Trial to paid', 'rl-freemius-bi' ), __( 'Trials converted to a paid subscription in the period, over trials started.', 'rl-freemius-bi' ) ),
		);
		foreach ( $overview_cards as $card_id => $card_meta ) :
			?>
			<div class="rl-fsbi-kpi-card" data-kpi="<?php echo esc_attr( $card_id ); ?>">
				<div class="rl-fsbi-kpi-head">
					<span class="rl-fsbi-kpi-label"><?php echo esc_html( $card_meta[0] ); ?></span>
					<span class="rl-fsbi-kpi-info" title="<?php echo esc_attr( $card_meta[1] ); ?>"><span class="dashicons dashicons-info" aria-hidden="true"></span><span class="screen-reader-text"><?php echo esc_html( $card_meta[1] ); ?></span></span>
				</div>
				<strong class="rl-fsbi-kpi-value" id="rl-fsbi-kpi-<?php echo esc_attr( $card_id ); ?>">—</strong>
				<span class="rl-fsbi-kpi-delta" id="rl-fsbi-kpi-<?php echo esc_attr( $card_id ); ?>-delta"></span>
				<div class="rl-fsbi-kpi-spark" hidden><canvas id="rl-fsbi-kpi-<?php echo esc_attr( $card_id ); ?>-spark" aria-hidden="true"></canvas></div>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="rl-fsbi-attention" id="rl-fsbi-attention" hidden>
		<h2 class="rl-fsbi-attention-title"><?php echo esc_html__( 'Needs attention', 'rl-freemius-bi' ); ?></h2>
		<ul class="rl-fsbi-attention-list" id="rl-fsbi-attention-list"></ul>
	</div>

	<div class="rl-fsbi-card rl-fsbi-waterfall-card">
		<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'MRR movement', 'rl-freemius-bi' ); ?></div>
		<div class="rl-fsbi-card-sub rl-fsbi-chart-note"><?php echo esc_html__( 'New is first-time subscriptions, reactivation is a customer whose earlier subscription had ended, churn is what stopped during the period. Expansion and contraction are not shown: they need plan-change history Freemius does not expose, and deriving them from amount deltas would report coupons or currency swings as expansion.', 'rl-freemius-bi' ); ?></div>
		<div class="rl-fsbi-waterfall" id="rl-fsbi-waterfall" role="img" aria-label="<?php echo esc_attr__( 'MRR movement for the selected period: new, reactivation, churn and net change.', 'rl-freemius-bi' ); ?>"></div>
	</div>


	</section>

	<section class="rl-fsbi-panel" data-panel="retention" id="rl-fsbi-panel-retention" role="tabpanel" aria-labelledby="rl-fsbi-tab-retention" tabindex="0">
		<div class="rl-fsbi-blocks">
			<section class="rl-fsbi-block rl-fsbi-block--retention" id="rl-fsbi-block-retention" aria-labelledby="rl-fsbi-block-ret-title">
				<h2 class="rl-fsbi-block-title" id="rl-fsbi-block-ret-title"><?php echo esc_html__( 'Retention', 'rl-freemius-bi' ); ?></h2>
				<p class="rl-fsbi-block-sub"><?php echo esc_html__( 'Who stayed, and who needs attention.', 'rl-freemius-bi' ); ?></p>
				<dl class="rl-fsbi-block-metrics">
					<div><dt><?php echo esc_html__( 'Active subscriptions', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-ret-active">—</dd></div>
					<div><dt><?php echo esc_html__( 'Renewals due (30 days)', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-ret-upcoming">—</dd></div>
					<div><dt><?php echo esc_html__( 'At-risk renewals', 'rl-freemius-bi' ); ?><span class="rl-fsbi-info-tooltip" title="<?php esc_attr_e( 'Renewals due in the next 30 days on subscriptions that carry a failed payment or an outstanding balance.', 'rl-freemius-bi' ); ?>" data-tippy-content="<?php echo esc_attr__( 'Renewals due in the next 30 days on subscriptions that carry a failed payment or an outstanding balance.', 'rl-freemius-bi' ); ?>"><span class="dashicons dashicons-info"></span></span></dt><dd id="rl-fsbi-ret-atrisk">—</dd></div>
					<div><dt><?php echo esc_html__( 'Active subs with a failed payment', 'rl-freemius-bi' ); ?><span class="rl-fsbi-info-tooltip" title="<?php esc_attr_e( 'How many of the active subscriptions counted above have recorded at least one failed payment. It is a count of subscriptions, not of failed attempts, and can never exceed the active total.', 'rl-freemius-bi' ); ?>" data-tippy-content="<?php echo esc_attr__( 'How many of the active subscriptions counted above have recorded at least one failed payment. It is a count of subscriptions, not of failed attempts, and can never exceed the active total.', 'rl-freemius-bi' ); ?>"><span class="dashicons dashicons-info"></span></span></dt><dd id="rl-fsbi-ret-failed">—</dd></div>
					<div><dt><?php echo esc_html__( 'Cancellations this month', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-ret-cancellations">—</dd></div>
				</dl>
			</section>
		</div>

		<div class="rl-fsbi-card">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Churn Trend', 'rl-freemius-bi' ); ?></div>
			<div class="rl-fsbi-card-sub rl-fsbi-chart-note"><?php echo esc_html__( 'Cancellations in each month over the base that existed at its start — the same rule as the Churn Rate KPI. A gap means no subscription history was recorded for that month yet.', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-churn-chart"></canvas>
		</div>
	</section>

	<div class="rl-fsbi-content-grid">
		<section class="rl-fsbi-panel" data-panel="growth" id="rl-fsbi-panel-growth" role="tabpanel" aria-labelledby="rl-fsbi-tab-growth" tabindex="0">

		<?php
		$health_tooltip_text = esc_attr__( "Health Score (0–100) measures SaaS stability:\n• Refunds (max 30 pts): 30 pts if <5%, penalized if ≥5%\n• Churn (max 25 pts): 25 pts if <3%, penalized if ≥3%\n• Revenue Momentum (max 20 pts): 20 pts if growing vs previous period\n• Trial Conversion (max 25 pts): only scored when the product runs trials; otherwise the other criteria are scaled to fill 100", 'rl-freemius-bi' );
		$health_tooltip_html = esc_attr( '<div style="text-align:left;line-height:1.45;font-size:12px;padding:2px 4px;"><strong style="display:block;margin-bottom:6px;border-bottom:1px solid rgba(255,255,255,0.15);padding-bottom:4px;">Health Score (0–100) Breakdown</strong><div style="margin-bottom:4px;">• <strong>Refund Control (30 pts):</strong> 30 pts if &lt;5%, penalized if &ge;5%</div><div style="margin-bottom:4px;">• <strong>Subscriber Churn (25 pts):</strong> 25 pts if &lt;3%, penalized if &ge;3%</div><div style="margin-bottom:4px;">• <strong>Trial Conversion (25 pts):</strong> 25 pts if &gt;25%, or direct %</div><div>• <strong>Revenue Momentum (20 pts):</strong> 20 pts if growing vs previous period</div></div>' );
		$arr_tooltip_html    = esc_attr( '<div style="text-align:left;font-size:12px;line-height:1.4;"><strong>Annual Run Rate (ARR)</strong><br>Annualized recurring revenue pace based on current MRR (MRR &times; 12).</div>' );
		$aov_tooltip_html    = esc_attr( '<div style="text-align:left;font-size:12px;line-height:1.4;"><strong>Average Order Value (AOV)</strong><br>Net revenue divided by total purchases in the selected period.</div>' );
		$refund_tooltip_html = esc_attr( '<div style="text-align:left;font-size:12px;line-height:1.4;"><strong>Refund Rate</strong><br>Percentage of orders refunded during the selected date range.</div>' );
		?>

		<div class="rl-fsbi-top-grid rl-fsbi-top-grid--3">
			<div class="rl-fsbi-card">
				<div class="rl-fsbi-card-label"><?php echo esc_html__( 'Expected Payout', 'rl-freemius-bi' ); ?></div>
				<div class="rl-fsbi-card-value" id="rl-fsbi-expected-payout">—</div>
				<div class="rl-fsbi-card-sub" id="rl-fsbi-payout-note"><?php echo esc_html__( 'Based on current MRR pace', 'rl-freemius-bi' ); ?></div>
			</div>
			<div class="rl-fsbi-card">
				<div class="rl-fsbi-card-label"><?php echo esc_html__( 'Refunds Breakdown', 'rl-freemius-bi' ); ?></div>
				<div class="rl-fsbi-card-value" id="rl-fsbi-refunds-breakdown">—</div>
				<div class="rl-fsbi-card-sub" id="rl-fsbi-refunds-sub">—</div>
			</div>
			<div class="rl-fsbi-card rl-fsbi-growth-card">
				<div class="rl-fsbi-card-label" title="<?php echo esc_attr__( 'The monthly goal counts new paid subscriptions plus reactivations: a customer whose earlier subscription had already ended is a reactivation, not a new customer.', 'rl-freemius-bi' ); ?>"><?php echo esc_html__( 'New + reactivations vs goal', 'rl-freemius-bi' ); ?></div>
				<div class="rl-fsbi-card-value rl-fsbi-growth-value">
					<span id="rl-fsbi-newsubs-current">—</span>
					<span class="rl-fsbi-growth-target">/ <span id="rl-fsbi-newsubs-target">—</span></span>
				</div>
				<div class="rl-fsbi-card-sub" id="rl-fsbi-newsubs-note"><?php echo esc_html__( 'this month vs target', 'rl-freemius-bi' ); ?></div>
				<div class="rl-fsbi-growth-chart-wrap">
					<canvas id="rl-fsbi-newsubs-chart" aria-label="<?php echo esc_attr__( 'New paid subscriptions over the last six months', 'rl-freemius-bi' ); ?>" role="img"></canvas>
				</div>
			</div>
		</div>

		<div class="rl-fsbi-mini-kpis">
			<div class="rl-fsbi-mini-kpi">
				<span class="rl-fsbi-kpi-header"><?php echo esc_html__( 'ARR', 'rl-freemius-bi' ); ?><span class="rl-fsbi-info-tooltip" title="<?php esc_attr_e( 'Annual Run Rate', 'rl-freemius-bi' ); ?>" data-tippy-content="<?php echo $arr_tooltip_html; ?>"><span class="dashicons dashicons-info"></span></span></span>
				<strong id="rl-fsbi-arr">—</strong>
				<small id="rl-fsbi-arr-as-of"></small>
			</div>
			<div class="rl-fsbi-mini-kpi">
				<span class="rl-fsbi-kpi-header"><?php echo esc_html__( 'Avg Order Value', 'rl-freemius-bi' ); ?><span class="rl-fsbi-info-tooltip" title="<?php esc_attr_e( 'Average Order Value', 'rl-freemius-bi' ); ?>" data-tippy-content="<?php echo $aov_tooltip_html; ?>"><span class="dashicons dashicons-info"></span></span></span>
				<strong id="rl-fsbi-aov">—</strong>
			</div>
			<div class="rl-fsbi-mini-kpi">
				<span class="rl-fsbi-kpi-header"><?php echo esc_html__( 'Refund Rate', 'rl-freemius-bi' ); ?><span class="rl-fsbi-info-tooltip" title="<?php esc_attr_e( 'Refund Rate', 'rl-freemius-bi' ); ?>" data-tippy-content="<?php echo $refund_tooltip_html; ?>"><span class="dashicons dashicons-info"></span></span></span>
				<strong id="rl-fsbi-refund-rate">—</strong>
			</div>
			<div class="rl-fsbi-mini-kpi">
				<span class="rl-fsbi-kpi-header"><?php echo esc_html__( 'Health Score', 'rl-freemius-bi' ); ?><span class="rl-fsbi-info-tooltip rl-fsbi-health-tooltip" title="<?php echo $health_tooltip_text; ?>" data-tippy-content="<?php echo $health_tooltip_html; ?>"><span class="dashicons dashicons-info"></span></span></span>
				<strong id="rl-fsbi-health-score">—</strong>
			</div>
		</div>

		<div class="rl-fsbi-blocks">
			<section class="rl-fsbi-block rl-fsbi-block--acquisition" aria-labelledby="rl-fsbi-block-acq-title">
				<h2 class="rl-fsbi-block-title" id="rl-fsbi-block-acq-title"><?php echo esc_html__( 'Acquisition', 'rl-freemius-bi' ); ?></h2>
				<p class="rl-fsbi-block-sub"><?php echo esc_html__( 'Who arrived in this period.', 'rl-freemius-bi' ); ?></p>
				<dl class="rl-fsbi-block-metrics">
					<div><dt><?php echo esc_html__( 'New customers', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-acq-new">—</dd></div>
					<div><dt><?php echo esc_html__( 'Reactivations', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-acq-reactivation">—</dd></div>
					<div><dt><?php echo esc_html__( 'Sales origin', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-acq-origin">—</dd></div>
				</dl>
			</section>

			<section class="rl-fsbi-block rl-fsbi-block--revenue" aria-labelledby="rl-fsbi-block-rev-title">
				<h2 class="rl-fsbi-block-title" id="rl-fsbi-block-rev-title"><?php echo esc_html__( 'Revenue', 'rl-freemius-bi' ); ?></h2>
				<p class="rl-fsbi-block-sub"><?php echo esc_html__( 'What the period earned.', 'rl-freemius-bi' ); ?></p>
				<dl class="rl-fsbi-block-metrics">
					<div><dt><?php echo esc_html__( 'ARR', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-rev-arr">—</dd></div>
					<div><dt><?php echo esc_html__( 'Gross', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-rev-gross">—</dd></div>
					<div><dt><?php echo esc_html__( 'Refunds', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-rev-refunds">—</dd></div>
					<div><dt><?php echo esc_html__( 'Fees', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-rev-fees">—</dd></div>
					<div><dt><?php echo esc_html__( 'Net', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-rev-net">—</dd></div>
				</dl>
				<div class="rl-fsbi-fx-note" id="rl-fsbi-fx-note" hidden>
					<?php echo esc_html__( "Converted at each day's rate; small rounding differences may occur.", 'rl-freemius-bi' ); ?>
				</div>
			</section>
		</div>
		<div class="rl-fsbi-card rl-fsbi-span-2">
			<div class="rl-fsbi-panel-title" id="rl-fsbi-sales-activity-title"><?php echo esc_html__( 'Sales Activity', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-sales-activity-chart"></canvas>
		</div>

		<div class="rl-fsbi-mini-stat-grid">
			<div class="rl-fsbi-card rl-fsbi-mini-stat"><span><?php echo esc_html__( 'Purchases', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-stat-purchases">0</strong></div>
			<div class="rl-fsbi-card rl-fsbi-mini-stat"><span><?php echo esc_html__( 'Trials / Conversions', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-stat-trials-conversions">0/0</strong></div>
			<div class="rl-fsbi-card rl-fsbi-mini-stat"><span><?php echo esc_html__( 'Refunds', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-stat-refunds">0</strong></div>
			<div class="rl-fsbi-card rl-fsbi-mini-stat"><span><?php echo esc_html__( 'Renewals', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-stat-renewals">0</strong></div>
			<div class="rl-fsbi-card rl-fsbi-mini-stat">
				<span style="display:flex; align-items:center; justify-content:space-between; width:100%;">
					<span>
						<?php echo esc_html__( 'Newsletter Opt-ins', 'rl-freemius-bi' ); ?>
						<?php
						$active_provider = (string) $this->settings->get_option( 'rl_fsbi_newsletter_provider', 'mailchimp' );
						$provider_label  = 'kit' === $active_provider ? 'Kit.com' : 'Mailchimp';
						$optin_scope     = 'kit' === $active_provider
							? esc_html__( 'Counts active subscribers in your Kit account, whatever their source: Freemius opt-ins, manual uploads and other forms all contribute.', 'rl-freemius-bi' )
							: esc_html__( 'Counts subscribed members of the Mailchimp audience, whatever their source: Freemius opt-ins, manual uploads and other forms all contribute.', 'rl-freemius-bi' );
						$optin_tooltip   = esc_attr(
							sprintf(
								/* translators: 1: provider name, 2: scope sentence, 3: provider name */
								__( 'Scope: %s It is not a count of Freemius customers. Click refresh to pull the live total from %s, or the envelope to push opted-in users from Freemius.', 'rl-freemius-bi' ),
								$optin_scope,
								$provider_label
							)
						);
						$refresh_title   = sprintf( esc_attr__( 'Refresh subscriber count from %s', 'rl-freemius-bi' ), $provider_label );
						$sync_title      = sprintf( esc_attr__( 'Sync Freemius opt-ins to %s', 'rl-freemius-bi' ), $provider_label );
						?>
						<span class="rl-fsbi-info-tooltip" title="<?php echo $optin_tooltip; ?>" data-tippy-content="<?php echo $optin_tooltip; ?>"><span class="dashicons dashicons-info"></span></span>
					</span>
					<span style="display:inline-flex; align-items:center; gap:2px;">
						<button type="button" id="rl-fsbi-refresh-optins-btn" class="button-link" title="<?php echo $refresh_title; ?>" style="color:#64748b; line-height:1; padding:2px; cursor:pointer;">
							<span class="dashicons dashicons-update" style="font-size:14px; width:14px; height:14px;"></span>
						</button>
						<button type="button" id="rl-fsbi-sync-mailchimp-btn" class="button-link" title="<?php echo $sync_title; ?>" style="color:#0284c7; line-height:1; padding:2px; cursor:pointer;">
							<span class="dashicons dashicons-email-alt" style="font-size:14px; width:14px; height:14px;"></span>
						</button>
						<button type="button" id="rl-fsbi-export-optins-csv-btn" class="button-link" title="<?php esc_attr_e( 'Export opted-in subscribers to CSV (for Kit.com import)', 'rl-freemius-bi' ); ?>" style="color:#10b981; line-height:1; padding:2px; cursor:pointer;">
							<span class="dashicons dashicons-download" style="font-size:14px; width:14px; height:14px;"></span>
						</button>
					</span>
				</span>
				<strong id="rl-fsbi-stat-marketing-optins">0</strong>
			</div>
		</div>

		<div class="rl-fsbi-card rl-fsbi-span-3">
			<div class="rl-fsbi-card-header-flex">
				<div>
					<div class="rl-fsbi-panel-title" id="rl-fsbi-expected-renewals-title"><?php echo esc_html__( 'Expected Renewals: Current Month', 'rl-freemius-bi' ); ?></div>
					<div class="rl-fsbi-card-sub rl-fsbi-chart-note"><?php echo esc_html__( 'Daily projected subscription renewals and expected revenue for the current month.', 'rl-freemius-bi' ); ?></div>
				</div>
				<div class="rl-fsbi-renewals-badges">
					<span class="rl-fsbi-renewals-badge rl-fsbi-renewals-badge--total" id="rl-fsbi-renewals-total-badge">—</span>
					<span class="rl-fsbi-renewals-badge rl-fsbi-renewals-badge--completed" id="rl-fsbi-renewals-completed-badge">—</span>
					<span class="rl-fsbi-renewals-badge rl-fsbi-renewals-badge--upcoming" id="rl-fsbi-renewals-upcoming-badge">—</span>
				</div>
			</div>
			<canvas id="rl-fsbi-expected-renewals-chart"></canvas>
		</div>

		<div class="rl-fsbi-card rl-fsbi-span-3">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Revenue Overview', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-revenue-overview-chart"></canvas>
		</div>

		<div class="rl-fsbi-card rl-fsbi-span-3 rl-fsbi-trial-panel">
			<div class="rl-fsbi-trial-panel-grid">
				<div class="rl-fsbi-trial-funnel-col">
					<div class="rl-fsbi-panel-title">
						<?php echo esc_html__( 'Trial Funnel', 'rl-freemius-bi' ); ?>
						<span class="rl-fsbi-data-source" id="rl-fsbi-trials-source"><?php echo esc_html__( 'Estimated from subscriptions', 'rl-freemius-bi' ); ?></span>
					</div>
					<div class="rl-fsbi-card-sub rl-fsbi-chart-note"><?php echo esc_html__( 'Trials started in the selected period and what happened to them.', 'rl-freemius-bi' ); ?></div>
					<div class="rl-fsbi-trial-numbers">
						<div><span><?php echo esc_html__( 'Total Trials', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-trials-total">0</strong></div>
						<div><span><?php echo esc_html__( 'With Payment Method', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-trials-with-method">0</strong></div>
						<div><span><?php echo esc_html__( 'Converted', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-trials-converted">0</strong></div>
						<div><span><?php echo esc_html__( 'Canceled', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-trials-canceled">0</strong></div>
						<div><span><?php echo esc_html__( 'Conversion Rate', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-trials-rate">0%</strong></div>
					</div>
					<div class="rl-fsbi-funnel" id="rl-fsbi-trial-funnel" aria-live="polite"></div>
				</div>
				<div class="rl-fsbi-churn-col">
					<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Churn Reasons', 'rl-freemius-bi' ); ?></div>
					<div class="rl-fsbi-card-sub rl-fsbi-chart-note">
						<?php
						echo esc_html__( 'Why people uninstalled, split by who they were. Free means never paid and never trialled.', 'rl-freemius-bi' );
						?>
					</div>
					<div class="rl-fsbi-churn-segments" id="rl-fsbi-churn-segments" role="group" aria-label="<?php echo esc_attr__( 'Filter uninstall reasons by customer type', 'rl-freemius-bi' ); ?>">
						<button type="button" class="rl-fsbi-churn-chip is-active" data-segment="free"><?php echo esc_html__( 'Free', 'rl-freemius-bi' ); ?></button>
						<button type="button" class="rl-fsbi-churn-chip" data-segment="trial"><?php echo esc_html__( 'Trial', 'rl-freemius-bi' ); ?></button>
						<button type="button" class="rl-fsbi-churn-chip" data-segment="paid"><?php echo esc_html__( 'Paying', 'rl-freemius-bi' ); ?></button>
						<button type="button" class="rl-fsbi-churn-chip" data-segment="all"><?php echo esc_html__( 'All', 'rl-freemius-bi' ); ?></button>
					</div>
					<p class="rl-fsbi-churn-scope" id="rl-fsbi-churn-scope"><?php echo esc_html__( 'Free plan installs that were uninstalled, with the reason the user picked.', 'rl-freemius-bi' ); ?></p>
					<ul class="rl-fsbi-churn-reasons" id="rl-fsbi-churn-reasons" aria-live="polite">
						<li class="rl-fsbi-churn-empty"><?php echo esc_html__( 'No uninstall feedback stored yet. Run a sync to collect it.', 'rl-freemius-bi' ); ?></li>
					</ul>
				</div>
			</div>
		</div>

		<div class="rl-fsbi-forecast-row">
		<div class="rl-fsbi-card">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( '12-Month Revenue Forecast', 'rl-freemius-bi' ); ?></div>
			<div class="rl-fsbi-card-sub rl-fsbi-chart-note"><?php echo esc_html__( 'Expected renewal amount from the selected date onward if subscriptions are not canceled.', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-forecast-chart"></canvas>
		</div>
		</div>

		<div class="rl-fsbi-four-chart-row">
		<div class="rl-fsbi-card">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Gross Revenue by Currency', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-currency-chart"></canvas>
		</div>

		<div class="rl-fsbi-card">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Net Revenue by Country', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-country-chart"></canvas>
		</div>

		<div class="rl-fsbi-card">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Active Subscriptions by Plan', 'rl-freemius-bi' ); ?></div>
			<div class="rl-fsbi-card-sub rl-fsbi-chart-note"><?php echo esc_html__( 'The same active subscriptions the Churn Rate and Active Subscriptions figures use, so the shares add up to that total.', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-plan-chart"></canvas>
		</div>
		</div>

		<div class="rl-fsbi-card rl-fsbi-span-3">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Freemius Sales Volume (Daily, Last 90 Days)', 'rl-freemius-bi' ); ?></div>
			<div class="rl-fsbi-card-sub rl-fsbi-chart-note"><?php echo esc_html__( 'Number of payment events recorded per day. This is Freemius billing activity, not WordPress.org install counts.', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-sales-volume-chart"></canvas>
		</div>

		<?php if ( ! empty( $GLOBALS['rl_fsbi_has_wporg'] ) ) : ?>
		</section>
		<section class="rl-fsbi-panel" data-panel="wporg" id="rl-fsbi-panel-wporg" role="tabpanel" aria-labelledby="rl-fsbi-tab-wporg" tabindex="0">
		<div class="rl-fsbi-card rl-fsbi-wporg-widget" id="rl-fsbi-wporg-widget">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'WordPress.org (free)', 'rl-freemius-bi' ); ?></div>
			<div class="rl-fsbi-card-sub rl-fsbi-chart-note"><?php echo esc_html__( 'From the public WordPress.org plugins API. These are installs of the free plugin from the WordPress.org directory — a different population from the Freemius install records beside them, so the two are shown separately and never added together.', 'rl-freemius-bi' ); ?></div>
			<dl class="rl-fsbi-block-metrics">
				<div><dt><?php echo esc_html__( 'Active installs', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-wporg-active">—</dd></div>
				<div><dt><?php echo esc_html__( 'Total downloads', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-wporg-downloads">—</dd></div>
				<div><dt><?php echo esc_html__( 'Version', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-wporg-version">—</dd></div>
				<div><dt><?php echo esc_html__( 'Last updated', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-wporg-updated">—</dd></div>
			</dl>
			<p class="rl-fsbi-wporg-status" id="rl-fsbi-wporg-status" hidden></p>
			<div class="rl-fsbi-wporg-history-wrap">
				<canvas id="rl-fsbi-wporg-installs-chart" aria-label="<?php echo esc_attr__( 'WordPress.org daily downloads', 'rl-freemius-bi' ); ?>" role="img"></canvas>
			</div>
		</div>
		<?php endif; ?>

		</section>
		<section class="rl-fsbi-panel" data-panel="growth">
		<div class="rl-fsbi-card rl-fsbi-installs-widget">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Freemius Installs', 'rl-freemius-bi' ); ?></div>
			<div class="rl-fsbi-card-sub rl-fsbi-chart-note"><?php echo esc_html__( 'Install records reported to Freemius by the plugin itself. WordPress.org shows a different population and is never combined with this figure.', 'rl-freemius-bi' ); ?></div>
			<dl class="rl-fsbi-block-metrics">
				<div><dt><?php echo esc_html__( 'Active', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-installs-active">—</dd></div>
				<div><dt><?php echo esc_html__( 'Premium', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-installs-premium">—</dd></div>
				<div><dt><?php echo esc_html__( 'Uninstalled', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-installs-uninstalled">—</dd></div>
				<div><dt><?php echo esc_html__( 'Total records', 'rl-freemius-bi' ); ?></dt><dd id="rl-fsbi-installs-total">—</dd></div>
			</dl>
		</div>
		</section>
	</div>

	<section class="rl-fsbi-panel" data-panel="marketing" id="rl-fsbi-panel-marketing" role="tabpanel" aria-labelledby="rl-fsbi-tab-marketing" tabindex="0">
	<div class="rl-fsbi-card rl-fsbi-coupons-card">
		<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Coupons', 'rl-freemius-bi' ); ?></div>
		<div class="rl-fsbi-card-sub rl-fsbi-chart-note"><?php echo esc_html__( 'Redemptions in the selected period with the coupon definition as Freemius reports it. Uses counts this period; the limit, remaining and expiry are the coupon\'s own. Discount given is derived from the amount actually charged.', 'rl-freemius-bi' ); ?></div>
		<div class="rl-fsbi-table-wrap">
			<table class="rl-fsbi-coupons-table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Code', 'rl-freemius-bi' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Discount', 'rl-freemius-bi' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Status', 'rl-freemius-bi' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Uses / limit', 'rl-freemius-bi' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Remaining', 'rl-freemius-bi' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Expiry', 'rl-freemius-bi' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Revenue', 'rl-freemius-bi' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Discount given', 'rl-freemius-bi' ); ?></th>
					</tr>
				</thead>
				<tbody id="rl-fsbi-coupons-body">
					<tr><td colspan="8"><?php echo esc_html__( 'No coupon redemptions in this period.', 'rl-freemius-bi' ); ?></td></tr>
				</tbody>
			</table>
		</div>
	</section>

	<section class="rl-fsbi-panel" data-panel="customers" id="rl-fsbi-panel-customers" role="tabpanel" aria-labelledby="rl-fsbi-tab-customers" tabindex="0">
	<div class="rl-fsbi-card rl-fsbi-table-card">
		<div class="rl-fsbi-card-header-flex">
			<div>
				<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Monthly Revenue Breakdown', 'rl-freemius-bi' ); ?></div>
				<div class="rl-fsbi-card-sub"><?php echo esc_html__( '12-month rolling revenue, subscription activity, and payout history.', 'rl-freemius-bi' ); ?></div>
			</div>
			<div class="rl-fsbi-export-actions">
				<button type="button" id="rl-fsbi-export-monthly-csv" class="button rl-fsbi-export-btn" title="<?php echo esc_attr__( 'Export last 12 months to CSV', 'rl-freemius-bi' ); ?>">
					<span class="dashicons dashicons-download" style="vertical-align: text-bottom; margin-right: 4px; font-size: 16px; width: 16px; height: 16px;"></span>
					<?php echo esc_html__( 'Export 12M CSV', 'rl-freemius-bi' ); ?>
				</button>
				<button type="button" id="rl-fsbi-export-3yr-csv" class="button rl-fsbi-export-btn" title="<?php echo esc_attr__( 'Export last 3 years (36 months) to CSV', 'rl-freemius-bi' ); ?>">
					<span class="dashicons dashicons-download" style="vertical-align: text-bottom; margin-right: 4px; font-size: 16px; width: 16px; height: 16px;"></span>
					<?php echo esc_html__( 'Export 3 Years CSV', 'rl-freemius-bi' ); ?>
				</button>
			</div>
		</div>
		<div class="rl-fsbi-table-wrap">
			<table id="rl-fsbi-monthly-table" class="display">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Period', 'rl-freemius-bi' ); ?></th>
						<th><?php echo esc_html__( 'Subscriptions', 'rl-freemius-bi' ); ?></th>
						<th><?php echo esc_html__( 'Gross', 'rl-freemius-bi' ); ?></th>
						<th><?php echo esc_html__( 'Refunds', 'rl-freemius-bi' ); ?></th>
						<th><?php echo esc_html__( 'Fees', 'rl-freemius-bi' ); ?></th>
						<th><?php echo esc_html__( 'Net', 'rl-freemius-bi' ); ?></th>
						<th><?php echo esc_html__( 'Currency', 'rl-freemius-bi' ); ?></th>
						<th><?php echo esc_html__( 'Payout', 'rl-freemius-bi' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
				<tfoot>
					<tr>
						<th><?php echo esc_html__( 'Total (12m Converted)', 'rl-freemius-bi' ); ?></th>
						<th>—</th>
						<th id="rl-fsbi-total-gross">—</th>
						<th id="rl-fsbi-total-refunds">—</th>
						<th id="rl-fsbi-total-fees">—</th>
						<th id="rl-fsbi-total-net">—</th>
						<th id="rl-fsbi-total-currency">—</th>
						<th>—</th>
					</tr>
				</tfoot>
			</table>
		</div>
	</div>
	</section>
	</div>

	<!-- Mailchimp Sync Live Progress Modal -->
	<div id="rl-fsbi-mc-sync-modal" class="rl-fsbi-modal-backdrop" style="display:none;">
		<div class="rl-fsbi-modal-box">
			<div class="rl-fsbi-modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
				<h3 style="margin:0; font-size:16px; font-weight:600; display:flex; align-items:center; gap:8px; color:#0f172a;">
					<span class="dashicons dashicons-email-alt" style="color:#0284c7; font-size:20px; width:20px; height:20px;"></span>
					<span id="rl-fsbi-mc-modal-title"><?php esc_html_e( 'Newsletter Opt-ins Sync', 'rl-freemius-bi' ); ?></span>
				</h3>
				<button type="button" class="rl-fsbi-modal-close" id="rl-fsbi-mc-modal-close-x" style="background:none; border:none; cursor:pointer; font-size:22px; line-height:1; color:#94a3b8;">&times;</button>
			</div>
				<?php
				$latest_export = get_option( 'rl_fsbi_latest_optins_csv_export', array() );
				$has_ready_file = ! empty( $latest_export['token'] ) && ! empty( $latest_export['path'] ) && file_exists( $latest_export['path'] );
				$ready_count = $has_ready_file && ! empty( $latest_export['optins_count'] ) ? number_format( (int) $latest_export['optins_count'] ) : '10,975';
				$download_url = $has_ready_file ? admin_url( 'admin-ajax.php?action=rl_fsbi_download_generated_csv&token=' . urlencode( $latest_export['token'] ) . '&nonce=' . wp_create_nonce( 'rl_fsbi_nonce' ) ) : '#';
				?>
				<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; font-size:12px; color:#475569; background:#f8fafc; padding:8px 12px; border-radius:6px; border:1px solid #e2e8f0; flex-wrap:wrap; gap:8px;">
					<label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-weight:500;" id="rl-fsbi-sync-delta-label">
						<input type="checkbox" id="rl-fsbi-sync-delta-only" checked="checked" />
						<span><?php esc_html_e( 'Sync latest opt-ins only (since last sync)', 'rl-freemius-bi' ); ?></span>
					</label>
					<label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-weight:500;" id="rl-fsbi-csv-email-label">
						<input type="checkbox" id="rl-fsbi-csv-email-admin" checked="checked" />
						<span><?php echo sprintf( esc_html__( 'Also email copy to %s', 'rl-freemius-bi' ), esc_html( get_option( 'admin_email', 'admin' ) ) ); ?></span>
					</label>
					<span id="rl-fsbi-last-sync-tag" style="font-size:11px; color:#64748b;"></span>
				</div>
				<p id="rl-fsbi-mc-sync-status-text" style="margin:0 0 12px; font-size:13px; color:#475569; font-weight:500;">
					<?php esc_html_e( 'Preparing synchronization...', 'rl-freemius-bi' ); ?>
				</p>
				<div class="rl-fsbi-progress-track" style="height:10px; background:#e2e8f0; border-radius:5px; overflow:hidden; margin-bottom:16px;">
					<div id="rl-fsbi-mc-sync-progress-bar" style="height:100%; width:0%; background:linear-gradient(90deg, #0284c7, #38bdf8); transition:width 0.3s ease;"></div>
				</div>
				<div class="rl-fsbi-modal-stats-grid" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; text-align:center; background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0; margin-bottom:14px;">
					<div>
						<span style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:600; display:block;"><?php esc_html_e( 'Scanned', 'rl-freemius-bi' ); ?></span>
						<strong id="rl-fsbi-mc-stat-scanned" style="font-size:16px; color:#1e293b;">0</strong>
					</div>
					<div>
						<span style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:600; display:block;"><?php esc_html_e( 'Opt-ins', 'rl-freemius-bi' ); ?></span>
						<strong id="rl-fsbi-mc-stat-optins" style="font-size:16px; color:#0284c7;">0</strong>
					</div>
					<div>
						<span id="rl-fsbi-mc-stat-synced-label" style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:600; display:block;"><?php esc_html_e( 'Exported', 'rl-freemius-bi' ); ?></span>
						<strong id="rl-fsbi-mc-stat-synced" style="font-size:16px; color:#16a34a;">0</strong>
					</div>
					<div>
						<span style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:600; display:block;"><?php esc_html_e( 'Errors', 'rl-freemius-bi' ); ?></span>
						<strong id="rl-fsbi-mc-stat-errors" style="font-size:16px; color:#dc2626;">0</strong>
					</div>
				</div>
				<div id="rl-fsbi-mc-sync-live-log" style="font-size:11px; color:#64748b; max-height:90px; overflow-y:auto; font-family:ui-monospace,monospace; background:#ffffff; border:1px solid #e2e8f0; padding:6px 10px; border-radius:4px; line-height:1.4;">
					<?php esc_html_e( 'Waiting to start...', 'rl-freemius-bi' ); ?>
				</div>
			</div>
			<div class="rl-fsbi-modal-footer" style="display:flex; justify-content:space-between; align-items:center; margin-top:16px; padding-top:12px; border-top:1px solid #f1f5f9; flex-wrap:wrap; gap:8px;">
				<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
					<a href="<?php echo esc_url( $download_url ); ?>" class="button button-primary" id="rl-fsbi-modal-download-latest-btn" style="<?php echo $has_ready_file ? 'display:inline-flex;' : 'display:none;'; ?> align-items:center; gap:6px; font-size:12px; background:#059669; border-color:#047857; color:#fff; text-decoration:none; padding:2px 12px; font-weight:600;">
						<span class="dashicons dashicons-download" style="font-size:14px; width:14px; height:14px; line-height:26px;"></span>
						<span id="rl-fsbi-modal-download-latest-text"><?php echo sprintf( esc_html__( 'Click to Download CSV (%s)', 'rl-freemius-bi' ), $ready_count ); ?></span>
					</a>
					<button type="button" class="button button-secondary" id="rl-fsbi-modal-export-csv-btn" style="display:inline-flex; align-items:center; gap:4px; font-size:12px; color:#0284c7; border-color:#bae6fd;">
						<span class="dashicons dashicons-media-spreadsheet" style="font-size:14px; width:14px; height:14px; line-height:14px;"></span>
						<span id="rl-fsbi-modal-export-csv-text"><?php esc_html_e( 'Generate CSV on Server', 'rl-freemius-bi' ); ?></span>
					</button>
				</div>
				<div style="display:flex; gap:8px;">
					<button type="button" class="button button-secondary" id="rl-fsbi-mc-stop-sync-btn" style="display:inline-flex; align-items:center; gap:4px; color:#dc2626; border-color:#fca5a5;">
						<span class="dashicons dashicons-no-alt" style="font-size:14px; width:14px; height:14px; line-height:14px;"></span>
						<span><?php esc_html_e( 'Stop', 'rl-freemius-bi' ); ?></span>
					</button>
					<button type="button" class="button button-primary" id="rl-fsbi-mc-close-modal-btn" style="display:none;">
						<?php esc_html_e( 'Done', 'rl-freemius-bi' ); ?>
					</button>
				</div>
			</div>
		</div>
	</div>
