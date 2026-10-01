<?php
/**
 * Kit.com (formerly ConvertKit) API Integration Service (Kit API V4)
 *
 * @package    RL_FSBI
 * @subpackage RL_FSBI/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles communication with the Kit REST API (v4).
 * Uses header-based authentication via X-Kit-Api-Key.
 */
class RL_FSBI_Kit {

	/**
	 * Kit API Base URL (v4).
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.kit.com/v4';

	/**
	 * Kit API Key (V4).
	 *
	 * @var string
	 */
	private string $api_key = '';

	/**
	 * Kit API Secret (kept for backwards compatibility, not required in v4).
	 *
	 * @var string
	 */
	private string $api_secret = '';

	/**
	 * In-memory cache of verified custom fields.
	 *
	 * @var array<string, bool>
	 */
	private array $ensured_fields = array();

	/**
	 * Constructor.
	 *
	 * @param string $api_key    Kit API Key (V4).
	 * @param string $api_secret Optional legacy secret.
	 */
	public function __construct( string $api_key = '', string $api_secret = '' ) {
		$this->api_key    = trim( $api_key );
		$this->api_secret = trim( $api_secret );
	}

	/**
	 * Set credentials.
	 *
	 * @param string $api_key    Kit API Key (V4).
	 * @param string $api_secret Optional legacy secret.
	 * @return void
	 */
	public function set_credentials( string $api_key, string $api_secret = '' ): void {
		$this->api_key    = trim( $api_key );
		$this->api_secret = trim( $api_secret );
	}

	/**
	 * Check if client has minimum required credentials.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return ! empty( $this->api_key );
	}

	/**
	 * In Kit v4, the API key provides full access for personal automation.
	 *
	 * @return bool
	 */
	public function has_secret(): bool {
		return ! empty( $this->api_key );
	}

	/**
	 * Send an HTTP request to the Kit REST API (v4).
	 *
	 * @param string $endpoint Endpoint relative to /v4.
	 * @param string $method   HTTP method ('GET', 'POST', 'PUT', 'DELETE').
	 * @param array  $body     Request body payload.
	 * @param array  $query    URL query parameters.
	 * @return array|WP_Error Response body decoded as array or WP_Error.
	 */
	public function request( string $endpoint, string $method = 'GET', array $body = array(), array $query = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', esc_html__( 'Kit API key is not configured.', 'rl-freemius-bi' ) );
		}

		$url = self::API_BASE . '/' . ltrim( $endpoint, '/' );

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 25,
			'headers' => array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json; charset=utf-8',
				'X-Kit-Api-Key' => $this->api_key,
				'User-Agent'    => 'RL-FSBI/' . ( defined( 'RL_FSBI_VERSION' ) ? RL_FSBI_VERSION : '1.0.0' ),
			),
		);

		if ( in_array( $args['method'], array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = ! empty( $body ) ? wp_json_encode( $body ) : '{}';
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $raw_body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$msg = 'HTTP ' . $status_code;
			if ( is_array( $data ) ) {
				if ( ! empty( $data['message'] ) ) {
					$msg = (string) $data['message'];
				} elseif ( ! empty( $data['error'] ) ) {
					$msg = is_string( $data['error'] ) ? $data['error'] : wp_json_encode( $data['error'] );
				} elseif ( ! empty( $data['errors'] ) ) {
					$msg = is_array( $data['errors'] ) ? implode( '; ', array_map( function( $e ) {
						return is_string( $e ) ? $e : ( $e['message'] ?? wp_json_encode( $e ) );
					}, $data['errors'] ) ) : (string) $data['errors'];
				}
			}
			return new WP_Error( 'kit_api_error', $msg, array( 'status' => $status_code, 'data' => $data ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Fetch account details to verify credentials in Kit (v4).
	 *
	 * @return array|WP_Error Account info array or WP_Error.
	 */
	public function get_account() {
		return $this->request( '/account', 'GET' );
	}

	/**
	 * Fetch all tags from Kit (v4).
	 *
	 * @param bool $force_refresh Skip transient cache.
	 * @return array<int, string> Array mapping tag ID to Tag Name.
	 */
	public function get_tags( bool $force_refresh = false ): array {
		if ( ! $this->is_configured() ) {
			return array();
		}

		$cache_key = 'rl_fsbi_kit_tags_' . md5( $this->api_key );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = $this->request( '/tags', 'GET' );
		if ( is_wp_error( $response ) || empty( $response['tags'] ) || ! is_array( $response['tags'] ) ) {
			return array();
		}

		$tags = array();
		foreach ( $response['tags'] as $item ) {
			$id   = (int) ( $item['id'] ?? 0 );
			$name = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
			if ( $id > 0 && '' !== $name ) {
				$tags[ $id ] = $name;
			}
		}

		asort( $tags );
		set_transient( $cache_key, $tags, HOUR_IN_SECONDS );
		return $tags;
	}

	/**
	 * Fetch total active subscriber count from Kit (v4).
	 *
	 * @return int Total active subscribers, or 0 on error.
	 */
	public function get_total_subscribers(): int {
		if ( ! $this->is_configured() ) {
			return 0;
		}

		$response = $this->request( '/subscribers', 'GET', array(), array(
			'include_total_count' => 'true',
			'per_page'            => 500,
		) );

		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return 0;
		}

		if ( isset( $response['pagination']['total_count'] ) ) {
			return (int) $response['pagination']['total_count'];
		}

		if ( isset( $response['pagination']['total'] ) ) {
			return (int) $response['pagination']['total'];
		}

		if ( isset( $response['total_subscribers'] ) ) {
			return (int) $response['total_subscribers'];
		}

		if ( isset( $response['total_count'] ) ) {
			return (int) $response['total_count'];
		}

		if ( isset( $response['total'] ) ) {
			return (int) $response['total'];
		}

		if ( isset( $response['subscribers'] ) && is_array( $response['subscribers'] ) ) {
			$count = count( $response['subscribers'] );
			$current_res = $response;
			// If there are more pages and total was omitted, page through cursors
			while ( ! empty( $current_res['pagination']['has_next_page'] ) && ! empty( $current_res['pagination']['end_cursor'] ) ) {
				$next_res = $this->request( '/subscribers', 'GET', array(), array(
					'after'    => $current_res['pagination']['end_cursor'],
					'per_page' => 500,
				) );
				if ( is_wp_error( $next_res ) || empty( $next_res['subscribers'] ) ) {
					break;
				}
				$count += count( $next_res['subscribers'] );
				$current_res = $next_res;
			}
			return $count;
		}

		return 0;
	}

	/**
	 * Fetch subscriber count for a specific tag from Kit (v4).
	 *
	 * @param int|string $tag_id Kit Tag ID.
	 * @return int Subscriber count for tag, or 0 on error.
	 */
	public function get_tag_subscribers_count( $tag_id ): int {
		$tag_id = (int) $tag_id;
		if ( ! $this->is_configured() || $tag_id <= 0 ) {
			return 0;
		}

		$response = $this->request( "/tags/{$tag_id}/subscribers", 'GET', array(), array(
			'include_total_count' => 'true',
			'per_page'            => 500,
		) );

		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return 0;
		}

		if ( isset( $response['pagination']['total_count'] ) ) {
			return (int) $response['pagination']['total_count'];
		}
		if ( isset( $response['pagination']['total'] ) ) {
			return (int) $response['pagination']['total'];
		}
		if ( isset( $response['total_subscribers'] ) ) {
			return (int) $response['total_subscribers'];
		}
		if ( isset( $response['total_count'] ) ) {
			return (int) $response['total_count'];
		}
		if ( isset( $response['subscribers'] ) && is_array( $response['subscribers'] ) ) {
			return count( $response['subscribers'] );
		}

		return 0;
	}

	/**
	 * Ensure custom field exists in Kit (v4) (e.g. 'website').
	 *
	 * @param string $label Label/Name for the field (e.g. 'Website').
	 * @return bool True if exists or created successfully.
	 */
	public function ensure_custom_field( string $label = 'Website' ): bool {
		$label = sanitize_text_field( $label );
		$key   = strtolower( preg_replace( '/[^a-z0-9_]/', '_', $label ) );

		if ( empty( $label ) || ! $this->is_configured() ) {
			return false;
		}

		if ( isset( $this->ensured_fields[ $key ] ) ) {
			return $this->ensured_fields[ $key ];
		}

		$cache_key = 'rl_fsbi_kit_cf_' . md5( $this->api_key . '_' . $key );
		if ( false !== get_transient( $cache_key ) ) {
			$this->ensured_fields[ $key ] = true;
			return true;
		}

		// Check existing custom fields
		$existing = $this->request( '/custom_fields', 'GET' );
		if ( ! is_wp_error( $existing ) && ! empty( $existing['custom_fields'] ) && is_array( $existing['custom_fields'] ) ) {
			foreach ( $existing['custom_fields'] as $cf ) {
				$cf_key   = strtolower( (string) ( $cf['key'] ?? '' ) );
				$cf_label = strtolower( (string) ( $cf['label'] ?? '' ) );
				if ( $cf_key === $key || $cf_label === strtolower( $label ) ) {
					set_transient( $cache_key, true, DAY_IN_SECONDS );
					$this->ensured_fields[ $key ] = true;
					return true;
				}
			}
		}

		// Create custom field in Kit v4
		$payload = array(
			'label' => $label,
		);

		$res = $this->request( '/custom_fields', 'POST', $payload );
		if ( ! is_wp_error( $res ) ) {
			set_transient( $cache_key, true, DAY_IN_SECONDS );
			$this->ensured_fields[ $key ] = true;
			return true;
		}

		// Cache for 1 hour to prevent tight retry loop on failure
		set_transient( $cache_key, true, HOUR_IN_SECONDS );
		$this->ensured_fields[ $key ] = true;
		return true;
	}

	/**
	 * Upsert a subscriber into Kit (v4) and apply tag(s).
	 *
	 * In Kit v4:
	 * 1. POST /subscribers upserts the subscriber (creating or updating fields).
	 * 2. POST /tags/{tag_id}/subscribers/{subscriber_id} tags the subscriber.
	 *
	 * @param int|string $tag_id     Kit Tag ID.
	 * @param string     $email      Subscriber email address.
	 * @param string     $first_name Subscriber first name.
	 * @param array      $extra_tags Additional tag IDs to assign.
	 * @param string     $site_url   Optional site origin URL.
	 * @return array|WP_Error Response or error.
	 */
	public function upsert_subscriber( $tag_id, string $email, string $first_name = '', array $extra_tags = array(), string $site_url = '' ) {
		$tag_id = (int) $tag_id;
		$email  = sanitize_email( $email );

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', esc_html__( 'Invalid email address.', 'rl-freemius-bi' ) );
		}

		// 1. Create or update subscriber via POST /subscribers
		$body = array(
			'email_address' => $email,
			'state'         => 'active',
		);

		if ( ! empty( $first_name ) ) {
			$body['first_name'] = sanitize_text_field( $first_name );
		}

		if ( ! empty( $site_url ) ) {
			$clean_url = esc_url_raw( $site_url );
			if ( ! empty( $clean_url ) ) {
				$this->ensure_custom_field( 'Website' );
				$body['fields'] = array(
					'website' => $clean_url,
				);
			}
		}

		$subscriber_res = $this->request( '/subscribers', 'POST', $body );
		if ( is_wp_error( $subscriber_res ) ) {
			return $subscriber_res;
		}

		$subscriber_id = (int) ( $subscriber_res['subscriber']['id'] ?? 0 );

		// 2. Tag the subscriber
		$tags_to_apply = array();
		if ( $tag_id > 0 ) {
			$tags_to_apply[] = $tag_id;
		}
		if ( ! empty( $extra_tags ) ) {
			foreach ( $extra_tags as $extra_id ) {
				$extra_id = (int) $extra_id;
				if ( $extra_id > 0 && ! in_array( $extra_id, $tags_to_apply, true ) ) {
					$tags_to_apply[] = $extra_id;
				}
			}
		}

		$tag_results = array();
		foreach ( $tags_to_apply as $t_id ) {
			if ( $subscriber_id > 0 ) {
				$tag_res = $this->request( "/tags/{$t_id}/subscribers/{$subscriber_id}", 'POST' );
			} else {
				// Fallback to tagging by email
				$tag_res = $this->request( "/tags/{$t_id}/subscribers", 'POST', array(
					'email_address' => $email,
				) );
			}
			$tag_results[ $t_id ] = ! is_wp_error( $tag_res );
		}

		return array(
			'subscriber'    => $subscriber_res['subscriber'] ?? array(),
			'subscriber_id' => $subscriber_id,
			'tag_results'   => $tag_results,
		);
	}
}
