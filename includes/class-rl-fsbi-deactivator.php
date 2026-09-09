<?php
/**
 * Fired during plugin deactivation.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes
 */

class RL_FSBI_Deactivator {

	/**
	 * Runs on plugin deactivation.
	 *
	 * Note: Tables are NOT dropped on deactivation to preserve historical data.
	 */
	public static function deactivate() {
		// Clear scheduled cron event if it exists
		$timestamp = wp_next_scheduled( 'rl_fsbi_scheduled_sync' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'rl_fsbi_scheduled_sync' );
		}
	}
}
