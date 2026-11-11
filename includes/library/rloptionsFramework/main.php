<?php
/**
 * Entry point for the RL Options Framework library.
 *
 * Two supported installation methods:
 *
 * 1. Manual (drop-in):
 *    Copy the library folder into your plugin/theme and call:
 *      require_once __DIR__ . '/path/to/rloptionsFramework/main.php';
 *
 * 2. Composer:
 *    composer require rosendolabs/rl-options-framework
 *    Then include vendor/autoload.php — this file runs automatically
 *    via the "files" autoload directive.
 *
 * Both methods are safe to use simultaneously. The class_exists guard
 * below prevents fatal errors if the framework ends up required more
 * than once (e.g., two plugins bundling the same library, or Composer
 * autoload + a manual require coexisting in the same project).
 *
 * When a second copy is skipped, its path and version are compared with the
 * copy that won. A mismatch is reported via the `rl_options_framework_duplicate_copy`
 * action (and error_log when WP_DEBUG is on) instead of failing silently.
 *
 * Defines RL_OPTIONS_FRAMEWORK_VERSION and RL_OPTIONS_FRAMEWORK_FILE for the
 * loaded copy.
 *
 * @package RL_Options_Framework
 * @version 2.5.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

// Version of *this* copy; keep in sync with RL_Options_Framework::VERSION.
$rlof_version = '2.5.1';

// Guard against duplicate class declarations on multiple requires.
if ( class_exists( 'RL_Options_Framework', false ) ) {
	// A second bundled copy cannot declare its classes again, and silently calling
	// into the foreign copy is how a plugin ends up rendering its settings page with
	// another plugin's framework build. Report the conflict so it is diagnosable.
	$rlof_loaded_file    = (string) ( new ReflectionClass( 'RL_Options_Framework' ) )->getFileName();
	$rlof_loaded_version = defined( 'RL_Options_Framework::VERSION' ) ? RL_Options_Framework::VERSION : 'unknown';

	// Compare against *this* copy's path: the loaded class may belong to another plugin.
	$rlof_conflict = $rlof_loaded_file !== __DIR__ . '/class-rl-options-framework.php'
		|| version_compare( $rlof_loaded_version, $rlof_version, '!=' );

	if ( $rlof_conflict ) {
		$rlof_message = sprintf(
			'RL Options Framework: another copy is already loaded from %1$s (version %2$s). This copy in %3$s (version %4$s) was not loaded.',
			$rlof_loaded_file,
			$rlof_loaded_version,
			__DIR__ . '/class-rl-options-framework.php',
			$rlof_version
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( $rlof_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		do_action( 'rl_options_framework_duplicate_copy', $rlof_message, $rlof_loaded_file, $rlof_loaded_version );
	}

	unset( $rlof_version, $rlof_loaded_file, $rlof_loaded_version, $rlof_conflict, $rlof_message );
	return;
}

if ( ! defined( 'RL_OPTIONS_FRAMEWORK_VERSION' ) ) {
	define( 'RL_OPTIONS_FRAMEWORK_VERSION', $rlof_version );
	define( 'RL_OPTIONS_FRAMEWORK_FILE', __FILE__ );
}

unset( $rlof_version );

require_once __DIR__ . '/class-rl-logger.php';
require_once __DIR__ . '/class-rl-options-framework.php';
require_once __DIR__ . '/services/class-rl-options-render-service.php';
require_once __DIR__ . '/services/class-rl-options-admin-handler.php';
require_once __DIR__ . '/services/class-rl-options-schema-manager.php';
require_once __DIR__ . '/services/class-rl-options-rest-api.php';
require_once __DIR__ . '/services/class-rl-options-storage-service.php';
require_once __DIR__ . '/services/class-rl-options-field-processor.php';
require_once __DIR__ . '/services/class-rl-options-assets-service.php';
require_once __DIR__ . '/services/class-rl-options-builder.php';
