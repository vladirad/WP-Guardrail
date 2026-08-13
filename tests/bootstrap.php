<?php
/**
 * Test bootstrap for WP Guardrail.
 *
 * Provides lightweight WordPress function stubs so the service-layer classes
 * can be tested without a full WordPress installation.
 *
 * Only stubs the minimum surface needed by the tested classes. Any function
 * referenced in the class under test that is not stubbed here will cause a
 * fatal on call — add a stub when you need it.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WPG_PATH' ) ) {
	define( 'WPG_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WPG_VERSION' ) ) {
	define( 'WPG_VERSION', '0.8.0-test' );
}

// ---------------------------------------------------------------------------
// Global state used by stubs.
// ---------------------------------------------------------------------------

$GLOBALS['_wpg_test_transients']  = array();
$GLOBALS['_wpg_test_options']     = array();
$GLOBALS['_wpg_test_current_uid'] = 1;
$GLOBALS['_wpg_test_cookies']     = array();
$GLOBALS['_wpg_test_time_offset'] = 0;

// ---------------------------------------------------------------------------
// WordPress function stubs.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		$store = $GLOBALS['_wpg_test_transients'];
		if ( ! isset( $store[ $key ] ) ) {
			return false;
		}
		$entry = $store[ $key ];
		// Respect TTL.
		if ( isset( $entry['expires'] ) && $entry['expires'] < wpg_test_time() ) {
			unset( $GLOBALS['_wpg_test_transients'][ $key ] );
			return false;
		}
		return $entry['value'];
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		$GLOBALS['_wpg_test_transients'][ $key ] = array(
			'value'   => $value,
			'expires' => $ttl > 0 ? ( wpg_test_time() + $ttl ) : PHP_INT_MAX,
		);
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['_wpg_test_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		return $GLOBALS['_wpg_test_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, $value, $autoload = null ): bool {
		$GLOBALS['_wpg_test_options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $key ): bool {
		unset( $GLOBALS['_wpg_test_options'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) $GLOBALS['_wpg_test_current_uid'];
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$pass  = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$pass .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $pass;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'test-salt-for-unit-tests-only';
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string {
		return date( 'Y-m-d H:i:s', wpg_test_time() );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.com' . $path;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return hash( 'sha256', $action . '-test-nonce' );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): int|false {
		return hash( 'sha256', $action . '-test-nonce' ) === $nonce ? 1 : false;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, string $url = '' ): string {
		if ( is_array( $args ) ) {
			return $url . '?' . http_build_query( $args );
		}
		return $url;
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg( $key, string $url = '' ): string {
		return $url;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		return (int) $GLOBALS['_wpg_test_current_uid'] > 0;
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax(): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron(): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $url, int $status = 302 ): void {
		// No-op in tests.
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'setcookie' ) ) {
	// Intercept setcookie calls and store in _COOKIE superglobal.
	// Note: PHP's setcookie is a built-in and cannot be redeclared, but tests
	// work because the session manager checks $_COOKIE, not the sent header.
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $str ): string {
		return rtrim( $str, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( string $path ): string {
		return str_replace( '\\', '/', $path );
	}
}

if ( ! function_exists( 'hash_equals' ) ) {
	// hash_equals is a PHP core function — stub only for environments missing it.
	// Most PHP 5.6+ environments already have it; this is a safety fallback.
	if ( version_compare( PHP_VERSION, '5.6.0', '<' ) ) {
		function hash_equals( string $a, string $b ): bool {
			return $a === $b;
		}
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return (int) $GLOBALS['_wpg_test_current_uid'] > 0;
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl(): bool {
		return false;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! defined( 'COOKIEPATH' ) )     { define( 'COOKIEPATH', '/' ); }
if ( ! defined( 'COOKIE_DOMAIN' ) )  { define( 'COOKIE_DOMAIN', '' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

// ---------------------------------------------------------------------------
// WordPress / plugin class stubs.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code(): string    { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}

if ( ! class_exists( 'WPG_Bootstrap' ) ) {
	class WPG_Bootstrap {
		public static function self_plugin_basename(): string {
			return 'wp-guardrail/wp-guardrail.php';
		}
	}
}

if ( ! class_exists( 'WPG_Admin' ) ) {
	class WPG_Admin {
		const SLUG_DASHBOARD = 'wp-guardrail';
	}
}

// ---------------------------------------------------------------------------
// Test helper — controllable clock.
// ---------------------------------------------------------------------------

function wpg_test_time(): int {
	return time() + (int) $GLOBALS['_wpg_test_time_offset'];
}

function wpg_test_advance_time( int $seconds ): void {
	$GLOBALS['_wpg_test_time_offset'] += $seconds;
}

function wpg_test_reset_state(): void {
	$GLOBALS['_wpg_test_transients']  = array();
	$GLOBALS['_wpg_test_options']     = array();
	$GLOBALS['_wpg_test_current_uid'] = 1;
	$GLOBALS['_wpg_test_cookies']     = array();
	$GLOBALS['_wpg_test_time_offset'] = 0;
}

// ---------------------------------------------------------------------------
// Load the classes under test.
// ---------------------------------------------------------------------------

require_once WPG_PATH . 'core/class-wpg-result-store.php';
require_once WPG_PATH . 'core/class-wpg-logger.php';
require_once WPG_PATH . 'core/class-wpg-snapshot.php';

// Session manager and container need a minimal _COOKIE stub.
if ( ! isset( $_COOKIE ) ) {
	$_COOKIE = array();
}
require_once WPG_PATH . 'core/class-wpg-session-manager.php';
require_once WPG_PATH . 'core/class-wpg-container.php';

// Safe-mode/recovery (depends only on options + time).
require_once WPG_PATH . 'modules/safe-mode/class-wpg-recovery.php';
