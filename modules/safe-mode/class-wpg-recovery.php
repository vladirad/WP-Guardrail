<?php
/**
 * Emergency sandbox recovery for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates and validates an emergency recovery link that stops the active
 * sandbox session when visited.
 *
 * The link is designed to work even if a disabled plugin has broken normal
 * admin navigation: the recovery handler fires at `init` priority 0 — before
 * any redirect or auth hook registered by the admin application.
 *
 * Token design:
 *  - A 32-character random string is generated via wp_generate_password().
 *  - The raw token is stored in a site option (not a transient) so it
 *    survives the sandbox disrupting the object cache.
 *  - The URL embeds the raw token; timing-safe comparison is used at
 *    validation time.
 *  - Tokens expire after TOKEN_TTL seconds (default 24 hours).
 *  - Each recovery link is single-use: once consumed the option is deleted.
 */
class WPG_Recovery {

	/**
	 * wp_options key for the active recovery token entry.
	 */
	const TOKEN_OPTION = 'wpg_recovery_token';

	/**
	 * Token lifetime in seconds.
	 */
	const TOKEN_TTL = 86400; // 24 hours

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Register the early-init recovery handler.
	 *
	 * Must be called before WordPress's own init hooks run so that the
	 * handler fires at priority 0, ahead of most other code.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_handle_recovery' ), 0 );
	}

	// -------------------------------------------------------------------------
	// Token management
	// -------------------------------------------------------------------------

	/**
	 * Generate a new recovery link, replacing any existing token.
	 *
	 * @return string Absolute recovery URL.
	 */
	public static function generate() {
		$token  = wp_generate_password( 32, false, false );
		$expiry = time() + self::TOKEN_TTL;

		update_option(
			self::TOKEN_OPTION,
			array(
				'token'   => $token,
				'expires' => $expiry,
			),
			false
		);

		return self::build_url( $token );
	}

	/**
	 * Return the current valid recovery URL, or false if no token exists or
	 * the stored token has expired.
	 *
	 * @return string|false
	 */
	public static function get_url() {
		$entry = get_option( self::TOKEN_OPTION, false );

		if ( ! is_array( $entry ) || empty( $entry['token'] ) || empty( $entry['expires'] ) ) {
			return false;
		}

		if ( (int) $entry['expires'] <= time() ) {
			delete_option( self::TOKEN_OPTION );
			return false;
		}

		return self::build_url( (string) $entry['token'] );
	}

	/**
	 * Whether a valid (non-expired) recovery token is currently stored.
	 *
	 * @return bool
	 */
	public static function has_active_token() {
		return false !== self::get_url();
	}

	/**
	 * Delete the stored recovery token.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::TOKEN_OPTION );
	}

	// -------------------------------------------------------------------------
	// Recovery handler
	// -------------------------------------------------------------------------

	/**
	 * Hooked at `init` priority 0.
	 *
	 * Checks for the `wpg_recover` query parameter, validates the token
	 * against the stored entry, stops the sandbox session, and redirects
	 * to the WP Guardrail dashboard with a success notice.
	 *
	 * @return void
	 */
	public static function maybe_handle_recovery() {
		if ( empty( $_GET['wpg_recover'] ) ) {
			return;
		}

		$provided = sanitize_text_field( wp_unslash( $_GET['wpg_recover'] ) );
		if ( '' === $provided ) {
			return;
		}

		$entry = get_option( self::TOKEN_OPTION, false );
		if ( ! is_array( $entry ) || empty( $entry['token'] ) || empty( $entry['expires'] ) ) {
			return;
		}

		if ( (int) $entry['expires'] <= time() ) {
			delete_option( self::TOKEN_OPTION );
			return;
		}

		if ( ! hash_equals( $entry['token'], $provided ) ) {
			return;
		}

		// Token is valid — stop any active sandbox and consume it.
		WPG_Session_Manager::stop();
		self::clear();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => WPG_Admin::SLUG_DASHBOARD,
					'wsm_notice' => 'recovery_applied',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a recovery URL from a raw token.
	 *
	 * @param string $token Raw token string.
	 * @return string
	 */
	private static function build_url( $token ) {
		return add_query_arg(
			array( 'wpg_recover' => rawurlencode( $token ) ),
			admin_url( 'admin.php' )
		);
	}
}
