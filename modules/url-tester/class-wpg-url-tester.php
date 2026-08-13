<?php
/**
 * URL test runner for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes baseline/sandbox URL checks and stores results via WPG_Result_Store.
 */
class WPG_URL_Tester {

	const TRACE_QP        = 'wpsm_trace';
	const TRACE_SIG       = 'wpsm_sig';
	const TRACE_TTL       = 300;
	const MAX_ERROR_CHARS = 4000;

	/**
	 * First runtime PHP error captured for traced requests.
	 *
	 * @var array|null
	 */
	private static $runtime_first_error = null;

	/**
	 * Whether the current request is a WP Guardrail traced URL test.
	 *
	 * Set to true in maybe_capture_traced_request_error() once a valid
	 * HMAC-signed trace ID is confirmed.  Used to scope error_reporting
	 * elevation to traced test target requests only, not all sandbox sessions.
	 *
	 * @var bool
	 */
	private static $is_traced_request = false;

	/**
	 * Bootstrap URL tester runtime hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_capture_traced_request_error' ), 0 );
		add_action( 'init', array( __CLASS__, 'maybe_enable_sandbox_debug' ), 1 );
	}

	/**
	 * Elevate PHP error reporting for traced test target requests only.
	 *
	 * Runs at init priority 1, after maybe_capture_traced_request_error() at
	 * priority 0, so $is_traced_request is already set when this fires.
	 * Normal admin sandbox sessions are not affected.
	 *
	 * @return void
	 */
	public static function maybe_enable_sandbox_debug() {
		if ( ! self::$is_traced_request ) {
			return;
		}

		error_reporting( E_ALL );
		@ini_set( 'log_errors', '1' );
		@ini_set( 'display_errors', '0' );
	}

	/**
	 * Resolve user input into a same-host absolute URL.
	 *
	 * @param string $input Raw URL/path input.
	 * @return string|WP_Error
	 */
	public static function resolve_test_url( $input ) {
		$input = is_string( $input ) ? trim( $input ) : '';
		$input = sanitize_text_field( $input );

		if ( '' === $input ) {
			return new WP_Error( 'wsm_invalid_url', __( 'Please enter a URL or path to test.', 'wp-guardrail' ) );
		}

		if ( 0 === strpos( $input, '/' ) ) {
			if ( 0 === strpos( $input, '/wp-admin/' ) ) {
				$admin_path = ltrim( substr( $input, strlen( '/wp-admin/' ) ), '/' );
				$url        = admin_url( $admin_path );
			} else {
				$url = home_url( $input );
			}
		} else {
			$url = esc_url_raw( $input );
		}

		if ( '' === $url ) {
			return new WP_Error( 'wsm_invalid_url', __( 'The URL is invalid.', 'wp-guardrail' ) );
		}

		$parsed_url  = wp_parse_url( $url );
		$parsed_home = wp_parse_url( home_url() );

		if ( ! is_array( $parsed_url ) || empty( $parsed_url['host'] ) ) {
			return new WP_Error( 'wsm_invalid_url', __( 'Could not parse URL host.', 'wp-guardrail' ) );
		}

		$scheme = isset( $parsed_url['scheme'] ) ? strtolower( $parsed_url['scheme'] ) : 'http';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'wsm_invalid_url', __( 'Only HTTP and HTTPS URLs are allowed.', 'wp-guardrail' ) );
		}

		$target_host = strtolower( $parsed_url['host'] );
		$site_host   = is_array( $parsed_home ) && ! empty( $parsed_home['host'] ) ? strtolower( $parsed_home['host'] ) : '';

		if ( '' === $site_host || $target_host !== $site_host ) {
			return new WP_Error( 'wsm_invalid_host', __( 'Only URLs on the current site host are allowed.', 'wp-guardrail' ) );
		}

		return $url;
	}

	/**
	 * Run a single URL test.
	 *
	 * @param string $url          Absolute URL.
	 * @param string $mode         baseline|sandbox.
	 * @param bool   $forward_auth Whether to forward the current user's WordPress auth cookies.
	 * @return array
	 */
	public static function run_test( $url, $mode, $forward_auth = false ) {
		$mode         = sanitize_key( $mode );
		$forward_auth = (bool) $forward_auth;

		if ( ! in_array( $mode, array( 'baseline', 'sandbox' ), true ) ) {
			$mode = 'baseline';
		}

		$display_url = $url;
		$trace_id    = self::generate_trace_id();
		$trace_sig   = self::trace_signature( $trace_id );
		$request_url = add_query_arg(
			array(
				self::TRACE_QP  => $trace_id,
				self::TRACE_SIG => $trace_sig,
			),
			$url
		);

		$parsed_url = wp_parse_url( $request_url );
		$domain     = isset( $parsed_url['host'] ) ? $parsed_url['host'] : wp_parse_url( home_url(), PHP_URL_HOST );

		$start = microtime( true );
		$args  = array(
			'timeout'     => 20,
			'redirection' => 3,
			'user-agent'  => 'WP Guardrail/0.3',
			'sslverify'   => true,
			'headers'     => array(
				'X-WPSM-Trace' => $trace_id,
			),
		);

		if ( 'baseline' === $mode ) {
			$args['cookies'] = $forward_auth ? self::get_auth_cookies( $domain ) : array();
		} else {
			$token = WPG_Container::instance()->get_token();
			if ( '' === $token ) {
				return array(
					'mode'            => $mode,
					'url'             => $display_url,
					'effective_url'   => $display_url,
					'http_code'       => 0,
					'time_ms'         => 0,
					'bytes'           => 0,
					'error_message'   => __( 'Sandbox token is missing for this session.', 'wp-guardrail' ),
					'fatal_suspected' => false,
					'fatal_snippet'   => '',
					'timestamp'       => current_time( 'mysql' ),
					'auth_forwarded'  => $forward_auth,
				);
			}

			$cookies = array(
				new WP_Http_Cookie(
					array(
						'name'   => WPG_Session_Manager::COOKIE,
						'value'  => $token,
						'domain' => $domain,
						'path'   => '/',
					)
				),
			);

			if ( $forward_auth ) {
				$cookies = array_merge( $cookies, self::get_auth_cookies( $domain ) );
			}

			$args['cookies'] = $cookies;
		}

		$response = wp_remote_get( $request_url, $args );
		$time_ms  = (int) round( ( microtime( true ) - $start ) * 1000 );
		$trace    = self::get_trace_result( $trace_id );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$trace_error   = self::format_trace_error( $trace );

			if ( '' !== $trace_error ) {
				$error_message .= ' | ' . $trace_error;
			}

			return array(
				'mode'            => $mode,
				'url'             => $display_url,
				'effective_url'   => $display_url,
				'http_code'       => 0,
				'time_ms'         => $time_ms,
				'bytes'           => 0,
				'error_message'   => $error_message,
				'fatal_suspected' => false,
				'fatal_snippet'   => '',
				'timestamp'       => current_time( 'mysql' ),
				'auth_forwarded'  => $forward_auth,
			);
		}

		$http_code      = (int) wp_remote_retrieve_response_code( $response );
		$body           = (string) wp_remote_retrieve_body( $response );
		$bytes          = strlen( $body );
		$effective_url  = self::extract_effective_url( $response, $request_url );
		$effective_url  = remove_query_arg( array( self::TRACE_QP, self::TRACE_SIG ), $effective_url );
		$fatal          = self::detect_fatal( $body, $http_code );
		$trace_error    = self::format_trace_error( $trace );
		$fatal_snippet  = isset( $fatal['snippet'] ) ? $fatal['snippet'] : '';
		$fatal_detected = ! empty( $fatal['fatal_suspected'] );

		if ( '' !== $trace_error ) {
			$fatal_detected = true;
			if ( '' !== $fatal_snippet ) {
				$fatal_snippet .= ' | ' . $trace_error;
			} else {
				$fatal_snippet = $trace_error;
			}
		}

		return array(
			'mode'            => $mode,
			'url'             => $display_url,
			'effective_url'   => $effective_url,
			'http_code'       => $http_code,
			'time_ms'         => $time_ms,
			'bytes'           => $bytes,
			'error_message'   => '',
			'fatal_suspected' => $fatal_detected,
			'fatal_snippet'   => $fatal_snippet,
			'timestamp'       => current_time( 'mysql' ),
			'auth_forwarded'  => $forward_auth,
		);
	}

	/**
	 * Detect likely fatal markers in response body.
	 *
	 * @param string $body      Response body.
	 * @param int    $http_code HTTP status code.
	 * @return array
	 */
	public static function detect_fatal( $body, $http_code ) {
		$body      = is_string( $body ) ? $body : '';
		$http_code = (int) $http_code;

		$markers = array(
			'Fatal error',
			'Parse error',
			'Uncaught Error',
			'Allowed memory size',
		);

		foreach ( $markers as $marker ) {
			$position = stripos( $body, $marker );
			if ( false !== $position ) {
				$snippet = self::plain_text_excerpt( $body, (int) $position, self::MAX_ERROR_CHARS );

				return array(
					'fatal_suspected' => true,
					'snippet'         => $snippet,
				);
			}
		}

		if ( $http_code >= 500 ) {
			return array(
				'fatal_suspected' => true,
				'snippet'         => self::plain_text_excerpt( $body, 0, self::MAX_ERROR_CHARS ),
			);
		}

		return array(
			'fatal_suspected' => false,
			'snippet'         => '',
		);
	}

	/**
	 * Prepend one result to the per-user result store (ring buffer of 50 items).
	 *
	 * @param array $result Test result.
	 * @return bool
	 */
	public static function append_result_to_session( $result ) {
		return WPG_Result_Store::append_test( self::sanitize_result_row( $result ) );
	}

	/**
	 * Get URL test results from the per-user result store.
	 *
	 * @return array
	 */
	public static function get_results() {
		return WPG_Result_Store::get_tests();
	}

	/**
	 * Clear URL tests and comparisons from the per-user result store.
	 *
	 * @return void
	 */
	public static function clear_results() {
		WPG_Result_Store::clear_tests();
		WPG_Result_Store::clear_comparisons();
	}

	/**
	 * Run baseline vs sandbox comparison for one URL.
	 *
	 * @param string $url          Absolute URL.
	 * @param bool   $forward_auth Whether to forward WordPress auth cookies.
	 * @return array
	 */
	public static function run_compare( $url, $forward_auth = false ) {
		$url          = esc_url_raw( (string) $url );
		$forward_auth = (bool) $forward_auth;
		$token        = WPG_Container::instance()->get_token();
		$baseline     = self::run_compare_request( $url, 'baseline', '', $forward_auth );
		$sandbox      = self::run_compare_request( $url, 'sandbox', $token, $forward_auth );

		return array(
			'timestamp'      => current_time( 'mysql' ),
			'url'            => $url,
			'baseline'       => $baseline,
			'sandbox'        => $sandbox,
			'auth_forwarded' => $forward_auth,
		);
	}

	/**
	 * Prepend one compare result to the per-user result store.
	 *
	 * @param array $comparison Comparison data.
	 * @return bool
	 */
	public static function append_compare_to_session( $comparison ) {
		return WPG_Result_Store::append_comparison( self::sanitize_compare_row( $comparison ) );
	}

	/**
	 * Get comparison history from the per-user result store.
	 *
	 * @return array
	 */
	public static function get_compare_results() {
		return WPG_Result_Store::get_comparisons();
	}

	/**
	 * Capture runtime errors for traced test requests.
	 *
	 * @return void
	 */
	public static function maybe_capture_traced_request_error() {
		$trace_id  = isset( $_GET[ self::TRACE_QP ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::TRACE_QP ] ) ) : '';
		$trace_id  = self::sanitize_trace_id( $trace_id );
		$trace_sig = isset( $_GET[ self::TRACE_SIG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::TRACE_SIG ] ) ) : '';

		if ( '' === $trace_id || '' === $trace_sig ) {
			return;
		}

		if ( ! self::is_valid_trace_signature( $trace_id, $trace_sig ) ) {
			return;
		}

		self::$is_traced_request   = true;
		self::$runtime_first_error = null;
		set_error_handler( array( __CLASS__, 'capture_runtime_error' ) );
		register_shutdown_function( array( __CLASS__, 'capture_shutdown_error' ), $trace_id );
	}

	/**
	 * PHP error handler callback for traced requests.
	 *
	 * @param int    $errno   Error number.
	 * @param string $errstr  Error text.
	 * @param string $errfile Error file.
	 * @param int    $errline Error line.
	 * @return bool
	 */
	public static function capture_runtime_error( $errno, $errstr, $errfile, $errline ) {
		if ( null === self::$runtime_first_error ) {
			self::$runtime_first_error = array(
				'type'    => (int) $errno,
				'message' => wp_strip_all_tags( (string) $errstr ),
				'file'    => wp_strip_all_tags( (string) $errfile ),
				'line'    => (int) $errline,
			);
		}

		// Preserve normal PHP/WordPress error handling chain.
		return false;
	}

	/**
	 * Shutdown callback used to persist traced request error details.
	 *
	 * @param string $trace_id Trace identifier.
	 * @return void
	 */
	public static function capture_shutdown_error( $trace_id ) {
		$trace_id = self::sanitize_trace_id( $trace_id );
		if ( '' === $trace_id ) {
			return;
		}

		$payload = array(
			'timestamp'   => current_time( 'mysql' ),
			'php_error'   => self::$runtime_first_error,
			'fatal_error' => null,
		);

		$last_error  = error_get_last();
		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
		if ( is_array( $last_error ) && isset( $last_error['type'] ) && in_array( (int) $last_error['type'], $fatal_types, true ) ) {
			$payload['fatal_error'] = array(
				'type'    => (int) $last_error['type'],
				'message' => isset( $last_error['message'] ) ? wp_strip_all_tags( (string) $last_error['message'] ) : '',
				'file'    => isset( $last_error['file'] ) ? wp_strip_all_tags( (string) $last_error['file'] ) : '',
				'line'    => isset( $last_error['line'] ) ? (int) $last_error['line'] : 0,
			);
		}

		if ( null === $payload['php_error'] && null === $payload['fatal_error'] ) {
			return;
		}

		set_transient( self::trace_transient_key( $trace_id ), $payload, self::TRACE_TTL );
	}

	/**
	 * Get effective URL after redirects from WP HTTP response object when available.
	 *
	 * @param array  $response HTTP response.
	 * @param string $fallback Original URL.
	 * @return string
	 */
	private static function extract_effective_url( $response, $fallback ) {
		if ( isset( $response['http_response'] ) && is_object( $response['http_response'] ) ) {
			$http_response = $response['http_response'];
			if ( method_exists( $http_response, 'get_response_object' ) ) {
				$response_object = $http_response->get_response_object();
				if ( is_object( $response_object ) && isset( $response_object->url ) && is_string( $response_object->url ) ) {
					return $response_object->url;
				}
			}
		}

		return $fallback;
	}

	/**
	 * Build a short human-readable error string from trace payload.
	 *
	 * @param array|false $trace Trace payload.
	 * @return string
	 */
	private static function format_trace_error( $trace ) {
		if ( ! is_array( $trace ) ) {
			return '';
		}

		$parts = array();

		if ( ! empty( $trace['fatal_error'] ) && is_array( $trace['fatal_error'] ) ) {
			$fatal   = $trace['fatal_error'];
			$message = isset( $fatal['message'] ) ? (string) $fatal['message'] : '';
			$file    = isset( $fatal['file'] ) ? (string) $fatal['file'] : '';
			$line    = isset( $fatal['line'] ) ? (int) $fatal['line'] : 0;
			$parts[] = sprintf( 'Fatal: %s (%s:%d)', $message, $file, $line );
		}

		if ( ! empty( $trace['php_error'] ) && is_array( $trace['php_error'] ) ) {
			$error   = $trace['php_error'];
			$message = isset( $error['message'] ) ? (string) $error['message'] : '';
			$file    = isset( $error['file'] ) ? (string) $error['file'] : '';
			$line    = isset( $error['line'] ) ? (int) $error['line'] : 0;
			$parts[] = sprintf( 'PHP: %s (%s:%d)', $message, $file, $line );
		}

		$summary = implode( "\n", $parts );
		$summary = wp_strip_all_tags( $summary );

		return substr( $summary, 0, self::MAX_ERROR_CHARS );
	}

	/**
	 * Retrieve and clear trace payload by trace ID.
	 *
	 * @param string $trace_id Trace identifier.
	 * @return array|false
	 */
	private static function get_trace_result( $trace_id ) {
		$trace_id = self::sanitize_trace_id( $trace_id );
		if ( '' === $trace_id ) {
			return false;
		}

		$key   = self::trace_transient_key( $trace_id );
		$trace = get_transient( $key );
		delete_transient( $key );

		return is_array( $trace ) ? $trace : false;
	}

	/**
	 * Generate a compact trace identifier.
	 *
	 * @return string
	 */
	private static function generate_trace_id() {
		$raw = wp_generate_password( 20, false, false ) . dechex( time() );
		$raw = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $raw );

		return strtolower( substr( $raw, 0, 32 ) );
	}

	/**
	 * Sanitize trace identifier.
	 *
	 * @param string $trace_id Trace identifier.
	 * @return string
	 */
	private static function sanitize_trace_id( $trace_id ) {
		$trace_id = is_string( $trace_id ) ? $trace_id : '';
		$trace_id = preg_replace( '/[^a-zA-Z0-9]/', '', $trace_id );

		return strtolower( substr( $trace_id, 0, 64 ) );
	}

	/**
	 * Sign a trace identifier.
	 *
	 * @param string $trace_id Trace identifier.
	 * @return string
	 */
	private static function trace_signature( $trace_id ) {
		return hash_hmac( 'sha256', $trace_id, wp_salt( 'auth' ) );
	}

	/**
	 * Validate trace signature.
	 *
	 * @param string $trace_id  Trace identifier.
	 * @param string $signature Provided signature.
	 * @return bool
	 */
	private static function is_valid_trace_signature( $trace_id, $signature ) {
		$expected = self::trace_signature( $trace_id );

		return is_string( $signature ) && hash_equals( $expected, $signature );
	}

	/**
	 * Build transient key for trace payload.
	 *
	 * @param string $trace_id Trace identifier.
	 * @return string
	 */
	private static function trace_transient_key( $trace_id ) {
		return 'wsm_trace_' . md5( $trace_id );
	}

	/**
	 * Convert HTML body to plain text and return a bounded excerpt.
	 *
	 * @param string $body   Raw response body.
	 * @param int    $offset Start offset.
	 * @param int    $length Max length.
	 * @return string
	 */
	private static function plain_text_excerpt( $body, $offset, $length ) {
		$body   = is_string( $body ) ? $body : '';
		$offset = max( 0, (int) $offset );
		$length = max( 1, (int) $length );

		$snippet = substr( $body, $offset, $length );
		$snippet = wp_strip_all_tags( $snippet );
		$snippet = html_entity_decode( $snippet, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$snippet = preg_replace( "/[\r\n\t]+/", ' ', (string) $snippet );
		$snippet = preg_replace( '/\s{2,}/', ' ', (string) $snippet );

		return trim( (string) $snippet );
	}

	/**
	 * Execute one compare request mode.
	 *
	 * Uses WP_Http_Cookie objects for consistent cookie handling across
	 * both baseline and sandbox modes (previously sandbox mode used a raw
	 * Cookie header string which bypassed the WP HTTP API cookie layer).
	 *
	 * @param string $url          Absolute URL.
	 * @param string $mode         baseline|sandbox.
	 * @param string $token        Sandbox token for sandbox mode.
	 * @param bool   $forward_auth Whether to forward WordPress auth cookies.
	 * @return array
	 */
	private static function run_compare_request( $url, $mode, $token, $forward_auth = false ) {
		$mode         = sanitize_key( $mode );
		$token        = sanitize_text_field( (string) $token );
		$forward_auth = (bool) $forward_auth;

		$parsed_url = wp_parse_url( $url );
		$domain     = isset( $parsed_url['host'] ) ? $parsed_url['host'] : wp_parse_url( home_url(), PHP_URL_HOST );

		$args = array(
			'timeout'     => 20,
			'redirection' => 3,
			'user-agent'  => 'WP Guardrail Compare',
			'sslverify'   => true,
		);

		if ( 'sandbox' === $mode ) {
			if ( '' === $token ) {
				return array(
					'http_code'       => 0,
					'time_ms'         => 0,
					'bytes'           => 0,
					'fatal_suspected' => false,
					'fatal_snippet'   => '',
					'error_message'   => __( 'Sandbox token is missing for this session.', 'wp-guardrail' ),
				);
			}

			$cookies = array(
				new WP_Http_Cookie(
					array(
						'name'   => WPG_Session_Manager::COOKIE,
						'value'  => $token,
						'domain' => $domain,
						'path'   => '/',
					)
				),
			);

			if ( $forward_auth ) {
				$cookies = array_merge( $cookies, self::get_auth_cookies( $domain ) );
			}

			$args['cookies'] = $cookies;
		} else {
			$args['cookies'] = $forward_auth ? self::get_auth_cookies( $domain ) : array();
		}

		$start    = microtime( true );
		$response = wp_remote_get( $url, $args );
		$time_ms  = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'http_code'       => 0,
				'time_ms'         => $time_ms,
				'bytes'           => 0,
				'fatal_suspected' => false,
				'fatal_snippet'   => '',
				'error_message'   => $response->get_error_message(),
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = (string) wp_remote_retrieve_body( $response );
		$bytes     = strlen( $body );
		$fatal     = self::detect_fatal( $body, $http_code );

		return array(
			'http_code'       => $http_code,
			'time_ms'         => $time_ms,
			'bytes'           => $bytes,
			'fatal_suspected' => ! empty( $fatal['fatal_suspected'] ),
			'fatal_snippet'   => isset( $fatal['snippet'] ) ? sanitize_textarea_field( $fatal['snippet'] ) : '',
			'error_message'   => '',
		);
	}

	/**
	 * Sanitize one URL test result row for storage.
	 *
	 * @param array $result Raw result row.
	 * @return array
	 */
	private static function sanitize_result_row( $result ) {
		$result = is_array( $result ) ? $result : array();

		return array(
			'mode'            => isset( $result['mode'] ) ? sanitize_key( $result['mode'] ) : '',
			'url'             => isset( $result['url'] ) ? esc_url_raw( $result['url'] ) : '',
			'effective_url'   => isset( $result['effective_url'] ) ? esc_url_raw( $result['effective_url'] ) : '',
			'http_code'       => isset( $result['http_code'] ) ? (int) $result['http_code'] : 0,
			'time_ms'         => isset( $result['time_ms'] ) ? (int) $result['time_ms'] : 0,
			'bytes'           => isset( $result['bytes'] ) ? (int) $result['bytes'] : 0,
			'error_message'   => isset( $result['error_message'] ) ? sanitize_textarea_field( $result['error_message'] ) : '',
			'fatal_suspected' => ! empty( $result['fatal_suspected'] ),
			'fatal_snippet'   => isset( $result['fatal_snippet'] ) ? sanitize_textarea_field( $result['fatal_snippet'] ) : '',
			'timestamp'       => isset( $result['timestamp'] ) ? sanitize_text_field( $result['timestamp'] ) : current_time( 'mysql' ),
			'auth_forwarded'  => ! empty( $result['auth_forwarded'] ),
		);
	}

	/**
	 * Sanitize compare row for storage.
	 *
	 * @param array $comparison Raw compare row.
	 * @return array
	 */
	private static function sanitize_compare_row( $comparison ) {
		$comparison = is_array( $comparison ) ? $comparison : array();
		$baseline   = isset( $comparison['baseline'] ) && is_array( $comparison['baseline'] ) ? $comparison['baseline'] : array();
		$sandbox    = isset( $comparison['sandbox'] ) && is_array( $comparison['sandbox'] ) ? $comparison['sandbox'] : array();

		return array(
			'timestamp'      => isset( $comparison['timestamp'] ) ? sanitize_text_field( $comparison['timestamp'] ) : current_time( 'mysql' ),
			'url'            => isset( $comparison['url'] ) ? esc_url_raw( $comparison['url'] ) : '',
			'auth_forwarded' => ! empty( $comparison['auth_forwarded'] ),
			'baseline'       => array(
				'http_code'       => isset( $baseline['http_code'] ) ? (int) $baseline['http_code'] : 0,
				'time_ms'         => isset( $baseline['time_ms'] ) ? (int) $baseline['time_ms'] : 0,
				'bytes'           => isset( $baseline['bytes'] ) ? (int) $baseline['bytes'] : 0,
				'fatal_suspected' => ! empty( $baseline['fatal_suspected'] ),
				'fatal_snippet'   => isset( $baseline['fatal_snippet'] ) ? sanitize_textarea_field( $baseline['fatal_snippet'] ) : '',
				'error_message'   => isset( $baseline['error_message'] ) ? sanitize_textarea_field( $baseline['error_message'] ) : '',
			),
			'sandbox'        => array(
				'http_code'       => isset( $sandbox['http_code'] ) ? (int) $sandbox['http_code'] : 0,
				'time_ms'         => isset( $sandbox['time_ms'] ) ? (int) $sandbox['time_ms'] : 0,
				'bytes'           => isset( $sandbox['bytes'] ) ? (int) $sandbox['bytes'] : 0,
				'fatal_suspected' => ! empty( $sandbox['fatal_suspected'] ),
				'fatal_snippet'   => isset( $sandbox['fatal_snippet'] ) ? sanitize_textarea_field( $sandbox['fatal_snippet'] ) : '',
				'error_message'   => isset( $sandbox['error_message'] ) ? sanitize_textarea_field( $sandbox['error_message'] ) : '',
			),
		);
	}

	/**
	 * Collect the current user's WordPress authentication cookies for a given domain.
	 *
	 * Looks for cookies whose names start with the standard WordPress prefixes
	 * (wordpress_, wordpress_logged_in_, wordpress_sec_) and wraps them as
	 * WP_Http_Cookie objects bound to the target domain.  Used for optional
	 * auth forwarding when testing /wp-admin/ and other authenticated URLs.
	 *
	 * @param string $domain Request domain.
	 * @return WP_Http_Cookie[]
	 */
	private static function get_auth_cookies( $domain ) {
		$cookies  = array();
		$prefixes = array( 'wordpress_', 'wordpress_logged_in_', 'wordpress_sec_' );

		foreach ( $_COOKIE as $name => $value ) {
			$name  = (string) $name;
			$value = (string) $value;

			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					$cookies[] = new WP_Http_Cookie(
						array(
							'name'   => sanitize_text_field( $name ),
							'value'  => sanitize_text_field( $value ),
							'domain' => $domain,
							'path'   => '/',
						)
					);
					break;
				}
			}
		}

		return $cookies;
	}
}
