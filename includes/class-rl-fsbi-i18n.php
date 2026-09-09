<?php
/**
 * Define the internationalization functionality.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes
 */

class RL_FSBI_i18n {

	/**
	 * Load the plugin text domain for translation.
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'rl-freemius-bi',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages'
		);
	}
}
