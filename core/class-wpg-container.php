<?php
/**
 * Central runtime context for WP Guardrail sandbox state.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared sandbox runtime context (singleton).
 *
 * Owns all session read/write operations for the current request.
 * A per-request in-memory cache prevents redundant transient lookups: the
 * session is read from the database at most once per page load, and the
 * cache is kept in sync on every update_session() call.
 *
 * Design notes:
 * - get_session() is a pure reader — it no longer calls WPG_Session_Manager::stop()
 *   as a side effect.  Stale cookies expire naturally within TTL (2 h).
 * - Callers that need to invalidate the cache after an external write should
 *   call clear_session_cache().
 */
class WPG_Container {

	/**
	 * Singleton instance.
	 *
	 * @var WPG_Container|null
	 */
	private static $instance = null;

	/**
	 * Per-request session cache value.
	 *
	 * @var array|false|null  null = not yet resolved.
	 */
	private $session_cache = null;

	/**
	 * Whether the session has been resolved at least once this request.
	 *
	 * @var bool
	 */
	private $session_cache_valid = false;

	/**
	 * Get singleton instance.
	 *
	 * @return WPG_Container
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}

	/**
	 * Read sandbox token from cookie.
	 *
	 * @return string
	 */
	public function get_token() {
		if ( empty( $_COOKIE[ WPG_Session_Manager::COOKIE ] ) ) {
			return '';
		}

		$token = sanitize_text_field( wp_unslash( $_COOKIE[ WPG_Session_Manager::COOKIE ] ) );

		return $this->normalize_token( $token );
	}

	/**
	 * Check whether current user is allowed to use sandbox tools.
	 *
	 * @return bool
	 */
	public function is_allowed() {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * Whether a valid sandbox session is currently active.
	 *
	 * @return bool
	 */
	public function is_sandbox_active() {
		return false !== $this->get_session();
	}

	/**
	 * Get the active sandbox session for the current user.
	 *
	 * Returns the session array on success, or false when no valid session
	 * exists.  The result is cached per request so repeated calls within one
	 * page load are free.
	 *
	 * NOTE: this method intentionally has no side effects.  It does not call
	 * WPG_Session_Manager::stop() or modify any state.
	 *
	 * @return array|false
	 */
	public function get_session() {
		if ( $this->session_cache_valid ) {
			return $this->session_cache;
		}

		if ( ! $this->is_allowed() ) {
			$this->session_cache       = false;
			$this->session_cache_valid = true;
			return false;
		}

		$token = $this->get_token();
		if ( '' === $token ) {
			$this->session_cache       = false;
			$this->session_cache_valid = true;
			return false;
		}

		$session = $this->get_by_token( $token );
		if ( ! $this->is_session_valid( $session ) ) {
			$this->session_cache       = false;
			$this->session_cache_valid = true;
			return false;
		}

		if ( (int) $session['user_id'] !== (int) get_current_user_id() ) {
			$this->session_cache       = false;
			$this->session_cache_valid = true;
			return false;
		}

		$this->session_cache       = $session;
		$this->session_cache_valid = true;
		return $session;
	}

	/**
	 * Update the current sandbox session payload.
	 *
	 * Writes the new data to the transient and refreshes the in-memory
	 * cache so subsequent get_session() calls within the same request see
	 * the updated state.
	 *
	 * @param array $data Session payload.
	 * @return bool
	 */
	public function update_session( $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}

		$token = $this->get_token();
		if ( '' === $token ) {
			return false;
		}

		$ok = set_transient( $this->transient_key( $token ), $data, WPG_Session_Manager::TTL );

		if ( $ok ) {
			// Keep the in-memory cache in sync.
			$this->session_cache       = $data;
			$this->session_cache_valid = true;
		}

		return $ok;
	}

	/**
	 * Invalidate the per-request session cache.
	 *
	 * Call this after an external write to the session transient that was
	 * not performed through update_session() — e.g., after WPG_Session_Manager::stop()
	 * deletes the transient.
	 *
	 * @return void
	 */
	public function clear_session_cache() {
		$this->session_cache       = null;
		$this->session_cache_valid = false;
	}

	/**
	 * Fetch raw session data by token.
	 *
	 * @param string $token Token value.
	 * @return array|false
	 */
	private function get_by_token( $token ) {
		$token = $this->normalize_token( $token );
		if ( '' === $token ) {
			return false;
		}

		$session = get_transient( $this->transient_key( $token ) );

		return is_array( $session ) ? $session : false;
	}

	/**
	 * Validate raw session shape and expiration.
	 *
	 * @param array|false $session Session data.
	 * @return bool
	 */
	private function is_session_valid( $session ) {
		if ( ! is_array( $session ) ) {
			return false;
		}

		if ( empty( $session['enabled'] ) || empty( $session['user_id'] ) || empty( $session['expires'] ) ) {
			return false;
		}

		if ( (int) $session['expires'] < time() ) {
			return false;
		}

		if ( ! isset( $session['baseline_active'] ) || ! is_array( $session['baseline_active'] ) ) {
			return false;
		}

		if ( ! isset( $session['disabled'] ) || ! is_array( $session['disabled'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Build transient key from token.
	 *
	 * @param string $token Token value.
	 * @return string
	 */
	private function transient_key( $token ) {
		return 'wsm_' . md5( $token );
	}

	/**
	 * Normalize token to strict alphanumeric lowercase.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private function normalize_token( $token ) {
		$token = is_string( $token ) ? $token : '';
		$token = preg_replace( '/[^a-zA-Z0-9]/', '', $token );

		return strtolower( (string) $token );
	}
}
