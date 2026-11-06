<?php

/**
 * WP-CLI commands for RL Freemius BI.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes
 */

class RL_FSBI_CLI
{

	/**
	 * Crawl Freemius users into the local table.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Keep running until the whole user list has been covered.
	 *
	 * [--no-csv]
	 * : Skip the CSV export and page through the JSON list instead.
	 *
	 * [--pages=<n>]
	 * : Pages to fetch when --all is not used. Default 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rl-fsbi sync-users --all
	 *     wp rl-fsbi sync-users --pages=10
	 *     wp rl-fsbi sync-users --all --no-csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync_users($args, $assoc_args)
	{
		$sync = new RL_FSBI_User_Sync();

		if (! empty($assoc_args['all'])) {
			$result = $sync->backfill(empty($assoc_args['no-csv']));

			if ('csv' === $result['via']) {
				\WP_CLI::success(
					sprintf(
						'Backfill finished from the CSV export: %d row(s) read, %d written in a single pass.',
						$result['rows'],
						$result['users']
					)
				);

				foreach ($result['errors'] as $error) {
					\WP_CLI::warning($error);
				}

				return;
			}

			\WP_CLI::success(
				sprintf(
					'Backfill finished: %d user row(s) over %d run(s), %d page(s).%s',
					$result['users'],
					$result['runs'],
					$result['pages'],
					$result['done'] ? '' : ' (stopped early — run again to continue)'
				)
			);

			return;
		}

		$pages  = isset($assoc_args['pages']) ? max(1, (int) $assoc_args['pages']) : RL_FSBI_User_Sync::PAGES_PER_RUN;
		$result = $sync->run_batch($pages);

		\WP_CLI::success(
			sprintf(
				'Fetched %d page(s), wrote %d user row(s). Next offset: %d (plugin index %d).',
				$result['pages'],
				$result['users'],
				$result['offset'],
				$result['plugin_index']
			)
		);
	}

	/**
	 * Show the state of the user crawl.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rl-fsbi user-status
	 */
	public function user_status($args, $assoc_args)
	{
		$sync   = new RL_FSBI_User_Sync();
		$status = $sync->status();

		\WP_CLI::line(wp_json_encode($status, JSON_PRETTY_PRINT));
	}
}

if (defined('WP_CLI') && WP_CLI) {
	\WP_CLI::add_command('rl-fsbi', 'RL_FSBI_CLI');
	\WP_CLI::add_command('rl-fsbi sync-users', array('RL_FSBI_CLI', 'sync_users'));
	\WP_CLI::add_command('rl-fsbi user-status', array('RL_FSBI_CLI', 'user_status'));
}
