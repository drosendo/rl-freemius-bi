<?php
/**
 * Mailchimp API Integration Service
 *
 * @package    RL_FSBI
 * @subpackage RL_FSBI/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles communication with the Mailchimp Marketing API (v3.0).
 */
class RL_FSBI_Mailchimp {

	/**
	 * Mailchimp API Key.
	 *
	 * @var string
	 */
	private $api_key = '';

	/**
	 * Mailchimp Data Center prefix (e.g. us1, us19).
	 *
	 * @var string
	 */
	private $data_center = '';

	/**
	 * In-memory cache of verified merge fields.
	 *
	 * @var array<string, bool>
	 */
	private array $ensured_merge_fields = array();

	/**
	 * Constructor.
	 *
	 * @param string $api_key Optional Mailchimp API key.
	 */
	public function __construct( $api_key = '' ) {
		if ( ! empty( $api_key ) ) {
			$this->set_api_key( $api_key );
		}
	}

	/**
	 * Set and parse the Mailchimp API key.
	 *
	 * @param string $api_key Mailchimp API key.
	 * @return void
	 */
	public function set_api_key( string $api_key ): void {
		$this->api_key = trim( $api_key );
		$parts         = explode( '-', $this->api_key );
		$this->data_center = count( $parts ) > 1 ? sanitize_key( end( $parts ) ) : '';
	}

	/**
	 * Check if the service has a valid API key format.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return ! empty( $this->api_key ) && ! empty( $this->data_center );
	}

	/**
	 * Perform a remote request to the Mailchimp REST API.
	 *
	 * @param string $endpoint API endpoint relative to v3.0 (e.g. '/lists').
	 * @param string $method   HTTP verb (GET, POST, PUT, PATCH, DELETE).
	 * @param array  $body     Request payload.
	 * @return array|WP_Error Parsed JSON response array or WP_Error.
	 */
	public function request( string $endpoint, string $method = 'GET', array $body = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'missing_api_key', esc_html__( 'Mailchimp API key is missing or invalid.', 'rl-freemius-bi' ) );
		}

		$endpoint = '/' . ltrim( $endpoint, '/' );
		$url      = "https://{$this->data_center}.api.mailchimp.com/3.0{$endpoint}";

		$args = array(
			'method'  => strtoupper( $method ),
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'user:' . $this->api_key ),
				'Content-Type'  => 'application/json; charset=utf-8',
				'User-Agent'    => 'RL-Freemius-BI/WordPress',
			),
			'timeout' => 20,
		);

		if ( ! empty( $body ) && in_array( $args['method'], array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$message = is_array( $data ) && ! empty( $data['detail'] ) ? $data['detail'] : wp_remote_retrieve_response_message( $response );
			return new WP_Error( 'mailchimp_error_' . $code, $message, $data );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Fetch all audiences (lists).
	 *
	 * @param bool $force_refresh Skip transient cache.
	 * @return array<string, string> Array mapping list ID to List Name.
	 */
	public function get_lists( bool $force_refresh = false ): array {
		if ( ! $this->is_configured() ) {
			return array();
		}

		$cache_key = 'rl_fsbi_mc_lists_' . md5( $this->api_key );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = $this->request( '/lists?count=100&fields=lists.id,lists.name' );
		if ( is_wp_error( $response ) || empty( $response['lists'] ) || ! is_array( $response['lists'] ) ) {
			return array();
		}

		$lists = array();
		foreach ( $response['lists'] as $item ) {
			if ( ! empty( $item['id'] ) && ! empty( $item['name'] ) ) {
				$lists[ sanitize_text_field( (string) $item['id'] ) ] = sanitize_text_field( (string) $item['name'] );
			}
		}

		set_transient( $cache_key, $lists, HOUR_IN_SECONDS );
		return $lists;
	}

	/**
	 * Fetch audience statistics (e.g. member count, unsubscribes, cleaned) from Mailchimp.
	 *
	 * @param string $list_id Mailchimp Audience/List ID.
	 * @return array<string, int|string> Stats array or empty array on failure.
	 */
	public function get_list_stats( string $list_id ): array {
		$list_id = sanitize_text_field( $list_id );
		if ( empty( $list_id ) || ! $this->is_configured() ) {
			return array();
		}

		$response = $this->request( "/lists/{$list_id}?fields=id,name,stats.member_count,stats.unsubscribe_count,stats.cleaned_count" );
		if ( is_wp_error( $response ) || empty( $response['stats'] ) || ! is_array( $response['stats'] ) ) {
			return array();
		}

		return array(
			'member_count'      => (int) ( $response['stats']['member_count'] ?? 0 ),
			'unsubscribe_count' => (int) ( $response['stats']['unsubscribe_count'] ?? 0 ),
			'cleaned_count'     => (int) ( $response['stats']['cleaned_count'] ?? 0 ),
			'name'              => (string) ( $response['name'] ?? '' ),
		);
	}

	/**
	 * Fetch all member tags / static segments for a specific audience (list).
	 *
	 * @param string $list_id       Mailchimp Audience/List ID.
	 * @param bool   $force_refresh Skip transient cache.
	 * @return array<string, string> Array mapping tag name to tag name.
	 */
	public function get_tags( string $list_id, bool $force_refresh = false ): array {
		$list_id = sanitize_text_field( $list_id );
		if ( empty( $list_id ) || ! $this->is_configured() ) {
			return array();
		}

		$cache_key = 'rl_fsbi_mc_tags_' . md5( $this->api_key . '_' . $list_id );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		$tags = array();

		// 1. Fetch tags via tag-search endpoint
		$search_resp = $this->request( "/lists/{$list_id}/tag-search?count=100" );
		if ( ! is_wp_error( $search_resp ) && ! empty( $search_resp['tags'] ) && is_array( $search_resp['tags'] ) ) {
			foreach ( $search_resp['tags'] as $tag_item ) {
				$name = trim( (string) ( $tag_item['name'] ?? '' ) );
				if ( '' !== $name ) {
					$clean = sanitize_text_field( $name );
					$tags[ $clean ] = $clean;
				}
			}
		}

		// 2. Fetch static segments (which are tags in Mailchimp)
		$segments_resp = $this->request( "/lists/{$list_id}/segments?type=static&count=100&fields=segments.name" );
		if ( ! is_wp_error( $segments_resp ) && ! empty( $segments_resp['segments'] ) && is_array( $segments_resp['segments'] ) ) {
			foreach ( $segments_resp['segments'] as $seg_item ) {
				$name = trim( (string) ( $seg_item['name'] ?? '' ) );
				if ( '' !== $name ) {
					$clean = sanitize_text_field( $name );
					$tags[ $clean ] = $clean;
				}
			}
		}

		ksort( $tags );
		set_transient( $cache_key, $tags, HOUR_IN_SECONDS );
		return $tags;
	}

	/**
	 * Ensure a merge field exists on a list (e.g. WEBSITE).
	 *
	 * @param string $list_id Audience/List ID.
	 * @param string $tag     Merge tag (e.g. 'WEBSITE').
	 * @param string $name    Field label/name (e.g. 'Website').
	 * @param string $type    Field type ('url' or 'text').
	 * @return bool True if confirmed or created, false on error.
	 */
	public function ensure_merge_field( string $list_id, string $tag = 'WEBSITE', string $name = 'Website', string $type = 'url' ): bool {
		$list_id = sanitize_text_field( $list_id );
		$tag     = strtoupper( sanitize_key( $tag ) );
		if ( empty( $list_id ) || empty( $tag ) || ! $this->is_configured() ) {
			return false;
		}

		$memory_key = $list_id . '_' . $tag;
		if ( isset( $this->ensured_merge_fields[ $memory_key ] ) ) {
			return $this->ensured_merge_fields[ $memory_key ];
		}

		$cache_key = 'rl_fsbi_mc_mf_' . md5( $this->api_key . '_' . $list_id . '_' . $tag );
		if ( false !== get_transient( $cache_key ) ) {
			$this->ensured_merge_fields[ $memory_key ] = true;
			return true;
		}

		// Check existing merge fields on audience
		$existing = $this->request( "/lists/{$list_id}/merge-fields?count=100&fields=merge_fields.tag" );
		if ( ! is_wp_error( $existing ) && ! empty( $existing['merge_fields'] ) && is_array( $existing['merge_fields'] ) ) {
			foreach ( $existing['merge_fields'] as $mf ) {
				if ( isset( $mf['tag'] ) && strtoupper( (string) $mf['tag'] ) === $tag ) {
					set_transient( $cache_key, true, DAY_IN_SECONDS );
					$this->ensured_merge_fields[ $memory_key ] = true;
					return true;
				}
			}
		}

		// Attempt to create the merge field
		$payload = array(
			'tag'      => $tag,
			'name'     => sanitize_text_field( $name ),
			'type'     => sanitize_text_field( $type ),
			'required' => false,
		);

		$res = $this->request( "/lists/{$list_id}/merge-fields", 'POST', $payload );
		if ( ! is_wp_error( $res ) && ! empty( $res['tag'] ) ) {
			set_transient( $cache_key, true, DAY_IN_SECONDS );
			$this->ensured_merge_fields[ $memory_key ] = true;
			return true;
		}

		// In case tag already exists or list has field restrictions, cache transient to avoid tight retry loop
		set_transient( $cache_key, true, HOUR_IN_SECONDS );
		$this->ensured_merge_fields[ $memory_key ] = true;
		return true;
	}

	/**
	 * Upsert a subscriber into a Mailchimp list with tags and optional site URL.
	 *
	 * Uses PUT /lists/{list_id}/members/{subscriber_hash} for idempotent insertion/updating.
	 *
	 * @param string $list_id    Audience/List ID.
	 * @param string $email      Subscriber email address.
	 * @param string $first_name Subscriber first name.
	 * @param string $last_name  Subscriber last name.
	 * @param array  $tags       Array of tag strings to apply.
	 * @param string $site_url   Optional site origin URL.
	 * @return array|WP_Error Response data or error.
	 */
	public function upsert_subscriber( string $list_id, string $email, string $first_name = '', string $last_name = '', array $tags = array(), string $site_url = '' ) {
		$list_id = sanitize_text_field( $list_id );
		$email   = sanitize_email( $email );

		if ( empty( $list_id ) || empty( $email ) || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_params', esc_html__( 'Invalid email or list ID.', 'rl-freemius-bi' ) );
		}

		$subscriber_hash = md5( strtolower( $email ) );
		$body = array(
			'email_address' => $email,
			'status_if_new' => 'subscribed',
			'merge_fields'  => array(),
		);

		if ( ! empty( $first_name ) ) {
			$body['merge_fields']['FNAME'] = sanitize_text_field( $first_name );
		}
		if ( ! empty( $last_name ) ) {
			$body['merge_fields']['LNAME'] = sanitize_text_field( $last_name );
		}
		if ( ! empty( $site_url ) ) {
			$clean_url = esc_url_raw( $site_url );
			if ( ! empty( $clean_url ) ) {
				$this->ensure_merge_field( $list_id, 'WEBSITE', 'Website', 'url' );
				$body['merge_fields']['WEBSITE'] = $clean_url;
			}
		}

		// Clean tags
		$clean_tags = array();
		foreach ( $tags as $t ) {
			$t = trim( (string) $t );
			if ( '' !== $t ) {
				$clean_tags[] = sanitize_text_field( $t );
			}
		}
		$clean_tags = array_values( array_unique( $clean_tags ) );

		if ( ! empty( $clean_tags ) ) {
			$body['tags'] = $clean_tags;
		}

		$res = $this->request( "/lists/{$list_id}/members/{$subscriber_hash}", 'PUT', $body );

		// If user was already subscribed, PUT preserves existing tags; apply tags via dedicated tag endpoint
		if ( ! is_wp_error( $res ) && ! empty( $clean_tags ) ) {
			$tag_payload = array(
				'tags' => array_map(
					function( $t ) {
						return array(
							'name'   => $t,
							'status' => 'active',
						);
					},
					$clean_tags
				),
			);
			$this->request( "/lists/{$list_id}/members/{$subscriber_hash}/tags", 'POST', $tag_payload );
		}

		return $res;
	}
}
