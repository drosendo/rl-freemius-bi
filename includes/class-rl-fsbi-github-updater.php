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
	 * One hour, not six: GitHub allows 60 anonymous requests per hour and this
	 * costs one, so a long cache buys nothing but a window where a fresh release
	 * is invisible.
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

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

		if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
			set_transient(self::CACHE_KEY, $empty, self::FAILURE_TTL);

			return $empty;
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);

		if (! is_array($data)) {
			set_transient(self::CACHE_KEY, $empty, self::FAILURE_TTL);

			return $empty;
		}

		$version = ltrim((string) ($data['tag_name'] ?? ''), 'vV');

		if ('' === $version) {
			set_transient(self::CACHE_KEY, $empty, self::FAILURE_TTL);

			return $empty;
		}

		// Prefer a release asset (a clean zip with the folder already named
		// correctly); fall back to the auto-generated tag archive.
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

		$release = array(
			'version'      => sanitize_text_field($version),
			'zip'          => esc_url_raw($zip),
			'body'         => (string) ($data['body'] ?? ''),
			'published_at' => (string) ($data['published_at'] ?? ''),
		);

		set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

		return $release;
	}
}
