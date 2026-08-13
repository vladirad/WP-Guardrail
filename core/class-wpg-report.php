<?php
/**
 * Diagnostic report builder for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds text/JSON diagnostic reports from current sandbox session.
 */
class WP_Guardrail_Report {

	/**
	 * Build report data and text output.
	 *
	 * @return array
	 */
	public static function build() {
		global $wp_version;

		$context  = WPG_Container::instance();
		$session  = $context->get_session();
		$is_active = false !== $session;
		$session  = is_array( $session ) ? $session : array();
		$theme    = wp_get_theme();
		$baseline = isset( $session['baseline_active'] ) && is_array( $session['baseline_active'] )
			? $session['baseline_active']
			: get_option( 'active_plugins', array() );
		$baseline = is_array( $baseline ) ? array_values( array_unique( array_map( 'sanitize_text_field', $baseline ) ) ) : array();

		$disabled = isset( $session['disabled'] ) && is_array( $session['disabled'] )
			? array_values( array_unique( array_map( 'sanitize_text_field', $session['disabled'] ) ) )
			: array();

		$wizard = isset( $session['wizard'] ) && is_array( $session['wizard'] )
			? $session['wizard']
			: array();

		$wizard_history = isset( $wizard['history'] ) && is_array( $wizard['history'] ) ? $wizard['history'] : array();
		$wizard_pool    = isset( $wizard['plugins_pool'] ) && is_array( $wizard['plugins_pool'] ) ? $wizard['plugins_pool'] : array();
		$wizard_likely  = array();
		if ( ! empty( $wizard_history ) && count( $wizard_pool ) <= 2 ) {
			$wizard_likely = array_values( array_unique( array_map( 'sanitize_text_field', $wizard_pool ) ) );
		}

		$url_compare = array_slice( WPG_Result_Store::get_comparisons(), 0, 20 );

		$report = array(
			'title'       => 'WP Guardrail Diagnostic Report',
			'generated_at' => current_time( 'mysql' ),
			'environment' => array(
				'wp_version'           => sanitize_text_field( (string) $wp_version ),
				'php_version'          => sanitize_text_field( phpversion() ? phpversion() : '' ),
				'active_theme'         => sanitize_text_field( $theme->get( 'Name' ) ),
				'active_theme_version' => sanitize_text_field( $theme->get( 'Version' ) ),
				'total_active_plugins' => count( $baseline ),
				'plugin_baseline'      => self::plugins_with_labels( $baseline ),
			),
			'sandbox'     => array(
				'active'           => $is_active,
				'disabled_plugins' => self::plugins_with_labels( $disabled ),
				'wizard_history'   => $wizard_history,
				'wizard_result'    => self::plugins_with_labels( $wizard_likely ),
			),
			'tests'       => array(
				'url_comparisons' => $url_compare,
			),
		);

		$report['text'] = self::build_text_report( $report );

		return $report;
	}

	/**
	 * Build plain text report.
	 *
	 * @param array $report Report data.
	 * @return string
	 */
	private static function build_text_report( $report ) {
		$env     = isset( $report['environment'] ) && is_array( $report['environment'] ) ? $report['environment'] : array();
		$sandbox = isset( $report['sandbox'] ) && is_array( $report['sandbox'] ) ? $report['sandbox'] : array();
		$tests   = isset( $report['tests'] ) && is_array( $report['tests'] ) ? $report['tests'] : array();

		$lines   = array();
		$lines[] = 'WP Guardrail Diagnostic Report';
		$lines[] = '--------------------------------';
		$lines[] = 'Date: ' . ( isset( $report['generated_at'] ) ? $report['generated_at'] : '' );
		$lines[] = 'WP Version: ' . ( isset( $env['wp_version'] ) ? $env['wp_version'] : '' );
		$lines[] = 'PHP Version: ' . ( isset( $env['php_version'] ) ? $env['php_version'] : '' );
		$lines[] = 'Theme: ' . ( isset( $env['active_theme'] ) ? $env['active_theme'] : '' ) . ' ' . ( isset( $env['active_theme_version'] ) ? $env['active_theme_version'] : '' );
		$lines[] = 'Total Active Plugins: ' . ( isset( $env['total_active_plugins'] ) ? (string) (int) $env['total_active_plugins'] : '0' );
		$lines[] = 'Plugins:';

		$plugins = isset( $env['plugin_baseline'] ) && is_array( $env['plugin_baseline'] ) ? $env['plugin_baseline'] : array();
		foreach ( $plugins as $plugin ) {
			$label = isset( $plugin['label'] ) ? $plugin['label'] : '';
			$file  = isset( $plugin['file'] ) ? $plugin['file'] : '';
			$lines[] = '- ' . $label . ' (' . $file . ')';
		}

		$lines[] = '';
		$lines[] = 'Sandbox Active: ' . ( ! empty( $sandbox['active'] ) ? 'YES' : 'NO' );
		$lines[] = 'Disabled Plugins:';
		$disabled = isset( $sandbox['disabled_plugins'] ) && is_array( $sandbox['disabled_plugins'] ) ? $sandbox['disabled_plugins'] : array();
		if ( empty( $disabled ) ) {
			$lines[] = '- None';
		} else {
			foreach ( $disabled as $plugin ) {
				$label = isset( $plugin['label'] ) ? $plugin['label'] : '';
				$file  = isset( $plugin['file'] ) ? $plugin['file'] : '';
				$lines[] = '- ' . $label . ' (' . $file . ')';
			}
		}

		$lines[] = '';
		$lines[] = 'Conflict Wizard Result:';
		$wizard_result = isset( $sandbox['wizard_result'] ) && is_array( $sandbox['wizard_result'] ) ? $sandbox['wizard_result'] : array();
		if ( empty( $wizard_result ) ) {
			$lines[] = '- Not available';
		} else {
			$lines[] = 'Likely conflict between:';
			foreach ( $wizard_result as $plugin ) {
				$label = isset( $plugin['label'] ) ? $plugin['label'] : '';
				$file  = isset( $plugin['file'] ) ? $plugin['file'] : '';
				$lines[] = '- ' . $label . ' (' . $file . ')';
			}
		}

		$lines[] = '';
		$lines[] = 'URL Test Results:';
		$comparisons = isset( $tests['url_comparisons'] ) && is_array( $tests['url_comparisons'] ) ? $tests['url_comparisons'] : array();
		if ( empty( $comparisons ) ) {
			$lines[] = '- No comparison results recorded';
		} else {
			foreach ( $comparisons as $row ) {
				$row      = is_array( $row ) ? $row : array();
				$url      = isset( $row['url'] ) ? $row['url'] : '';
				$baseline = isset( $row['baseline'] ) && is_array( $row['baseline'] ) ? $row['baseline'] : array();
				$sandbox_row = isset( $row['sandbox'] ) && is_array( $row['sandbox'] ) ? $row['sandbox'] : array();
				$lines[]  = 'URL: ' . $url;
				$lines[]  = 'Baseline: ' . ( isset( $baseline['http_code'] ) ? (int) $baseline['http_code'] : 0 ) . ' / ' . ( isset( $baseline['time_ms'] ) ? (int) $baseline['time_ms'] : 0 ) . 'ms';
				$lines[]  = 'Sandbox: ' . ( isset( $sandbox_row['http_code'] ) ? (int) $sandbox_row['http_code'] : 0 ) . ' / ' . ( isset( $sandbox_row['time_ms'] ) ? (int) $sandbox_row['time_ms'] : 0 ) . 'ms';
			}
		}

		$lines[] = '--------------------------------';

		return implode( "\n", $lines );
	}

	/**
	 * Convert plugin file list into labeled rows.
	 *
	 * @param array $plugins Plugin files.
	 * @return array
	 */
	private static function plugins_with_labels( $plugins ) {
		$plugins = is_array( $plugins ) ? $plugins : array();
		$rows    = array();

		foreach ( $plugins as $plugin_file ) {
			$plugin_file = sanitize_text_field( (string) $plugin_file );
			if ( '' === $plugin_file ) {
				continue;
			}

			$rows[] = array(
				'file'  => $plugin_file,
				'label' => self::plugin_label( $plugin_file ),
			);
		}

		return $rows;
	}

	/**
	 * Resolve plugin label from plugin file path.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @return string
	 */
	private static function plugin_label( $plugin_file ) {
		static $plugins = null;

		$plugin_file = sanitize_text_field( (string) $plugin_file );
		if ( '' === $plugin_file ) {
			return '';
		}

		if ( null === $plugins ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
		}

		if ( isset( $plugins[ $plugin_file ]['Name'] ) && '' !== $plugins[ $plugin_file ]['Name'] ) {
			return sanitize_text_field( $plugins[ $plugin_file ]['Name'] );
		}

		return $plugin_file;
	}
}
