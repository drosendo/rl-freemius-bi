<?php

/**
 * Resumable background sync of Freemius users (emails + marketing opt-in).
 *
 * Freemius caps /users.json at 50 rows per page and this store has more than
 * 6000 users, so the crawl is spread over many cron runs. Progress is persisted
 * after every page, which makes the job safe to kill at any moment.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes
 */

class RL_FSBI_User_Sync
{

	/**
	 * Cron hook.
	 */
	const CRON_HOOK = 'rl_fsbi_user_sync';

	/**
	 * Schedule name registered through cron_schedules.
	 */
	const SCHEDULE = 'rl_fsbi_five_minutes';

	/**
	 * Cleanup hook for the REST access log.
	 */
	const LOG_CRON = 'rl_fsbi_api_log_cleanup';

	/**
	 * Resumable cursor: plugin index + offset.
	 */
	const OPT_STATE = 'rl_fsbi_user_sync_state';

	/**
	 * Timestamp of the last completed run.
	 */
	const OPT_LAST_RUN = 'rl_fsbi_user_sync_last_run';

	/**
	 * Last error message, cleared on the next successful run.
	 */
	const OPT_ERROR = 'rl_fsbi_user_sync_error';

	/**
	 * Whether the current cycle has reached the end of every plugin.
	 */
	const OPT_CYCLE_DONE = 'rl_fsbi_user_sync_cycle_done';

	/**
	 * Pages per cron run. Kept small on purpose: a long request on a busy host
	 * starves the PHP workers.
	 */
	const PAGES_PER_RUN = 20;

	/**
	 * Seconds per cron run.
	 */
	const TIME_BUDGET = 25;

	/**
	 * Days the REST access log is kept.
	 */
	const LOG_RETENTION_DAYS = 90;

	/**
	 * Settings manager.
	 *
	 * @var RL_FSBI_Settings_Manager
	 */
	private $settings;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->settings = new RL_FSBI_Settings_Manager();
	}

	/* ---------------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------------ */

	/**
	 * Add the five-minute interval used by the crawler.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_schedules($schedules)
	{
		if (! isset($schedules[self::SCHEDULE])) {
			$schedules[self::SCHEDULE] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => esc_html__('Every 5 minutes (RL Freemius BI)', 'rl-freemius-bi'),
			);
		}

		return $schedules;
	}

	/**
	 * Ensure the events exist (runs on admin_init).
	 */
	public function maybe_schedule()
	{
		RL_FSBI_Activator::ensure_aux_tables();
		self::ensure_scheduled();
	}

	/**
	 * Make sure both events are scheduled.
	 */
	public static function ensure_scheduled()
	{
		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + 60, self::SCHEDULE, self::CRON_HOOK);
		}

		if (! wp_next_scheduled(self::LOG_CRON)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::LOG_CRON);
		}
	}

	/**
	 * Remove both events (deactivation).
	 */
	public static function unschedule()
	{
		wp_clear_scheduled_hook(self::CRON_HOOK);
		wp_clear_scheduled_hook(self::LOG_CRON);
	}

	/* ---------------------------------------------------------------------
	 * Cron callbacks
	 * ------------------------------------------------------------------ */

	/**
	 * Cron entry point.
	 */
	public function run_cron()
	{
		$this->run_batch(self::PAGES_PER_RUN, self::TIME_BUDGET);
	}

	/**
	 * Delete access-log rows older than the retention window.
	 */
	public function cleanup_log()
	{
		global $wpdb;

		$cutoff = gmdate('Y-m-d H:i:s', time() - (self::LOG_RETENTION_DAYS * DAY_IN_SECONDS));

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}rl_fsbi_api_log WHERE created_at < %s",
				$cutoff
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * The resumable crawl
	 * ------------------------------------------------------------------ */

	/**
	 * Crawl up to a page limit or a time budget, whichever comes first.
	 *
	 * State is persisted after every page so an interrupted run resumes from the
	 * last completed page rather than from zero.
	 *
	 * @param int $max_pages   Pages to fetch in this run.
	 * @param int $time_budget Seconds to spend in this run.
	 * @return array{pages:int,users:int,offset:int,plugin_index:int,done:bool}
	 */
	public function run_batch($max_pages = self::PAGES_PER_RUN, $time_budget = self::TIME_BUDGET)
	{
		$api = $this->api();

		if (! $api) {
			update_option(self::OPT_ERROR, 'Freemius credentials are not configured.');
			return array('pages' => 0, 'users' => 0, 'offset' => 0, 'plugin_index' => 0, 'done' => false);
		}

		$plugins = $this->plugin_ids();
		if (empty($plugins)) {
			update_option(self::OPT_ERROR, 'No plugin ids available to sync users for.');
			return array('pages' => 0, 'users' => 0, 'offset' => 0, 'plugin_index' => 0, 'done' => false);
		}

		$state  = $this->get_state();
		$start  = time();
		$pages  = 0;
		$saved  = 0;
		$done   = false;

		while ($pages < $max_pages && (time() - $start) < $time_budget) {
			$plugin_id = $plugins[$state['plugin_index']] ?? null;

			if (null === $plugin_id) {
				// End of the plugin list: the cycle is complete.
				$state = array('plugin_index' => 0, 'offset' => 0);
				$this->save_state($state);
				update_option(self::OPT_CYCLE_DONE, time());
				$done = true;
				break;
			}

			$rows = $api->retrieve_users((int) $plugin_id, array('count' => 50, 'offset' => $state['offset']));
			$pages++;

			if (is_wp_error($rows)) {
				update_option(self::OPT_ERROR, $rows->get_error_message());
				break;
			}

			if (empty($rows)) {
				$state = $this->next_plugin($state);
				$this->save_state($state);
				continue;
			}

			$saved += $this->upsert_users((int) $plugin_id, $rows);

			if (count($rows) < 50) {
				$state = $this->next_plugin($state);
				$this->save_state($state);
				continue;
			}

			$state['offset'] += 50;
			$this->save_state($state);
		}

		update_option(self::OPT_LAST_RUN, gmdate('Y-m-d H:i:s'));
		delete_option(self::OPT_ERROR);

		return array(
			'pages'        => $pages,
			'users'        => $saved,
			'offset'       => (int) $state['offset'],
			'plugin_index' => (int) $state['plugin_index'],
			'done'         => $done,
		);
	}

	/**
	 * Run until the whole user list is covered (manual backfill).
	 *
	 * @param int $max_cycles Safety stop.
	 * @return array Aggregate result.
	 */
	public function backfill_all($max_cycles = 200)
	{
		$total = array('pages' => 0, 'users' => 0, 'runs' => 0, 'done' => false);

		for ($i = 0; $i < $max_cycles; $i++) {
			$result = $this->run_batch(self::PAGES_PER_RUN, self::TIME_BUDGET);

			$total['pages'] += $result['pages'];
			$total['users'] += $result['users'];
			$total['runs']++;

			if ($result['done']) {
				$total['done'] = true;
				break;
			}

			// Nothing fetched and not finished means we are stuck; stop.
			if (0 === $result['pages']) {
				break;
			}
		}

		return $total;
	}

	/**
	 * Move the cursor to the next plugin.
	 *
	 * @param array $state Current state.
	 * @return array
	 */
	private function next_plugin(array $state)
	{
		$state['plugin_index']++;
		$state['offset'] = 0;

		return $state;
	}

	/**
	 * Current cursor.
	 *
	 * @return array{plugin_index:int,offset:int}
	 */
	private function get_state()
	{
		$state = get_option(self::OPT_STATE, array());

		return array(
			'plugin_index' => isset($state['plugin_index']) ? max(0, (int) $state['plugin_index']) : 0,
			'offset'       => isset($state['offset']) ? max(0, (int) $state['offset']) : 0,
		);
	}

	/**
	 * Persist the cursor.
	 *
	 * @param array $state State to store.
	 */
	private function save_state(array $state)
	{
		update_option(
			self::OPT_STATE,
			array(
				'plugin_index' => max(0, (int) $state['plugin_index']),
				'offset'       => max(0, (int) $state['offset']),
			),
			false
		);
	}

	/**
	 * Upsert a page of users.
	 *
	 * @param int   $plugin_id Plugin id.
	 * @param array $rows      Freemius user rows.
	 * @return int Rows written.
	 */
	private function upsert_users($plugin_id, array $rows)
	{
		global $wpdb;

		$table = $wpdb->prefix . 'rl_fsbi_users';
		$count = 0;

		foreach ($rows as $row) {
			$user_id = (int) ($row['id'] ?? 0);

			if ($user_id <= 0) {
				continue;
			}

			$email = isset($row['email']) ? sanitize_email((string) $row['email']) : '';

			$wpdb->replace(
				$table,
				array(
					'user_id'              => $user_id,
					'plugin_id'            => (int) $plugin_id,
					'email'                => '' === $email ? null : strtolower($email),
					'first_name'           => sanitize_text_field((string) ($row['first'] ?? '')),
					'is_marketing_allowed' => ! empty($row['is_marketing_allowed']) ? 1 : 0,
					'fs_created_at'        => ! empty($row['created']) ? (string) $row['created'] : null,
					'fs_updated_at'        => ! empty($row['updated']) ? (string) $row['updated'] : null,
				),
				array('%d', '%d', '%s', '%s', '%d', '%s', '%s')
			);

			$count++;
		}

		return $count;
	}

	/**
	 * Public status for the REST endpoint and the settings panel.
	 *
	 * @return array
	 */
	public function status()
	{
		global $wpdb;

		$state      = $this->get_state();
		$cycle_done = (int) get_option(self::OPT_CYCLE_DONE, 0);
		$last_run   = (string) get_option(self::OPT_LAST_RUN, '');
		$last_ts    = '' !== $last_run ? strtotime($last_run) : 0;

		$running = $last_ts > 0 && (time() - $last_ts) < 10 * MINUTE_IN_SECONDS && 0 === $cycle_done;

		return array(
			'state'        => $running ? 'running' : 'idle',
			'offset'       => (int) $state['offset'],
			'total_known'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rl_fsbi_users"),
			'with_email'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rl_fsbi_users WHERE email IS NOT NULL AND email <> ''"),
			'last_run'     => '' !== $last_run ? gmdate('c', $last_ts) : null,
			'last_error'   => (string) get_option(self::OPT_ERROR, '') ?: null,
			'cycle_done'   => $cycle_done > 0 ? gmdate('c', $cycle_done) : null,
			'plugin_index' => (int) $state['plugin_index'],
			'plugins'      => $this->plugin_ids(),
		);
	}

	/**
	 * Plugin ids that hold licences (or the configured selection).
	 *
	 * @return int[]
	 */
	private function plugin_ids()
	{
		global $wpdb;

		$ids = $wpdb->get_col("SELECT DISTINCT plugin_id FROM {$wpdb->prefix}rl_fsbi_licenses");
		$ids = array_values(array_filter(array_map('intval', (array) $ids)));

		if (empty($ids)) {
			$selected = $this->settings->get_option('rl_fsbi_selected_plugins', array());
			$ids      = array_values(array_filter(array_map('intval', (array) $selected)));
		}

		return $ids;
	}

	/**
	 * API client when credentials exist.
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
}
