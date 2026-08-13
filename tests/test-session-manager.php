<?php
/**
 * Tests for session state management.
 *
 * Tests the session state structure, storage, and retrieval without
 * invoking WordPress HTTP or cookie machinery. We seed transients directly
 * and verify state reads/writes behave correctly.
 *
 * @package WPGuardrail
 */

class Test_Session_Manager extends WPG_TestCase {

	// =========================================================================
	// Session start.
	// =========================================================================

	public function test_start_creates_session_with_required_keys(): void {
		$token = WPG_Session_Manager::start();
		$this->assertNotFalse( $token );

		// start() sets the cookie internally; container can now read the session.
		$session = WPG_Session_Manager::get();
		$this->assertIsArray( $session );
		$this->assertArrayHasKey( 'enabled', $session );
		$this->assertArrayHasKey( 'user_id', $session );
		$this->assertArrayHasKey( 'baseline_active', $session );
		$this->assertArrayHasKey( 'disabled', $session );
		$this->assertArrayHasKey( 'created', $session );
		$this->assertArrayHasKey( 'expires', $session );
	}

	public function test_start_sets_session_cookie(): void {
		WPG_Session_Manager::start();
		$this->assertArrayHasKey( 'wsm_token', $_COOKIE );
		$this->assertNotEmpty( $_COOKIE['wsm_token'] );
	}

	public function test_start_captures_baseline(): void {
		$baseline = array( 'plugin-a/plugin-a.php', 'plugin-b/plugin-b.php' );
		update_option( 'active_plugins', $baseline );
		WPG_Session_Manager::start();

		$session = WPG_Session_Manager::get();
		// start() also injects its own plugin basename into baseline_active.
		$this->assertContains( 'plugin-a/plugin-a.php', $session['baseline_active'] );
		$this->assertContains( 'plugin-b/plugin-b.php', $session['baseline_active'] );
	}

	public function test_start_disabled_list_is_empty_initially(): void {
		WPG_Session_Manager::start();

		$session = WPG_Session_Manager::get();
		$this->assertSame( array(), $session['disabled'] );
	}

	public function test_start_sets_user_id(): void {
		$GLOBALS['_wpg_test_current_uid'] = 42;
		WPG_Session_Manager::start();

		$session = WPG_Session_Manager::get();
		$this->assertSame( 42, $session['user_id'] );
	}

	public function test_start_sets_expires_in_future(): void {
		WPG_Session_Manager::start();

		$session = WPG_Session_Manager::get();
		$this->assertGreaterThan( time(), $session['expires'] );
	}

	// =========================================================================
	// Session apply_disabled.
	// =========================================================================

	public function test_apply_disabled_updates_session(): void {
		$baseline = array( 'plugin-a/plugin-a.php', 'plugin-b/plugin-b.php' );
		update_option( 'active_plugins', $baseline );
		WPG_Session_Manager::start();

		$result = WPG_Session_Manager::apply_disabled( array( 'plugin-b/plugin-b.php' ) );
		$this->assertTrue( $result );

		$session = WPG_Session_Manager::get();
		$this->assertContains( 'plugin-b/plugin-b.php', $session['disabled'] );
		$this->assertNotContains( 'plugin-a/plugin-a.php', $session['disabled'] );
	}

	public function test_apply_disabled_strips_plugins_not_in_baseline(): void {
		$baseline = array( 'plugin-a/plugin-a.php' );
		update_option( 'active_plugins', $baseline );
		WPG_Session_Manager::start();

		// Try to disable a plugin that is not in the baseline.
		WPG_Session_Manager::apply_disabled( array( 'unknown/unknown.php' ) );

		$session = WPG_Session_Manager::get();
		$this->assertNotContains( 'unknown/unknown.php', $session['disabled'] );
	}

	public function test_apply_disabled_returns_false_without_session(): void {
		// No session started — cookie not set.
		$result = WPG_Session_Manager::apply_disabled( array( 'plugin-a/plugin-a.php' ) );
		$this->assertFalse( $result );
	}

	// =========================================================================
	// Session stop / clear.
	// =========================================================================

	public function test_stop_clears_session(): void {
		WPG_Session_Manager::start();
		WPG_Session_Manager::stop();
		WPG_Container::instance()->clear_session_cache();

		$session = WPG_Session_Manager::get();
		$this->assertFalse( $session );
	}

	public function test_stop_clears_cookie(): void {
		WPG_Session_Manager::start();
		WPG_Session_Manager::stop();

		$this->assertArrayNotHasKey( 'wsm_token', $_COOKIE );
	}

	// =========================================================================
	// Session expiry.
	// =========================================================================

	public function test_expired_session_is_not_returned(): void {
		WPG_Session_Manager::start();

		// Advance past the 2-hour TTL. The transient stub honours wpg_test_time()
		// so get_transient() will return false, making get_session() return false.
		wpg_test_advance_time( 7201 );
		WPG_Container::instance()->clear_session_cache();

		$session = WPG_Session_Manager::get();
		$this->assertFalse( $session );
	}

	// =========================================================================
	// Session state structure.
	// =========================================================================

	public function test_session_enabled_flag_is_set(): void {
		WPG_Session_Manager::start();

		$session = WPG_Session_Manager::get();
		$this->assertSame( 1, $session['enabled'] );
	}

	public function test_session_has_no_wizard_state_initially(): void {
		WPG_Session_Manager::start();

		$session = WPG_Session_Manager::get();
		// Wizard key may be absent or set to an empty/inactive state.
		$wizard = $session['wizard'] ?? array();
		$this->assertEmpty( $wizard['active'] ?? false );
	}

	public function test_session_has_no_simulation_initially(): void {
		WPG_Session_Manager::start();

		$session = WPG_Session_Manager::get();
		$sim = $session['simulation'] ?? array();
		$this->assertEmpty( $sim['active'] ?? false );
	}
}