<?php
/**
 * Plugin Name: WP Guardrail
 * Plugin URI:  https://example.com/
 * Description: Admin diagnostics toolkit with session-scoped plugin isolation, URL testing, and expandable troubleshooting tools.
 * Version:     0.8.0
 * Author:      Vladimir Radisic
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-guardrail
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPG_VERSION', '0.8.0' );
define( 'WPG_FILE', __FILE__ );
define( 'WPG_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPG_URL', plugin_dir_url( __FILE__ ) );

// Core
require_once WPG_PATH . 'core/class-wpg-bootstrap.php';
require_once WPG_PATH . 'core/class-wpg-session-manager.php';
require_once WPG_PATH . 'core/class-wpg-container.php';
require_once WPG_PATH . 'core/class-wpg-logger.php';
require_once WPG_PATH . 'core/class-wpg-result-store.php';
require_once WPG_PATH . 'core/class-wpg-report.php';
require_once WPG_PATH . 'core/class-wpg-snapshot.php';

// Modules
require_once WPG_PATH . 'modules/isolation/class-wpg-virtual-plugins.php';
require_once WPG_PATH . 'modules/url-tester/class-wpg-url-tester.php';
require_once WPG_PATH . 'modules/conflict-detector/class-wpg-conflict-wizard.php';
require_once WPG_PATH . 'modules/update-simulator/class-wpg-update-simulator.php';
require_once WPG_PATH . 'modules/performance-analyzer/class-wpg-diagnostics.php';
require_once WPG_PATH . 'modules/safe-mode/class-wpg-safety-guard.php';
require_once WPG_PATH . 'modules/safe-mode/class-wpg-recovery.php';

// Admin
require_once WPG_PATH . 'admin/class-wpg-ui.php';
require_once WPG_PATH . 'admin/class-wpg-admin.php';
require_once WPG_PATH . 'admin/class-wpg-admin-pages.php';
require_once WPG_PATH . 'admin/class-wpg-admin-actions.php';
require_once WPG_PATH . 'admin/class-wpg-admin-bar.php';

register_activation_hook( __FILE__, array( 'WPG_Bootstrap', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPG_Bootstrap', 'deactivate' ) );

WPG_Bootstrap::init();
