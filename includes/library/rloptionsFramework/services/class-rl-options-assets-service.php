<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
/**
 * RL Options Assets Service
 *
 * Handles admin asset enqueueing: CSS, JavaScript, and inline styles.
 * Manages CDN vs local asset strategy based on config.
 *
 * @package RL_Options_Framework
 */

if (!defined('ABSPATH')) {
	return;
}

class RL_Options_Assets_Service {
	/**
	 * @var RL_Options_Framework Framework instance for config/option access.
	 */
	private $framework;

	/**
	 * Constructor.
	 *
	 * @param RL_Options_Framework $framework Framework instance.
	 */
	public function __construct(RL_Options_Framework $framework) {
		$this->framework = $framework;
	}

	/**
	 * Enqueue global assets like the standalone JS logger.
	 * Runs on both admin and frontend.
	 */
	public function enqueue_global_assets(): void
	{
		$config      = $this->framework->get_config();
		$assets_url  = $this->resolve_assets_url();
		$debug_level = $this->resolve_debug_level();
		$handle      = $this->framework->asset_handle_base() . '-rl-logger';

		// Always register the logger: other framework scripts depend on this handle,
		// and an unregistered dependency makes WordPress drop the dependent script.
		wp_register_script(
			$handle,
			$assets_url . 'js/rl-logger.js',
			[],
			$config['version'],
			false
		);

		if ('debug' !== $debug_level) {
			return;
		}

		wp_enqueue_script($handle);

		// We need to pass the debug level to the global window object
		wp_localize_script(
			$handle,
			'rlFrameworkDebugConfig',
			[
				'debug_level' => $debug_level,
			]
		);

		// Add inline script to set the debug level early
		wp_add_inline_script(
			$handle,
			'window.rlFrameworkDebugLevel = rlFrameworkDebugConfig.debug_level;',
			'before'
		);
	}

	/**
	 * Enqueue the options page assets.
	 *
	 * WordPress admin_enqueue_scripts hook callback.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets(string $hook): void
	{
		$this->enqueue_global_assets();

		if (!$this->is_target_admin_page($hook)) {
			return;
		}

		// Vendor assets always ship with the library: WordPress.org guidelines forbid
		// loading them from a CDN, so the `use_local_assets_toggle` setting is kept
		// for host compatibility but never switches delivery.
		wp_enqueue_style('dashicons');

		wp_enqueue_style('wp-color-picker');

		$config = $this->framework->get_config();
		$assets_url = $this->resolve_assets_url();

		// Shared vendor handles are prefixed per instance; two plugins bundling this
		// library must not fight over a single global handle name.
		$handle_prefix = $this->framework->asset_handle_prefix();
		$sweetalert_handle = $handle_prefix . '-sweetalert2';
		$tippy_handle = $handle_prefix . '-tippy';
		$popper_handle = $handle_prefix . '-popper';

		wp_enqueue_style(
			$this->framework->asset_handle_base() . '-framework',
			$assets_url . 'css/options-framework.css',
			['dashicons'],
			$config['version']
		);

		$sweetalert_css_url = $assets_url . 'vendor/sweetalert2/sweetalert2.min.css';
		$tippy_css_url = $assets_url . 'vendor/tippy/tippy.css';

		// SweetAlert2 for better notifications
		wp_enqueue_style(
			$sweetalert_handle,
			$sweetalert_css_url,
			[],
			'11.0.0'
		);
		// Tippy.js CSS for tooltips
		wp_enqueue_style(
			$tippy_handle,
			$tippy_css_url,
			[],
			'6.3.7'
		);

		$sweetalert_js_url = $assets_url . 'vendor/sweetalert2/sweetalert2.all.min.js';
		$popper_js_url = $assets_url . 'vendor/popper/popper.min.js';
		$tippy_js_url = $assets_url . 'vendor/tippy/tippy.umd.min.js';

		wp_enqueue_script('wp-color-picker');
		wp_enqueue_script('jquery-ui-datepicker');
		wp_localize_jquery_ui_datepicker(); // i18n: locale-aware month/day names
		wp_enqueue_script(
			$sweetalert_handle,
			$sweetalert_js_url,
			[],
			'11.0.0',
			true
		);

		// Popper.js (required for Tippy.js)
		wp_enqueue_script(
			$popper_handle,
			$popper_js_url,
			[],
			'2.11.8',
			true
		);

		// Tippy.js for tooltips
		wp_enqueue_script(
			$tippy_handle,
			$tippy_js_url,
			[$popper_handle],
			'6.3.7',
			true
		);

		// Enqueue WordPress media uploader
		wp_enqueue_media();

		// Only depend on the logger when it is actually enqueued (debug mode).
		$framework_deps = ['jquery', 'wp-color-picker', 'jquery-ui-datepicker', $sweetalert_handle, $tippy_handle];
		$handle_base    = $this->framework->asset_handle_base();
		$logger_handle  = $handle_base . '-rl-logger';
		if (wp_script_is($logger_handle, 'enqueued')) {
			$framework_deps[] = $logger_handle;
		}

		wp_enqueue_script(
			$handle_base . '-framework',
			$assets_url . 'js/options-framework.js',
			$framework_deps,
			$config['version'],
			true
		);

		// CodeMirror settings, only when a `code` field is present on the page.
		$has_code_field = false;
		foreach ( $this->framework->get_fields_index() as $candidate ) {
			$candidate_type = (string) ( $candidate['type'] ?? '' );
			if ( 'code' === $candidate_type || 'ace_editor' === $candidate_type ) {
				$has_code_field = true;
				break;
			}
		}

		if ( $has_code_field && function_exists( 'wp_enqueue_code_editor' ) ) {
			$code_settings = wp_enqueue_code_editor( [ 'type' => 'text/html' ] );
			if ( is_array( $code_settings ) ) {
				wp_localize_script( $handle_base . '-framework', 'rlCodeEditorSettings', $code_settings );
			}
		}

		wp_localize_script(
			$handle_base . '-framework',
			'rlFramework',
			[
				'page' => $config['page_slug'],
				'optionField' => $config['form_field_prefix'],
				'ajax_url' => admin_url('admin-ajax.php'),
				'ajax_action' => $this->framework->ajax_action(),
				'provider_action' => $this->framework->provider_action(),
				'validate_action' => $this->framework->validate_action(),
				'nonce' => wp_create_nonce($this->framework->nonce_action()),
				'sync_history' => !empty($config['sync_history']),
				'swal_fallback' => !empty($config['swal_fallback']),
				'debug_level' => $this->resolve_debug_level(),
				'rest_base' => esc_url_raw(rest_url($this->framework->rest_namespace() . '/')),
				'color_palette' => apply_filters('rl_options_color_palette', (array) ($config['color_palette'] ?? []), $config),
			]
		);
	}

	/**
	 * Check whether current admin hook/page matches framework options page.
	 *
	 * @param string $hook Current admin hook suffix.
	 * @return bool
	 */
	private function is_target_admin_page(string $hook): bool
	{
		$config = $this->framework->get_config();
		$page_slug = (string) ($config['page_slug'] ?? '');

		if ('' === $page_slug) {
			return false;
		}

		if ($hook === 'toplevel_page_' . $page_slug || substr($hook, -strlen('_page_' . $page_slug)) === '_page_' . $page_slug) {
			return true;
		}

		$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash((string) $_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $page === $page_slug;
	}

	/**
	 * Resolve framework assets URL.
	 *
	 * Applies strategy: explicit config → plugin context → theme context → plugin fallback.
	 *
	 * @return string Trailing-slash-terminated assets URL.
	 */
	public function resolve_assets_url(): string
	{
		$config = $this->framework->get_config();

		if (!empty($config['assets_url'])) {
			return trailingslashit((string) $config['assets_url']);
		}

		$context = strtolower((string) ($config['context'] ?? 'auto'));
		if (!in_array($context, ['auto', 'plugin', 'theme'], true)) {
			$context = 'auto';
		}

		// Try plugin context (host object passed to init()).
		$plugin = $this->framework->plugin_instance();
		if (null === $plugin && isset($config['_plugin_instance'])) {
			$plugin = $config['_plugin_instance'];
		}

		if (('auto' === $context || 'plugin' === $context) && is_object($plugin) && method_exists($plugin, 'get_plugin_url')) {
			return trailingslashit($plugin->get_plugin_url() . 'includes/library/rloptionsFramework/assets');
		}

		$relative = 'includes/library/rloptionsFramework/assets/';
		if ('theme' === $context || 'auto' === $context) {
			$theme_dir = trailingslashit(get_template_directory());
			$theme_uri = trailingslashit(get_template_directory_uri());
			if (file_exists($theme_dir . $relative)) {
				return $theme_uri . $relative;
			}

			if (is_child_theme()) {
				$child_dir = trailingslashit(get_stylesheet_directory());
				$child_uri = trailingslashit(get_stylesheet_directory_uri());
				if (file_exists($child_dir . $relative)) {
					return $child_uri . $relative;
				}
			}
		}

		// Fallback: resolve from the framework root so the public URL contains no "../" segments.
		return trailingslashit(plugin_dir_url(dirname(__FILE__, 2) . '/main.php') . 'assets');
	}

	/**
	 * Resolve the effective JS debug level.
	 *
	 * If the saved options contain a truthy debug toggle, returns 'debug'.
	 * Otherwise falls back to config['debug_level'] (default 'error').
	 *
	 * @return string Debug level: 'error', 'warn', 'info', or 'debug'.
	 */
	public function resolve_debug_level(): string
	{
		$config = $this->framework->get_config();
		$options = $this->framework->get_options();
		$debug_field_id = $config['debug_field_id'] ?? '';

		if ($debug_field_id && is_array($options) && !empty($options[$debug_field_id])) {
			return 'debug';
		}

		return (string) ($config['debug_level'] ?? 'error');
	}

	/**
	 * Check whether vendor assets should be served locally.
	 *
	 * Falls back to local if toggle is enabled but field not present/false.
	 *
	 * @return bool True to use local assets; false to use CDN.
	 */
	public function should_use_local_assets(): bool
	{
		$config = $this->framework->get_config();

		if (empty($config['use_local_assets_toggle'])) {
			return false;
		}

		$field_id = $config['local_assets_field_id'] ?? 'rloptions_local_assets';
		$options = $this->framework->get_options();

		if (!is_array($options)) {
			return true;
		}

		if (!array_key_exists($field_id, $options)) {
			return true;
		}

		return (bool) $options[$field_id];
	}
}
