<?php
/**
 * Admin Dashboard Template
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>

<div class="wrap rl-fsbi-dashboard">
	<h1><?php echo esc_html__( 'Freemius Business Intelligence Dashboard', 'rl-freemius-bi' ); ?></h1>

	<div class="rl-fsbi-header">
		<div class="rl-fsbi-controls">
			<label for="rl-fsbi-plugin-filter"><?php echo esc_html__( 'Plugin:', 'rl-freemius-bi' ); ?></label>
			<select id="rl-fsbi-plugin-filter" name="plugin_id">
				<option value="all"><?php echo esc_html__( 'All Plugins', 'rl-freemius-bi' ); ?></option>
				<?php
				global $wpdb;
				$plugins = $wpdb->get_results( "SELECT DISTINCT plugin_id FROM {$wpdb->prefix}rl_fsbi_payments ORDER BY plugin_id" );
				foreach ( $plugins as $plugin ) {
					echo '<option value="' . intval( $plugin->plugin_id ) . '">' . intval( $plugin->plugin_id ) . '</option>';
				}
				?>
			</select>

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

	<div class="rl-fsbi-kpi-section">
		<div class="rl-fsbi-kpi-card">
			<h3><?php echo esc_html__( 'Gross Revenue', 'rl-freemius-bi' ); ?></h3>
			<div class="rl-fsbi-kpi-value" id="rl-fsbi-gross-revenue">—</div>
			<div class="rl-fsbi-trend" id="rl-fsbi-gross-trend"></div>
		</div>

		<div class="rl-fsbi-kpi-card">
			<h3><?php echo esc_html__( 'Net Revenue', 'rl-freemius-bi' ); ?></h3>
			<div class="rl-fsbi-kpi-value" id="rl-fsbi-net-revenue">—</div>
			<div class="rl-fsbi-trend" id="rl-fsbi-net-trend"></div>
		</div>

		<div class="rl-fsbi-kpi-card">
			<h3><?php echo esc_html__( 'MRR', 'rl-freemius-bi' ); ?></h3>
			<div class="rl-fsbi-kpi-value" id="rl-fsbi-mrr">—</div>
			<div class="rl-fsbi-trend" id="rl-fsbi-mrr-trend"></div>
		</div>

		<div class="rl-fsbi-kpi-card">
			<h3><?php echo esc_html__( 'Active Subscriptions', 'rl-freemius-bi' ); ?></h3>
			<div class="rl-fsbi-kpi-value" id="rl-fsbi-active-subs">—</div>
		</div>
	</div>

	<div class="rl-fsbi-charts-section">
		<div class="rl-fsbi-chart-container">
			<h3><?php echo esc_html__( 'Revenue Trend', 'rl-freemius-bi' ); ?></h3>
			<canvas id="rl-fsbi-revenue-chart"></canvas>
		</div>

		<div class="rl-fsbi-chart-container">
			<h3><?php echo esc_html__( 'Currency Distribution', 'rl-freemius-bi' ); ?></h3>
			<canvas id="rl-fsbi-currency-chart"></canvas>
		</div>
	</div>

	<div class="rl-fsbi-table-section">
		<h3><?php echo esc_html__( 'Recent Payments', 'rl-freemius-bi' ); ?></h3>
		<table id="rl-fsbi-payments-table" class="display">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Payment ID', 'rl-freemius-bi' ); ?></th>
					<th><?php echo esc_html__( 'Date', 'rl-freemius-bi' ); ?></th>
					<th><?php echo esc_html__( 'Gross', 'rl-freemius-bi' ); ?></th>
					<th><?php echo esc_html__( 'Net', 'rl-freemius-bi' ); ?></th>
					<th><?php echo esc_html__( 'Currency', 'rl-freemius-bi' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'rl-freemius-bi' ); ?></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>
</div>
