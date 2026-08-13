<?php
/**
 * Update Simulator report provider for WP Guardrail.
 *
 * Reads the current simulation session state and produces a normalized
 * report payload for WPG_Report_Builder.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces a normalized report payload from Update Simulator run data.
 */
class WPG_Update_Simulator_Report_Provider {

	/**
	 * Collect and normalize Update Simulator data into a report payload.
	 *
	 * @return array Normalized provider payload.
	 */
	public static function collect(): array {
		$simulation = WPG_Update_Simulator::get_simulation();
		$simulation = is_array( $simulation ) ? $simulation : array();

		$active    = ! empty( $simulation['active'] );
		$plugins   = $simulation['plugins']   ?? array();
		$results   = $simulation['results']   ?? array();
		$preflight = $simulation['preflight'] ?? array();
		$cleanup   = $simulation['cleanup']   ?? array();
		$created   = $simulation['created']   ?? '';

		$issues      = array();
		$fatal_count = 0;
		$issue_count = 0;
		$status      = 'ok';

		// Preflight failures.
		if ( is_array( $preflight ) ) {
			foreach ( $preflight as $check ) {
				if ( is_array( $check ) && isset( $check['pass'] ) && ! $check['pass'] ) {
					$issues[] = array(
						'severity' => 'critical',
						'module'   => 'update_simulator',
						'title'    => 'Preflight check failed: ' . ( $check['label'] ?? 'Unknown check' ),
						'detail'   => $check['message'] ?? 'No details available.',
					);
					$status = 'critical';
				}
			}
		}

		// Simulation test results.
		foreach ( $results as $row ) {
			$url     = $row['url']       ?? '';
			$summary = $row['summary']   ?? '';
			$sim     = $row['simulated'] ?? array();
			$fatal   = ! empty( $sim['fatal_suspected'] );

			if ( $fatal ) {
				$fatal_count++;
				$issues[] = array(
					'severity' => 'critical',
					'module'   => 'update_simulator',
					'title'    => 'Fatal error on simulated update for: ' . $url,
					'detail'   => 'A fatal PHP error was detected with the new version loaded. Do not apply this update without resolving this first.',
				);
			} elseif ( 'possible_issues' === $summary ) {
				$issue_count++;
				$issues[] = array(
					'severity' => 'warning',
					'module'   => 'update_simulator',
					'title'    => 'Possible issues on update for: ' . $url,
					'detail'   => 'The simulated version produced a different HTTP response than the baseline.',
				);
			}
		}

		// Cleanup failures.
		$cleanup_failed = is_array( $cleanup ) && ! empty( $cleanup['failed'] );
		if ( $cleanup_failed ) {
			$issues[] = array(
				'severity' => 'warning',
				'module'   => 'update_simulator',
				'title'    => 'Temporary simulation files were not fully cleaned up',
				'detail'   => 'Some temp files from the simulation could not be removed. Use "Clear Leftovers" in the Update Simulator page.',
			);
		}

		if ( 'ok' === $status ) {
			if ( $fatal_count > 0 ) {
				$status = 'critical';
			} elseif ( $issue_count > 0 || $cleanup_failed ) {
				$status = 'warning';
			}
		}

		$plugin_names = self::plugin_names( $plugins );

		return array(
			'module'  => 'update_simulator',
			'status'  => $status,
			'title'   => 'Update Simulator',
			'summary' => array(
				array( 'label' => 'Simulation Active', 'value' => $active ? 'Yes' : 'No',       'severity' => $active ? 'warning' : 'neutral' ),
				array( 'label' => 'URLs Tested',       'value' => (string) count( $results ),   'severity' => 'neutral' ),
				array( 'label' => 'Fatal Errors',      'value' => (string) $fatal_count,        'severity' => $fatal_count  > 0 ? 'critical' : 'ok' ),
				array( 'label' => 'Possible Issues',   'value' => (string) $issue_count,        'severity' => $issue_count  > 0 ? 'warning'  : 'ok' ),
			),
			'issues'          => $issues,
			'details'         => self::build_details( $results, $plugin_names ),
			'recommendations' => array(),
			'meta'            => array(
				'simulation_active' => $active,
				'plugins_tested'    => $plugin_names,
				'created'           => $created,
				'results_count'     => count( $results ),
			),
		);
	}

	// =========================================================================
	// Private helpers.
	// =========================================================================

	/**
	 * Build technical detail rows from simulation results.
	 *
	 * @param array $results      Simulation test results.
	 * @param array $plugin_names Human-readable plugin names.
	 * @return array
	 */
	private static function build_details( array $results, array $plugin_names ): array {
		$rows = array();

		if ( ! empty( $plugin_names ) ) {
			$rows[] = array(
				'label' => 'Plugins simulated',
				'value' => implode( ', ', $plugin_names ),
			);
		}

		foreach ( array_slice( $results, 0, 10 ) as $row ) {
			$url      = $row['url']       ?? '';
			$summary  = $row['summary']   ?? '';
			$baseline = $row['baseline']  ?? array();
			$sim      = $row['simulated'] ?? array();
			$b_code   = (int) ( $baseline['http_code'] ?? 0 );
			$s_code   = (int) ( $sim['http_code']      ?? 0 );
			$b_time   = (int) ( $baseline['time_ms']   ?? 0 );
			$s_time   = (int) ( $sim['time_ms']        ?? 0 );
			$rows[]   = array(
				'label' => $url . ' [' . strtoupper( $summary ) . ']',
				'value' => sprintf(
					'Baseline %d (%d ms)  →  Sim %d (%d ms)',
					$b_code, $b_time, $s_code, $s_time
				),
			);
		}

		return $rows;
	}

	/**
	 * Resolve human-readable names for plugin files.
	 *
	 * @param array $plugin_files Array of plugin file paths.
	 * @return array
	 */
	private static function plugin_names( array $plugin_files ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all   = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$names = array();
		foreach ( $plugin_files as $f ) {
			$names[] = isset( $all[ $f ]['Name'] ) ? $all[ $f ]['Name'] : $f;
		}
		return $names;
	}
}
