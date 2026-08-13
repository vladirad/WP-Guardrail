<?php
/**
 * Central report builder for WP Guardrail.
 *
 * Orchestrates building a normalized report payload from one or more
 * module report providers.  Supports summary and technical modes.
 *
 * Concepts:
 *  - Run      : raw result stored by WPG_Result_Store (already exists).
 *  - Report   : normalized, formatted output built from one or more runs.
 *  - Health Page: a published, protected, local view of a saved report.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds module and general reports from provider data.
 */
class WPG_Report_Builder {

	/**
	 * Severity rank map for aggregation comparisons.
	 *
	 * @var array<string,int>
	 */
	private static array $severity_rank = array(
		'ok'       => 0,
		'info'     => 1,
		'warning'  => 2,
		'critical' => 3,
	);

	// =========================================================================
	// Public API.
	// =========================================================================

	/**
	 * Build a single-module report.
	 *
	 * @param string $module Module slug: 'url_tester'|'conflict'|'update_simulator'|'diagnostics'|'sandbox'.
	 * @param string $mode   'summary' or 'technical'.
	 * @return array Normalized report payload.
	 */
	public static function build_module_report( string $module, string $mode = 'summary' ): array {
		$provider = self::get_provider( $module );
		if ( ! $provider ) {
			return self::empty_report( $module, $mode );
		}

		$payload = $provider::collect();
		return self::normalize( $payload, $module, $mode );
	}

	/**
	 * Build a general (all-modules) combined report.
	 *
	 * @param string $mode 'summary' or 'technical'.
	 * @return array Normalized report payload.
	 */
	public static function build_general_report( string $mode = 'summary' ): array {
		global $wp_version;

		$modules        = array( 'url_tester', 'conflict', 'update_simulator', 'diagnostics', 'sandbox' );
		$sections       = array();
		$all_issues     = array();
		$overall_status = 'ok';

		foreach ( $modules as $module ) {
			$provider = self::get_provider( $module );
			if ( ! $provider ) {
				continue;
			}

			$payload    = $provider::collect();
			$mod_status = $payload['status'] ?? 'ok';

			// Aggregate issues.
			if ( ! empty( $payload['issues'] ) ) {
				foreach ( $payload['issues'] as $issue ) {
					$issue['module'] = $issue['module'] ?? $module;
					$all_issues[]    = $issue;
				}
			}

			// Track worst overall status.
			if ( self::rank( $mod_status ) > self::rank( $overall_status ) ) {
				$overall_status = $mod_status;
			}

			// Collect one section per module.
			$sections[] = array(
				'title'   => $payload['title']   ?? ucwords( str_replace( '_', ' ', $module ) ),
				'status'  => $mod_status,
				'module'  => $module,
				'summary' => $payload['summary'] ?? array(),
				'details' => ( 'technical' === $mode ) ? ( $payload['details'] ?? array() ) : array(),
			);
		}

		// Sort issues: critical first.
		usort( $all_issues, function ( $a, $b ) {
			return self::rank( $b['severity'] ?? 'info' ) <=> self::rank( $a['severity'] ?? 'info' );
		} );

		$recommendations = self::generate_recommendations( $all_issues );
		$theme           = wp_get_theme();

		return array(
			'id'              => self::generate_id(),
			'type'            => 'general',
			'module'          => 'general',
			'mode'            => $mode,
			'status'          => $overall_status,
			'title'           => 'WP Guardrail — Site Health Report',
			'created_at'      => current_time( 'mysql' ),
			'sources'         => $modules,
			'summary'         => self::general_summary_tiles( $sections, $all_issues ),
			'sections'        => $sections,
			'issues'          => $all_issues,
			'recommendations' => $recommendations,
			'meta'            => array(
				'wp_version'   => sanitize_text_field( (string) $wp_version ),
				'php_version'  => sanitize_text_field( phpversion() ?: '' ),
				'active_theme' => sanitize_text_field( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ),
			),
		);
	}

	/**
	 * Generate actionable recommendations from an issue list.
	 *
	 * Rule-based: maps known issue patterns to recommended actions.
	 *
	 * @param array $issues Normalized issue array.
	 * @return array
	 */
	public static function generate_recommendations( array $issues ): array {
		$recs = array();

		foreach ( $issues as $issue ) {
			$title    = $issue['title']    ?? '';
			$module   = $issue['module']   ?? '';
			$severity = $issue['severity'] ?? 'info';

			if ( 'update_simulator' === $module && 'critical' === $severity ) {
				$recs[] = array(
					'priority' => 'high',
					'title'    => 'Do not deploy this update',
					'action'   => 'The update simulation detected a critical failure. Review the detailed results before applying any updates.',
				);
			} elseif ( 'conflict' === $module && in_array( $severity, array( 'critical', 'warning' ), true ) ) {
				$recs[] = array(
					'priority' => 'high',
					'title'    => 'Isolate the conflicting plugin',
					'action'   => 'Use Plugin Isolation to disable the suspected plugin. Confirm the issue resolves, then contact the plugin author.',
				);
			} elseif ( 'diagnostics' === $module && false !== stripos( $title, 'autoload' ) ) {
				$recs[] = array(
					'priority' => 'medium',
					'title'    => 'Review autoloaded options',
					'action'   => 'Identify and clean up large autoloaded options. Plugins storing large data in wp_options on every page load slow down your site.',
				);
			} elseif ( 'diagnostics' === $module && false !== stripos( $title, 'cron' ) ) {
				$recs[] = array(
					'priority' => 'medium',
					'title'    => 'Investigate WP-Cron backlog',
					'action'   => 'A large cron backlog indicates scheduled tasks are not running. Check that WP-Cron is enabled and not blocked by server configuration.',
				);
			} elseif ( 'url_tester' === $module && 'critical' === $severity ) {
				$recs[] = array(
					'priority' => 'high',
					'title'    => 'Investigate fatal errors on tested URLs',
					'action'   => 'A fatal error was detected during URL testing. Check error logs and disable recently activated plugins to isolate the cause.',
				);
			} elseif ( 'update_simulator' === $module && false !== stripos( $title, 'cleanup' ) ) {
				$recs[] = array(
					'priority' => 'low',
					'title'    => 'Clean up simulation leftovers',
					'action'   => 'Go to Update Simulator and use the "Clear Leftovers" action to remove stale temporary files.',
				);
			} elseif ( 'sandbox' === $module && 'warning' === $severity ) {
				$recs[] = array(
					'priority' => 'medium',
					'title'    => 'Review sandbox plugin selection',
					'action'   => 'Security, authentication, or caching plugins are currently disabled. Restore them once your testing is complete.',
				);
			}
		}

		// Deduplicate by title.
		$seen  = array();
		$dedup = array();
		foreach ( $recs as $rec ) {
			$key = $rec['title'];
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$dedup[]      = $rec;
			}
		}

		return $dedup;
	}

	// =========================================================================
	// Private helpers.
	// =========================================================================

	/**
	 * Resolve provider class name for a module slug.
	 *
	 * @param string $module Module slug.
	 * @return string|null Provider class name or null if not available.
	 */
	private static function get_provider( string $module ): ?string {
		$map = array(
			'url_tester'       => 'WPG_URL_Tester_Report_Provider',
			'conflict'         => 'WPG_Conflict_Report_Provider',
			'update_simulator' => 'WPG_Update_Simulator_Report_Provider',
			'diagnostics'      => 'WPG_Diagnostics_Report_Provider',
			'sandbox'          => 'WPG_Sandbox_Report_Provider',
		);

		$class = $map[ $module ] ?? null;
		return ( $class && class_exists( $class ) ) ? $class : null;
	}

	/**
	 * Normalize a raw provider payload into the saved report structure.
	 *
	 * @param array  $payload Raw provider data.
	 * @param string $module  Module slug.
	 * @param string $mode    'summary' or 'technical'.
	 * @return array
	 */
	private static function normalize( array $payload, string $module, string $mode ): array {
		global $wp_version;

		$issues          = $payload['issues'] ?? array();
		$recommendations = self::generate_recommendations( $issues );
		$theme           = wp_get_theme();

		// In summary mode, omit raw detail rows from sections.
		$sections = ( 'technical' === $mode ) ? ( $payload['details'] ?? array() ) : array();

		return array(
			'id'              => self::generate_id(),
			'type'            => 'module',
			'module'          => $module,
			'mode'            => $mode,
			'status'          => $payload['status']  ?? 'ok',
			'title'           => $payload['title']   ?? ucwords( str_replace( '_', ' ', $module ) ) . ' Report',
			'created_at'      => current_time( 'mysql' ),
			'sources'         => array( $module ),
			'summary'         => $payload['summary'] ?? array(),
			'sections'        => $sections,
			'issues'          => $issues,
			'recommendations' => $recommendations,
			'meta'            => array_merge(
				array(
					'wp_version'   => sanitize_text_field( (string) $wp_version ),
					'php_version'  => sanitize_text_field( phpversion() ?: '' ),
					'active_theme' => sanitize_text_field( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ),
				),
				$payload['meta'] ?? array()
			),
		);
	}

	/**
	 * Generate a unique report ID.
	 *
	 * @return string
	 */
	private static function generate_id(): string {
		return 'wpg_' . substr( md5( uniqid( '', true ) ), 0, 12 );
	}

	/**
	 * Return an empty report skeleton.
	 *
	 * @param string $module Module slug.
	 * @param string $mode   Mode.
	 * @return array
	 */
	private static function empty_report( string $module, string $mode ): array {
		return array(
			'id'              => self::generate_id(),
			'type'            => 'module',
			'module'          => $module,
			'mode'            => $mode,
			'status'          => 'ok',
			'title'           => ucwords( str_replace( '_', ' ', $module ) ) . ' Report',
			'created_at'      => current_time( 'mysql' ),
			'sources'         => array( $module ),
			'summary'         => array(),
			'sections'        => array(),
			'issues'          => array(),
			'recommendations' => array(),
			'meta'            => array(),
		);
	}

	/**
	 * Return severity rank integer for comparison.
	 *
	 * @param string $severity Severity slug.
	 * @return int
	 */
	private static function rank( string $severity ): int {
		return self::$severity_rank[ $severity ] ?? 0;
	}

	/**
	 * Build summary tiles for a general report.
	 *
	 * @param array $sections Module sections.
	 * @param array $issues   All issues across modules.
	 * @return array
	 */
	private static function general_summary_tiles( array $sections, array $issues ): array {
		$critical_count = 0;
		$warning_count  = 0;

		foreach ( $issues as $issue ) {
			$s = $issue['severity'] ?? 'info';
			if ( 'critical' === $s ) {
				$critical_count++;
			} elseif ( 'warning' === $s ) {
				$warning_count++;
			}
		}

		$module_statuses = array_column( $sections, 'status' );
		$healthy_count   = count( array_filter( $module_statuses, fn( $s ) => 'ok' === $s ) );
		$total_modules   = count( $sections );

		return array(
			array(
				'label'    => 'Modules Healthy',
				'value'    => $healthy_count . '/' . $total_modules,
				'severity' => ( $healthy_count === $total_modules ) ? 'ok' : 'warning',
			),
			array(
				'label'    => 'Critical Issues',
				'value'    => (string) $critical_count,
				'severity' => $critical_count > 0 ? 'critical' : 'ok',
			),
			array(
				'label'    => 'Warnings',
				'value'    => (string) $warning_count,
				'severity' => $warning_count > 0 ? 'warning' : 'ok',
			),
		);
	}
}
