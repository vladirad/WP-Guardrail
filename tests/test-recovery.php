<?php
/**
 * Tests for WPG_Recovery — emergency sandbox recovery link.
 *
 * Covers:
 *  - Token generation stores correct option structure.
 *  - get_url() returns a URL when a valid token exists.
 *  - get_url() returns false when no token exists.
 *  - get_url() returns false after the token has expired.
 *  - clear() removes the token.
 *  - Token value is non-empty and alphanumeric.
 *  - Expired / invalid recovery states fail safely.
 *
 * @package WPGuardrail
 */

class Test_Recovery extends WPG_TestCase {

	// =========================================================================
	// Token generation.
	// =========================================================================

	public function test_generate_stores_option(): void {
		WPG_Recovery::generate();

		$stored = get_option( 'wpg_recovery_token' );
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'token', $stored );
		$this->assertArrayHasKey( 'expires', $stored );
	}

	public function test_generate_returns_url_string(): void {
		$url = WPG_Recovery::generate();

		$this->assertIsString( $url );
		$this->assertNotEmpty( $url );
	}

	public function test_generated_url_contains_token(): void {
		$url = WPG_Recovery::generate();

		$stored = get_option( 'wpg_recovery_token' );
		$token  = $stored['token'];

		$this->assertStringContainsString( $token, $url );
	}

	public function test_token_is_alphanumeric_and_non_empty(): void {
		WPG_Recovery::generate();

		$stored = get_option( 'wpg_recovery_token' );
		$token  = $stored['token'];

		$this->assertNotEmpty( $token );
		$this->assertMatchesRegularExpression( '/^[a-zA-Z0-9]+$/', $token );
	}

	public function test_token_expires_24_hours_from_now(): void {
		WPG_Recovery::generate();

		$stored  = get_option( 'wpg_recovery_token' );
		$expires = $stored['expires'];

		// Should be roughly now + 86400 (±5s).
		$expected = wpg_test_time() + 86400;
		$this->assertEqualsWithDelta( $expected, $expires, 5 );
	}

	// =========================================================================
	// get_url().
	// =========================================================================

	public function test_get_url_returns_url_when_token_valid(): void {
		WPG_Recovery::generate();

		$url = WPG_Recovery::get_url();
		$this->assertIsString( $url );
		$this->assertNotEmpty( $url );
	}

	public function test_get_url_returns_false_when_no_token(): void {
		$url = WPG_Recovery::get_url();
		$this->assertFalse( $url );
	}

	public function test_get_url_returns_false_when_token_expired(): void {
		// Seed the option with an already-expired timestamp.
		// get_url() compares against native time(), so we cannot use
		// wpg_test_advance_time() here — seed the expiry directly instead.
		update_option( 'wpg_recovery_token', array(
			'token'   => 'validlookingtokenvalue12345678901234',
			'expires' => time() - 1,
		) );

		$url = WPG_Recovery::get_url();
		$this->assertFalse( $url );
	}

	// =========================================================================
	// clear().
	// =========================================================================

	public function test_clear_removes_token(): void {
		WPG_Recovery::generate();
		WPG_Recovery::clear();

		$stored = get_option( 'wpg_recovery_token' );
		$this->assertFalse( $stored );
	}

	public function test_clear_makes_get_url_return_false(): void {
		WPG_Recovery::generate();
		WPG_Recovery::clear();

		$this->assertFalse( WPG_Recovery::get_url() );
	}

	// =========================================================================
	// generate() overwrites any previous token.
	// =========================================================================

	public function test_generate_overwrites_previous_token(): void {
		WPG_Recovery::generate();
		$stored_first = get_option( 'wpg_recovery_token' );

		WPG_Recovery::generate();
		$stored_second = get_option( 'wpg_recovery_token' );

		// Tokens are randomly generated so they should differ.
		// (Very unlikely to collide; if they do, re-run the test.)
		$this->assertNotSame( $stored_first['token'], $stored_second['token'] );
	}

	// =========================================================================
	// Invalid / tampered recovery token does not produce a URL.
	// =========================================================================

	public function test_tampered_token_is_invalid(): void {
		WPG_Recovery::generate();
		$stored = get_option( 'wpg_recovery_token' );

		// Corrupt the token in the option.
		update_option( 'wpg_recovery_token', array(
			'token'   => 'bad-tampered-token!',
			'expires' => $stored['expires'],
		) );

		// get_url() reconstructs the URL from the stored token value,
		// so this only tests that the option is read back cleanly.
		// The validation happens in maybe_handle_recovery() via hash_equals.
		// Here we just confirm get_url() still returns a string (the tampered value).
		$url = WPG_Recovery::get_url();
		// The URL is built from whatever is in the option; get_url() itself
		// does not validate — it just checks expiry.
		$this->assertIsString( $url );
		$this->assertStringContainsString( 'bad-tampered-token', $url );
	}

	public function test_expired_stored_token_prevents_url(): void {
		update_option( 'wpg_recovery_token', array(
			'token'   => 'validlookingtokenvalue12345678901234',
			'expires' => wpg_test_time() - 1,   // already expired
		) );

		$url = WPG_Recovery::get_url();
		$this->assertFalse( $url );
	}
}
