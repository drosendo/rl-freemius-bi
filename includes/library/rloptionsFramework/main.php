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
 * Both methods are safe to use simultaneously, and safe to use from any number
 * of plugins/themes that each bundle a copy.
 *
 * Copies do not load their classes here. Each copy registers itself with
 * RL_Options_Framework_Loader and the newest copy of the family is loaded once
 * the plugin set is complete (`plugins_loaded`, priority -1000), so the newest
 * build wins regardless of load order. A host that touches a framework class
 * earlier (e.g. `new RL_Options_Framework()` at file scope) resolves the winner
 * at that moment through the loader's autoloader instead of fataling.
 *
 * Copies that did not win are reported via the `rl_options_framework_duplicate_copy`
 * action (and error_log when WP_DEBUG is on) instead of failing silently.
 *
 * Defines RL_OPTIONS_FRAMEWORK_VERSION, RL_OPTIONS_FRAMEWORK_FILE and
 * RL_OPTIONS_FRAMEWORK_DIR for the copy that wins.
 *
 * @package RL_Options_Framework
 * @version 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

// Version of *this* copy; keep in sync with RL_Options_Framework::VERSION.
$rlof_version = '2.6.0';

// Copies with the same class names compete; a future major registers its own family.
$rlof_family = 'rl-options-framework';

// Loader contract this copy knows how to talk to.
$rlof_protocol = 1;

if ( ! class_exists( 'RL_Options_Framework_Loader', false ) ) {
	require_once __DIR__ . '/class-rl-options-framework-loader.php';
}

$rlof_loader_ok = class_exists( 'RL_Options_Framework_Loader', false ) && method_exists( 'RL_Options_Framework_Loader', 'register' );

if ( $rlof_loader_ok && defined( 'RL_Options_Framework_Loader::PROTOCOL_VERSION' ) ) {
	$rlof_loader_ok = (int) constant( 'RL_Options_Framework_Loader::PROTOCOL_VERSION' ) >= $rlof_protocol;
}

if ( ! $rlof_loader_ok ) {
	// Another copy shipped an incompatible loader contract. Never fatal over it.
	$rlof_message = 'RL Options Framework: a copy with an incompatible loader was already included; this copy in ' . __DIR__ . ' was not loaded.';

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( $rlof_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	if ( function_exists( 'do_action' ) ) {
		do_action( 'rl_options_framework_duplicate_copy', $rlof_message, '', '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	unset( $rlof_version, $rlof_family, $rlof_protocol, $rlof_loader_ok, $rlof_message );
	return;
}

if ( class_exists( 'RL_Options_Framework', false ) ) {
	// Classes are already declared: either the winner of this request resolved
	// first, or a copy older than 2.6.0 (which loaded immediately) got here before
	// us. Either way this copy cannot run, so say so instead of failing silently.
	$rlof_loaded_file    = (string) ( new ReflectionClass( 'RL_Options_Framework' ) )->getFileName();
	$rlof_loaded_version = defined( 'RL_Options_Framework::VERSION' ) ? RL_Options_Framework::VERSION : 'unknown';
	$rlof_self_file      = (string) realpath( __DIR__ . '/class-rl-options-framework.php' );

	if ( (string) realpath( $rlof_loaded_file ) !== $rlof_self_file || version_compare( $rlof_loaded_version, $rlof_version, '!=' ) ) {
		$rlof_message = sprintf(
			'RL Options Framework: classes are already loaded from %1$s (version %2$s); this copy in %3$s (version %4$s) was not loaded.',
			$rlof_loaded_file,
			$rlof_loaded_version,
			__DIR__ . '/class-rl-options-framework.php',
			$rlof_version
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( $rlof_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		do_action( 'rl_options_framework_duplicate_copy', $rlof_message, $rlof_loaded_file, $rlof_loaded_version ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	unset( $rlof_version, $rlof_family, $rlof_protocol, $rlof_loader_ok, $rlof_loaded_file, $rlof_loaded_version, $rlof_message );
	return;
}

RL_Options_Framework_Loader::register(
	[
		'family'  => $rlof_family,
		'version' => $rlof_version,
		'file'    => __FILE__,
		'dir'     => __DIR__,
		'classes' => [ 'RL_Options_Framework', 'RL_Options_', 'RL_Field_', 'RL_Logger' ],
		'anchor'  => 'RL_Options_Framework',
		'load'    => static function () use ( $rlof_version ) {
			if ( class_exists( 'RL_Options_Framework', false ) ) {
				// Another autoloader (e.g. a Composer classmap) already declared the
				// classes; adopt their identity instead of requiring files twice.
				if ( ! defined( 'RL_OPTIONS_FRAMEWORK_VERSION' ) ) {
					$rlof_loaded = new ReflectionClass( 'RL_Options_Framework' );
					define( 'RL_OPTIONS_FRAMEWORK_VERSION', (string) RL_Options_Framework::VERSION );
					define( 'RL_OPTIONS_FRAMEWORK_FILE', (string) $rlof_loaded->getFileName() );
					define( 'RL_OPTIONS_FRAMEWORK_DIR', dirname( (string) $rlof_loaded->getFileName() ) );
				}

				return;
			}

			if ( ! defined( 'RL_OPTIONS_FRAMEWORK_VERSION' ) ) {
				define( 'RL_OPTIONS_FRAMEWORK_VERSION', $rlof_version );
				define( 'RL_OPTIONS_FRAMEWORK_FILE', __FILE__ );
				define( 'RL_OPTIONS_FRAMEWORK_DIR', __DIR__ );
			}

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
		},
	]
);

RL_Options_Framework_Loader::schedule();

unset( $rlof_version, $rlof_family, $rlof_protocol, $rlof_loader_ok );
