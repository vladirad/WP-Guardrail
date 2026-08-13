<?php
/**
 * Sandbox / Plugin Isolation report provider for WP Guardrail.
 *
 * Reads the current sandbox session state and produces a normalized
 * report payload for WPG_Report_Builder.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces a normalized report payload from the current sandbox isolation state.
 */
class WPG_Sandbox_Report_Provider {

	/**
	 * Collect and normalize sandbox session data into a report payload.
	 *
	 * @return array Normalized provider payload.
	 */
	public static function collect(): array {
		$context   = WPG_Container::instance();
		$session   = $context->get_session();
		$is_active = false !== $session;
		$session   = is_array( $session ) ? $session : array();

		$baseline = $session['baseline_active'] ?? get_option( 'active_plugins', array() );
		$baseline = is_array( $baseline ) ? $baseline : array();
		$disabled = isset( $session['disabled'] ) && is_array( $session['disabled'] ) ? $session['disabled'] : array();

		$issues  = array();
		$status  = 'ok';

		if ( $is_active && ! empty( $disabled ) ) {
			$flagged = WPG_Safety_Guard::get_flagged_plugins( $disabled );
			if ( ! empty( $flagged ) ) {
				$names    = self::plugin_names( $flagged );
				$issues[] = array(
					'severity' => 'warning',
					'module'   => 'sandbox',
					'title'    => 'Security or authentication plugin(s) are disabled in sandbox',
					'detail'   => 'Currently disabled: ' . implode( ', ', $names ) . '. Monitor your admin session closely and re-enable when done.',
				);
				$status = 'warning';
			} else {
				$status = 'info';
			}
		}

		$disabled_labels = self::plugin_names( $disabled );
		$detail_rows     = array_map(
			fn( $name ) => array( 'label' => 'Disabled in sandbox', 'value' => $name ),
			$disabled_labels
		);

		return array(
			'module'  => 'sandbox',
			'status'  => $status,
			'title'   => 'Plugin Isolation',
			'summary' => array(
				array( 'label' => 'Sandbox Active',   'value' => $is_active ? 'Yes' : 'No',     'severity' => $is_active ? 'warning' : 'neutral' ),
				array( 'label' => 'Disabled Plugins', 'value' => (string) count( $disabled ),   'severity' => count( $disabled ) > 0 ? 'warning' : 'ok' ),
				array( 'label' => 'Baseline Plugins', 'value' => (string) count( $baseline ),   'severity' => 'neutral' ),
			),
			'issues'          => $issues,
			'details'         => $detail_rows,
			'recommendations' => array(),
			'meta'            => array(
				'sandbox_active'   => $is_active,
				'disabled_count'   => count( $disabled ),
				'disabled_plugins' => $disabled,
				'baseline_count'   => count( $baseline ),
			),
		);
	}

	// =========================================================================
	// Private helpers.
	// =========================================================================

	/**
	 * Resolve human-readable plugin names from file paths.
	 *
	 * @param array $plugin_files Plugin file paths.
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
