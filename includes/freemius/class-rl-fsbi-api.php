<?php

/**
 * Freemius REST API Client with HMAC-SHA256 Authentication
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes/freemius
 */

class RL_FSBI_API
{

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
	private $retry_delays = array(1, 2, 4);

	/**
	 * Constructor
	 *
	 * @param int    $developer_id Developer ID from Freemius.
	 * @param string $public_key Public API key.
	 * @param string $secret_key Secret API key.
	 * @param bool   $use_sandbox Use sandbox API.
	 */
	public function __construct($developer_id, $public_key, $secret_key, $use_sandbox = false)
	{
		$this->developer_id = (int) $developer_id;
		$this->public_key   = trim((string) $public_key);
		$this->secret_key   = trim((string) $secret_key);
		$this->use_sandbox  = (bool) $use_sandbox;
	}

	/**
	 * Custom Debug Logger
	 */
	private function log($message, $data = null)
	{
		$log_entry = '[RL_FSBI_API Debug] ' . $message;
		if (null !== $data) {
			$log_entry .= ' | Data: ' . print_r($data, true);
		}
		error_log($log_entry);
	}

	/**
	 * Base64 URL-safe encoding without padding.
	 * Matches Freemius SDK signature behavior.
	 *
	 * @param string $input Raw input.
	 * @return string
	 */
	private function base64_url_encode($input)
	{
		return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
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
	private function generate_signature($http_verb, $content_md5, $content_type, $date, $canonicalized_resource)
	{
		$this->log('generate_signature() called', array(
			'http_verb' => $http_verb,
			'content_md5' => $content_md5,
			'content_type' => $content_type,
		));

		$string_to_sign = implode("\n", array(
			$http_verb,
			$content_md5,
			$content_type,
			$date,
			$canonicalized_resource,
		));

		// Freemius SDK signs using HEX HMAC and then Base64 URL-safe encoding.
		$signature = $this->base64_url_encode(
			hash_hmac('sha256', $string_to_sign, $this->secret_key)
		);

		$this->log('generate_signature() completed', array('signature_length' => strlen($signature)));

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
	private function build_auth_header($http_verb, $content_md5, $content_type, $date, $canonicalized_resource)
	{
		$this->log('build_auth_header() called', array('verb' => $http_verb));

		$signature = $this->generate_signature(
			$http_verb,
			$content_md5,
			$content_type,
			$date,
			$canonicalized_resource
		);

		$auth_header = sprintf(
			'FS %d:%s:%s',
			$this->developer_id,
			$this->public_key,
			$signature
		);

		$this->log('build_auth_header() completed');

		return $auth_header;
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
	public function request($endpoint, $method = 'GET', $body = array(), $query = array())
	{
		$this->log('request() called', array(
			'endpoint' => $endpoint,
			'method' => $method,
			'body_count' => count($body),
			'query_params' => array_keys($query),
		));

		$method = strtoupper($method);

		$sdk_client = $this->get_sdk_client();
		if ($sdk_client) {
			$this->log('Using Freemius SDK client');

			$sdk_path = $this->build_sdk_path($endpoint, $query);
			$this->log('SDK path built', array('sdk_path' => $sdk_path));

			$sdk_result = $sdk_client->Api($sdk_path, $method, $body);

			if (is_object($sdk_result) && isset($sdk_result->error)) {
				$this->log('SDK returned error', array('error' => $sdk_result->error));
				return false;
			}

			$response = $this->safe_response(json_decode(wp_json_encode($sdk_result), true));
			$this->log('request() completed via SDK', array('response_type' => gettype($response)));
			return $response;
		}

		$base_url = $this->use_sandbox ? self::SANDBOX_API_URL : self::PRODUCTION_API_URL;

		// Build full URL
		$url = $base_url . $endpoint;
		if (! empty($query)) {
			$url = add_query_arg($query, $url);
		}

		// Prepare request body
		$body_json = '';
		$content_md5 = '';
		$content_type = '';
		if (! empty($body) && in_array($method, array('POST', 'PUT'), true)) {
			$body_json = wp_json_encode($body);
			$content_md5 = md5($body_json);
			$content_type = 'application/json';
		}

		// Prepare headers
		$date = gmdate('D, d M Y H:i:s +0000');

		// CORREÇÃO: A assinatura da Freemius não pode incluir a Query String.
		$canonicalized_resource = parse_url($url, PHP_URL_PATH);

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
		);

		if (! empty($content_type)) {
			$headers['Content-Type'] = $content_type;
		}

		if (! empty($content_md5)) {
			$headers['Content-MD5'] = $content_md5;
		}

		// Prepare request args
		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 30,
		);

		if ($body_json) {
			$args['body'] = $body_json;
		}

		// Execute request with retry logic
		$this->log('Executing HTTP request', array(
			'url' => $url,
			'method' => $method,
		));

		$response = $this->execute_with_retry($url, $args);

		if (is_wp_error($response)) {
			$this->log('Request failed with WP_Error', array(
				'error_code' => $response->get_error_code(),
				'error_message' => $response->get_error_message(),
			));
			return false;
		}

		// Parse response
		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);

		$this->log('HTTP response received', array(
			'response_code' => $response_code,
			'body_length' => strlen($response_body),
		));

		if ($response_code >= 200 && $response_code < 300) {
			$this->log('Response successful, decoding JSON');

			$data = json_decode($response_body, true);
			if (null === $data) {
				$this->log('JSON decode error', array('error' => json_last_error_msg()));
			}

			$result = $this->safe_response($data);
			$this->log('request() completed successfully', array('result_type' => gettype($result)));
			return $result;
		}

		$this->log('request() failed with response code', array(
			'code' => $response_code,
			'body' => substr($response_body, 0, 500),
		));
		return false;
	}

	/**
	 * Resolve and create Freemius SDK client when available.
	 *
	 * @return Freemius_Api_WordPress|null
	 */
	private function get_sdk_client()
	{
		if (null !== $this->sdk_client) {
			$this->log('SDK client already cached');
			return $this->sdk_client;
		}

		$this->log('Attempting to load Freemius SDK client');

		if (! class_exists('Freemius_Api_WordPress')) {
			$sdk_file = WP_PLUGIN_DIR . '/fsbi-premium/freemius/includes/sdk/FreemiusWordPress.php';
			$this->log('SDK file path', array('file' => $sdk_file));

			if (file_exists($sdk_file)) {
				$this->log('SDK file found, including it');
				require_once $sdk_file;
			} else {
				$this->log('SDK file not found at expected path');
			}
		}

		if (! class_exists('Freemius_Api_WordPress')) {
			$this->log('Freemius_Api_WordPress class not available');
			return null;
		}

		$this->log('Instantiating Freemius SDK client', array(
			'developer_id' => $this->developer_id,
			'use_sandbox' => $this->use_sandbox,
		));

		$this->sdk_client = new Freemius_Api_WordPress(
			'developer',
			(int) $this->developer_id,
			(string) $this->public_key,
			(string) $this->secret_key,
			(bool) $this->use_sandbox
		);

		$this->log('SDK client created successfully');
		return $this->sdk_client;
	}

	/**
	 * Convert full developer endpoint to SDK relative path.
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $query Query args.
	 * @return string
	 */
	private function build_sdk_path($endpoint, $query = array())
	{
		$this->log('build_sdk_path() called', array(
			'endpoint' => $endpoint,
			'query_keys' => array_keys($query),
		));

		$path = (string) $endpoint;
		$prefix = '/v1/developers/' . $this->developer_id;

		if (0 === strpos($path, $prefix)) {
			$path = substr($path, strlen($prefix));
			$this->log('Stripped developer prefix from path', array('new_path' => $path));
		}

		if (empty($path) || '/' !== $path[0]) {
			$path = '/' . ltrim($path, '/');
			$this->log('Normalized path to start with /', array('path' => $path));
		}

		if (! empty($query)) {
			$query_string = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
			$path .= (false === strpos($path, '?') ? '?' : '&') . $query_string;
			$this->log('Added query string to path', array('query_string' => $query_string));
		}

		$this->log('build_sdk_path() completed', array('final_path' => $path));

		return $path;
	}

	/**
	 * Execute request with exponential backoff retry
	 *
	 * @param string $url Full request URL.
	 * @param array  $args Request arguments.
	 * @return array|WP_Error Response or error.
	 */
	private function execute_with_retry($url, $args)
	{
		$this->log('execute_with_retry() started', array(
			'url' => $url,
			'max_retries' => $this->max_retries,
		));

		$response = null;

		for ($attempt = 0; $attempt < $this->max_retries; $attempt++) {
			$this->log('Attempt ' . ($attempt + 1) . ' of ' . $this->max_retries);

			$response = wp_remote_request($url, $args);

			if (! is_wp_error($response)) {
				$code = wp_remote_retrieve_response_code($response);
				$this->log('Response received', array('code' => $code));

				if ($code >= 200 && $code < 300) {
					$this->log('execute_with_retry() succeeded', array('attempt' => $attempt + 1, 'code' => $code));
					return $response;
				}

				// Retry on server errors
				if ($code >= 500 && $attempt < $this->max_retries - 1) {
					$delay = $this->retry_delays[$attempt];
					$this->log('Server error, will retry', array(
						'code' => $code,
						'delay_seconds' => $delay,
					));
					sleep($delay);
					continue;
				}
			} else {
				$error_code = $response->get_error_code();
				$error_msg = $response->get_error_message();
				$this->log('Network error', array(
					'error_code' => $error_code,
					'error_message' => $error_msg,
			));

				// Retry on network errors
				if ($attempt < $this->max_retries - 1) {
					$delay = $this->retry_delays[$attempt];
					$this->log('Will retry after network error', array('delay_seconds' => $delay));
					sleep($delay);
					continue;
				}
			}

			$this->log('No more retries available, breaking');
			break;
		}

		$this->log('execute_with_retry() completed');
		return $response;
	}

	/**
	 * Safely extract expected properties from API response
	 *
	 * @param mixed $response API response data.
	 * @return array Validated response.
	 */
	private function safe_response($response)
	{
		if (is_object($response)) {
			$response = json_decode(wp_json_encode($response), true);
		}

		if (! is_array($response)) {
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
	private function extract_collection($response, $key)
	{
		if (isset($response[$key]) && is_array($response[$key])) {
			return $response[$key];
		}

		if (isset($response['data'][$key]) && is_array($response['data'][$key])) {
			return $response['data'][$key];
		}

		if (isset($response['data']) && is_array($response['data']) && isset($response['data'][0])) {
			return $response['data'];
		}

		if (isset($response[0])) {
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
	public function retrieve_plugins()
	{
		$this->log('retrieve_plugins() called');

		$endpoint = "/v1/developers/{$this->developer_id}/plugins.json";
		$response = $this->request($endpoint, 'GET', array(), array('all' => 'true'));

		if (! is_array($response)) {
			$this->log('retrieve_plugins() failed: response is not array', array('type' => gettype($response)));
			return array();
		}

		$plugins = $this->extract_collection($response, 'plugins');
		$this->log('retrieve_plugins() completed', array('count' => count($plugins)));

		return $plugins;
	}

	/**
	 * Retrieve plugin statistics
	 *
	 * GET /v1/developers/{developer_id}/plugins/{plugin_id}/stats.json
	 *
	 * @param int $plugin_id Plugin ID.
	 * @return array Plugin stats or empty array.
	 */
	public function retrieve_plugin_stats($plugin_id)
	{
		$this->log('retrieve_plugin_stats() called', array('plugin_id' => $plugin_id));

		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/stats.json";
		$response = $this->request($endpoint, 'GET');

		$result = is_array($response) ? $response : array();
		$this->log('retrieve_plugin_stats() completed', array('has_data' => ! empty($result)));

		return $result;
	}

	/**
	 * Retrieve plugin performance metrics
	 *
	 * GET /v1/developers/{developer_id}/plugins/{plugin_id}/performance.json
	 *
	 * @param int $plugin_id Plugin ID.
	 * @return array Performance data or empty array.
	 */
	public function retrieve_plugin_performance($plugin_id)
	{
		$this->log('retrieve_plugin_performance() called', array('plugin_id' => $plugin_id));

		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/performance.json";
		$response = $this->request($endpoint, 'GET');

		$result = is_array($response) ? $response : array();
		$this->log('retrieve_plugin_performance() completed', array('has_data' => ! empty($result)));

		return $result;
	}

	/**
	 * Retrieve plugin tags (deployments/versions)
	 *
	 * GET /v1/developers/{developer_id}/plugins/{plugin_id}/tags.json
	 *
	 * @param int $plugin_id Plugin ID.
	 * @return array Tags array or empty array.
	 */
	public function retrieve_plugin_tags($plugin_id)
	{
		$this->log('retrieve_plugin_tags() called', array('plugin_id' => $plugin_id));

		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/tags.json";
		$response = $this->request($endpoint, 'GET');

		if (! is_array($response)) {
			$this->log('retrieve_plugin_tags() failed: response is not array', array('type' => gettype($response)));
			return array();
		}

		$tags = $this->extract_collection($response, 'tags');
		$this->log('retrieve_plugin_tags() completed', array('count' => count($tags)));

		return $tags;
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
	public function retrieve_payments($plugin_id, $query = array())
	{
		$this->log('retrieve_payments() called', array(
			'plugin_id' => $plugin_id,
			'custom_query' => ! empty($query),
		));

		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/payments.json";
		$defaults = array(
			'count'  => 50,
			'offset' => 0,
		);
		$query = wp_parse_args($query, $defaults);
		$this->log('Query parameters prepared', $query);

		$response = $this->request($endpoint, 'GET', array(), $query);

		$payments = $this->extract_collection($response, 'payments');
		$this->log('retrieve_payments() completed', array('count' => count($payments)));

		return $payments;
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
	public function retrieve_subscriptions($plugin_id, $query = array())
	{
		$this->log('retrieve_subscriptions() called', array(
			'plugin_id' => $plugin_id,
			'custom_query' => ! empty($query),
		));

		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/subscriptions.json";
		$defaults = array(
			'count'  => 50,
			'offset' => 0,
		);
		$query = wp_parse_args($query, $defaults);
		$this->log('Query parameters prepared', $query);

		$response = $this->request($endpoint, 'GET', array(), $query);

		$subscriptions = $this->extract_collection($response, 'subscriptions');
		$this->log('retrieve_subscriptions() completed', array('count' => count($subscriptions)));

		return $subscriptions;
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
	public function retrieve_licenses($plugin_id, $query = array())
	{
		$this->log('retrieve_licenses() called', array(
			'plugin_id' => $plugin_id,
			'custom_query' => ! empty($query),
		));

		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/licenses.json";
		$defaults = array(
			'count'  => 50,
			'offset' => 0,
		);
		$query = wp_parse_args($query, $defaults);
		$this->log('Query parameters prepared', $query);

		$response = $this->request($endpoint, 'GET', array(), $query);

		$licenses = $this->extract_collection($response, 'licenses');
		$this->log('retrieve_licenses() completed', array('count' => count($licenses)));

		return $licenses;
	}

	/**
	 * Retrieve plans
	 *
	 * GET /v1/developers/{developer_id}/plugins/{plugin_id}/plans.json
	 *
	 * @param int $plugin_id Plugin ID.
	 * @return array Plans array or empty array.
	 */
	public function retrieve_plans($plugin_id)
	{
		$this->log('retrieve_plans() called', array('plugin_id' => $plugin_id));

		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/plans.json";
		$response = $this->request($endpoint, 'GET');

		$plans = $this->extract_collection($response, 'plans');
		$this->log('retrieve_plans() completed', array('count' => count($plans)));

		return $plans;
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
	public function retrieve_revenues($plugin_id, $query = array())
	{
		$this->log('retrieve_revenues() called', array(
			'plugin_id' => $plugin_id,
			'custom_query' => ! empty($query),
		));

		$endpoint = "/v1/developers/{$this->developer_id}/plugins/{$plugin_id}/revenues.json";
		$response = $this->request($endpoint, 'GET', array(), $query);

		$result = is_array($response) ? $response : array();
		$this->log('retrieve_revenues() completed', array('has_data' => ! empty($result)));

		return $result;
	}

	/**
	 * Retrieve account balance and payout information
	 *
	 * GET /v1/apps/{app_id}/developers/{developer_id}/balance.json
	 *
	 * @param int $app_id Freemius app ID (5172 for FSBI).
	 * @return array Balance data or empty array.
	 */
	public function retrieve_balance($app_id)
	{
		$this->log('retrieve_balance() called', array('app_id' => $app_id));

		$endpoint = "/v1/apps/{$app_id}/developers/{$this->developer_id}/balance.json";
		$response = $this->request($endpoint, 'GET');

		$result = is_array($response) ? $response : array();
		$this->log('retrieve_balance() completed', array('has_data' => ! empty($result)));

		return $result;
	}
}
