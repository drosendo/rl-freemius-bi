<?php
/**
 * Freemius REST API Client with HMAC-SHA256 Authentication
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes/freemius
 */

class RL_FSBI_API {

	/**
	 * SDK client cache.
	 *
	 * @var Freemius_Api_WordPress|null
	 */
	private $sdk_client = null;

	/**
	 * API base URLs
	 */
	const PRODUCTION_API_URL = 'https://api.freemius.com';
	const SANDBOX_API_URL    = 'https://sandbox-api.freemius.com';

	/**
	 * Authentication credentials
	 */
	private $developer_id;
	private $public_key;
	private $secret_key;
	private $scope = 'developer';
	private $use_sandbox = false;

	/**
	 * Retry configuration
	 */
	private $max_retries = 3;
	private $retry_delays = array( 1, 2, 4 );

	/**
	 * Constructor
	 *
	 * @param int    $developer_id Developer ID from Freemius.
	 * @param string $public_key Public API key.
	 * @param string $secret_key Secret API key.
	 * @param bool   $use_sandbox Use sandbox API.
	 */
	public function __construct( $developer_id, $public_key, $secret_key, $use_sandbox = false ) {
		$this->developer_id = (int) $developer_id;
		$this->public_key   = trim( (string) $public_key );
		$this->secret_key   = trim( (string) $secret_key );
		$this->use_sandbox  = (bool) $use_sandbox;
	}

	/**
	 * Generate HMAC-SHA256 signature per Freemius specs
	 *
	 * @param string $http_verb HTTP method (GET, POST, PUT, DELETE).
	 * @param string $content_md5 MD5 hash of request body (empty for GET).
	 * @param string $content_type Content-Type header value.
	 * @param string $date RFC 2822 formatted date.
	 * @param string $canonicalized_resource API path with query string.
	 * @return string Base64-encoded HMAC-SHA256 signature.
	 */
	private function generate_signature( $http_verb, $content_md5, $content_type, $date, $canonicalized_resource ) {
		$string_to_sign = implode( "\n", array(
			$http_verb,
			$content_md5,
			$content_type,
			$date,
			$canonicalized_resource,
		) );

		$signature = base64_encode(
			hash_hmac( 'sha256', $string_to_sign, $this->secret_key, true )
		);

		return $signature;
	}

	/**
	 * Build Authorization header
	 *
	 * @param string $http_verb HTTP method.
	 * @param string $content_md5 MD5 hash of body.
	 * @param string $content_type Content-Type.
	 * @param string $date RFC 2822 date.
	 * @param string $canonicalized_resource API path.
	 * @return string Authorization header value.
	 */
	private function build_auth_header( $http_verb, $content_md5, $content_type, $date, $canonicalized_resource ) {
		$signature = $this->generate_signature(
			$http_verb,
			$content_md5,
			$content_type,
			$date,
			$canonicalized_resource
		);

		return sprintf(
			'FS %d:%s:%s',
			$this->developer_id,
			$this->public_key,
			$signature
		);
	}

	/**
	 * Make HTTP request to Freemius API
	 *
	 * @param string $endpoint API endpoint (e.g., '/v1/developers/{id}/plugins.json').
	 * @param string $method HTTP method (GET, POST, PUT, DELETE).
	 * @param array  $body Request body (for POST/PUT).
	 * @param array  $query Query parameters.
	 * @return array|false Response array or false on failure.
	 */
	public function request( $endpoint, $method = 'GET', $body = array(), $query = array() ) {
		$method = strtoupper( $method );

		$sdk_client = $this->get_sdk_client();
		if ( $sdk_client ) {
			$sdk_path = $this->build_sdk_path( $endpoint, $query );
			$sdk_result = $sdk_client->Api( $sdk_path, $method, $body );

			if ( is_object( $sdk_result ) && isset( $sdk_result->error ) ) {
				return false;
			}

			return $this->safe_response( json_decode( wp_json_encode( $sdk_result ), true ) );
		}

		$base_url = $this->use_sandbox ? self::SANDBOX_API_URL : self::PRODUCTION_API_URL;

		// Build full URL
		$url = $base_url . $endpoint;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		// Prepare request body
		$body_json = '';
		$content_md5 = '';
		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT' ), true ) ) {
			$body_json = wp_json_encode( $body );
			$content_md5 = base64_encode( md5( $body_json, true ) );
		}

		// Prepare headers
		$date = gmdate( 'D, d M Y H:i:s +0000' );
		$content_type = 'application/json';
		$canonicalized_resource = parse_url( $url, PHP_URL_PATH ) . ( parse_url( $url, PHP_URL_QUERY ) ? '?' . parse_url( $url, PHP_URL_QUERY ) : '' );

		$auth_header = $this->build_auth_header(
			$method,
			$content_md5,
			$content_type,
			$date,
			$canonicalized_resource
		);

		$headers = array(
			'Authorization' => $auth_header,
			'Date'          => $date,
			'Content-Type'  => $content_type,
		);

		// Prepare request args
		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 30,
		);

		if ( $body_json ) {
			$args['body'] = $body_json;
		}

		// Execute request with retry logic
		$response = $this->execute_with_retry( $url, $args );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		// Parse response
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( $response_code >= 200 && $response_code < 300 ) {
			$data = json_decode( $response_body, true );
			return $this->safe_response( $data );
		}

		return false;
	}

	/**
	 * Resolve and create Freemius SDK client when available.
	 *
	 * @return Freemius_Api_WordPress|null
	 */
	private function get_sdk_client() {
		if ( null !== $this->sdk_client ) {
			return $this->sdk_client;
		}

		if ( ! class_exists( 'Freemius_Api_WordPress' ) ) {
			$sdk_file = WP_PLUGIN_DIR . '/fsbi-premium/freemius/includes/sdk/FreemiusWordPress.php';
			if ( file_exists( $sdk_file ) ) {
				require_once $sdk_file;
			}
		}

		if ( ! class_exists( 'Freemius_Api_WordPress' ) ) {
			return null;
		}

		$this->sdk_client = new Freemius_Api_WordPress(
			'developer',
			(int) $this->developer_id,
			(string) $this->public_key,
			(string) $this->secret_key,
			(bool) $this->use_sandbox
		);

		return $this->sdk_client;
	}

	/**
	 * Convert full developer endpoint to SDK relative path.
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $query Query args.
	 * @return string
	 */
	private function build_sdk_path( $endpoint, $query = array() ) {
		$path = (string) $endpoint;
		$prefix = '/v1/developers/' . $this->developer_id;

		if ( 0 === strpos( $path, $prefix ) ) {
			$path = substr( $path, strlen( $prefix ) );
		}

		if ( empty( $path ) || '/' !== $path[0] ) {
			$path = '/' . ltrim( $path, '/' );
		}

		if ( ! empty( $query ) ) {
			$query_string = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
			$path .= ( false === strpos( $path, '?' ) ? '?' : '&' ) . $query_string;
		}

		return $path;
	}

	/**
	 * Execute request with exponential backoff retry
	 *
	 * @param string $url Full request URL.
	 * @param array  $args Request arguments.
	 * @return array|WP_Error Response or error.
	 */
	private function execute_with_retry( $url, $args ) {
		$response = null;

		for ( $attempt = 0; $attempt < $this->max_retries; $attempt++ ) {
			$response = wp_remote_request( $url, $args );

			if ( ! is_wp_error( $response ) ) {
				$code = wp_remote_retrieve_response_code( $response );
				if ( $code >= 200 && $code < 300 ) {
					return $response;
				}

				// Retry on server errors
				if ( $code >= 500 && $attempt < $this->max_retries - 1 ) {
					sleep( $this->retry_delays[ $attempt ] );
					continue;
				}
			} else {
				// Retry on network errors
				if ( $attempt < $this->max_retries - 1 ) {
					sleep( $this->retry_delays[ $attempt ] );
					continue;
				}
			}

			break;
		}

		return $response;
	}

	/**
	 * Safely extract expected properties from API response
	 *
	 * @param mixed $response API response data.
	 * @return array Validated response.
	 */
	private function safe_response( $response ) {
		if ( is_object( $response ) ) {
			$response = json_decode( wp_json_encode( $response ), true );
		}

		if ( ! is_array( $response ) ) {
			return array();
		}

		return $response;
	}

	/**
	 * Normalize collection responses with flexible envelopes.
	 *
	 * @param array  $response Raw decoded response.
	 * @param string $key Expected top-level key (e.g. plugins/payments).
	 * @return array
	 */
	private function extract_collection( $response, $key ) {
		if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
			return $response[ $key ];
		}

		if ( isset( $response['data'][ $key ] ) && is_array( $response['data'][ $key ] ) ) {
			return $response['data'][ $key ];
		}

		if ( isset( $response['data'] ) && is_array( $response['data'] ) && isset( $response['data'][0] ) ) {
			return $response['data'];
		}

		if ( isset( $response[0] ) ) {
			return $response;
		}

		return array();
	}

	/**
	 * Retrieve all plugins for developer
	 *
	 * GET /v1/developers/{developer_id}/plugins.json?all=true
	 *
	 * @return array Array of plugin objects or empty array.
	 */
	public function retrieve_plugins() {
		$endpoint = "/v1/developers/{$this->developer_id}/plugins.json";
		$response = $this->request( $endpoint, 'GET', array(), array( 'all' => 'true' ) );

		if ( ! is_array( $response ) ) {
			return array();
		}

		return $this->extract_collection( $response, 'plugins' );
	}

	/**
	 * Retrieve plugin statistics
	 *
	 * GET /v1/developers/{developer_id}/plugins/{plugin_id}/stats.json
	 *
	 * @param int $plugin_id Plugin ID.
	 * @return array Plugin stats or empty array.
	 */
	public function retrieve_plugin_stats( $plugin_id ) {
		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/stats.json";
		$response = $this->request( $endpoint, 'GET' );

		return is_array( $response ) ? $response : array();
	}

	/**
	 * Retrieve plugin performance metrics
	 *
	 * GET /v1/developers/{developer_id}/plugins/{plugin_id}/performance.json
	 *
	 * @param int $plugin_id Plugin ID.
	 * @return array Performance data or empty array.
	 */
	public function retrieve_plugin_performance( $plugin_id ) {
		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/performance.json";
		$response = $this->request( $endpoint, 'GET' );

		return is_array( $response ) ? $response : array();
	}

	/**
	 * Retrieve payments (transactions)
	 *
	 * GET /v1/developers/{developer_id}/plugins/{plugin_id}/payments.json
	 *
	 * @param int   $plugin_id Plugin ID.
	 * @param array $query Query parameters (count, offset).
	 * @return array Payments array or empty array.
	 */
	public function retrieve_payments( $plugin_id, $query = array() ) {
		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/payments.json";
		$defaults = array(
			'count'  => 50,
			'offset' => 0,
		);
		$query = wp_parse_args( $query, $defaults );

		$response = $this->request( $endpoint, 'GET', array(), $query );

		return $this->extract_collection( $response, 'payments' );
	}

	/**
	 * Retrieve subscriptions
	 *
	 * GET /v1/developers/{developer_id}/plugins/{plugin_id}/subscriptions.json
	 *
	 * @param int   $plugin_id Plugin ID.
	 * @param array $query Query parameters (count, offset).
	 * @return array Subscriptions array or empty array.
	 */
	public function retrieve_subscriptions( $plugin_id, $query = array() ) {
		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/subscriptions.json";
		$defaults = array(
			'count'  => 50,
			'offset' => 0,
		);
		$query = wp_parse_args( $query, $defaults );

		$response = $this->request( $endpoint, 'GET', array(), $query );

		return $this->extract_collection( $response, 'subscriptions' );
	}

	/**
	 * Retrieve licenses
	 *
	 * GET /v1/developers/{developer_id}/plugins/{plugin_id}/licenses.json
	 *
	 * @param int   $plugin_id Plugin ID.
	 * @param array $query Query parameters (count, offset).
	 * @return array Licenses array or empty array.
	 */
	public function retrieve_licenses( $plugin_id, $query = array() ) {
		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/licenses.json";
		$defaults = array(
			'count'  => 50,
			'offset' => 0,
		);
		$query = wp_parse_args( $query, $defaults );

		$response = $this->request( $endpoint, 'GET', array(), $query );

		return $this->extract_collection( $response, 'licenses' );
	}

	/**
	 * Retrieve plans
	 *
	 * GET /v1/developers/{developer_id}/plugins/{plugin_id}/plans.json
	 *
	 * @param int $plugin_id Plugin ID.
	 * @return array Plans array or empty array.
	 */
	public function retrieve_plans( $plugin_id ) {
		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/plans.json";
		$response = $this->request( $endpoint, 'GET' );

		return $this->extract_collection( $response, 'plans' );
	}

	/**
	 * Retrieve revenue data
	 *
	 * GET /v1/developers/{developer_id}/plugins/{plugin_id}/revenues.json
	 *
	 * @param int   $plugin_id Plugin ID.
	 * @param array $query Query parameters.
	 * @return array Revenue data or empty array.
	 */
	public function retrieve_revenues( $plugin_id, $query = array() ) {
		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/revenues.json";
		$response = $this->request( $endpoint, 'GET', array(), $query );

		return is_array( $response ) ? $response : array();
	}

	/**
	 * Retrieve account balance and payout information
	 *
	 * GET /v1/apps/{app_id}/developers/{developer_id}/balance.json
	 *
	 * @param int $app_id Freemius app ID (5172 for FSBI).
	 * @return array Balance data or empty array.
	 */
	public function retrieve_balance( $app_id ) {
		$endpoint = "/v1/apps/{$app_id}/developers/{$this->developer_id}/balance.json";
		$response = $this->request( $endpoint, 'GET' );

		return is_array( $response ) ? $response : array();
	}
}
