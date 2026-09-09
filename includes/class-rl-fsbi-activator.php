<?php
/**
 * Fired during plugin activation.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes
 */

class RL_FSBI_Activator {

	/**
	 * Runs on plugin activation.
	 * Creates database tables and schedules cron tasks.
	 */
	public static function activate() {
		self::create_tables();
		self::schedule_cron();
	}

	/**
	 * Create plugin database tables using dbDelta.
	 */
	private static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$tables = array(
			// Payments table
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rl_fsbi_payments (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				plugin_id bigint(20) NOT NULL,
				payment_id bigint(20) NOT NULL UNIQUE,
				user_id bigint(20) NOT NULL,
				subscription_id bigint(20),
				transaction_date datetime NOT NULL,
				gross decimal(10,2) NOT NULL,
				net decimal(10,2),
				fee decimal(10,2),
				currency varchar(3) NOT NULL DEFAULT 'USD',
				payment_method varchar(50),
				status varchar(20) DEFAULT 'completed',
				country_code varchar(2),
				is_refund tinyint(1) DEFAULT 0,
				refund_id bigint(20),
				metadata longtext,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_plugin_id (plugin_id),
				KEY idx_user_id (user_id),
				KEY idx_transaction_date (transaction_date),
				KEY idx_currency (currency),
				KEY idx_subscription_id (subscription_id)
			) {$charset_collate};",

			// Subscriptions table
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rl_fsbi_subscriptions (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				plugin_id bigint(20) NOT NULL,
				subscription_id bigint(20) NOT NULL UNIQUE,
				user_id bigint(20) NOT NULL,
				plan_id bigint(20),
				status varchar(20) DEFAULT 'active',
				billing_cycle varchar(50),
				trial_ends datetime,
				next_renewal datetime,
				expires_at datetime,
				outstanding_balance decimal(10,2) DEFAULT 0,
				currency varchar(3) NOT NULL DEFAULT 'USD',
				metadata longtext,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_plugin_id (plugin_id),
				KEY idx_user_id (user_id),
				KEY idx_status (status),
				KEY idx_next_renewal (next_renewal)
			) {$charset_collate};",

			// Licenses table
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rl_fsbi_licenses (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				plugin_id bigint(20) NOT NULL,
				license_id bigint(20) NOT NULL UNIQUE,
				user_id bigint(20) NOT NULL,
				plan_id bigint(20),
				license_key varchar(255) UNIQUE,
				status varchar(20) DEFAULT 'valid',
				quota_sites int(11),
				active_sites int(11) DEFAULT 0,
				expires_at datetime,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_plugin_id (plugin_id),
				KEY idx_user_id (user_id),
				KEY idx_status (status),
				KEY idx_expires_at (expires_at)
			) {$charset_collate};",

			// Plans table
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rl_fsbi_plans (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				plugin_id bigint(20) NOT NULL,
				plan_id bigint(20) NOT NULL UNIQUE,
				plan_name varchar(255) NOT NULL,
				description longtext,
				price_usd decimal(10,2),
				price_eur decimal(10,2),
				price_gbp decimal(10,2),
				billing_cycle varchar(50),
				trial_days int(11),
				features longtext,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_plugin_id (plugin_id)
			) {$charset_collate};",

			// Balances table (developer payouts)
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rl_fsbi_balances (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				app_id bigint(20) NOT NULL,
				developer_id bigint(20) NOT NULL,
				processed_balance decimal(10,2) DEFAULT 0,
				pending_balance decimal(10,2) DEFAULT 0,
				commission_rate decimal(5,2),
				commission_amount decimal(10,2) DEFAULT 0,
				net_payout decimal(10,2) DEFAULT 0,
				currency varchar(3) NOT NULL DEFAULT 'USD',
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_developer_id (developer_id),
				KEY idx_app_id (app_id)
			) {$charset_collate};"
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $tables as $table_sql ) {
			dbDelta( $table_sql );
		}

		update_option( 'rl_fsbi_db_version', RL_FSBI_DB_VERSION );
	}

	/**
	 * Schedule WP-Cron task for periodic data sync.
	 */
	private static function schedule_cron() {
		if ( ! wp_next_scheduled( 'rl_fsbi_scheduled_sync' ) ) {
			wp_schedule_event( time(), 'hourly', 'rl_fsbi_scheduled_sync' );
		}
	}
}
