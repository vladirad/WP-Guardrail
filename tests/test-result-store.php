<?php
/**
 * Tests for WPG_Result_Store — generalized result persistence layer.
 *
 * Covers:
 *  - Generalized save_result / get_results / clear_results API.
 *  - Legacy wrapper API (append_test, get_tests, etc.).
 *  - Ring buffer capping at MAX_ITEMS.
 *  - Per-user isolation.
 *  - Storage key routing (legacy vs. new types).
 *
 * @package WPGuardrail
 */

class Test_Result_Store extends WPG_TestCase {

	// =========================================================================
	// Generalized API.
	// =========================================================================

	public function test_save_and_get_result(): void {
		$saved = WPG_Result_Store::save_result( 'url_test', array( 'url' => '/foo', 'http_code' => 200 ) );
		$this->assertTrue( $saved );

		$results = WPG_Result_Store::get_results( 'url_test' );
		$this->assertCount( 1, $results );
		$this->assertSame( '/foo', $results[0]['url'] );
	}

	public function test_results_are_newest_first(): void {
		WPG_Result_Store::save_result( 'url_test', array( 'url' => '/first' ) );
		WPG_Result_Store::save_result( 'url_test', array( 'url' => '/second' ) );

		$results = WPG_Result_Store::get_results( 'url_test' );
		$this->assertSame( '/second', $results[0]['url'] );
		$this->assertSame( '/first', $results[1]['url'] );
	}

	public function test_ring_buffer_caps_at_max_items(): void {
		$limit = WPG_Result_Store::MAX_ITEMS;

		for ( $i = 0; $i < $limit + 10; $i++ ) {
			WPG_Result_Store::save_result( 'url_test', array( 'i' => $i ) );
		}

		$results = WPG_Result_Store::get_results( 'url_test' );
		$this->assertCount( $limit, $results );
	}

	public function test_get_results_returns_empty_for_no_user(): void {
		$GLOBALS['_wpg_test_current_uid'] = 0;

		$results = WPG_Result_Store::get_results( 'url_test' );
		$this->assertSame( array(), $results );
	}

	public function test_save_result_returns_false_for_no_user(): void {
		$GLOBALS['_wpg_test_current_uid'] = 0;

		$saved = WPG_Result_Store::save_result( 'url_test', array( 'url' => '/' ) );
		$this->assertFalse( $saved );
	}

	public function test_clear_results_removes_entries(): void {
		WPG_Result_Store::save_result( 'url_test', array( 'url' => '/' ) );
		WPG_Result_Store::clear_results( 'url_test' );

		$results = WPG_Result_Store::get_results( 'url_test' );
		$this->assertSame( array(), $results );
	}

	public function test_set_results_replaces_list(): void {
		WPG_Result_Store::save_result( 'url_test', array( 'url' => '/old' ) );
		WPG_Result_Store::set_results( 'url_test', array(
			array( 'url' => '/new-a' ),
			array( 'url' => '/new-b' ),
		) );

		$results = WPG_Result_Store::get_results( 'url_test' );
		$this->assertCount( 2, $results );
		$this->assertSame( '/new-a', $results[0]['url'] );
	}

	public function test_new_result_type_uses_normalized_key(): void {
		WPG_Result_Store::save_result( 'sim_run', array( 'hash' => 'abc123' ) );

		// Verify the data was stored under the normalized key.
		$raw = get_transient( 'wpg_results_sim_run_1' );
		$this->assertIsArray( $raw );
		$this->assertSame( 'abc123', $raw[0]['hash'] );
	}

	public function test_url_test_type_uses_legacy_key(): void {
		WPG_Result_Store::save_result( 'url_test', array( 'url' => '/legacy' ) );

		// Must be under the legacy key, not the normalized key.
		$legacy = get_transient( 'wpg_url_tests_1' );
		$this->assertIsArray( $legacy );
		$this->assertSame( '/legacy', $legacy[0]['url'] );

		// Must NOT be under the new-format key.
		$new = get_transient( 'wpg_results_url_test_1' );
		$this->assertFalse( $new );
	}

	public function test_results_are_isolated_by_user(): void {
		$GLOBALS['_wpg_test_current_uid'] = 1;
		WPG_Result_Store::save_result( 'url_test', array( 'url' => '/user1' ) );

		$GLOBALS['_wpg_test_current_uid'] = 2;
		WPG_Result_Store::save_result( 'url_test', array( 'url' => '/user2' ) );

		$GLOBALS['_wpg_test_current_uid'] = 1;
		$results = WPG_Result_Store::get_results( 'url_test' );
		$this->assertCount( 1, $results );
		$this->assertSame( '/user1', $results[0]['url'] );
	}

	public function test_get_results_limit_arg(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			WPG_Result_Store::save_result( 'url_test', array( 'i' => $i ) );
		}

		$results = WPG_Result_Store::get_results( 'url_test', array( 'limit' => 3 ) );
		$this->assertCount( 3, $results );
	}

	public function test_meta_is_merged_into_payload(): void {
		WPG_Result_Store::save_result(
			'sim_run',
			array( 'summary' => 'no_issues' ),
			array( 'run_id' => 'xyz' )
		);

		$results = WPG_Result_Store::get_results( 'sim_run' );
		$this->assertSame( 'xyz', $results[0]['run_id'] );
		$this->assertSame( 'no_issues', $results[0]['summary'] );
	}

	// =========================================================================
	// Legacy wrapper API.
	// =========================================================================

	public function test_append_test_and_get_tests(): void {
		WPG_Result_Store::append_test( array( 'url' => '/wp-admin/', 'http_code' => 200 ) );

		$tests = WPG_Result_Store::get_tests();
		$this->assertCount( 1, $tests );
		$this->assertSame( '/wp-admin/', $tests[0]['url'] );
	}

	public function test_set_tests_replaces_history(): void {
		WPG_Result_Store::append_test( array( 'url' => '/old' ) );
		WPG_Result_Store::set_tests( array( array( 'url' => '/restored' ) ) );

		$tests = WPG_Result_Store::get_tests();
		$this->assertCount( 1, $tests );
		$this->assertSame( '/restored', $tests[0]['url'] );
	}

	public function test_clear_tests(): void {
		WPG_Result_Store::append_test( array( 'url' => '/foo' ) );
		WPG_Result_Store::clear_tests();
		$this->assertSame( array(), WPG_Result_Store::get_tests() );
	}

	public function test_append_comparison_and_get_comparisons(): void {
		WPG_Result_Store::append_comparison( array(
			'url'      => '/shop',
			'baseline' => array( 'http_code' => 200 ),
			'sandbox'  => array( 'http_code' => 200 ),
		) );

		$comparisons = WPG_Result_Store::get_comparisons();
		$this->assertCount( 1, $comparisons );
		$this->assertSame( '/shop', $comparisons[0]['url'] );
	}

	public function test_set_comparisons_replaces_history(): void {
		WPG_Result_Store::append_comparison( array( 'url' => '/old' ) );
		WPG_Result_Store::set_comparisons( array( array( 'url' => '/restored' ) ) );

		$comparisons = WPG_Result_Store::get_comparisons();
		$this->assertCount( 1, $comparisons );
		$this->assertSame( '/restored', $comparisons[0]['url'] );
	}

	public function test_clear_comparisons(): void {
		WPG_Result_Store::append_comparison( array( 'url' => '/bar' ) );
		WPG_Result_Store::clear_comparisons();
		$this->assertSame( array(), WPG_Result_Store::get_comparisons() );
	}

	public function test_legacy_types_use_independent_storage(): void {
		WPG_Result_Store::append_test( array( 'url' => '/test' ) );
		WPG_Result_Store::append_comparison( array( 'url' => '/compare' ) );

		$this->assertCount( 1, WPG_Result_Store::get_tests() );
		$this->assertCount( 1, WPG_Result_Store::get_comparisons() );

		WPG_Result_Store::clear_tests();

		$this->assertCount( 0, WPG_Result_Store::get_tests() );
		$this->assertCount( 1, WPG_Result_Store::get_comparisons() );
	}
}
