<?php
/**
 * Main plugin loader and lifecycle manager for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 *
 * Responsible for bootstrapping feature modules and managing the mu-plugin
 * bootstrap loader that makes true sandbox isolation possible.
 *
 * The mu-plugin (wp-guardrail-loader.php) registers the option_active_plugins
 * filter at priority 0 — before any regular plugin is loaded — so that
 * sandbox-disabled plugins genuinely never execute in sandboxed requests.
 * Without it, isolation operates in fallback/display mode only.
 *
 * The loader is written on activation, removed on deactivation, and
 * self-healed on every admin page load so that it survives plugin updates
 * and hosting-environment file sweeps.
 */
class WPG_Bootstrap {

	/**
	 * WordPress option key that tracks mu-plugin installation status.
	 */
	const MU_STATUS_OPTION = 'wpg_muplugin_status';

	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		WPG_Recovery::init();
		add_action( 'init', array( __CLASS__, 'boot' ), 1 );
	}

	/**
	 * Boot feature modules at init priority 1.
	 *
	 * Also triggers a self-heal check in admin context so the mu-plugin
	 * loader is automatically reinstated if it was accidentally deleted.
	 *
	 * @return void
	 */
	public static function boot() {
		// Self-heal: ensure the mu-plugin bootstrap is installed on every
		// admin request.  Keeps isolation working after plugin updates or
		// host-level file sweeps without requiring manual reactivation.
		if ( is_admin() ) {
			$status       = get_option( self::MU_STATUS_OPTION, '' );
			$loader_path  = self::get_mu_plugin_path();
			if ( 'ok' !== $status || ! file_exists( $loader_path ) ) {
				self::write_mu_plugin();
			}
		}

		WPG_URL_Tester::init();
		WPG_Session_Manager::maybe_capture_query_token();
		WPG_Virtual_Plugins::init();
		WPG_Admin::init();
		WPG_Admin_Bar::init();
	}

	/**
	 * Plugin activation callback.
	 *
	 * Writes the mu-plugin bootstrap loader so that sandbox isolation takes
	 * effect on the very next request.
	 *
	 * @return void
	 */
	public static function activate() {
		self::write_mu_plugin();
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * Removes the mu-plugin bootstrap loader so WordPress returns to its
	 * normal plugin-loading behaviour.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::remove_mu_plugin();
		WPG_Recovery::clear();
	}

	/**
	 * Write the mu-plugin bootstrap loader to wp-content/mu-plugins/.
	 *
	 * Creates the mu-plugins directory if it does not yet exist.
	 * Records success/failure in the wpg_muplugin_status option so that
	 * the admin UI can surface an appropriate notice.
	 *
	 * @return bool True on success.
	 */
	public static function write_mu_plugin() {
		$loader_path = self::get_mu_plugin_path();
		$mu_dir      = dirname( $loader_path );

		// Create mu-plugins directory if absent.
		if ( ! is_dir( $mu_dir ) ) {
			if ( ! wp_mkdir_p( $mu_dir ) ) {
				update_option( self::MU_STATUS_OPTION, 'failed', false );
				return false;
			}
		}

		if ( ! is_writable( $mu_dir ) ) {
			update_option( self::MU_STATUS_OPTION, 'failed', false );
			return false;
		}

		$content = self::mu_plugin_content();
		$written = file_put_contents( $loader_path, $content );

		if ( false === $written || 0 === $written ) {
			update_option( self::MU_STATUS_OPTION, 'failed', false );
			return false;
		}

		update_option( self::MU_STATUS_OPTION, 'ok', false );
		return true;
	}

	/**
	 * Delete the mu-plugin bootstrap loader.
	 *
	 * @return void
	 */
	public static function remove_mu_plugin() {
		$loader_path = self::get_mu_plugin_path();

		if ( file_exists( $loader_path ) ) {
			unlink( $loader_path );
		}

		delete_option( self::MU_STATUS_OPTION );
	}

	/**
	 * Whether the mu-plugin bootstrap loader is installed and recorded as OK.
	 *
	 * @return bool
	 */
	public static function is_mu_plugin_installed() {
		return 'ok' === get_option( self::MU_STATUS_OPTION, '' )
			&& file_exists( self::get_mu_plugin_path() );
	}

	/**
	 * Absolute path to the generated mu-plugin loader file.
	 *
	 * @return string
	 */
	public static function get_mu_plugin_path() {
		return trailingslashit( WPMU_PLUGIN_DIR ) . 'wp-guardrail-loader.php';
	}

	/**
	 * Get this plugin's basename (folder/file.php).
	 *
	 * @return string
	 */
	public static function self_plugin_basename() {
		return plugin_basename( WPG_FILE );
	}

	/**
	 * Admin settings page URL.
	 *
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'admin.php?page=' . WPG_Admin::SLUG_DASHBOARD );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the content of the mu-plugin bootstrap loader.
	 *
	 * The generated file is completely self-contained: it has no dependency on
	 * any WP Guardrail class and is valid for PHP 8.0+.  It registers the
	 * option_active_plugins filter at priority 0 so the filter fires before any
	 * plugin is loaded by wp-settings.php.
	 *
	 * get_transient() is available at this point because wp-includes/option.php
	 * is loaded before mu-plugins in wp-settings.php.
	 *
	 * @return string PHP file content.
	 */
	private static function mu_plugin_content() {
		$ver = WPG_VERSION;

		// Build the file as a string so heredoc indentation rules never bite us.
		$c  = "<?php\n";
		$c .= "/**\n";
		$c .= " * WP Guardrail Bootstrap Loader\n";
		$c .= " *\n";
		$c .= " * Auto-generated by WP Guardrail {$ver}. Do not edit manually.\n";
		$c .= " * This file is regenerated when WP Guardrail is activated or updated.\n";
		$c .= " *\n";
		$c .= " * Registers option_active_plugins at priority 0 — before any regular\n";
		$c .= " * plugin loads — so that sandbox-disabled plugins genuinely never\n";
		$c .= " * execute in sandboxed requests, and update-simulation shims can\n";
		$c .= " * replace original plugin files at the correct phase.\n";
		$c .= " *\n";
		$c .= " * @package WPGuardrail\n";
		$c .= " */\n\n";

		$c .= "defined( 'ABSPATH' ) || exit;\n\n";

		$c .= "// Early exit: no sandbox token present in this request.\n";
		$c .= "if ( empty( \$_COOKIE['wsm_token'] ) ) {\n";
		$c .= "\treturn;\n";
		$c .= "}\n\n";

		$c .= "\$_wpg_raw = preg_replace( '/[^a-zA-Z0-9]/', '', (string) \$_COOKIE['wsm_token'] );\n";
		$c .= "\$_wpg_raw = strtolower( \$_wpg_raw );\n\n";

		$c .= "if ( '' === \$_wpg_raw ) {\n";
		$c .= "\tunset( \$_wpg_raw );\n";
		$c .= "\treturn;\n";
		$c .= "}\n\n";

		$c .= "// get_transient() is available here: wp-includes/option.php is loaded\n";
		$c .= "// before mu-plugins in wp-settings.php.\n";
		$c .= "\$_wpg_session = get_transient( 'wsm_' . md5( \$_wpg_raw ) );\n";
		$c .= "unset( \$_wpg_raw );\n\n";

		$c .= "if (\n";
		$c .= "\t! is_array( \$_wpg_session )\n";
		$c .= "\t|| empty( \$_wpg_session['enabled'] )\n";
		$c .= "\t|| empty( \$_wpg_session['user_id'] )\n";
		$c .= "\t|| empty( \$_wpg_session['expires'] )\n";
		$c .= "\t|| (int) \$_wpg_session['expires'] < time()\n";
		$c .= "\t|| ! isset( \$_wpg_session['baseline_active'], \$_wpg_session['disabled'] )\n";
		$c .= "\t|| ! is_array( \$_wpg_session['baseline_active'] )\n";
		$c .= "\t|| ! is_array( \$_wpg_session['disabled'] )\n";
		$c .= ") {\n";
		$c .= "\tunset( \$_wpg_session );\n";
		$c .= "\treturn;\n";
		$c .= "}\n\n";

		$c .= "add_filter(\n";
		$c .= "\t'option_active_plugins',\n";
		$c .= "\tstatic function ( \$active_plugins ) use ( \$_wpg_session ) {\n";
		$c .= "\t\t\$baseline = is_array( \$_wpg_session['baseline_active'] ) ? \$_wpg_session['baseline_active'] : array();\n";
		$c .= "\t\t\$disabled = is_array( \$_wpg_session['disabled'] )       ? \$_wpg_session['disabled']       : array();\n\n";

		$c .= "\t\t// Ensure scalar strings and remove duplicates.\n";
		$c .= "\t\t\$baseline = array_values( array_unique( array_filter( array_map( 'strval', \$baseline ) ) ) );\n";
		$c .= "\t\t\$disabled = array_values( array_unique( array_filter( array_map( 'strval', \$disabled ) ) ) );\n\n";

		$c .= "\t\t\$filtered = array_values( array_diff( \$baseline, \$disabled ) );\n\n";

		$c .= "\t\t// Update-simulation: load shims in place of the original plugin file.\n";
		$c .= "\t\t\$simulation = isset( \$_wpg_session['simulation'] ) && is_array( \$_wpg_session['simulation'] )\n";
		$c .= "\t\t\t? \$_wpg_session['simulation']\n";
		$c .= "\t\t\t: array();\n\n";

		$c .= "\t\tif ( ! empty( \$simulation['active'] ) && ! empty( \$simulation['shims'] ) && is_array( \$simulation['shims'] ) ) {\n";
		$c .= "\t\t\tstatic \$_wpg_loaded_shims = array();\n\n";

		$c .= "\t\t\tforeach ( \$filtered as \$_wpg_idx => \$_wpg_file ) {\n";
		$c .= "\t\t\t\tif ( ! isset( \$simulation['shims'][ \$_wpg_file ] ) ) {\n";
		$c .= "\t\t\t\t\tcontinue;\n";
		$c .= "\t\t\t\t}\n\n";

		$c .= "\t\t\t\t\$_wpg_shim = (string) \$simulation['shims'][ \$_wpg_file ];\n";
		$c .= "\t\t\t\t\$_wpg_abs  = '' !== \$_wpg_shim\n";
		$c .= "\t\t\t\t\t&& ( '/' === \$_wpg_shim[0] || (bool) preg_match( '/^[A-Za-z]:[\\\\\\\\\\/]/', \$_wpg_shim ) );\n\n";

		$c .= "\t\t\t\tif (\n";
		$c .= "\t\t\t\t\t\$_wpg_abs\n";
		$c .= "\t\t\t\t\t&& '.php' === strtolower( substr( \$_wpg_shim, -4 ) )\n";
		$c .= "\t\t\t\t\t&& file_exists( \$_wpg_shim )\n";
		$c .= "\t\t\t\t\t&& is_readable( \$_wpg_shim )\n";
		$c .= "\t\t\t\t) {\n";
		$c .= "\t\t\t\t\tif ( ! isset( \$_wpg_loaded_shims[ \$_wpg_shim ] ) ) {\n";
		$c .= "\t\t\t\t\t\trequire_once \$_wpg_shim;\n";
		$c .= "\t\t\t\t\t\t\$_wpg_loaded_shims[ \$_wpg_shim ] = true;\n";
		$c .= "\t\t\t\t\t}\n";
		$c .= "\t\t\t\t\t// Remove original so WordPress does not also load it.\n";
		$c .= "\t\t\t\t\tunset( \$filtered[ \$_wpg_idx ] );\n";
		$c .= "\t\t\t\t}\n";
		$c .= "\t\t\t}\n\n";

		$c .= "\t\t\t\$filtered = array_values( \$filtered );\n";
		$c .= "\t\t}\n\n";

		$c .= "\t\treturn \$filtered;\n";
		$c .= "\t},\n";
		$c .= "\t0\n";
		$c .= ");\n\n";

		$c .= "unset( \$_wpg_session );\n";

		return $c;
	}
}
