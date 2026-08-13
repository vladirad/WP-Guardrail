<?php
/**
 * Shareable session snapshots for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles saving and restoring transient-backed snapshots.
 */
class WP_Guardrail_Snapshot {

	/**
	 * Snapshot TTL in seconds (6 hours).
	 */
	const TTL = 21600;

	/**
	 * Create snapshot from current sandbox session.
	 *
	 * @return string|WP_Error Snapshot hash or error.
	 */
	public static function create() {
		$context = WPG_Container::instance();
		$session = $context->get_session();

		if ( false === $session || ! is_array( $session ) ) {
			return new WP_Error( 'snapshot_no_session', __( 'Sandbox Mode must be active to save a snapshot.', 'wp-guardrail' ) );
		}

		$payload = array(
			'disabled'          => isset( $session['disabled'] ) && is_array( $session['disabled'] ) ? $session['disabled'] : array(),
			'wizard'            => isset( $session['wizard'] ) && is_array( $session['wizard'] ) ? $session['wizard'] : array(),
			'url_tests'         => WPG_Result_Store::get_tests(),
			'url_tests_compare' => WPG_Result_Store::get_comparisons(),
			'baseline_active'   => isset( $session['baseline_active'] ) && is_array( $session['baseline_active'] ) ? $session['baseline_active'] : array(),
			'created_at'        => current_time( 'mysql' ),
		);

		$hash = self::generate_hash();
		$ok   = set_transient( self::transient_key( $hash ), $payload, self::TTL );

		if ( ! $ok ) {
			return new WP_Error( 'snapshot_save_failed', __( 'Failed to save snapshot.', 'wp-guardrail' ) );
		}

		return $hash;
	}

	/**
	 * Restore snapshot into the current sandbox session.
	 *
	 * @param string $hash Snapshot hash.
	 * @return true|WP_Error
	 */
	public static function restore( $hash ) {
		$hash = self::sanitize_hash( $hash );
		if ( '' === $hash ) {
			return new WP_Error( 'snapshot_invalid_hash', __( 'Invalid snapshot link.', 'wp-guardrail' ) );
		}

		$snapshot = get_transient( self::transient_key( $hash ) );
		if ( ! is_array( $snapshot ) ) {
			return new WP_Error( 'snapshot_missing', __( 'Snapshot not found or expired.', 'wp-guardrail' ) );
		}

		$context = WPG_Container::instance();
		$session = $context->get_session();

		// Keep core sandbox mechanics unchanged, but ensure a current sandbox session exists.
		if ( false === $session ) {
			WPG_Session_Manager::start();
			$session = $context->get_session();
		}

		if ( false === $session || ! is_array( $session ) ) {
			return new WP_Error( 'snapshot_no_session', __( 'Unable to initialize sandbox session for restore.', 'wp-guardrail' ) );
		}

		$session['disabled']      = isset( $snapshot['disabled'] ) && is_array( $snapshot['disabled'] ) ? $snapshot['disabled'] : array();
		$session['wizard']        = isset( $snapshot['wizard'] ) && is_array( $snapshot['wizard'] ) ? $snapshot['wizard'] : array();
		$session['baseline_active'] = isset( $snapshot['baseline_active'] ) && is_array( $snapshot['baseline_active'] ) ? $snapshot['baseline_active'] : array();

		if ( empty( $session['baseline_active'] ) ) {
			$session['baseline_active'] = get_option( 'active_plugins', array() );
		}

		$ok = $context->update_session( $session );
		if ( ! $ok ) {
			return new WP_Error( 'snapshot_restore_failed', __( 'Failed to restore snapshot.', 'wp-guardrail' ) );
		}

		// Restore URL test history into the per-user result store.
		WPG_Result_Store::set_tests(
			isset( $snapshot['url_tests'] ) && is_array( $snapshot['url_tests'] ) ? $snapshot['url_tests'] : array()
		);
		WPG_Result_Store::set_comparisons(
			isset( $snapshot['url_tests_compare'] ) && is_array( $snapshot['url_tests_compare'] ) ? $snapshot['url_tests_compare'] : array()
		);

		return true;
	}

	/**
	 * Build an admin-only snapshot restore link.
	 *
	 * The link includes a nonce so that the restore action is protected
	 * against CSRF.  The nonce is verified in WPG_Admin::handle_post_actions().
	 *
	 * @param string $hash Snapshot hash.
	 * @return string
	 */
	public static function link( $hash ) {
		$hash = self::sanitize_hash( $hash );
		if ( '' === $hash ) {
			return '';
		}

		return add_query_arg(
			array(
				'page'               => WPG_Admin::SLUG_DASHBOARD,
				'guardrail_snapshot' => $hash,
				'_wpnonce'           => wp_create_nonce( 'wpg_restore_snapshot' ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Generate random snapshot hash.
	 *
	 * @return string
	 */
	private static function generate_hash() {
		return substr( md5( wp_generate_password( 64, true, true ) . microtime( true ) ), 0, 32 );
	}

	/**
	 * Build snapshot transient key.
	 *
	 * @param string $hash Snapshot hash.
	 * @return string
	 */
	private static function transient_key( $hash ) {
		return 'wp_guardrail_snapshot_' . $hash;
	}

	/**
	 * Sanitize snapshot hash.
	 *
	 * @param string $hash Raw hash.
	 * @return string
	 */
	private static function sanitize_hash( $hash ) {
		$hash = is_string( $hash ) ? $hash : '';
		$hash = preg_replace( '/[^a-zA-Z0-9]/', '', $hash );

		return strtolower( substr( $hash, 0, 64 ) );
	}
}
