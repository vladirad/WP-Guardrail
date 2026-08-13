<?php
/**
 * URL Tester report provider for WP Guardrail.
 *
 * Reads stored comparison and individual test results and produces a
 * normalized report payload for WPG_Report_Builder.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces a normalized report payload from URL Tester run data.
 */
class WPG_URL_Tester_Report_Provider {

	/**
	 * Collect and normalize URL Tester data into a report payload.
	 *
	 * @return array Normalized provider payload.
	 */
	public static function collect(): array {
		$comparisons = WPG_Result_Store::get_results( 'url_comparison' );
		$tests       = WPG_Result_Store::get_results( 'url_test' );

		$issues         = array();
		$fatal_count    = 0;
		$status_changes = 0;

		foreach ( $comparisons as $row ) {
			$url      = $row['url']      ?? '';
			$baseline = $row['baseline'] ?? array();
			$sandbox  = $row['sandbox']  ?? array();

			$b_code  = (int) ( $baseline['http_code']      ?? 0 );
			$s_code  = (int) ( $sandbox['http_code']       ?? 0 );
			$b_fatal = ! empty( $baseline['fatal_suspected'] );
			$s_fatal = ! empty( $sandbox['fatal_suspected'] );

			if ( $s_fatal && ! $b_fatal ) {
				$fatal_count++;
				$issues[] = array(
					'severity' => 'critical',
					'module'   => 'url_tester',
					'title'    => 'Fatal error in sandbox for: ' . $url,
					'detail'   => 'Baseline was clean. The sandbox state caused a fatal error — likely a disabled plugin is required by this URL.',
				);
			} elseif ( $b_fatal ) {
				$issues[] = array(
					'severity' => 'warning',
					'module'   => 'url_tester',
					'title'    => 'Fatal error in baseline for: ' . $url,
					'detail'   => 'The baseline itself returned a fatal error. An existing issue may be present before any sandbox changes.',
				);
			} elseif ( $s_code !== $b_code && $s_code >= 400 ) {
				$status_changes++;
				$issues[] = array(
					'severity' => 'warning',
					'module'   => 'url_tester',
					'title'    => 'HTTP error introduced in sandbox for: ' . $url,
					'detail'   => 'Baseline returned ' . $b_code . ', sandbox returned ' . $s_code . '. The disabled plugin set affects this URL.',
				);
			} elseif ( $s_code !== $b_code ) {
				$status_changes++;
				$issues[] = array(
					'severity' => 'info',
					'module'   => 'url_tester',
					'title'    => 'HTTP status changed for: ' . $url,
					'detail'   => 'Baseline: ' . $b_code . ', Sandbox: ' . $s_code . '.',
				);
			}
		}

		// Scan individual tests for fatals as well.
		$individual_fatals = 0;
		foreach ( $tests as $test ) {
			if ( ! empty( $test['fatal_suspected'] ) ) {
				$individual_fatals++;
			}
		}

		$status = 'ok';
		if ( $fatal_count > 0 ) {
			$status = 'critical';
		} elseif ( $status_changes > 0 || $individual_fatals > 0 ) {
			$status = 'warning';
		}

		return array(
			'module'  => 'url_tester',
			'status'  => $status,
			'title'   => 'URL Tester',
			'summary' => array(
				array( 'label' => 'Comparisons Run',  'value' => (string) count( $comparisons ), 'severity' => 'neutral' ),
				array( 'label' => 'Fatal in Sandbox', 'value' => (string) $fatal_count,          'severity' => $fatal_count > 0    ? 'critical' : 'ok' ),
				array( 'label' => 'Status Changes',   'value' => (string) $status_changes,       'severity' => $status_changes > 0 ? 'warning'  : 'ok' ),
			),
			'issues'          => $issues,
			'details'         => self::build_details( $comparisons ),
			'recommendations' => array(),
			'meta'            => array(
				'comparisons_count'  => count( $comparisons ),
				'tests_count'        => count( $tests ),
				'individual_fatals'  => $individual_fatals,
			),
		);
	}

	// =========================================================================
	// Private helpers.
	// =========================================================================

	/**
	 * Build technical detail rows from comparison results.
	 *
	 * @param array $comparisons Comparison results.
	 * @return array
	 */
	private static function build_details( array $comparisons ): array {
		$rows = array();
		foreach ( array_slice( $comparisons, 0, 10 ) as $row ) {
			$url    = $row['url']      ?? '';
			$b      = $row['baseline'] ?? array();
			$s      = $row['sandbox']  ?? array();
			$b_code = (int) ( $b['http_code'] ?? 0 );
			$s_code = (int) ( $s['http_code']  ?? 0 );
			$b_time = (int) ( $b['time_ms']    ?? 0 );
			$s_time = (int) ( $s['time_ms']    ?? 0 );
			$rows[] = array(
				'label' => $url,
				'value' => sprintf(
					'Baseline %d (%d ms)  →  Sandbox %d (%d ms)',
					$b_code, $b_time, $s_code, $s_time
				),
			);
		}
		return $rows;
	}
}
