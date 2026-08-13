<?php
/**
 * Virtual plugin filter for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters the active plugin list for display and fallback isolation.
 *
 * ## Role after Phase 1
 *
 * The PRIMARY isolation mechanism is the mu-plugin bootstrap loader written
 * to wp-content/mu-plugins/wp-guardrail-loader.php on activation.  That
 * loader registers option_active_plugins at priority 0, before any regular
 * plugin is loaded by wp-settings.php, so disabled plugins never execute.
 *
 * This class registers its own option_active_plugins filter at init priority
 * 1 (which fires after all plugins have already loaded).  Its purpose is:
 *
 * 1. Display consistency — admin pages that call get_option('active_plugins')
 *    after init see the correct sandbox-filtered list.
 * 2. Fallback isolation — when the mu-plugin could not be written (e.g. the
 *    mu-plugins directory is not writable), this filter still enforces the
 *    sandbox policy for any code that reads the option at runtime.
 *
 * Shim loading for update simulation is handled exclusively by the mu-plugin
 * at the correct (pre-plugin-load) phase.  This class no longer performs
 * require_once on shim files to avoid redundant loads.
 */
class WPG_Virtual_Plugins {

	/**
	 * Register option filters.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'option_active_plugins', array( __CLASS__, 'filter_active_plugins' ), 1 );
		add_filter( 'site_option_active_sitewide_plugins', array( __CLASS__, 'filter_sitewide_plugins' ), 1 );
	}

	/**
	 * Filter the active plugin list for the current request.
	 *
	 * Enforces the sandbox policy (baseline minus disabled) so that any
	 * runtime code reading get_option('active_plugins') sees the correct set,
	 * regardless of whether the mu-plugin bootstrap is installed.
	 *
	 * @param array $active_plugins Active plugin paths from the database.
	 * @return array
	 */
	public static function filter_active_plugins( $active_plugins ) {
		$active_plugins = is_array( $active_plugins ) ? $active_plugins : array();
		$active_plugins = array_map( 'sanitize_text_field', $active_plugins );
		$active_plugins = array_values( array_unique( $active_plugins ) );

		// Sandbox is session-scoped and admin-only.
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return $active_plugins;
		}

		$session = WPG_Container::instance()->get_session();
		if ( false === $session ) {
			return $active_plugins;
		}

		$self_plugin = WPG_Bootstrap::self_plugin_basename();

		$baseline = ! empty( $session['baseline_active'] ) && is_array( $session['baseline_active'] )
			? array_map( 'sanitize_text_field', $session['baseline_active'] )
			: $active_plugins;

		$disabled = isset( $session['disabled'] ) && is_array( $session['disabled'] )
			? array_map( 'sanitize_text_field', $session['disabled'] )
			: array();

		// WP Guardrail itself must never be disabled.
		$disabled = array_diff( $disabled, array( $self_plugin ) );
		$filtered = array_values( array_unique( array_diff( $baseline, $disabled ) ) );

		// For update simulation: surface the simulated plugin list in the
		// displayed active-plugins set (shim loading itself is done by the
		// mu-plugin at the correct pre-load phase, not here).
		$simulation = isset( $session['simulation'] ) && is_array( $session['simulation'] )
			? $session['simulation']
			: array();

		if ( ! empty( $simulation['active'] ) && ! empty( $simulation['shims'] ) && is_array( $simulation['shims'] ) ) {
			// Remove shimmed plugins from the display list; they were already
			// loaded by the mu-plugin under their shim path.
			foreach ( $filtered as $index => $plugin_file ) {
				if ( isset( $simulation['shims'][ $plugin_file ] ) ) {
					unset( $filtered[ $index ] );
				}
			}
			$filtered = array_values( array_unique( $filtered ) );
		}

		// WP Guardrail must always appear active.
		if ( ! in_array( $self_plugin, $filtered, true ) ) {
			$filtered[] = $self_plugin;
		}

		return array_values( array_unique( $filtered ) );
	}

	/**
	 * Multisite sitewide plugin filter — stub for future implementation.
	 *
	 * TODO v0.3: Add network-active plugin isolation support.
	 *
	 * @param array $active_sitewide_plugins Network-active plugins.
	 * @return array
	 */
	public static function filter_sitewide_plugins( $active_sitewide_plugins ) {
		// v0.2 intentionally does not modify network-active plugins.
		return $active_sitewide_plugins;
	}
}
