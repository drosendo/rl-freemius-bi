<?php
/**
 * RL Freemius Business Intelligence
 *
 * @link              https://rosendolabs.com
 * @since             1.0.0
 * @package           RL_Freemius_BI
 *
 * @wordpress-plugin
 * Plugin Name:       RL Freemius Business Intelligence
 * Plugin URI:        https://rosendolabs.com
 * Update URI:        https://github.com/drosendo/rl-freemius-bi
 * Description:       Comprehensive Business Intelligence dashboard for Freemius plugin sales, renewals, MRR, and payouts.
 * Version:           1.6.3
 * Author:            Rosendo Labs
 * Author URI:        https://rosendolabs.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       rl-freemius-bi
 * Domain Path:       /languages
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 */
define( 'RL_FSBI_VERSION', '1.6.3' );
define( 'RL_FSBI_DB_VERSION', '1.2.3' );
define( 'RL_FSBI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RL_FSBI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RL_FSBI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 */
function activate_rl_fsbi() {
	require_once RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi-activator.php';
	RL_FSBI_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_rl_fsbi() {
	require_once RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi-deactivator.php';
	RL_FSBI_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_rl_fsbi' );
register_deactivation_hook( __FILE__, 'deactivate_rl_fsbi' );

/**
 * Register this plugin's copy of the options framework as early as possible.
 *
 * 2.6.0 decides which bundled copy wins on `plugins_loaded` (priority -1000), so
 * a copy that is only required later never enters the race and the site ends up
 * running another plugin's build. Requiring the entry point here registers this
 * copy; no framework class is used until the settings manager instantiates it.
 */
function rl_fsbi_register_options_framework() {
	require_once RL_FSBI_PLUGIN_DIR . 'includes/library/rloptionsFramework/main.php';
}
rl_fsbi_register_options_framework();

/**
 * Apply pending database schema upgrades on regular plugin updates.
 *
 * Activation hooks do not run when the plugin files are replaced, so without
 * this the new tables and columns would never reach existing installations.
 */
function rl_fsbi_maybe_upgrade_db() {
	require_once RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi-activator.php';
	RL_FSBI_Activator::maybe_upgrade();
	RL_FSBI_Activator::maybe_upgrade_plugin();
}
add_action( 'plugins_loaded', 'rl_fsbi_maybe_upgrade_db' );

/**
 * Load translations on init (WP 6.7+ requirement).
 */
function load_rl_fsbi_textdomain() {
	load_plugin_textdomain(
		'rl-freemius-bi',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'load_rl_fsbi_textdomain', 1 );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require RL_FSBI_PLUGIN_DIR . 'includes/class-rl-fsbi.php';

/**
 * Begins execution of the plugin.
 */
function run_rl_fsbi() {
	$plugin = new RL_FSBI();
	$plugin->run();
}

add_action( 'init', 'run_rl_fsbi', 20 );
