<?php
/**
 * Admin bar indicator for active safe mode.
 *
 * @package WPSandboxMode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the Safe Mode admin bar item.
 */
class WPG_Admin_Bar {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_node' ), 100 );
	}

	/**
	 * Add active indicator node.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar object.
	 * @return void
	 */
	public static function add_node( $admin_bar ) {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		if ( ! WPG_Container::instance()->is_sandbox_active() ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'id'    => 'wsm-sandbox-mode-active',
				'title' => '🧪 Sandbox Mode Active',
				'href'  => WPG_Bootstrap::settings_url(),
				'meta'  => array(
					'class' => 'wsm-sandbox-mode-active',
				),
			)
		);
	}
}
