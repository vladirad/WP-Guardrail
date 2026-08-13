<?php
/**
 * Report persistence layer for WP Guardrail.
 *
 * Saves, retrieves, lists, and deletes normalized report payloads.
 * Reports are stored in wp_options for persistence beyond transient TTLs —
 * they survive plugin deactivation cycles and are not automatically pruned.
 *
 * Storage model:
 *  - Index:   wp_option 'wpg_saved_reports'  → array of report metadata rows.
 *  - Payload: wp_option 'wpg_report_{id}'    → full report array.
 *  - Max stored reports: MAX_REPORTS (oldest auto-deleted on overflow).
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saved report persistence layer.
 */
class WPG_Report_Repository {

	const MAX_REPORTS   = 20;
	const INDEX_OPTION  = 'wpg_saved_reports';
	const REPORT_PREFIX = 'wpg_report_';

	// =========================================================================
	// Public API.
	// =========================================================================

	/**
	 * Save a report.  Auto-trims the index to MAX_REPORTS.
	 *
	 * @param array $report Full normalized report payload (must include 'id').
	 * @return string Report ID on success, empty string on failure.
	 */
	public static function save( array $report ): string {
		$id = sanitize_key( $report['id'] ?? '' );
		if ( '' === $id ) {
			return '';
		}

		// Store full payload.
		update_option( self::REPORT_PREFIX . $id, $report, false );

		// Append to index.
		$index   = self::get_index();
		$index[] = array(
			'id'         => $id,
			'type'       => sanitize_text_field( $report['type']       ?? 'general' ),
			'module'     => sanitize_text_field( $report['module']     ?? '' ),
			'mode'       => sanitize_text_field( $report['mode']       ?? 'summary' ),
			'status'     => sanitize_text_field( $report['status']     ?? 'ok' ),
			'title'      => sanitize_text_field( $report['title']      ?? '' ),
			'created_at' => sanitize_text_field( $report['created_at'] ?? current_time( 'mysql' ) ),
		);

		// Keep newest first.
		usort( $index, fn( $a, $b ) => strcmp( $b['created_at'], $a['created_at'] ) );

		// Trim overflow — delete oldest reports.
		if ( count( $index ) > self::MAX_REPORTS ) {
			$trimmed = array_splice( $index, self::MAX_REPORTS );
			foreach ( $trimmed as $old ) {
				delete_option( self::REPORT_PREFIX . sanitize_key( $old['id'] ) );
			}
		}

		update_option( self::INDEX_OPTION, $index, false );

		return $id;
	}

	/**
	 * Get a single saved report by ID.
	 *
	 * @param string $id Report ID.
	 * @return array|null Full report payload, or null if not found.
	 */
	public static function get( string $id ): ?array {
		$id   = sanitize_key( $id );
		$data = get_option( self::REPORT_PREFIX . $id, null );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * List all saved reports (index metadata only — no full payloads).
	 *
	 * @return array Array of report metadata rows.
	 */
	public static function list(): array {
		return self::get_index();
	}

	/**
	 * Delete a saved report by ID.
	 *
	 * Also removes it from the index and revokes any associated health pages.
	 *
	 * @param string $id Report ID.
	 * @return bool True if deleted (regardless of whether it existed).
	 */
	public static function delete( string $id ): bool {
		$id = sanitize_key( $id );

		delete_option( self::REPORT_PREFIX . $id );

		$index   = self::get_index();
		$updated = array_values( array_filter( $index, fn( $row ) => $row['id'] !== $id ) );
		update_option( self::INDEX_OPTION, $updated, false );

		return true;
	}

	/**
	 * Delete all saved reports and clear the index.
	 *
	 * @return void
	 */
	public static function delete_all(): void {
		$index = self::get_index();
		foreach ( $index as $row ) {
			delete_option( self::REPORT_PREFIX . sanitize_key( $row['id'] ) );
		}
		delete_option( self::INDEX_OPTION );
	}

	// =========================================================================
	// Private helpers.
	// =========================================================================

	/**
	 * Load the report index from wp_options.
	 *
	 * @return array
	 */
	private static function get_index(): array {
		$index = get_option( self::INDEX_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}
}
