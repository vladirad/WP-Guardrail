<?php
/**
 * Conflict Detector report provider for WP Guardrail.
 *
 * Reads the current Conflict Wizard session state and produces a
 * normalized report payload for WPG_Report_Builder.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces a normalized report payload from Conflict Wizard run data.
 */
class WPG_Conflict_Report_Provider {

	/**
	 * Collect and normalize Conflict Wizard data into a report payload.
	 *
	 * @return array Normalized provider payload.
	 */
	public static function collect(): array {
		$wizard = WPG_Conflict_Wizard::get_state();
		$wizard = is_array( $wizard ) ? $wizard : array();

		$active       = ! empty( $wizard['active'] );
		$history      = $wizard['history']           ?? array();
		$pool         = $wizard['plugins_pool']       ?? array();
		$step         = (int) ( $wizard['step']       ?? 0 );
		$initial_size = (int) ( $wizard['initial_pool_size'] ?? 0 );
		$state_error  = $wizard['state_error']        ?? '';

		// Wizard narrowed down to ≤ 2 plugins with completed steps = likely conflict.
		$likely = array();
		if ( ! empty( $history ) && count( $pool ) <= 2 ) {
			$likely = $pool;
		}

		$issues = array();
		$status = 'ok';

		if ( ! empty( $state_error ) ) {
			$issues[] = array(
				'severity' => 'warning',
				'module'   => 'conflict',
				'title'    => 'Conflict Wizard ended with an error',
				'detail'   => 'Error state: ' . $state_error . '. Restart the wizard to run a new isolation.',
			);
			$status = 'warning';
		}

		if ( ! empty( $likely ) ) {
			$names = self::plugin_names( $likely );
			$issues[] = array(
				'severity' => 'critical',
				'module'   => 'conflict',
				'title'    => 'Likely conflicting plugin(s) identified: ' . implode( ', ', $names ),
				'detail'   => 'The wizard narrowed the conflict to these plugin(s). Isolate them individually to confirm, then contact the plugin author.',
			);
			$status = 'critical';
		} elseif ( $active && $step > 0 ) {
			$estimated = $initial_size > 0 ? (int) ceil( log( $initial_size ) / log( 2 ) ) : 0;
			$issues[]  = array(
				'severity' => 'info',
				'module'   => 'conflict',
				'title'    => 'Conflict Wizard in progress (step ' . $step . ' of ~' . $estimated . ')',
				'detail'   => 'Continue the wizard to narrow down the conflicting plugin.',
			);
		}

		$steps_done = count( array_filter( $history, fn( $h ) => ( $h['event'] ?? '' ) === 'decision' ) );

		return array(
			'module'  => 'conflict',
			'status'  => $status,
			'title'   => 'Conflict Detector',
			'summary' => array(
				array( 'label' => 'Wizard Active',   'value' => $active ? 'Yes' : 'No',           'severity' => $active ? 'warning' : 'neutral' ),
				array( 'label' => 'Steps Completed', 'value' => (string) $steps_done,              'severity' => 'neutral' ),
				array( 'label' => 'Suspects Found',  'value' => (string) count( $likely ),         'severity' => count( $likely ) > 0 ? 'critical' : 'ok' ),
			),
			'issues'          => $issues,
			'details'         => self::build_history_details( $history ),
			'recommendations' => array(),
			'meta'            => array(
				'wizard_active'     => $active,
				'step'              => $step,
				'initial_pool_size' => $initial_size,
				'likely_plugins'    => $likely,
			),
		);
	}

	// =========================================================================
	// Private helpers.
	// =========================================================================

	/**
	 * Build technical detail rows from wizard history.
	 *
	 * @param array $history Wizard history.
	 * @return array
	 */
	private static function build_history_details( array $history ): array {
		$rows = array();
		foreach ( $history as $entry ) {
			$event     = $entry['event']      ?? '';
			$step      = $entry['step']       ?? 0;
			$pool_size = $entry['pool_count'] ?? 0;
			$timestamp = $entry['timestamp']  ?? '';
			$rows[]    = array(
				'label' => 'Step ' . $step . ' — ' . $event,
				'value' => 'Pool remaining: ' . $pool_size . ( $timestamp ? '  (' . $timestamp . ')' : '' ),
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
