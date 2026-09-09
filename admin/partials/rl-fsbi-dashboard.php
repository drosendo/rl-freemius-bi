<?php
/**
 * Admin Dashboard Template
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>

<div class="wrap rl-fsbi-dashboard">
	<h1><?php echo esc_html__( 'FSBI v2', 'rl-freemius-bi' ); ?></h1>
	<?php
	$forced_plugin_id = isset( $GLOBALS['rl_fsbi_forced_plugin_id'] ) ? (int) $GLOBALS['rl_fsbi_forced_plugin_id'] : 0;
	$plugins_catalog = isset( $GLOBALS['rl_fsbi_plugins_catalog'] ) && is_array( $GLOBALS['rl_fsbi_plugins_catalog'] ) ? $GLOBALS['rl_fsbi_plugins_catalog'] : array();
	?>

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

	<div class="rl-fsbi-mini-kpis">
		<div class="rl-fsbi-mini-kpi"><span><?php echo esc_html__( 'MRR', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-mrr">—</strong><small id="rl-fsbi-mrr-as-of"></small></div>
		<div class="rl-fsbi-mini-kpi"><span><?php echo esc_html__( 'ARR', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-arr">—</strong><small id="rl-fsbi-arr-as-of"></small></div>
		<div class="rl-fsbi-mini-kpi"><span><?php echo esc_html__( 'Avg Order Value', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-aov">—</strong></div>
		<div class="rl-fsbi-mini-kpi"><span><?php echo esc_html__( 'Refund Rate', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-refund-rate">—</strong></div>
		<div class="rl-fsbi-mini-kpi"><span><?php echo esc_html__( 'Churn Rate', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-churn-rate">—</strong></div>
		<div class="rl-fsbi-mini-kpi"><span><?php echo esc_html__( 'Health Score', 'rl-freemius-bi' ); ?></span><strong id="rl-fsbi-health-score">—</strong></div>
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

		<div class="rl-fsbi-card">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( '12-Month Revenue Forecast', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-forecast-chart"></canvas>
		</div>

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

		<div class="rl-fsbi-card rl-fsbi-span-3">
			<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'WP.org Growth & Downloads', 'rl-freemius-bi' ); ?></div>
			<canvas id="rl-fsbi-wporg-chart"></canvas>
		</div>
	</div>

	<div class="rl-fsbi-card rl-fsbi-table-card">
		<div class="rl-fsbi-panel-title"><?php echo esc_html__( 'Monthly Revenue Breakdown', 'rl-freemius-bi' ); ?></div>
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
</div>
