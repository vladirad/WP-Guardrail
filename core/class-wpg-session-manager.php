<?php
/**
 * Session management for WP Guardrail.
 *
 * @package WPSandboxMode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the safe mode session lifecycle.
 */
class WPG_Session_Manager {

	const COOKIE = 'wsm_token';
	const QP     = 'wsm_token';
	const TTL    = 7200;

	/**
	 * Capture token from query string, set cookie, then redirect without token.
	 *
	 * @return void
	 */
	public static function maybe_capture_query_token() {
		if ( ! isset( $_GET[ self::QP ] ) ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ self::QP ] ) );
		$token = self::normalize_token( $token );

		if ( $token && self::is_allowed() ) {
			$session = self::get_by_token( $token );

			if ( self::is_session_valid( $session ) ) {
				self::set_cookie( $token );
			}
		}

		$redirect = remove_query_arg( self::QP );
		if ( ! headers_sent() ) {
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * Get current session token from cookie.
	 *
	 * @return string
	 */
	public static function token() {
		return WPG_Container::instance()->get_token();
	}

	/**
	 * Check whether current user can use safe mode.
	 *
	 * @return bool
	 */
	public static function is_allowed() {
		return WPG_Container::instance()->is_allowed();
	}

	/**
	 * Get active safe mode session for current user.
	 *
	 * @return array|false
	 */
	public static function get() {
		return WPG_Container::instance()->get_session();
	}

	/**
	 * Set raw session data by token.
	 *
	 * @param string $token   Session token.
	 * @param array  $session Session data.
	 * @return bool
	 */
	public static function set( $token, $session ) {
		$token = self::normalize_token( $token );
		if ( '' === $token || ! is_array( $session ) ) {
			return false;
		}

		return set_transient( self::transient_key( $token ), $session, self::TTL );
	}

	/**
	 * Start safe mode for the current user.
	 *
	 * @return string|false
	 */
	public static function start() {
		if ( ! self::is_allowed() ) {
			return false;
		}

		$token   = self::normalize_token( wp_generate_password( 64, false, false ) );
		$user_id = get_current_user_id();
		$now     = time();
		$self    = WPG_Bootstrap::self_plugin_basename();

		$baseline = self::get_baseline_active_plugins();
		$baseline = is_array( $baseline ) ? $baseline : array();

		// Fallback to current active plugins if baseline source is empty/corrupted.
		if ( empty( $baseline ) ) {
			$baseline = get_option( 'active_plugins', array() );
			$baseline = is_array( $baseline ) ? $baseline : array();
		}

		$baseline = array_map( 'sanitize_text_field', $baseline );
		$baseline = array_values( array_unique( $baseline ) );

		// This plugin must stay active in safe mode.
		if ( ! in_array( $self, $baseline, true ) ) {
			$baseline[] = $self;
		}

		$session = array(
			'enabled'         => 1,
			'user_id'         => (int) $user_id,
			'baseline_active' => array_values( $baseline ),
			'disabled'        => array(),
			'created'         => $now,
			'expires'         => $now + self::TTL,
		);

		self::set( $token, $session );
		self::set_cookie( $token );

		return $token;
	}

	/**
	 * Stop safe mode for the current user.
	 *
	 * @return void
	 */
	public static function stop() {
		$token = self::token();

		if ( '' !== $token ) {
			$session = self::get_by_token( $token );
			if ( is_array( $session ) && isset( $session['simulation'] ) && is_array( $session['simulation'] ) && ! empty( $session['simulation']['active'] ) ) {
				$hash = isset( $session['simulation']['hash'] ) ? sanitize_key( (string) $session['simulation']['hash'] ) : '';
				if ( '' !== $hash && class_exists( 'WPG_Update_Simulator' ) ) {
					WPG_Update_Simulator::cleanup_simulation_artifacts( $hash );
				}
				unset( $session['simulation'] );
				self::set( $token, $session );
			}

			delete_transient( self::transient_key( $token ) );
		}

		self::clear_cookie();
	}

	/**
	 * Update disabled plugins for current session.
	 *
	 * @param array $disabled Disabled plugin list.
	 * @return bool
	 */
	public static function apply_disabled( $disabled ) {
		$context = WPG_Container::instance();
		$session = $context->get_session();
		if ( false === $session ) {
			return false;
		}

		$self = WPG_Bootstrap::self_plugin_basename();
		$base = isset( $session['baseline_active'] ) && is_array( $session['baseline_active'] )
			? $session['baseline_active']
			: array();

		$base = array_map( 'sanitize_text_field', $base );
		$base = array_values( array_unique( $base ) );

		$disabled = is_array( $disabled ) ? $disabled : array();
		$disabled = array_map( 'sanitize_text_field', $disabled );
		$disabled = array_intersect( $disabled, $base );
		$disabled = array_diff( $disabled, array( $self ) );
		$disabled = array_values( array_unique( $disabled ) );

		$session['disabled'] = $disabled;

		return $context->update_session( $session );
	}

	/**
	 * Build baseline active plugins excluding this plugin.
	 *
	 * @return array
	 */
	private static function get_baseline_active_plugins() {
		$active = get_option( 'active_plugins', array() );
		$active = is_array( $active ) ? $active : array();
		$self   = WPG_Bootstrap::self_plugin_basename();

		$active = array_map( 'sanitize_text_field', $active );
		$active = array_diff( $active, array( $self ) );

		return array_values( array_unique( $active ) );
	}

	/**
	 * Fetch raw session by token.
	 *
	 * @param string $token Token value.
	 * @return array|false
	 */
	private static function get_by_token( $token ) {
		$token = self::normalize_token( $token );
		if ( '' === $token ) {
			return false;
		}

		$session = get_transient( self::transient_key( $token ) );
		return is_array( $session ) ? $session : false;
	}

	/**
	 * Validate raw session structure and expiration.
	 *
	 * @param array|false $session Session data.
	 * @return bool
	 */
	private static function is_session_valid( $session ) {
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
	 * Set safe mode cookie.
	 *
	 * @param string $token Token to set.
	 * @return void
	 */
	private static function set_cookie( $token ) {
		if ( headers_sent() ) {
			return;
		}

		$secure   = is_ssl();
		$http_only = true;
		$expire   = time() + self::TTL;
		$path     = COOKIEPATH ? COOKIEPATH : '/';

		setcookie( self::COOKIE, $token, $expire, $path, COOKIE_DOMAIN, $secure, $http_only );
		$_COOKIE[ self::COOKIE ] = $token;
	}

	/**
	 * Clear safe mode cookie.
	 *
	 * @return void
	 */
	private static function clear_cookie() {
		if ( headers_sent() ) {
			return;
		}

		$path = COOKIEPATH ? COOKIEPATH : '/';
		setcookie( self::COOKIE, '', time() - HOUR_IN_SECONDS, $path, COOKIE_DOMAIN );
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Get transient key for a token.
	 *
	 * @param string $token Token value.
	 * @return string
	 */
	private static function transient_key( $token ) {
		return 'wsm_' . md5( $token );
	}

	/**
	 * Normalize token string.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private static function normalize_token( $token ) {
		$token = is_string( $token ) ? $token : '';
		$token = preg_replace( '/[^a-zA-Z0-9]/', '', $token );
		return strtolower( $token );
	}
}
