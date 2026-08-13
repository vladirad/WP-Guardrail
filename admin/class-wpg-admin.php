<?php
/**
 * Admin coordinator for WP Guardrail.
 *
 * Thin bootstrap: owns all page-slug constants and wires WordPress hooks
 * to the specialised handler classes.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI coordinator.
 *
 * Registers all WordPress admin hooks and delegates execution to:
 *  - WPG_Admin_Pages  — menu registration, asset enqueueing, page rendering.
 *  - WPG_Admin_Actions — POST/GET action handling.
 */
class WPG_Admin {

	const SLUG                  = 'wp-guardrail';
	const SLUG_DASHBOARD        = 'wp-guardrail';
	const SLUG_CONFLICT_WIZARD  = 'wp-guardrail-conflict-wizard';
	const SLUG_URL_TESTER       = 'wp-guardrail-url-tester';
	const SLUG_PLUGIN_ISOLATION = 'wp-guardrail-plugin-isolation';
	const SLUG_UPDATE_SIMULATOR = 'wp-guardrail-update-simulator';
	const SLUG_REPORTS          = 'wp-guardrail-reports';
	const SLUG_DIAGNOSTICS      = 'wp-guardrail-diagnostics';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu',            array( 'WPG_Admin_Pages',   'register_menu' ) );
		add_action( 'admin_init',            array( 'WPG_Admin_Actions', 'handle_post_actions' ) );
		add_action( 'admin_enqueue_scripts', array( 'WPG_Admin_Pages',   'enqueue_assets' ) );
		add_action( 'admin_notices',         array( 'WPG_Admin_Pages',   'maybe_show_muplugin_notice' ) );
	}
}
