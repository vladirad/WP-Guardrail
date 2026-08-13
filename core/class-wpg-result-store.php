<?php
/**
 * Generalized result persistence layer for WP Guardrail.
 *
 * Stores named result categories as per-user transients with a 24-hour TTL,
 * independent of the sandbox session lifetime.
 *
 * Storage model:
 *  - Each result type is stored under a separate transient key.
 *  - Results are stored as flat arrays (ring buffers, newest first).
 *  - The ring buffer is capped at MAX_ITEMS entries per type per user.
 *  - Storage keys follow the pattern: wpg_results_{type}_{user_id}
 *    (except for the two legacy types below, which retain their original keys
 *    for backward compatibility with existing stored data).
 *
 * Built-in result types:
 *  - 'url_test'       → wpg_url_tests_{user_id}        (legacy key preserved)
 *  - 'url_comparison' → wpg_url_comparisons_{user_id}  (legacy key preserved)
 *  - 'sim_run'        → wpg_results_sim_run_{user_id}  (new type)
 *
 * Generalized API:
 *  save_result( string $type, array $payload, array $meta = [] ): bool
 *  get_results( string $type, array $args = [] ): array
 *  clear_results( string $type ): void
 *
 * Legacy wrapper API (backward compatible):
 *  append_test(), get_tests(), set_tests(), clear_tests()
 *  append_comparison(), get_comparisons(), set_comparisons(), clear_comparisons()
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generalized per-user result persistence layer.
 */
class WPG_Result_Store {

	/**
	 * Result TTL in seconds (24 hours).
	 */
	const TTL = 86400;

	/**
	 * Maximum number of stored items per list.
	 */
	const MAX_ITEMS = 50;

	/**
	 * Legacy transient key types that retain their original key format.
	 * All other types use the normalized wpg_results_{type}_{user_id} format.
	 */
	private static array $legacy_key_types = array(
		'url_test'       => 'wpg_url_tests_',
		'url_comparison' => 'wpg_url_comparisons_',
	);

	// =========================================================================
	// Generalized API.
	// =========================================================================

	/**
	 * Prepend a result entry to the named result list for the current user.
	 *
	 * @param string $type    Result type slug (e.g. 'url_test', 'sim_run').
	 * @param array  $payload Result data row.
	 * @param array  $meta    Optional metadata merged into the stored row.
	 * @return bool
	 */
	public static function save_result( string $type, array $payload, array $meta = array() ): bool {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( ! empty( $meta ) ) {
			$payload = array_merge( $meta, $payload );
		}

		$key     = self::transient_key( $type, $user_id );
		$results = get_transient( $key );
		$results = is_array( $results ) ? $results : array();

		array_unshift( $results, $payload );
		$results = array_slice( $results, 0, self::MAX_ITEMS );

		return set_transient( $key, $results, self::TTL );
	}

	/**
	 * Get all stored results for the given type and current user.
	 *
	 * Supported $args:
	 *   limit int  — max number of results to return (default: MAX_ITEMS).
	 *
	 * @param string $type Result type slug.
	 * @param array  $args Optional query args.
	 * @return array
	 */
	public static function get_results( string $type, array $args = array() ): array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}

		$results = get_transient( self::transient_key( $type, $user_id ) );
		$results = is_array( $results ) ? $results : array();

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 0;
		if ( $limit > 0 ) {
			$results = array_slice( $results, 0, $limit );
		}

		return $results;
	}

	/**
	 * Replace the full result list for the given type and current user.
	 *
	 * Used by snapshot restore to reinstate a saved state.
	 *
	 * @param string $type    Result type slug.
	 * @param array  $results Result rows.
	 * @return bool
	 */
	public static function set_results( string $type, array $results ): bool {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		$results = array_slice( $results, 0, self::MAX_ITEMS );
		return set_transient( self::transient_key( $type, $user_id ), $results, self::TTL );
	}

	/**
	 * Delete all stored results for the given type and current user.
	 *
	 * @param string $type Result type slug.
	 * @return void
	 */
	public static function clear_results( string $type ): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			delete_transient( self::transient_key( $type, $user_id ) );
		}
	}

	// =========================================================================
	// Legacy wrapper API — backward compatible with pre-0.8.0 call sites.
	// =========================================================================

	/**
	 * Prepend a URL test result to the user's history.
	 *
	 * @param array $result Sanitized result row.
	 * @return bool
	 */
	public static function append_test( array $result ): bool {
		return self::save_result( 'url_test', $result );
	}

	/**
	 * Get all URL test results for the current user.
	 *
	 * @return array
	 */
	public static function get_tests(): array {
		return self::get_results( 'url_test' );
	}

	/**
	 * Replace the full URL test history for the current user.
	 *
	 * @param array $tests Test result rows.
	 * @return bool
	 */
	public static function set_tests( array $tests ): bool {
		return self::set_results( 'url_test', $tests );
	}

	/**
	 * Delete all URL test results for the current user.
	 *
	 * @return void
	 */
	public static function clear_tests(): void {
		self::clear_results( 'url_test' );
	}

	/**
	 * Prepend a comparison result to the user's history.
	 *
	 * @param array $comparison Sanitized comparison row.
	 * @return bool
	 */
	public static function append_comparison( array $comparison ): bool {
		return self::save_result( 'url_comparison', $comparison );
	}

	/**
	 * Get all comparison results for the current user.
	 *
	 * @return array
	 */
	public static function get_comparisons(): array {
		return self::get_results( 'url_comparison' );
	}

	/**
	 * Replace the full comparison history for the current user.
	 *
	 * @param array $results Comparison rows.
	 * @return bool
	 */
	public static function set_comparisons( array $results ): bool {
		return self::set_results( 'url_comparison', $results );
	}

	/**
	 * Delete all comparison results for the current user.
	 *
	 * @return void
	 */
	public static function clear_comparisons(): void {
		self::clear_results( 'url_comparison' );
	}

	// =========================================================================
	// Private helpers.
	// =========================================================================

	/**
	 * Resolve the transient key for a result type and user ID.
	 *
	 * Legacy types use their original key format to preserve existing stored data.
	 * All other types use the normalized wpg_results_{type}_{user_id} format.
	 *
	 * @param string $type    Result type slug.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	private static function transient_key( string $type, int $user_id ): string {
		$type    = sanitize_key( $type );
		$user_id = (int) $user_id;

		if ( isset( self::$legacy_key_types[ $type ] ) ) {
			return self::$legacy_key_types[ $type ] . $user_id;
		}

		return 'wpg_results_' . $type . '_' . $user_id;
	}
}
