<?php

/**
 * Offer plugin updates published as GitHub releases.
 *
 * Manual by design: this only surfaces the standard "update available" row in
 * Plugins. Nothing is ever installed without an administrator clicking Update.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes
 */

class RL_FSBI_GitHub_Updater
{

	/**
	 * Repository owner.
	 */
	const OWNER = 'drosendo';

	/**
	 * Repository name.
	 */
	const REPO = 'rl-freemius-bi';

	/**
	 * Plugin slug used by WordPress.
	 */
	const SLUG = 'rl-freemius-bi';

	/**
	 * Plugin basename, e.g. rl-freemius-bi/rl-freemius-bi.php.
	 */
	const PLUGIN_BASENAME = 'rl-freemius-bi/rl-freemius-bi.php';

	/**
	 * Transient holding the last successful lookup.
	 */
	const CACHE_KEY = 'rl_fsbi_github_release';

	/**
	 * How long a release lookup is reused.
	 *
	 * Five minutes. The feed costs no API quota, so there is nothing to conserve:
	 * a longer cache only widens the window where a freshly published release is
	 * invisible to a site that is otherwise perfectly configured.
	 */
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * How long a failure is remembered, to stop hammering the API.
	 */
	const FAILURE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Currently installed version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * @param string $version Installed plugin version.
	 */
	public function __construct($version)
	{
		$this->version = (string) $version;
	}

	/**
	 * Add an update row when a newer release exists.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public function check_for_update($transient)
	{
		if (! is_object($transient) || empty($transient->checked)) {
			return $transient;
		}

		$release = $this->get_release();

		if (empty($release['version']) || empty($release['zip'])) {
			return $transient;
		}

		// Compare against what WordPress believes is installed, which it reads
		// from the plugin's Version header. Using RL_FSBI_VERSION instead caused
		// a silent deadlock: editing the header changed the version on screen
		// while the comparison still used the constant, so no update appeared.
		$installed = isset($transient->checked[self::PLUGIN_BASENAME])
			? (string) $transient->checked[self::PLUGIN_BASENAME]
			: (string) $this->version;

		if (! version_compare($release['version'], $installed, '>')) {
			return $transient;
		}

		$transient->response[self::PLUGIN_BASENAME] = (object) array(
			'slug'         => self::SLUG,
			'plugin'       => self::PLUGIN_BASENAME,
			'new_version'  => $release['version'],
			'url'          => 'https://github.com/' . self::OWNER . '/' . self::REPO,
			'package'      => $release['zip'],
			'icons'        => array(),
			'banners'      => array(),
			'tested'       => '',
			'requires'     => '5.8',
			'requires_php' => '8.0',
		);

		return $transient;
	}

	/**
	 * Populate the "View version details" modal.
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action API action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public function plugin_info($result, $action, $args)
	{
		if ('plugin_information' !== $action || empty($args->slug) || self::SLUG !== $args->slug) {
			return $result;
		}

		$release = $this->get_release();

		if (empty($release['version'])) {
			return $result;
		}

		return (object) array(
			'name'          => 'RL Freemius Business Intelligence',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://rosendolabs.com">Rosendo Labs</a>',
			'homepage'      => 'https://github.com/' . self::OWNER . '/' . self::REPO,
			'requires'      => '5.8',
			'requires_php'  => '8.0',
			'last_updated'  => $release['published_at'],
			'download_link' => $release['zip'],
			'sections'      => array(
				'description' => esc_html__('Business intelligence for Freemius: revenue, subscriptions, trials, churn and customer data.', 'rl-freemius-bi'),
				'changelog'   => wp_kses_post(nl2br(esc_html($release['body']))),
			),
		);
	}

	/**
	 * Make sure the unpacked folder is named rl-freemius-bi.
	 *
	 * GitHub's own zips unpack as "owner-repo-hash" or "repo-tag", which
	 * WordPress refuses as an invalid plugin folder.
	 *
	 * @param string $source        Unpacked source directory.
	 * @param string $remote_source Parent working directory.
	 * @param mixed  $upgrader      Upgrader instance.
	 * @return string
	 */
	public function fix_source_dir($source, $remote_source, $upgrader)
	{
		global $wp_filesystem;

		$source = (string) $source;

		if (false === strpos($source, self::SLUG)) {
			return $source;
		}

		if (self::SLUG === basename($source)) {
			return $source;
		}

		if (! is_object($wp_filesystem) || ! file_exists(trailingslashit($source) . 'rl-freemius-bi.php')) {
			return $source;
		}

		$desired = trailingslashit($remote_source) . self::SLUG;

		if ($wp_filesystem->move($source, $desired, true)) {
			return $desired;
		}

		return $source;
	}

	/**
	 * WordPress is rebuilding its own update data (the "Check again" button):
	 * drop the cached lookup so the check is genuinely fresh instead of being
	 * answered from a 6-hour-old copy.
	 */
	public function flush_cache()
	{
		delete_transient(self::CACHE_KEY);
	}

	/**
	 * Drop the cached lookup after an update.
	 *
	 * @param mixed $upgrader_object Upgrader instance.
	 * @param array $options        Update options.
	 */
	public function flush_after_update($upgrader_object, $options)
	{
		if (! is_array($options) || 'update' !== ($options['action'] ?? '')) {
			return;
		}

		delete_transient(self::CACHE_KEY);
	}

	/**
	 * Latest release, cached.
	 *
	 * @return array{version:string,zip:string,body:string,published_at:string}
	 */
	private function get_release()
	{
		// WordPress's "Check again" only forces the core check; wp_update_plugins()
		// is throttled to a minute and never honours force-check, so nothing else
		// would ever invalidate this cache on demand.
		$forced = isset($_GET['force-check']) || isset($_GET['force_check']);

		if (! $forced) {
			$cached = get_transient(self::CACHE_KEY);

			if (is_array($cached)) {
				return $cached;
			}
		}

		$empty = array('version' => '', 'zip' => '', 'body' => '', 'published_at' => '');

		// The public releases page answers with a redirect to the newest tag and
		// costs no API quota. The REST API allows 60 anonymous requests per hour
		// per IP, which a shared host cannot rely on: the whole server's traffic
		// shares one address, so the quota can be spent by unrelated sites.
		$release = $this->release_from_atom();

		if (null === $release) {
			$release = $this->release_from_redirect();
		}

		if (null === $release) {
			$release = $this->release_from_api();
		}

		if (null === $release || '' === $release['version']) {
			$this->record_status('error', 0, 'No release could be resolved from GitHub.');
			set_transient(self::CACHE_KEY, $empty, self::FAILURE_TTL);

			return $empty;
		}

		$this->record_status('ok', 200, '');

		set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

		return $release;
	}

	/**
	 * Resolve the newest release from the public Atom feed.
	 *
	 * The feed costs no API quota and, unlike the `/releases/latest` redirect,
	 * it is not served stale from GitHub's CDN: right after publishing, the
	 * redirect still pointed at the previous tag while the feed already listed
	 * the new one.
	 *
	 * @return array|null Release data, or null when this path fails.
	 */
	private function release_from_atom()
	{
		$response = wp_remote_get(
			'https://github.com/' . self::OWNER . '/' . self::REPO . '/releases.atom',
			array(
				'timeout' => 10,
				'headers' => array('User-Agent' => 'RL-FSBI/' . $this->version),
			)
		);

		if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
			return null;
		}

		$body = (string) wp_remote_retrieve_body($response);

		// The first entry is the newest release.
		if (! preg_match('#<entry>.*?/releases/tag/([^"<>\s]+)#s', $body, $matches)) {
			return null;
		}

		return $this->release_from_tag($matches[1]);
	}

	/**
	 * Resolve the newest release from the `/releases/latest` redirect.
	 *
	 * Secondary: the redirect can lag behind a fresh publish because it is
	 * cached at the CDN edge.
	 *
	 * @return array|null Release data, or null when this path fails.
	 */
	private function release_from_redirect()
	{
		$response = wp_remote_get(
			'https://github.com/' . self::OWNER . '/' . self::REPO . '/releases/latest',
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array('User-Agent' => 'RL-FSBI/' . $this->version),
			)
		);

		if (is_wp_error($response)) {
			return null;
		}

		$code     = (int) wp_remote_retrieve_response_code($response);
		$location = (string) wp_remote_retrieve_header($response, 'location');

		if ($code < 300 || $code >= 400 || '' === $location) {
			return null;
		}

		if (! preg_match('#/tag/(v?[0-9][0-9A-Za-z.\-]*)$#', $location, $matches)) {
			return null;
		}

		return $this->release_from_tag($matches[1]);
	}

	/**
	 * Build release data from a tag name.
	 *
	 * @param string $tag Tag, e.g. v1.3.24.
	 * @return array|null
	 */
	private function release_from_tag($tag)
	{
		$tag = trim((string) $tag);

		if (! preg_match('#^v?[0-9][0-9A-Za-z.\-]*$#', $tag)) {
			return null;
		}

		$version = ltrim($tag, 'vV');
		$base    = 'https://github.com/' . self::OWNER . '/' . self::REPO;
		$asset   = $base . '/releases/download/' . $tag . '/' . self::SLUG . '-' . $version . '.zip';
		$archive = $base . '/archive/refs/tags/' . $tag . '.zip';

		// The asset is confirmed before it is offered, so an update can never
		// point at a URL that 404s at install time.
		$zip = $this->asset_exists($asset) ? $asset : $archive;

		return array(
			'version'      => sanitize_text_field($version),
			'zip'          => esc_url_raw($zip),
			'body'         => '',
			'published_at' => '',
		);
	}

	/**
	 * Confirm a release asset is downloadable.
	 *
	 * @param string $url Asset URL.
	 * @return bool
	 */
	private function asset_exists($url)
	{
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 3,
				'headers'     => array('User-Agent' => 'RL-FSBI/' . $this->version),
			)
		);

		if (is_wp_error($response)) {
			return false;
		}

		return 200 === (int) wp_remote_retrieve_response_code($response);
	}

	/**
	 * Resolve the newest release through the REST API.
	 *
	 * Kept as a fallback only: the anonymous quota is per IP and a shared host
	 * shares it with every other site on the machine.
	 *
	 * @return array|null Release data, or null when the lookup fails.
	 */
	private function release_from_api()
	{
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::OWNER . '/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 8,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'RL-FSBI/' . $this->version,
				),
			)
		);

		if (is_wp_error($response)) {
			$this->record_status('http_error', 0, $response->get_error_message());
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code($response);

		if (200 !== $code) {
			$this->record_status('http_error', $code, 'api.github.com answered HTTP ' . $code);
			return null;
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);

		if (! is_array($data)) {
			return null;
		}

		$version = ltrim((string) ($data['tag_name'] ?? ''), 'vV');

		if ('' === $version) {
			return null;
		}

		$zip = '';

		foreach ((array) ($data['assets'] ?? array()) as $asset) {
			$url = (string) ($asset['browser_download_url'] ?? '');

			if ('' !== $url && '.zip' === substr($url, -4)) {
				$zip = $url;
				break;
			}
		}

		if ('' === $zip) {
			$zip = (string) ($data['zipball_url'] ?? '');
		}

		return array(
			'version'      => sanitize_text_field($version),
			'zip'          => esc_url_raw($zip),
			'body'         => (string) ($data['body'] ?? ''),
			'published_at' => (string) ($data['published_at'] ?? ''),
		);
	}

	/**
	 * Show a notice when the last update lookup failed.
	 *
	 * Without this the only symptom is an update row that never appears, which
	 * is indistinguishable from "you are up to date".
	 *
	 * @return void
	 */
	public function render_status_notice()
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		if (! $screen || ! in_array($screen->id, array('plugins', 'update-core'), true)) {
			return;
		}

		$status = get_option('rl_fsbi_github_release_status');

		if (! is_array($status) || empty($status['state']) || 'ok' === $status['state']) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__('RL Freemius BI: update check failed.', 'rl-freemius-bi'),
			esc_html(sprintf(
				/* translators: 1: error detail, 2: when the check ran */
				__('No release could be read from GitHub (%1$s). Last attempt %2$s ago. The plugin is unchanged; this only affects update detection.', 'rl-freemius-bi'),
				'' !== (string) $status['error'] ? (string) $status['error'] : 'HTTP ' . (int) $status['code'],
				human_time_diff((int) $status['time'], time())
			))
		);
	}

	/**
	 * Remember how the last lookup went, so a silent failure is visible.
	 *
	 * @param string $state Lookup state.
	 * @param int    $code  HTTP status, when there was one.
	 * @param string $error Error detail.
	 * @return void
	 */
	private function record_status($state, $code, $error)
	{
		update_option('rl_fsbi_github_release_status', array(
			'time'  => time(),
			'state' => (string) $state,
			'code'  => (int) $code,
			'error' => (string) $error,
		), false);
	}
}
