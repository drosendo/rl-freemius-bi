<?php
/**
 * Admin Dashboard Template
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>

<div class="wrap rl-fsbi-dashboard">
	<?php
	$forced_plugin_id = isset( $GLOBALS['rl_fsbi_forced_plugin_id'] ) ? (int) $GLOBALS['rl_fsbi_forced_plugin_id'] : 0;
	$plugins_catalog  = isset( $GLOBALS['rl_fsbi_plugins_catalog'] ) && is_array( $GLOBALS['rl_fsbi_plugins_catalog'] ) ? $GLOBALS['rl_fsbi_plugins_catalog'] : array();
	$raw_catalog      = isset( $GLOBALS['rl_fsbi_raw_catalog'] ) && is_array( $GLOBALS['rl_fsbi_raw_catalog'] ) ? $GLOBALS['rl_fsbi_raw_catalog'] : array();
	$initial_version  = isset( $GLOBALS['rl_fsbi_plugin_version'] ) ? (string) $GLOBALS['rl_fsbi_plugin_version'] : '';

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

			<label for="rl-fsbi-currency-filter"><?php echo esc_html__( 'Currency:', 'rl-freemius-bi' ); ?></label>
			<select id="rl-fsbi-currency-filter" name="currency">
				<option value="all"><?php echo esc_html__( 'All Currencies', 'rl-freemius-bi' ); ?></option>
				<option value="USD">USD</option>
				<option value="EUR">EUR</option>
				<option value="GBP">GBP</option>
			</select>

			<label for="rl-fsbi-date-range"><?php echo esc_html__( 'Date Range:', 'rl-freemius-bi' ); ?></label>
			<input type="date" id="rl-fsbi-start-date" />
			<span>—</span>
			<input type="date" id="rl-fsbi-end-date" />

			<button id="rl-fsbi-sync-btn" class="button button-primary"><?php echo esc_html__( 'Sync Now', 'rl-freemius-bi' ); ?></button>
			<button id="rl-fsbi-sync-cancel-btn" class="button" type="button" hidden><?php echo esc_html__( 'Cancel Sync', 'rl-freemius-bi' ); ?></button>
		</div>
	</div>

	<div class="rl-fsbi-top-grid">
		<div class="rl-fsbi-card rl-fsbi-hero-card">
			<div class="rl-fsbi-card-label"><?php echo esc_html__( 'Total Net Revenue', 'rl-freemius-bi' ); ?></div>
			<div class="rl-fsbi-hero-value" id="rl-fsbi-net-revenue">—</div>
			<div class="rl-fsbi-card-sub" id="rl-fsbi-net-comparison"><?php echo esc_html__( 'vs previous period', 'rl-freemius-bi' ); ?></div>
		</div>
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
	</div>

	<?php
	$health_tooltip_text = esc_attr__( "Health Score (0–100) measures SaaS stability:\n• Refunds (max 30 pts): 30 pts if <5%, penalized if ≥5%\n• Churn (max 25 pts): 25 pts if <3%, penalized if ≥3%\n• Trial Conversion (max 25 pts): 25 pts if >25%, or actual %\n• Revenue Momentum (max 20 pts): 20 pts if growing vs previous period", 'rl-freemius-bi' );
	$health_tooltip_html = esc_attr( '<div style="text-align:left;line-height:1.45;font-size:12px;padding:2px 4px;"><strong style="display:block;margin-bottom:6px;border-bottom:1px solid rgba(255,255,255,0.15);padding-bottom:4px;">Health Score (0–100) Breakdown</strong><div style="margin-bottom:4px;">• <strong>Refund Control (30 pts):</strong> 30 pts if &lt;5%, penalized if &ge;5%</div><div style="margin-bottom:4px;">• <strong>Subscriber Churn (25 pts):</strong> 25 pts if &lt;3%, penalized if &ge;3%</div><div style="margin-bottom:4px;">• <strong>Trial Conversion (25 pts):</strong> 25 pts if &gt;25%, or direct %</div><div>• <strong>Revenue Momentum (20 pts):</strong> 20 pts if growing vs previous period</div></div>' );
	$mrr_tooltip_html    = esc_attr( '<div style="text-align:left;font-size:12px;line-height:1.4;"><strong>Monthly Recurring Revenue (MRR)</strong><br>Normalized recurring monthly revenue generated by active subscriptions.</div>' );
	$arr_tooltip_html    = esc_attr( '<div style="text-align:left;font-size:12px;line-height:1.4;"><strong>Annual Run Rate (ARR)</strong><br>Annualized recurring revenue pace based on current MRR (MRR &times; 12).</div>' );
	$aov_tooltip_html    = esc_attr( '<div style="text-align:left;font-size:12px;line-height:1.4;"><strong>Average Order Value (AOV)</strong><br>Net revenue divided by total purchases in the selected period.</div>' );
	$refund_tooltip_html = esc_attr( '<div style="text-align:left;font-size:12px;line-height:1.4;"><strong>Refund Rate</strong><br>Percentage of orders refunded during the selected date range.</div>' );
	$churn_tooltip_html  = esc_attr( '<div style="text-align:left;font-size:12px;line-height:1.4;"><strong>Subscriber Churn Rate</strong><br>Percentage of active subscriptions canceled during the current month.</div>' );
	?>

	<div class="rl-fsbi-card rl-fsbi-portfolio-widget">
		<div class="rl-fsbi-portfolio-header">
			<div>
				<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Portfolio Performance', 'rl-freemius-bi' ); ?></div>
				<div class="rl-fsbi-card-sub"><?php echo esc_html__( 'A concise view of recurring strength and recent momentum.', 'rl-freemius-bi' ); ?></div>
			</div>
			<div class="rl-fsbi-portfolio-health-wrap">
				<strong id="rl-fsbi-portfolio-health">—</strong>
				<span class="rl-fsbi-info-tooltip" title="<?php echo $health_tooltip_text; ?>" data-tippy-content="<?php echo $health_tooltip_html; ?>">
					<span class="dashicons dashicons-info"></span>
				</span>
			</div>
		</div>
		<div class="rl-fsbi-portfolio-metrics">
			<div><span><?php echo esc_html__( 'Net Revenue', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-portfolio-revenue">—</strong></div>
			<div><span><?php echo esc_html__( 'MRR', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-portfolio-mrr">—</strong></div>
			<div><span><?php echo esc_html__( 'ARR', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-portfolio-arr">—</strong></div>
			<div><span><?php echo esc_html__( 'Active Subscribers', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-portfolio-subs">—</strong></div>
		</div>
		<canvas id="rl-fsbi-portfolio-chart"></canvas>
	</div>

	<div class="rl-fsbi-mini-kpis">
		<div class="rl-fsbi-mini-kpi">
			<span class="rl-fsbi-kpi-header"><?php echo esc_html__( 'MRR', 'rl-freemius-bi' ); ?><span class="rl-fsbi-info-tooltip" title="<?php esc_attr_e( 'Monthly Recurring Revenue', 'rl-freemius-bi' ); ?>" data-tippy-content="<?php echo $mrr_tooltip_html; ?>"><span class="dashicons dashicons-info"></span></span></span>
			<strong id="rl-fsbi-mrr">—</strong>
			<small id="rl-fsbi-mrr-as-of"></small>
		</div>
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
			<span class="rl-fsbi-kpi-header"><?php echo esc_html__( 'Churn Rate', 'rl-freemius-bi' ); ?><span class="rl-fsbi-info-tooltip" title="<?php esc_attr_e( 'Subscriber Churn Rate', 'rl-freemius-bi' ); ?>" data-tippy-content="<?php echo $churn_tooltip_html; ?>"><span class="dashicons dashicons-info"></span></span></span>
			<strong id="rl-fsbi-churn-rate">—</strong>
		</div>
		<div class="rl-fsbi-mini-kpi">
			<span class="rl-fsbi-kpi-header"><?php echo esc_html__( 'Health Score', 'rl-freemius-bi' ); ?><span class="rl-fsbi-info-tooltip" title="<?php echo $health_tooltip_text; ?>" data-tippy-content="<?php echo $health_tooltip_html; ?>"><span class="dashicons dashicons-info"></span></span></span>
			<strong id="rl-fsbi-health-score">—</strong>
		</div>
	</div>

	<div class="rl-fsbi-content-grid">
		<div class="rl-fsbi-card rl-fsbi-span-2">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Sales Activity: Current Month', 'rl-freemius-bi' ); ?></div>
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
						$optin_tooltip   = sprintf( esc_attr__( 'Active subscriber count in %s. Click the refresh icon to pull live subscriber totals directly from %s, or the mail icon to sync newly opted-in users from Freemius.', 'rl-freemius-bi' ), $provider_label, $provider_label );
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
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Trial Conversion', 'rl-freemius-bi' ); ?></div>
			<div class="rl-fsbi-trial-numbers">
				<div><span><?php echo esc_html__( 'Total Trials', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-trials-total">0</strong></div>
				<div><span><?php echo esc_html__( 'Converted', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-trials-converted">0</strong></div>
				<div><span><?php echo esc_html__( 'Conversion Rate', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-trials-rate">0%</strong></div>
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
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Churn Trend', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-churn-chart"></canvas>
		</div>

		<div class="rl-fsbi-card">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Revenue by Currency', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-currency-chart"></canvas>
		</div>

		<div class="rl-fsbi-card">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Revenue by Country', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-country-chart"></canvas>
		</div>

		<div class="rl-fsbi-card">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Plan Distribution', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-plan-chart"></canvas>
		</div>
		</div>

		<div class="rl-fsbi-card rl-fsbi-span-3">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'WP.org Growth & Downloads', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-wporg-chart"></canvas>
		</div>
	</div>

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
</div>
