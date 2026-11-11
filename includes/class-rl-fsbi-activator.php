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

		if ( self::schema_is_current() ) {
			update_option( 'rl_fsbi_db_version', RL_FSBI_DB_VERSION );
		}

		self::schedule_cron();
	}

	/**
	 * Apply pending schema upgrades on regular plugin updates.
	 *
	 * Runs once per database version. Safe to call on every request because the
	 * version comparison short-circuits before any database work.
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( 'rl_fsbi_db_version', '0' );

		if ( version_compare( $installed, RL_FSBI_DB_VERSION, '>=' ) ) {
			return;
		}

		self::create_tables();

		// Never record a version the database did not actually reach, otherwise a
		// silently failed ALTER would never be retried.
		if ( ! self::schema_is_current() ) {
			return;
		}

		self::backfill_payments_from_metadata();
		self::backfill_subscriptions_from_metadata();

		delete_option( 'rl_fsbi_backfill_payments_cursor' );
		delete_option( 'rl_fsbi_backfill_subscriptions_cursor' );

		update_option( 'rl_fsbi_db_version', RL_FSBI_DB_VERSION );
	}

	/**
	 * One-time work for a plugin version bump (not a schema bump).
	 *
	 * The revenue share has to be present for commission to be deducted, and an
	 * account that detection called legacy kept an empty setting because only a
	 * manual Accept ever wrote it. Persisting it here means a deployed update
	 * stops reporting "revenue share not set" without anyone clicking anything.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_plugin() {
		$installed = (string) get_option( 'rl_fsbi_plugin_version', '' );

		if ( RL_FSBI_VERSION === $installed ) {
			return;
		}

		$settings = get_option( 'rl_fsbi_settings' );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$share = isset( $settings['rl_fsbi_fs_revenue_share'] ) ? trim( (string) $settings['rl_fsbi_fs_revenue_share'] ) : '';
		$model = isset( $settings['rl_fsbi_commission_model'] ) ? (string) $settings['rl_fsbi_commission_model'] : '';

		if ( '' === $model && class_exists( 'RL_FSBI_Detection' ) ) {
			$summary = get_option( RL_FSBI_Detection::OPTION_SUMMARY, array() );
			if ( is_array( $summary ) && ! empty( $summary['model'] ) ) {
				$model = (string) $summary['model'];
				$settings['rl_fsbi_commission_model'] = $model;
			}
		}

		if ( '' === $share && 'legacy' === $model && class_exists( 'RL_FSBI_Detection' ) ) {
			$summary = get_option( RL_FSBI_Detection::OPTION_SUMMARY, array() );
			$rate    = is_array( $summary ) ? (float) ( $summary['marginal_rate'] ?? 0 ) : 0.0;
			$settings['rl_fsbi_fs_revenue_share'] = (string) ( $rate > 0 ? $rate : RL_FSBI_Detection::TIER_3_RATE );

			if ( ! isset( $settings['rl_fsbi_fs_fixed_fee'] ) || '' === trim( (string) $settings['rl_fsbi_fs_fixed_fee'] ) ) {
				$settings['rl_fsbi_fs_fixed_fee'] = '0';
			}
		}

		// Payout dates follow Freemius (10th of X+2); an old stored day override is
		// dropped here rather than only being ignored at read time.
		$settings['rl_fsbi_payout_day'] = '10';
		if ( '' === trim( (string) ( $settings['rl_fsbi_payout_lag_months'] ?? '' ) ) ) {
			$settings['rl_fsbi_payout_lag_months'] = '2';
		}

		update_option( 'rl_fsbi_settings', $settings );

		// Only record the version when the rate is actually present, otherwise a
		// deployment that ran before detection produced a summary would mark the
		// upgrade done and never apply it.
		$rate = trim( (string) ( $settings['rl_fsbi_fs_revenue_share'] ?? '' ) );
		if ( '' !== $rate || 'legacy' !== $model ) {
			update_option( 'rl_fsbi_plugin_version', RL_FSBI_VERSION );
		}
	}

	/**
	 * Create the two add-on tables without touching the main schema.
	 *
	 * These are deliberately NOT part of the dbDelta pass: running dbDelta over
	 * every table on a large production database, on every request, is what took
	 * the site down. This runs once, is guarded by an option and a lock, and only
	 * ever issues CREATE TABLE IF NOT EXISTS.
	 *
	 * @return bool
	 */
	public static function ensure_aux_tables() {
		if ( get_option( 'rl_fsbi_aux_tables' ) ) {
			return true;
		}

		if ( get_transient( 'rl_fsbi_aux_tables_lock' ) ) {
			return false;
		}

		set_transient( 'rl_fsbi_aux_tables_lock', 1, MINUTE_IN_SECONDS );

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rl_fsbi_users (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				user_id bigint(20) NOT NULL,
				plugin_id bigint(20) DEFAULT 0,
				email varchar(191),
				first_name varchar(191),
				is_marketing_allowed tinyint(1) DEFAULT 0,
				fs_created_at datetime,
				fs_updated_at datetime,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_fs_user (user_id),
				KEY idx_fs_user_email (email),
				KEY idx_fs_user_plugin (plugin_id)
			) {$charset_collate}"
		);

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rl_fsbi_api_log (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				user_id bigint(20) NOT NULL DEFAULT 0,
				route varchar(100) NOT NULL,
				rows_returned int(11) DEFAULT 0,
				ip varchar(45),
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_api_log_created (created_at),
				KEY idx_api_log_user (user_id)
			) {$charset_collate}"
		);

		$ready = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}rl_fsbi_users'" )
			&& $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}rl_fsbi_api_log'" );

		delete_transient( 'rl_fsbi_aux_tables_lock' );

		if ( $ready ) {
			update_option( 'rl_fsbi_aux_tables', 1, false );
			return true;
		}

		return false;
	}

	/**
	 * Verify that the columns and tables added by the current schema exist.
	 *
	 * dbDelta does not report failure, so the upgrade path checks the result
	 * instead of trusting it.
	 *
	 * @return bool
	 */
	private static function schema_is_current() {
		global $wpdb;

		$sentinel_columns = array(
			$wpdb->prefix . 'rl_fsbi_payments'      => 'is_renewal',
			$wpdb->prefix . 'rl_fsbi_subscriptions' => 'renewal_amount',
			$wpdb->prefix . 'rl_fsbi_licenses'      => 'activated',
			$wpdb->prefix . 'rl_fsbi_trials'        => 'trial_id',
			$wpdb->prefix . 'rl_fsbi_installs'      => 'install_id',
			$wpdb->prefix . 'rl_fsbi_uninstalls'    => 'reason_label',
		);

		foreach ( $sentinel_columns as $table => $column ) {
			$found = $wpdb->get_results(
				$wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column )
			);

			if ( empty( $found ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Create plugin database tables using dbDelta.
	 */
	private static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$tables = array(
			// Payments table
			"CREATE TABLE {$wpdb->prefix}rl_fsbi_payments (
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
				payment_type varchar(20),
				is_renewal tinyint(1) DEFAULT 0,
				gateway varchar(50),
				gateway_fee decimal(10,2),
				vat decimal(10,2),
				coupon_id bigint(20),
				plan_id bigint(20),
				pricing_id bigint(20),
				license_id bigint(20),
				install_id bigint(20),
				bound_payment_id bigint(20),
				refund_reason varchar(100),
				metadata longtext,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_plugin_id (plugin_id),
				KEY idx_user_id (user_id),
				KEY idx_transaction_date (transaction_date),
				KEY idx_currency (currency),
				KEY idx_subscription_id (subscription_id),
				KEY idx_is_renewal (is_renewal),
				KEY idx_payment_plan_id (plan_id),
				KEY idx_payment_install_id (install_id)
			) {$charset_collate};",

			// Subscriptions table
			"CREATE TABLE {$wpdb->prefix}rl_fsbi_subscriptions (
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
				failed_payments int(11) DEFAULT 0,
				canceled_at datetime,
				amount_per_cycle decimal(10,2),
				renewal_amount decimal(10,2),
				initial_amount decimal(10,2),
				total_gross decimal(10,2),
				tax_rate decimal(6,3),
				pricing_id bigint(20),
				license_id bigint(20),
				install_id bigint(20),
				currency varchar(3) NOT NULL DEFAULT 'USD',
				metadata longtext,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_plugin_id (plugin_id),
				KEY idx_user_id (user_id),
				KEY idx_status (status),
				KEY idx_next_renewal (next_renewal),
				KEY idx_canceled_at (canceled_at),
				KEY idx_subscription_plan_id (plan_id)
			) {$charset_collate};",

			// Licenses table
			"CREATE TABLE {$wpdb->prefix}rl_fsbi_licenses (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				plugin_id bigint(20) NOT NULL,
				license_id bigint(20) NOT NULL UNIQUE,
				user_id bigint(20) NOT NULL,
				plan_id bigint(20),
				license_key varchar(255) UNIQUE,
				status varchar(20) DEFAULT 'valid',
				quota_sites int(11),
				active_sites int(11) DEFAULT 0,
				activated tinyint(1) DEFAULT 0,
				is_cancelled tinyint(1) DEFAULT 0,
				pricing_id bigint(20),
				fs_created_at datetime,
				fs_updated_at datetime,
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
			"CREATE TABLE {$wpdb->prefix}rl_fsbi_plans (
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
			"CREATE TABLE {$wpdb->prefix}rl_fsbi_balances (
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
			) {$charset_collate};",

			// Trials table
			"CREATE TABLE {$wpdb->prefix}rl_fsbi_trials (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				plugin_id bigint(20) NOT NULL,
				trial_id bigint(20) NOT NULL,
				user_id bigint(20) DEFAULT 0,
				payment_id bigint(20),
				subscription_id bigint(20),
				license_id bigint(20),
				started_at datetime,
				trial_ends_at datetime,
				with_payment_method tinyint(1) DEFAULT 0,
				canceled_at datetime,
				converted_at datetime,
				environment varchar(20),
				metadata longtext,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_trial (trial_id),
				KEY idx_plugin_id (plugin_id),
				KEY idx_trial_ends_at (trial_ends_at),
				KEY idx_started_at (started_at),
				KEY idx_converted_at (converted_at),
				KEY idx_trial_user_id (user_id)
			) {$charset_collate};",

			// Installs table
			"CREATE TABLE {$wpdb->prefix}rl_fsbi_installs (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				plugin_id bigint(20) NOT NULL,
				install_id bigint(20) NOT NULL,
				user_id bigint(20) DEFAULT 0,
				license_id bigint(20),
				plan_id bigint(20),
				site_id varchar(64),
				title varchar(255),
				url varchar(255),
				version varchar(50),
				is_active tinyint(1) DEFAULT 1,
				is_premium tinyint(1) DEFAULT 0,
				is_uninstalled tinyint(1) DEFAULT 0,
				last_seen_at datetime,
				environment varchar(20),
				metadata longtext,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_install (install_id),
				KEY idx_plugin_id (plugin_id),
				KEY idx_is_uninstalled (is_uninstalled),
				KEY idx_install_user_id (user_id),
				KEY idx_install_plan_id (plan_id)
			) {$charset_collate};",

			// Uninstall feedback table
			"CREATE TABLE {$wpdb->prefix}rl_fsbi_uninstalls (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				plugin_id bigint(20) NOT NULL,
				install_id bigint(20) NOT NULL,
				uninstall_id bigint(20) DEFAULT 0,
				site_id varchar(64),
				reason_id bigint(20) DEFAULT 0,
				reason_label varchar(191),
				reason_info text,
				uninstalled_at datetime,
				metadata longtext,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_plugin_install (plugin_id,install_id),
				KEY idx_plugin_id (plugin_id),
				KEY idx_reason_id (reason_id),
				KEY idx_uninstalled_at (uninstalled_at)
			) {$charset_collate};",

		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $tables as $table_sql ) {
			dbDelta( $table_sql );
		}
	}

	/**
	 * Schedule WP-Cron task for periodic data sync.
	 */
	private static function schedule_cron() {
		if ( ! wp_next_scheduled( 'rl_fsbi_scheduled_sync' ) ) {
			wp_schedule_event( time(), 'hourly', 'rl_fsbi_scheduled_sync' );
		}
	}

	/**
	 * Copy payment fields that were previously only kept in the metadata blob
	 * into their own columns, so they can be queried and aggregated.
	 *
	 * Resumable: the last processed row ID is stored as an option so an
	 * interrupted upgrade continues where it stopped on the next request.
	 */
	private static function backfill_payments_from_metadata() {
		global $wpdb;

		$table  = $wpdb->prefix . 'rl_fsbi_payments';
		$cursor = (int) get_option( 'rl_fsbi_backfill_payments_cursor', 0 );
		$batch  = 250;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, metadata FROM {$table} WHERE id > %d AND metadata IS NOT NULL AND metadata <> '' ORDER BY id ASC LIMIT %d",
					$cursor,
					$batch
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$cursor = (int) $row->id;
				$meta   = json_decode( (string) $row->metadata, true );

				if ( ! is_array( $meta ) ) {
					continue;
				}

				$wpdb->update(
					$table,
					array(
						'payment_type'     => isset( $meta['type'] ) ? sanitize_text_field( (string) $meta['type'] ) : null,
						'is_renewal'       => ! empty( $meta['is_renewal'] ) ? 1 : 0,
						'gateway'          => isset( $meta['gateway'] ) ? sanitize_text_field( (string) $meta['gateway'] ) : null,
						'gateway_fee'      => isset( $meta['gateway_fee'] ) ? (float) $meta['gateway_fee'] : null,
						'vat'              => isset( $meta['vat'] ) ? (float) $meta['vat'] : null,
						'coupon_id'        => isset( $meta['coupon_id'] ) ? (int) $meta['coupon_id'] : null,
						'plan_id'          => isset( $meta['plan_id'] ) ? (int) $meta['plan_id'] : null,
						'pricing_id'       => isset( $meta['pricing_id'] ) ? (int) $meta['pricing_id'] : null,
						'license_id'       => isset( $meta['license_id'] ) ? (int) $meta['license_id'] : null,
						'install_id'       => isset( $meta['install_id'] ) ? (int) $meta['install_id'] : null,
						'bound_payment_id' => isset( $meta['bound_payment_id'] ) ? (int) $meta['bound_payment_id'] : null,
						'refund_reason'    => isset( $meta['refund_reason'] ) ? sanitize_text_field( (string) $meta['refund_reason'] ) : null,
					),
					array( 'id' => (int) $row->id ),
					array( '%s', '%d', '%s', '%f', '%f', '%d', '%d', '%d', '%d', '%d', '%d', '%s' ),
					array( '%d' )
				);
			}

			update_option( 'rl_fsbi_backfill_payments_cursor', $cursor, false );
		} while ( count( $rows ) === $batch );
	}

	/**
	 * Copy subscription fields that were previously only kept in the metadata
	 * blob into their own columns.
	 */
	private static function backfill_subscriptions_from_metadata() {
		global $wpdb;

		$table  = $wpdb->prefix . 'rl_fsbi_subscriptions';
		$cursor = (int) get_option( 'rl_fsbi_backfill_subscriptions_cursor', 0 );
		$batch  = 250;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, metadata FROM {$table} WHERE id > %d AND metadata IS NOT NULL AND metadata <> '' ORDER BY id ASC LIMIT %d",
					$cursor,
					$batch
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$cursor = (int) $row->id;
				$meta   = json_decode( (string) $row->metadata, true );

				if ( ! is_array( $meta ) ) {
					continue;
				}

				$wpdb->update(
					$table,
					array(
						'failed_payments'   => isset( $meta['failed_payments'] ) ? (int) $meta['failed_payments'] : 0,
						'canceled_at'       => ! empty( $meta['canceled_at'] ) ? self::to_mysql_datetime( $meta['canceled_at'] ) : null,
						'amount_per_cycle'  => isset( $meta['amount_per_cycle'] ) ? (float) $meta['amount_per_cycle'] : null,
						'renewal_amount'    => isset( $meta['renewal_amount'] ) ? (float) $meta['renewal_amount'] : null,
						'initial_amount'    => isset( $meta['initial_amount'] ) ? (float) $meta['initial_amount'] : null,
						'total_gross'       => isset( $meta['total_gross'] ) ? (float) $meta['total_gross'] : null,
						'tax_rate'          => isset( $meta['tax_rate'] ) ? (float) $meta['tax_rate'] : null,
						'pricing_id'        => isset( $meta['pricing_id'] ) ? (int) $meta['pricing_id'] : null,
						'license_id'        => isset( $meta['license_id'] ) ? (int) $meta['license_id'] : null,
						'install_id'        => isset( $meta['install_id'] ) ? (int) $meta['install_id'] : null,
					),
					array( 'id' => (int) $row->id ),
					array( '%d', '%s', '%f', '%f', '%f', '%f', '%f', '%d', '%d', '%d' ),
					array( '%d' )
				);
			}

			update_option( 'rl_fsbi_backfill_subscriptions_cursor', $cursor, false );
		} while ( count( $rows ) === $batch );
	}

	/**
	 * Normalize an API date string into a MySQL DATETIME value.
	 *
	 * @param mixed $value Raw API date.
	 * @return string|null
	 */
	private static function to_mysql_datetime( $value ) {
		$timestamp = strtotime( (string) $value );

		return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
