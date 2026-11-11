<?php
/**
 * Loader / copy registry for the RL Options Framework.
 *
 * Every bundled copy of the library (manual drop-in or Composer) registers itself
 * here at include time and loads its classes only when the *winning* copy has been
 * chosen. Resolution happens once per request, after all plugin files are loaded
 * (`plugins_loaded`, priority -1000), so load order no longer decides which build
 * runs: the newest copy of the same major always wins.
 *
 * Copies are grouped in families. A family is "the same set of class names", e.g.
 * `rl-options-framework` for the 2.x line. Only copies inside one family compete;
 * a future major registers a different family (and its own class names) and
 * therefore coexists with the previous major instead of replacing it.
 *
 * The class is intentionally dependency-free, side-effect free on registration and
 * stable across versions: it is the single piece of code every copy must agree on.
 *
 * @package RL_Options_Framework
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( class_exists( 'RL_Options_Framework_Loader', false ) ) {
	return;
}

/**
 * Copy registry and per-family winner selection.
 */
final class RL_Options_Framework_Loader {

	/**
	 * Contract version of this loader. Copies with a different protocol cannot be
	 * trusted to agree on winning rules, so they are reported instead of merged.
	 */
	public const PROTOCOL_VERSION = 1;

	/**
	 * Hook used to pick the winner once every plugin had a chance to register.
	 */
	public const RESOLVE_HOOK = 'plugins_loaded';

	/**
	 * Priority for the resolution hook; earlier than any documented host callback.
	 */
	public const RESOLVE_PRIORITY = -1000;

	/**
	 * Registered copies by family and copy key.
	 *
	 * @var array<string,array<string,array>>
	 */
	private static array $copies = [];

	/**
	 * Family => winning copy (null when nothing could be loaded).
	 *
	 * @var array<string,array|null>
	 */
	private static array $winners = [];

	/**
	 * Families currently being resolved (recursion guard for the autoloader).
	 *
	 * @var array<string,bool>
	 */
	private static array $resolving = [];

	/**
	 * Whether the autoloader/hook were already wired.
	 *
	 * @var bool
	 */
	private static bool $scheduled = false;

	/**
	 * Register one bundled copy.
	 *
	 * Required keys: `file` (the copy's main.php) and `version`.
	 * Recommended: `load` (callable that requires the copy's class files).
	 *
	 * @param array $copy {
	 *     @type string        $family  Family id; defaults to `rl-options-framework`.
	 *     @type string        $version Copy version, compared with version_compare().
	 *     @type string        $file    Absolute path to this copy's main.php.
	 *     @type string        $dir     Absolute path to this copy's directory.
	 *     @type string[]      $classes Class-name prefixes owned by the family.
	 *     @type string        $anchor  Class that proves the family is declared.
	 *     @type callable|null $load    Loader called for the winning copy.
	 * }
	 * @return string Copy key (empty when the copy has no file).
	 */
	public static function register( array $copy ): string {
		$file = isset( $copy['file'] ) ? (string) $copy['file'] : '';
		if ( '' === $file ) {
			return '';
		}

		$copy = array_merge(
			[
				'family'  => 'rl-options-framework',
				'version' => '0.0.0',
				'dir'     => dirname( $file ),
				'classes' => [ 'RL_' ],
				'load'    => null,
				'anchor'  => '',
			],
			$copy
		);

		$copy['family'] = '' !== (string) $copy['family'] ? (string) $copy['family'] : 'rl-options-framework';
		$copy['file']   = $file;
		$copy['key']    = self::copy_key( $file );

		if ( ! isset( self::$copies[ $copy['family'] ] ) ) {
			self::$copies[ $copy['family'] ] = [];
		}

		self::$copies[ $copy['family'] ][ $copy['key'] ] = $copy;

		// Included after the family was resolved (a late plugin, a theme include):
		// it cannot win anymore, so report it instead of leaving it silent.
		$winner = self::$winners[ $copy['family'] ] ?? null;

		if ( array_key_exists( $copy['family'], self::$winners ) && null === $winner ) {
			// Nothing could be loaded last time; give this copy a chance.
			unset( self::$winners[ $copy['family'] ] );
			self::resolve( $copy['family'] );

			return $copy['key'];
		}

		if ( null !== $winner && $winner['key'] !== $copy['key'] ) {
			$message = sprintf(
				'RL Options Framework: %1$s (version %2$s) was included after version %3$s had already been loaded and was not used.',
				$copy['file'],
				$copy['version'],
				$winner['version']
			);

			self::log( $message );
			self::fire(
				'rl_options_framework_duplicate_copy',
				[
					$message,
					$winner['file'],
					$winner['version'],
				]
			);
		}

		return $copy['key'];
	}

	/**
	 * Wire the autoloader and the resolution hook. Safe to call from every copy.
	 */
	public static function schedule(): void {
		if ( self::$scheduled ) {
			return;
		}

		self::$scheduled = true;

		if ( function_exists( 'spl_autoload_register' ) ) {
			// Prepend: a Composer classmap for a bundled copy must not win by loading
			// its classes before this loader picked the newest copy.
			spl_autoload_register( [ __CLASS__, 'autoload' ], true, true );
		}

		// Outside WordPress (CLI probes, unit tests) there is no hook cycle.
		if ( ! function_exists( 'add_action' ) ) {
			self::resolve_all();
			return;
		}

		// Required after plugins_loaded (themes, late includes): resolve now.
		if ( function_exists( 'did_action' ) && did_action( self::RESOLVE_HOOK ) > 0 ) {
			self::resolve_all();
			return;
		}

		add_action( self::RESOLVE_HOOK, [ __CLASS__, 'resolve_all' ], self::RESOLVE_PRIORITY );
	}

	/**
	 * Autoloader: a framework class was requested before resolution ran.
	 *
	 * @param string $class Requested class name.
	 */
	public static function autoload( string $class ): void {
		foreach ( self::$copies as $family => $copies ) {
			if ( array_key_exists( $family, self::$winners ) ) {
				continue;
			}

			foreach ( $copies as $copy ) {
				foreach ( (array) $copy['classes'] as $prefix ) {
					$prefix = (string) $prefix;
					if ( '' !== $prefix && 0 === stripos( $class, $prefix ) ) {
						self::resolve( $family );
						continue 3;
					}
				}
			}
		}
	}

	/**
	 * Resolve every registered family.
	 */
	public static function resolve_all(): void {
		foreach ( array_keys( self::$copies ) as $family ) {
			self::resolve( (string) $family );
		}
	}

	/**
	 * Pick and load the winner for one family.
	 *
	 * The winner is the highest version among the copies registered for that
	 * family, independent of registration order. A copy that fails to load is
	 * skipped so a broken bundle cannot take the site down.
	 *
	 * @param string $family Family id.
	 * @return array|null Winning copy, or null when nothing could be loaded.
	 */
	public static function resolve( string $family ): ?array {
		if ( array_key_exists( $family, self::$winners ) ) {
			return self::$winners[ $family ];
		}

		if ( empty( self::$copies[ $family ] ) || ! empty( self::$resolving[ $family ] ) ) {
			return null;
		}

		self::$resolving[ $family ] = true;

		$candidates = self::sort_by_version( self::$copies[ $family ] );
		$winner     = null;

		foreach ( $candidates as $candidate ) {
			if ( ! is_callable( $candidate['load'] ) ) {
				continue;
			}

			// Classes declared by another autoloader (or by a copy that failed halfway)
			// cannot be declared again: adopt the candidate instead of requiring twice.
			$anchor = (string) ( $candidate['anchor'] ?? '' );
			if ( '' !== $anchor && class_exists( $anchor, false ) ) {
				$winner = $candidate;
				break;
			}

			try {
				call_user_func( $candidate['load'] );
				$winner = $candidate;
				break;
			} catch ( Throwable $e ) {
				self::log(
					sprintf(
						'RL Options Framework: copy %1$s (version %2$s) failed to load: %3$s',
						$candidate['file'],
						$candidate['version'],
						$e->getMessage()
					)
				);
			}
		}

		self::$winners[ $family ] = $winner;
		unset( self::$resolving[ $family ] );

		$losers = [];
		foreach ( $candidates as $candidate ) {
			if ( null !== $winner && $candidate['key'] === $winner['key'] ) {
				continue;
			}
			$losers[] = $candidate;
		}

		if ( null !== $winner ) {
			self::fire(
				'rl_options_framework_copy_loaded',
				[
					$winner,
					$losers,
					$family,
				]
			);
		}

		foreach ( $losers as $loser ) {
			$message = null === $winner
				? sprintf(
					'RL Options Framework: no copy could be loaded; %1$s (version %2$s) was skipped.',
					$loser['file'],
					$loser['version']
				)
				: sprintf(
					'RL Options Framework: %1$s (version %2$s) was not loaded because the newer copy %3$s (version %4$s) won.',
					$loser['file'],
					$loser['version'],
					$winner['file'],
					$winner['version']
				);

			self::log( $message );
			self::fire(
				'rl_options_framework_duplicate_copy',
				[
					$message,
					null === $winner ? '' : $winner['file'],
					null === $winner ? '' : $winner['version'],
				]
			);
		}

		return $winner;
	}

	/**
	 * Winning copy for a family, or null when the family was not resolved yet.
	 *
	 * @param string $family Family id.
	 */
	public static function winner( string $family ): ?array {
		return self::$winners[ $family ] ?? null;
	}

	/**
	 * Copies registered for a family, newest first.
	 *
	 * @param string $family Family id.
	 * @return array<int,array>
	 */
	public static function copies( string $family ): array {
		if ( empty( self::$copies[ $family ] ) ) {
			return [];
		}

		return array_values( self::sort_by_version( self::$copies[ $family ] ) );
	}

	/**
	 * Families that registered at least one copy.
	 *
	 * @return string[]
	 */
	public static function families(): array {
		return array_keys( self::$copies );
	}

	/**
	 * Stable key for a copy path.
	 */
	private static function copy_key( string $file ): string {
		$real = realpath( $file );

		return false !== $real ? $real : $file;
	}

	/**
	 * Sort copies by version, newest first (stable for equal versions).
	 *
	 * @param array<string,array> $copies Registered copies.
	 * @return array<string,array>
	 */
	private static function sort_by_version( array $copies ): array {
		$sorted = [];
		foreach ( $copies as $copy ) {
			$position = count( $sorted );
			foreach ( $sorted as $index => $existing ) {
				if ( version_compare( (string) $copy['version'], (string) $existing['version'], '>' ) ) {
					$position = $index;
					break;
				}
			}

			array_splice( $sorted, $position, 0, [ $copy ] );
		}

		return $sorted;
	}

	/**
	 * Fire an action when WordPress is available.
	 */
	private static function fire( string $hook, array $args ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Log a loader message. Verbose only when WP_DEBUG is on.
	 */
	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
