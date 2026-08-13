<?php
/**
 * Tests for WPG_Snapshot — shareable session state snapshots.
 *
 * Covers:
 *  - Snapshot creation stores expected structure.
 *  - Snapshot retrieval by hash returns the stored data.
 *  - Restoring a snapshot writes state to the result store.
 *  - Snapshots expire after their TTL.
 *  - link() returns a non-empty URL for a valid hash.
 *  - link() returns '' for an unknown hash.
 *
 * WPG_Snapshot depends on WPG_Container for session reads and WPG_Result_Store
 * for URL test data. We seed these via helper methods.
 *
 * @package WPGuardrail
 */

class Test_Snapshot extends WPG_TestCase {

	// =========================================================================
	// create().
	// =========================================================================

	public function test_create_returns_hash_string_when_session_active(): void {
		$token = $this->seed_session( array(
			'disabled'        => array( 'plugin-a/plugin-a.php' ),
			'baseline_active' => array( 'plugin-a/plugin-a.php', 'plugin-b/plugin-b.php' ),
		) );
		$this->set_session_cookie( $token );
		WPG_Container::instance()->clear_session_cache();

		$hash = WP_Guardrail_Snapshot::create();
		$this->assertIsString( $hash );
		$this->assertNotEmpty( $hash );
	}

	public function test_create_returns_wp_error_without_session(): void {
		$this->clear_session_cookie();

		$hash = WP_Guardrail_Snapshot::create();
		$this->assertInstanceOf( WP_Error::class, $hash );
	}

	public function test_create_stores_disabled_plugins(): void {
		$token = $this->seed_session( array(
			'disabled' => array( 'plugin-a/plugin-a.php' ),
		) );
		$this->set_session_cookie( $token );
		WPG_Container::instance()->clear_session_cache();

		$hash = WP_Guardrail_Snapshot::create();
		$data = WP_Guardrail_Snapshot::get( $hash );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'disabled', $data );
		$this->assertContains( 'plugin-a/plugin-a.php', $data['disabled'] );
	}

	public function test_create_stores_baseline(): void {
		$baseline = array( 'plugin-a/plugin-a.php', 'plugin-b/plugin-b.php' );
		$token    = $this->seed_session( array( 'baseline_active' => $baseline ) );
		$this->set_session_cookie( $token );
		WPG_Container::instance()->clear_session_cache();

		$hash = WP_Guardrail_Snapshot::create();
		$data = WP_Guardrail_Snapshot::get( $hash );

		$this->assertSame( $baseline, $data['baseline'] );
	}

	public function test_create_includes_url_tests_from_result_store(): void {
		// Seed URL tests.
		WPG_Result_Store::append_test( array( 'url' => '/test-page', 'http_code' => 200 ) );

		$token = $this->seed_session( array() );
		$this->set_session_cookie( $token );
		WPG_Container::instance()->clear_session_cache();

		$hash = WP_Guardrail_Snapshot::create();
		$data = WP_Guardrail_Snapshot::get( $hash );

		$this->assertIsArray( $data['url_tests'] );
		$this->assertCount( 1, $data['url_tests'] );
		$this->assertSame( '/test-page', $data['url_tests'][0]['url'] );
	}

	// =========================================================================
	// get().
	// =========================================================================

	public function test_get_returns_false_for_unknown_hash(): void {
		$data = WP_Guardrail_Snapshot::get( 'nonexistenthash12345' );
		$this->assertFalse( $data );
	}

	public function test_get_returns_false_after_expiry(): void {
		$token = $this->seed_session( array() );
		$this->set_session_cookie( $token );
		WPG_Container::instance()->clear_session_cache();

		$hash = WP_Guardrail_Snapshot::create();

		// Advance past 6-hour TTL.
		wpg_test_advance_time( 21601 );

		$data = WP_Guardrail_Snapshot::get( $hash );
		$this->assertFalse( $data );
	}

	// =========================================================================
	// restore().
	// =========================================================================

	public function test_restore_writes_url_tests_to_result_store(): void {
		// Seed a snapshot manually with url_tests.
		$hash = str_repeat( 'a', 32 );
		set_transient( 'wp_guardrail_snapshot_' . $hash, array(
			'disabled'        => array(),
			'baseline'        => array(),
			'wizard'          => array(),
			'url_tests'       => array( array( 'url' => '/restored', 'http_code' => 200 ) ),
			'url_tests_compare' => array(),
		), 21600 );

		// Need a valid session to restore into.
		$token = $this->seed_session( array() );
		$this->set_session_cookie( $token );
		WPG_Container::instance()->clear_session_cache();

		$result = WP_Guardrail_Snapshot::restore( $hash );
		$this->assertTrue( $result );

		$tests = WPG_Result_Store::get_tests();
		$this->assertCount( 1, $tests );
		$this->assertSame( '/restored', $tests[0]['url'] );
	}

	public function test_restore_returns_wp_error_for_invalid_hash(): void {
		$token = $this->seed_session( array() );
		$this->set_session_cookie( $token );
		WPG_Container::instance()->clear_session_cache();

		$result = WP_Guardrail_Snapshot::restore( 'invaliddoesnotexist' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_restore_applies_disabled_plugins(): void {
		$hash = str_repeat( 'b', 32 );
		set_transient( 'wp_guardrail_snapshot_' . $hash, array(
			'disabled'          => array( 'plugin-x/plugin-x.php' ),
			'baseline'          => array( 'plugin-x/plugin-x.php' ),
			'wizard'            => array(),
			'url_tests'         => array(),
			'url_tests_compare' => array(),
		), 21600 );

		$token = $this->seed_session( array(
			'baseline_active' => array( 'plugin-x/plugin-x.php' ),
		) );
		$this->set_session_cookie( $token );
		WPG_Container::instance()->clear_session_cache();

		WP_Guardrail_Snapshot::restore( $hash );

		// Clear cache so container re-reads the updated session.
		WPG_Container::instance()->clear_session_cache();
		$session = WPG_Container::instance()->get_session();

		$this->assertContains( 'plugin-x/plugin-x.php', $session['disabled'] );
	}

	// =========================================================================
	// link().
	// =========================================================================

	public function test_link_returns_url_for_valid_hash(): void {
		$token = $this->seed_session( array() );
		$this->set_session_cookie( $token );
		WPG_Container::instance()->clear_session_cache();

		$hash = WP_Guardrail_Snapshot::create();
		$url  = WP_Guardrail_Snapshot::link( $hash );

		$this->assertIsString( $url );
		$this->assertNotEmpty( $url );
		$this->assertStringContainsString( $hash, $url );
	}

	public function test_link_returns_empty_for_unknown_hash(): void {
		$url = WP_Guardrail_Snapshot::link( 'unknownhash' );
		$this->assertSame( '', $url );
	}
}
