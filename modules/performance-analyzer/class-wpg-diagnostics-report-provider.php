<?php
/**
 * Diagnostics report provider for WP Guardrail.
 *
 * Runs site performance diagnostics and produces a normalized report
 * payload for WPG_Report_Builder.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces a normalized report payload from site diagnostics metrics.
 */
class WPG_Diagnostics_Report_Provider {

	/**
	 * Collect and normalize diagnostics data into a report payload.
	 *
	 * @return array Normalized provider payload.
	 */
	public static function collect(): array {
		$autoload   = WPG_Diagnostics::get_autoload_stats();
		$cron       = WPG_Diagnostics::get_cron_stats();
		$memory     = WPG_Diagnostics::get_memory_stats();
		$transients = WPG_Diagnostics::get_transient_stats();

		$issues = array();

		// Autoload issues.
		if ( 'critical' === $autoload['status'] ) {
			$issues[] = array(
				'severity' => 'critical',
				'module'   => 'diagnostics',
				'title'    => 'Autoload size critical: ' . WPG_Diagnostics::format_bytes( $autoload['total_bytes'] ),
				'detail'   => 'Very large autoload is executed on every page request, significantly increasing server response time.',
			);
		} elseif ( 'warning' === $autoload['status'] ) {
			$issues[] = array(
				'severity' => 'warning',
				'module'   => 'diagnostics',
				'title'    => 'Autoload size elevated: ' . WPG_Diagnostics::format_bytes( $autoload['total_bytes'] ),
				'detail'   => 'Autoloaded options are approaching a size that could impact performance. Consider auditing large entries.',
			);
		}

		// Cron issues.
		if ( 'critical' === $cron['status'] ) {
			$overdue_names = implode( ', ', array_slice( $cron['overdue_hooks'], 0, 5 ) );
			$issues[]      = array(
				'severity' => 'critical',
				'module'   => 'diagnostics',
				'title'    => 'WP-Cron backlog critical: ' . $cron['overdue_count'] . ' overdue events',
				'detail'   => 'Overdue hooks include: ' . $overdue_names . '. Scheduled tasks are not running on time.',
			);
		} elseif ( 'warning' === $cron['status'] ) {
			$issues[] = array(
				'severity' => 'warning',
				'module'   => 'diagnostics',
				'title'    => 'WP-Cron backlog: ' . $cron['overdue_count'] . ' overdue events',
				'detail'   => 'Some scheduled tasks are overdue. Check that WP-Cron is enabled and functional.',
			);
		}

		// Memory issues.
		if ( 'critical' === $memory['status'] ) {
			$issues[] = array(
				'severity' => 'critical',
				'module'   => 'diagnostics',
				'title'    => 'PHP memory usage critical: ' . $memory['peak_pct'] . '% of limit used',
				'detail'   => 'Peak: ' . WPG_Diagnostics::format_bytes( $memory['peak_bytes'] ) . ' of ' . WPG_Diagnostics::format_bytes( $memory['limit_bytes'] ) . ' limit. Risk of out-of-memory errors.',
			);
		} elseif ( 'warning' === $memory['status'] ) {
			$issues[] = array(
				'severity' => 'warning',
				'module'   => 'diagnostics',
				'title'    => 'PHP memory usage elevated: ' . $memory['peak_pct'] . '% of limit used',
				'detail'   => 'Peak: ' . WPG_Diagnostics::format_bytes( $memory['peak_bytes'] ) . ' of ' . WPG_Diagnostics::format_bytes( $memory['limit_bytes'] ) . ' limit.',
			);
		}

		// Transient count issues.
		if ( 'critical' === $transients['status'] ) {
			$issues[] = array(
				'severity' => 'warning',
				'module'   => 'diagnostics',
				'title'    => 'Transient count very high: ' . number_format( $transients['total_count'] ),
				'detail'   => 'A large number of transients in wp_options may slow down option table queries.',
			);
		}

		// Aggregate overall status.
		$statuses = array( $autoload['status'], $cron['status'], $memory['status'], $transients['status'] );
		$overall  = 'ok';
		foreach ( $statuses as $s ) {
			if ( 'critical' === $s ) {
				$overall = 'critical';
				break;
			}
			if ( 'warning' === $s ) {
				$overall = 'warning';
			}
		}

		return array(
			'module'  => 'diagnostics',
			'status'  => $overall,
			'title'   => 'Site Diagnostics',
			'summary' => array(
				array( 'label' => 'Autoload Size',    'value' => WPG_Diagnostics::format_bytes( $autoload['total_bytes'] ), 'severity' => $autoload['status'] ),
				array( 'label' => 'Cron Overdue',     'value' => (string) $cron['overdue_count'],                           'severity' => $cron['status'] ),
				array( 'label' => 'Memory Peak',      'value' => $memory['peak_pct'] . '%',                                 'severity' => $memory['status'] ),
				array( 'label' => 'Transients',       'value' => (string) $transients['total_count'],                       'severity' => $transients['status'] ),
			),
			'issues'          => $issues,
			'details'         => array(
				array( 'label' => 'Autoload: option count',   'value' => number_format( $autoload['total_count'] ) ),
				array( 'label' => 'Autoload: total size',     'value' => WPG_Diagnostics::format_bytes( $autoload['total_bytes'] ) ),
				array( 'label' => 'Cron: total events',       'value' => (string) $cron['total_events'] ),
				array( 'label' => 'Cron: overdue events',     'value' => (string) $cron['overdue_count'] ),
				array( 'label' => 'Memory: current',          'value' => WPG_Diagnostics::format_bytes( $memory['current_bytes'] ) ),
				array( 'label' => 'Memory: peak',             'value' => WPG_Diagnostics::format_bytes( $memory['peak_bytes'] ) ),
				array( 'label' => 'Memory: limit',            'value' => WPG_Diagnostics::format_bytes( $memory['limit_bytes'] ) ),
				array( 'label' => 'Memory: peak %',           'value' => $memory['peak_pct'] . '%' ),
				array( 'label' => 'Transients: total',        'value' => number_format( $transients['total_count'] ) ),
				array( 'label' => 'Transients: expired',      'value' => number_format( $transients['expired_count'] ) ),
			),
			'recommendations' => array(),
			'meta'            => array(
				'autoload'   => $autoload,
				'cron'       => $cron,
				'memory'     => $memory,
				'transients' => $transients,
			),
		);
	}
}
