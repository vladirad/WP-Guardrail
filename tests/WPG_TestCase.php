<?php
/**
 * Base test case for WP Guardrail service-layer tests.
 *
 * @package WPGuardrail
 */

use PHPUnit\Framework\TestCase;

/**
 * Resets all stub state before each test.
 */
abstract class WPG_TestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		wpg_test_reset_state();

		// Clear the per-request session cache in WPG_Container so tests
		// start from a clean container state each time.
		if ( class_exists( 'WPG_Container' ) ) {
			WPG_Container::instance()->clear_session_cache();
		}
	}

	protected function tearDown(): void {
		parent::tearDown();
		wpg_test_reset_state();
	}

	// -------------------------------------------------------------------------
	// Convenience helpers.
	// -------------------------------------------------------------------------

	/**
	 * Write a mock session transient for the given token.
	 *
	 * @param array  $data  Session data.
	 * @param string $token 64-char token (will be normalized internally).
	 * @return string The normalized token used as key input.
	 */
	protected function seed_session( array $data, string $token = 'testtoken123456789012345678901234567890123456789012345678901234' ): string {
		$normalized = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $token ) );
		$key        = 'wsm_' . md5( $normalized );

		$defaults = array(
			'enabled'        => 1,
			'user_id'        => 1,
			'baseline_active'=> array(),
			'disabled'       => array(),
			'created'        => wpg_test_time(),
			'expires'        => wpg_test_time() + 7200,
		);

		set_transient( $key, array_merge( $defaults, $data ), 7200 );
		return $normalized;
	}

	/**
	 * Set the cookie for the given token so WPG_Container can read it.
	 *
	 * @param string $token Normalized token.
	 * @return void
	 */
	protected function set_session_cookie( string $token ): void {
		$_COOKIE['wsm_token'] = $token;
	}

	/**
	 * Clear any session cookie.
	 *
	 * @return void
	 */
	protected function clear_session_cookie(): void {
		unset( $_COOKIE['wsm_token'] );
	}
}
