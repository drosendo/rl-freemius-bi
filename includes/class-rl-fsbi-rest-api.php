<?php

/**
 * Read-only REST surface over the synced Freemius customer base.
 *
 * Everything is served from the local sync tables plus one cached call to the
 * Freemius users endpoint (the only place emails and opt-in flags live). No
 * per-customer API call is ever made.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes
 */

class RL_FSBI_REST_API
{

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'rl-fsbi/v1';

	/**
	 * Cache lifetime for the assembled dataset and the segment counters.
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Cache lifetime for the user map (emails + opt-in flags).
	 */
	const USERS_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Minimum seconds between forced syncs, per user.
	 */
	const SYNC_INTERVAL = 60;

	/**
	 * Freemius plan ids this store sells.
	 */
	const PLAN_PRO  = 3874;
	const PLAN_SELF = 4862;

	/**
	 * Settings manager.
	 *
	 * @var RL_FSBI_Settings_Manager
	 */
	private $settings;

	/**
	 * Admin instance, reused for currency conversion and the licence sync.
	 *
	 * @var RL_FSBI_Admin
	 */
	private $admin;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->settings = new RL_FSBI_Settings_Manager();
		$this->admin    = new RL_FSBI_Admin('rl-freemius-bi', defined('RL_FSBI_VERSION') ? RL_FSBI_VERSION : '1.0.0');
	}

	/**
	 * Register the routes.
	 */
	public function register_routes()
	{
		register_rest_route(
			self::REST_NAMESPACE,
			'/customers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_customers'),
				'permission_callback' => array($this, 'can_read'),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/segments',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_segments'),
				'permission_callback' => array($this, 'can_read'),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/sync-status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_sync_status'),
				'permission_callback' => array($this, 'can_read'),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/diagnostics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_diagnostics'),
				'permission_callback' => array($this, 'can_read'),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'post_sync'),
				'permission_callback' => array($this, 'can_read'),
			)
		);
	}

	/**
	 * Only administrators may read customer data.
	 *
	 * WordPress answers 401 for a logged-out caller and 403 for a logged-in
	 * caller without the capability, which is what the contract asks for.
	 *
	 * @return bool
	 */
	public function can_read()
	{
		return current_user_can('manage_options');
	}

	/* ---------------------------------------------------------------------
	 * Endpoint 1: /customers
	 * ------------------------------------------------------------------ */

	/**
	 * Paginated customer list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_customers($request)
	{
		$page = $this->int_param($request, 'page', 1, 1, PHP_INT_MAX);
		if (is_wp_error($page)) {
			return $page;
		}

		$per_page = $this->int_param($request, 'per_page', 100, 1, 500);
		if (is_wp_error($per_page)) {
			return $per_page;
		}

		$status = $this->enum_param($request, 'status', 'all', array('active', 'expired', 'cancelled', 'all'));
		if (is_wp_error($status)) {
			return $status;
		}

		$plan = $this->enum_param($request, 'plan', 'all', array('pro', 'self', 'all'));
		if (is_wp_error($plan)) {
			return $plan;
		}

		$marketing = $this->enum_param($request, 'marketing', 'all', array('optin', 'no', 'all'));
		if (is_wp_error($marketing)) {
			return $marketing;
		}

		$updated_since = null;
		$raw_since     = $request->get_param('updated_since');
		if (null !== $raw_since && '' !== $raw_since) {
			$timestamp = strtotime((string) $raw_since);
			if (false === $timestamp) {
				return new WP_Error(
					'invalid_param',
					esc_html__('updated_since must be an ISO 8601 timestamp.', 'rl-freemius-bi'),
					array('status' => 400)
				);
			}
			$updated_since = $timestamp;
		}

		$refresh   = $this->wants_refresh($request);
		$had_cache = false !== get_transient('rl_fsbi_rest_dataset_v1');
		$dataset   = $this->get_dataset($refresh);
		$cached    = $had_cache && ! $refresh;

		$rows = array();

		foreach ($dataset as $customer) {
			$license = $customer['license'];

			if ('all' !== $status && $license['status'] !== $status) {
				continue;
			}

			if ('all' !== $plan && $license['plan'] !== $plan) {
				continue;
			}

			if ('all' !== $marketing) {
				$wants = 'optin' === $marketing;
				if ((bool) $customer['marketing_optin'] !== $wants) {
					continue;
				}
			}

			if (null !== $updated_since && (int) $customer['_updated_ts'] < $updated_since) {
				continue;
			}

			$rows[] = $customer;
		}

		$total = count($rows);
		$pages = $per_page > 0 ? (int) ceil($total / $per_page) : 0;
		$slice = array_slice($rows, ($page - 1) * $per_page, $per_page);

		$this->log_api_access('customers', count($slice));

		return new WP_REST_Response(
			array(
				'page'         => $page,
				'per_page'     => $per_page,
				'total'        => $total,
				'pages'        => $pages,
				'basis'        => 'customer',
				'generated_at' => gmdate('c', $this->dataset_generated_at()),
				'cached'       => $cached,
				'skipped_no_email' => (int) get_transient('rl_fsbi_rest_skipped_users'),
				'email_unknown'    => (int) get_transient('rl_fsbi_rest_email_unknown'),
				'customers'    => array_map(array($this, 'format_customer'), $slice),
			),
			200
		);
	}

	/**
	 * Strip internal keys before serialising.
	 *
	 * @param array $customer Internal row.
	 * @return array
	 */
	private function format_customer(array $customer)
	{
		foreach (array_keys($customer) as $key) {
			if (0 === strpos((string) $key, '_')) {
				unset($customer[$key]);
			}
		}

		return $customer;
	}

	/* ---------------------------------------------------------------------
	 * Endpoint 2: /segments
	 * ------------------------------------------------------------------ */

	/**
	 * Pre-computed segment counters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_segments($request)
	{
		$refresh = $this->wants_refresh($request);
		$cached  = get_transient('rl_fsbi_rest_segments_v1');

		if (! $refresh && is_array($cached)) {
			$cached['generated_at'] = gmdate('c', (int) ($cached['_generated_ts'] ?? time()));
			unset($cached['_generated_ts']);

			return new WP_REST_Response($cached, 200);
		}

		$payload = $this->build_segments_payload();

		set_transient('rl_fsbi_rest_segments_v1', $payload, self::CACHE_TTL);

		unset($payload['_generated_ts']);

		return new WP_REST_Response($payload, 200);
	}

	/**
	 * Compute the segment payload. Pure: writes nothing, so the caller decides
	 * when the cached copy is replaced.
	 *
	 * @return array
	 */
	private function build_segments_payload()
	{
		global $wpdb;

		$now = time();
		$licenses = $wpdb->get_results(
			"SELECT plan_id, status, expires_at, is_cancelled, activated FROM {$wpdb->prefix}rl_fsbi_licenses",
			ARRAY_A
		);

		$segments = array(
			'active_pro'       => 0,
			'active_self'      => 0,
			'active_other'     => 0,
			'cancelled'        => 0,
			'expired_0_12m'    => 0,
			'expired_12_36m'   => 0,
			'expired_36m_plus' => 0,
			'never_activated'  => 0,
			'refunded'         => 0,
			'mrr_eur'          => 0.0,
			'arr_eur'          => 0.0,
		);

		$reconcile = 0;

		foreach ((array) $licenses as $license) {
			$reconcile++;

			$expires = ! empty($license['expires_at']) ? strtotime((string) $license['expires_at']) : false;
			$status  = $this->derive_status($expires, ! empty($license['is_cancelled']));
			$plan    = $this->normalise_plan($license['plan_id']);

			if ('active' === $status) {
				if ('pro' === $plan) {
					$segments['active_pro']++;
				} elseif ('self' === $plan) {
					$segments['active_self']++;
				} else {
					$segments['active_other']++;
				}

				if (empty($license['activated'])) {
					$segments['never_activated']++;
				}

				continue;
			}

			if ('cancelled' === $status) {
				$segments['cancelled']++;
				continue;
			}

			$months = false !== $expires ? ($now - $expires) / MONTH_IN_SECONDS : 999;

			if ($months < 12) {
				$segments['expired_0_12m']++;
			} elseif ($months < 36) {
				$segments['expired_12_36m']++;
			} else {
				$segments['expired_36m_plus']++;
			}
		}

		$segments['refunded'] = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}rl_fsbi_payments WHERE is_refund = 1"
		);

		$segments['mrr_eur'] = round($this->admin->rest_mrr_eur(), 2);
		$segments['arr_eur'] = round($segments['mrr_eur'] * 12, 2);

		$segmented = $segments['active_pro']
			+ $segments['active_self']
			+ $segments['active_other']
			+ $segments['cancelled']
			+ $segments['expired_0_12m']
			+ $segments['expired_12_36m']
			+ $segments['expired_36m_plus'];

		$payload = array(
			'generated_at' => gmdate('c'),
			'basis'        => 'licence',
			'segments'     => $segments,
			'reconcile'    => array(
				'licences'  => $reconcile,
				'segmented' => $segmented,
				'difference' => $reconcile - $segmented,
			),
			'_generated_ts' => time(),
		);

		return $payload;
	}

	/* ---------------------------------------------------------------------
	 * Endpoint 3: /sync
	 * ------------------------------------------------------------------ */

	/**
	 * Force a refresh of the cached Freemius data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_sync($request)
	{
		$user_id = get_current_user_id();
		$lock    = 'rl_fsbi_rest_sync_lock_' . $user_id;
		$last    = (int) get_transient($lock);

		if ($last > 0) {
			$retry = max(1, self::SYNC_INTERVAL - (time() - $last));

			return new WP_Error(
				'rate_limited',
				sprintf(
					/* translators: %d: seconds until the next allowed sync */
					esc_html__('A sync was requested less than a minute ago. Retry in %d seconds.', 'rl-freemius-bi'),
					$retry
				),
				array('status' => 429, 'retry_after' => $retry)
			);
		}

		set_transient($lock, time(), self::SYNC_INTERVAL);

		$started = microtime(true);
		$records = 0;

		// Refresh licences from Freemius so the dataset is not stale.
		$api = $this->api();
		if ($api) {
			foreach ($this->tracked_plugin_ids() as $plugin_id) {
				$this->admin->sync_licenses($plugin_id, $api, new RL_FSBI_Repository());
			}
		}

		$this->forget_caches();

		$records = count($this->get_dataset(true));

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'synced_at'   => gmdate('c'),
				'records'     => $records,
				'duration_ms' => (int) round((microtime(true) - $started) * 1000),
			),
			200
		);
	}

	/* ---------------------------------------------------------------------
	 * Endpoint 4: /sync-status and /diagnostics
	 * ------------------------------------------------------------------ */

	/**
	 * Progress of the background user crawl.
	 *
	 * @return WP_REST_Response
	 */
	public function get_sync_status()
	{
		RL_FSBI_Activator::ensure_aux_tables();

		$sync   = new RL_FSBI_User_Sync();
		$status = $sync->status();

		return new WP_REST_Response(
			array(
				'state'        => $status['state'],
				'offset'       => $status['offset'],
				'total_known'  => $status['total_known'],
				'with_email'   => $status['with_email'],
				'last_run'     => $status['last_run'],
				'last_error'   => $status['last_error'],
				'cycle_done'   => $status['cycle_done'],
				'plugin_index' => $status['plugin_index'],
				'plugins'      => $status['plugins'],
			),
			200
		);
	}

	/**
	 * Which fields the underlying sources actually provide.
	 *
	 * @return WP_REST_Response
	 */
	public function get_diagnostics()
	{
		global $wpdb;

		$users_table = $wpdb->prefix . 'rl_fsbi_users';
		$log_table   = $wpdb->prefix . 'rl_fsbi_api_log';
		$sync        = new RL_FSBI_User_Sync();

		$next = function ($hook) {
			$ts = wp_next_scheduled($hook);

			return $ts ? gmdate('c', $ts) : null;
		};

		$log_rows  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$log_table}");
		$log_last  = $wpdb->get_row("SELECT route, rows_returned, created_at FROM {$log_table} ORDER BY id DESC LIMIT 1", ARRAY_A);
		$built     = (int) get_transient('rl_fsbi_rest_dataset_built');

		return new WP_REST_Response(
			array(
				'generated_at' => gmdate('c'),
				'user_sync'    => $sync->status(),
				'crons'        => array(
					'rl_fsbi_user_sync'     => $next('rl_fsbi_user_sync'),
					'rl_fsbi_rest_warm'     => $next('rl_fsbi_rest_warm'),
					'rl_fsbi_api_log_cleanup' => $next('rl_fsbi_api_log_cleanup'),
				),
				'cache'        => array(
					'dataset_built_at'  => $built > 0 ? gmdate('c', $built) : null,
					'dataset_cached'    => false !== get_transient('rl_fsbi_rest_dataset_v1'),
					'segments_cached'   => false !== get_transient('rl_fsbi_rest_segments_v1'),
					'last_warm_error'   => get_option('rl_fsbi_rest_warm_error') ?: null,
				),
				'api_log'      => array(
					'rows'           => $log_rows,
					'retention_days' => RL_FSBI_User_Sync::LOG_RETENTION_DAYS,
					'last'           => $log_last ? array(
						'route'  => $log_last['route'],
						'rows'   => (int) $log_last['rows_returned'],
						'at'     => $log_last['created_at'],
					) : null,
				),
				'counts'       => array(
					'licences'         => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rl_fsbi_licenses"),
					'users'            => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$users_table}"),
					'users_with_email' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$users_table} WHERE email IS NOT NULL AND email <> ''"),
				),
				'fields'       => array(
					'user_id'                 => array('available' => true, 'source' => 'licenses.user_id'),
					'email'                   => array('available' => true, 'source' => 'users.json (background crawl)', 'note' => 'null until the crawl has reached that user'),
					'first_name'              => array('available' => false, 'source' => 'users.json', 'note' => 'only populated for crawled users; empty string otherwise'),
					'created_at'              => array('available' => true, 'source' => 'licenses.created'),
					'marketing_optin'         => array('available' => true, 'source' => 'users.json is_marketing_allowed'),
					'optin_at'                => array('available' => false, 'source' => null, 'note' => 'Freemius stores a boolean only, no timestamp'),
					'license.activations'     => array('available' => false, 'source' => null, 'note' => 'current site count is not exposed; only activated (ever)'),
					'license.activated_ever'  => array('available' => true, 'source' => 'licenses.activated'),
					'license.seats'           => array('available' => true, 'source' => 'licenses.quota'),
					'license.next_payment_at' => array('available' => true, 'source' => 'subscriptions.next_renewal'),
					'license.failed_payments' => array('available' => true, 'source' => 'subscriptions.failed_payments'),
					'license.total_paid'      => array('available' => true, 'source' => 'payments table (not converted)'),
					'license.payments_count'  => array('available' => true, 'source' => 'payments table'),
				),
			),
			200
		);
	}

	/**
	 * Rebuild the cached dataset and segments (hourly cron).
	 *
	 * Failures leave the previous transients in place.
	 */
	public function warm_cache()
	{
		try {
			// Build first, publish second: a failed build leaves the previous
			// cached copy in place instead of wiping it.
			$dataset  = $this->build_dataset();
			$segments = $this->build_segments_payload();

			set_transient('rl_fsbi_rest_dataset_v1', $dataset, self::CACHE_TTL);
			set_transient('rl_fsbi_rest_dataset_built', time(), self::CACHE_TTL);
			set_transient('rl_fsbi_rest_segments_v1', $segments, self::CACHE_TTL);

			delete_option('rl_fsbi_rest_warm_error');
		} catch (Throwable $e) {
			update_option('rl_fsbi_rest_warm_error', $e->getMessage());
		}
	}

	/**
	 * Append an access-log row. Never stores personal data beyond the caller id.
	 *
	 * @param string $route Route name.
	 * @param int    $rows  Rows returned.
	 */
	private function log_api_access($route, $rows)
	{
		global $wpdb;

		$ip = isset($_SERVER['REMOTE_ADDR'])
			? substr(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])), 0, 45)
			: null;

		$wpdb->insert(
			$wpdb->prefix . 'rl_fsbi_api_log',
			array(
				'user_id'       => get_current_user_id(),
				'route'         => substr((string) $route, 0, 100),
				'rows_returned' => (int) $rows,
				'ip'            => $ip,
			),
			array('%d', '%s', '%d', '%s')
		);
	}

	/* ---------------------------------------------------------------------
	 * Dataset assembly
	 * ------------------------------------------------------------------ */

	/**
	 * Customer rows, cached for an hour.
	 *
	 * @param bool $refresh Bypass the cache.
	 * @return array
	 */
	private function get_dataset($refresh = false)
	{
		if (! $refresh) {
			$cached = get_transient('rl_fsbi_rest_dataset_v1');
			if (is_array($cached)) {
				return $cached;
			}
		}

		$dataset = $this->build_dataset();
		set_transient('rl_fsbi_rest_dataset_v1', $dataset, self::CACHE_TTL);
		set_transient('rl_fsbi_rest_dataset_built', time(), self::CACHE_TTL);

		return $dataset;
	}

	/**
	 * Build the dataset from the local tables plus the cached user map.
	 *
	 * @return array
	 */
	private function build_dataset()
	{
		global $wpdb;

		RL_FSBI_Activator::ensure_aux_tables();

		delete_transient('rl_fsbi_rest_skipped_users');
		delete_transient('rl_fsbi_rest_email_unknown');

		$licenses = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}rl_fsbi_licenses", ARRAY_A);

		$user_rows = $wpdb->get_results(
			"SELECT user_id, email, first_name, is_marketing_allowed, fs_updated_at FROM {$wpdb->prefix}rl_fsbi_users",
			ARRAY_A
		);

		$users = array();
		foreach ((array) $user_rows as $user_row) {
			$users[(string) $user_row['user_id']] = array(
				'email'      => (string) $user_row['email'],
				'first'      => (string) $user_row['first_name'],
				'optin'      => ! empty($user_row['is_marketing_allowed']),
				'updated_ts' => ! empty($user_row['fs_updated_at']) ? strtotime((string) $user_row['fs_updated_at']) : 0,
			);
		}
		$payment_agg   = $wpdb->get_results(
			"SELECT user_id, currency, COUNT(*) AS cnt, SUM(gross) AS total, MAX(transaction_date) AS last_at
			FROM {$wpdb->prefix}rl_fsbi_payments
			WHERE is_refund = 0
			GROUP BY user_id, currency",
			ARRAY_A
		);
		$refunded = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->prefix}rl_fsbi_payments WHERE is_refund = 1");
		$subs     = $wpdb->get_results(
			"SELECT user_id, license_id, next_renewal, failed_payments, status FROM {$wpdb->prefix}rl_fsbi_subscriptions",
			ARRAY_A
		);

		// user_id => currency => aggregate
		$payments = array();
		foreach ((array) $payment_agg as $row) {
			$uid = (string) $row['user_id'];
			$cur = strtolower((string) $row['currency']);
			$payments[$uid][$cur] = array(
				'count'   => (int) $row['cnt'],
				'total'   => (float) $row['total'],
				'last_at' => (string) $row['last_at'],
			);
		}

		$refunded_map = array();
		foreach ((array) $refunded as $uid) {
			$refunded_map[(string) $uid] = true;
		}

		$subs_by_license = array();
		$subs_by_user    = array();
		foreach ((array) $subs as $row) {
			if (! empty($row['license_id'])) {
				$subs_by_license[(string) $row['license_id']] = $row;
			}
			if (! empty($row['user_id']) && ! isset($subs_by_user[(string) $row['user_id']])) {
				$subs_by_user[(string) $row['user_id']] = $row;
			}
		}

		$by_user = array();
		foreach ((array) $licenses as $license) {
			$by_user[(string) $license['user_id']][] = $license;
		}

		$dataset = array();

		foreach ($by_user as $user_id => $user_licenses) {
			$user  = isset($users[$user_id]) ? $users[$user_id] : null;
			$email = is_array($user) ? trim((string) $user['email']) : '';

			if (is_array($user) && '' === $email) {
				// Known user with no address on file: skip, as specified.
				$this->log_skipped_user($user_id);
				continue;
			}

			if (null === $user) {
				$this->count_email_unknown();
			}

			$license = $this->pick_best_license($user_licenses);
			$sub     = null;

			if (! empty($license['license_id']) && isset($subs_by_license[(string) $license['license_id']])) {
				$sub = $subs_by_license[(string) $license['license_id']];
			} elseif (isset($subs_by_user[$user_id])) {
				$sub = $subs_by_user[$user_id];
			}

			$lic_currency = strtolower((string) ($this->payment_currency($payments, $user_id, $license)));
			$agg          = isset($payments[$user_id][$lic_currency]) ? $payments[$user_id][$lic_currency] : null;

			$created_ts = ! empty($license['fs_created_at']) ? strtotime((string) $license['fs_created_at']) : false;
			$expires_ts = ! empty($license['expires_at']) ? strtotime((string) $license['expires_at']) : false;
			$updated_ts = ! empty($license['fs_updated_at']) ? strtotime((string) $license['fs_updated_at']) : false;

			$next_payment = ! empty($sub['next_renewal']) ? strtotime((string) $sub['next_renewal']) : false;

			$status = $this->derive_status($expires_ts, ! empty($license['is_cancelled']));

			$dataset[] = array(
				'user_id'         => (string) $user_id,
				'email'           => '' === $email ? null : strtolower($email),
				'first_name'      => (string) ($user['first'] ?? ''),
				'created_at'      => $created_ts ? gmdate('Y-m-d', $created_ts) : null,
				'marketing_optin' => ! empty($user['optin']),
				'optin_at'        => null,
				'had_license'     => ! empty($user_licenses),
				'updated_at'      => $updated_ts ? gmdate('c', $updated_ts) : null,
				'license'         => array(
					'id'              => (string) $license['license_id'],
					'status'          => $status,
					'plan'            => $this->normalise_plan($license['plan_id']),
					'plan_raw'        => (string) ($license['plan_id'] ?? ''),
					'seats'           => null === $license['quota_sites'] ? null : (int) $license['quota_sites'],
					'activations'     => null,
					'activated_ever'  => ! empty($license['activated']),
					'created_at'      => $created_ts ? gmdate('Y-m-d', $created_ts) : null,
					'expires_at'      => $expires_ts ? gmdate('Y-m-d', $expires_ts) : null,
					'next_payment_at' => $next_payment ? gmdate('Y-m-d', $next_payment) : null,
					'is_cancelled'    => ! empty($license['is_cancelled']),
					'failed_payments' => (int) ($sub['failed_payments'] ?? 0),
					'currency'        => $lic_currency,
					'total_paid'      => $agg ? round((float) $agg['total'], 2) : 0.0,
					'payments_count'  => $this->payment_count($payments, $user_id),
				),
				'_updated_ts'     => (int) max(
					$updated_ts ?: 0,
					! empty($license['updated_at']) ? strtotime((string) $license['updated_at']) : 0,
					(int) ($user['updated_ts'] ?? 0)
				),
				'_expires_ts'     => (int) $expires_ts,
				'_refunded'       => isset($refunded_map[$user_id]),
			);
		}

		// Rows with a known address first, then alphabetically.
		usort(
			$dataset,
			function ($a, $b) {
				$ae = null !== $a['email'];
				$be = null !== $b['email'];

				if ($ae !== $be) {
					return $ae ? -1 : 1;
				}

				return strcmp((string) $a['email'], (string) $b['email']);
			}
		);

		return $dataset;
	}

	/**
	 * Most relevant licence: active beats cancelled beats expired, newest wins.
	 *
	 * @param array $licenses Licences belonging to one user.
	 * @return array
	 */
	private function pick_best_license(array $licenses)
	{
		$best     = null;
		$best_rank = -1;
		$best_exp  = 0;

		foreach ($licenses as $license) {
			$expires = ! empty($license['expires_at']) ? strtotime((string) $license['expires_at']) : 0;
			$status  = $this->derive_status($expires, ! empty($license['is_cancelled']));

			$rank = 'active' === $status ? 3 : ('cancelled' === $status ? 2 : 1);

			if ($rank > $best_rank || ($rank === $best_rank && $expires > $best_exp)) {
				$best      = $license;
				$best_rank = $rank;
				$best_exp  = $expires;
			}
		}

		return $best;
	}

	/**
	 * Derive the licence status from the data, not from the stored column.
	 *
	 * @param int|false $expires_ts  Expiry timestamp.
	 * @param bool      $is_cancelled Auto-renew off.
	 * @return string
	 */
	private function derive_status($expires_ts, $is_cancelled)
	{
		if (false !== $expires_ts && $expires_ts > 0 && $expires_ts < time()) {
			return 'expired';
		}

		return $is_cancelled ? 'cancelled' : 'active';
	}

	/**
	 * Map a Freemius plan id onto the public plan vocabulary.
	 *
	 * @param mixed $plan_id Plan id.
	 * @return string
	 */
	private function normalise_plan($plan_id)
	{
		$plan_id = (int) $plan_id;

		if (self::PLAN_PRO === $plan_id) {
			return 'pro';
		}

		if (self::PLAN_SELF === $plan_id) {
			return 'self';
		}

		return 'unknown';
	}

	/**
	 * Currency to report for a customer: the licence's payment currency.
	 *
	 * @param array $payments Payment aggregates.
	 * @param string $user_id User id.
	 * @param array $license Chosen licence.
	 * @return string
	 */
	private function payment_currency(array $payments, $user_id, array $license)
	{
		if (empty($payments[$user_id])) {
			return 'usd';
		}

		if (! empty($license['pricing_id'])) {
			// Pricing id does not carry a currency locally; fall through.
		}

		$currencies = $payments[$user_id];
		$latest     = '';
		$latest_at  = '';

		foreach ($currencies as $currency => $agg) {
			if ($agg['last_at'] > $latest_at) {
				$latest_at = $agg['last_at'];
				$latest    = $currency;
			}
		}

		return '' !== $latest ? $latest : 'usd';
	}

	/**
	 * Total number of paid payments for a customer across currencies.
	 *
	 * @param array  $payments Aggregates.
	 * @param string $user_id  User id.
	 * @return int
	 */
	private function payment_count(array $payments, $user_id)
	{
		if (empty($payments[$user_id])) {
			return 0;
		}

		$count = 0;
		foreach ($payments[$user_id] as $agg) {
			$count += (int) $agg['count'];
		}

		return $count;
	}


	/* ---------------------------------------------------------------------
	 * User map (emails + opt-in)
	 * ------------------------------------------------------------------ */


	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * API client when credentials are present.
	 *
	 * @return RL_FSBI_API|null
	 */
	private function api()
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
	 * Plugin ids that have licences locally.
	 *
	 * @return int[]
	 */
	private function tracked_plugin_ids()
	{
		global $wpdb;

		$ids = $wpdb->get_col("SELECT DISTINCT plugin_id FROM {$wpdb->prefix}rl_fsbi_licenses");
		$ids = array_map('intval', (array) $ids);
		$ids = array_values(array_filter($ids));

		if (empty($ids)) {
			$selected = $this->settings->get_option('rl_fsbi_selected_plugins', array());
			$ids      = array_map('intval', (array) $selected);
		}

		return $ids;
	}

	/**
	 * Whether the caller asked to bypass the cache.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function wants_refresh($request)
	{
		return '1' === (string) $request->get_param('refresh') && current_user_can('manage_options');
	}

	/**
	 * Integer query parameter with bounds.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $key     Parameter.
	 * @param int             $default Default.
	 * @param int             $min     Minimum.
	 * @param int             $max     Maximum.
	 * @return int|WP_Error
	 */
	private function int_param($request, $key, $default, $min, $max)
	{
		$raw = $request->get_param($key);

		if (null === $raw || '' === $raw) {
			return $default;
		}

		if (! is_numeric($raw)) {
			return new WP_Error(
				'invalid_param',
				sprintf(/* translators: %s: parameter name */ esc_html__('%s must be an integer.', 'rl-freemius-bi'), $key),
				array('status' => 400)
			);
		}

		$value = (int) $raw;

		if ($value < $min || $value > $max) {
			return new WP_Error(
				'invalid_param',
				sprintf(
					/* translators: 1: parameter, 2: min, 3: max */
					esc_html__('%1$s must be between %2$d and %3$d.', 'rl-freemius-bi'),
					$key,
					$min,
					$max
				),
				array('status' => 400)
			);
		}

		return $value;
	}

	/**
	 * Enumerated query parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $key     Parameter.
	 * @param string          $default Default.
	 * @param array           $allowed Allowed values.
	 * @return string|WP_Error
	 */
	private function enum_param($request, $key, $default, array $allowed)
	{
		$raw = $request->get_param($key);

		if (null === $raw || '' === $raw) {
			return $default;
		}

		$value = sanitize_text_field((string) $raw);

		if (! in_array($value, $allowed, true)) {
			return new WP_Error(
				'invalid_param',
				sprintf(
					/* translators: 1: parameter, 2: allowed values */
					esc_html__('%1$s must be one of: %2$s.', 'rl-freemius-bi'),
					$key,
					implode(', ', $allowed)
				),
				array('status' => 400)
			);
		}

		return $value;
	}

	/**
	 * Timestamp the dataset was built.
	 *
	 * @return int
	 */
	private function dataset_generated_at()
	{
		$built = (int) get_transient('rl_fsbi_rest_dataset_built');

		return $built > 0 ? $built : time();
	}

	/**
	 * Drop every REST cache.
	 */
	private function forget_caches()
	{
		delete_transient('rl_fsbi_rest_dataset_v1');
		delete_transient('rl_fsbi_rest_segments_v1');
		delete_transient('rl_fsbi_rest_users_v1');
	}

	/**
	 * Note a customer we could not include, without logging the email.
	 *
	 * @param string $user_id Freemius user id.
	 */
	private function log_skipped_user($user_id)
	{
		$count = (int) get_transient('rl_fsbi_rest_skipped_users');
		set_transient('rl_fsbi_rest_skipped_users', $count + 1, self::CACHE_TTL);
	}

	/**
	 * Count customers whose address is not in the cached user map yet.
	 *
	 * Freemius exposes >6000 users per plugin behind a hard 50-per-page limit,
	 * so the map is filled progressively rather than exhaustively.
	 */
	private function count_email_unknown()
	{
		$count = (int) get_transient('rl_fsbi_rest_email_unknown');
		set_transient('rl_fsbi_rest_email_unknown', $count + 1, self::CACHE_TTL);
	}
}
