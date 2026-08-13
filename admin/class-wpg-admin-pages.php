<?php
/**
 * Admin menu registration, routing, asset enqueueing, and shared page helpers
 * for WP Guardrail.
 *
 * Actual page content is rendered by dedicated page classes under admin/pages/.
 * This class is responsible for:
 *  - Registering menus and submenus.
 *  - Enqueueing CSS/JS on plugin pages.
 *  - Resolving shared page context and dispatching to page render classes.
 *  - Providing routing helpers used by WPG_Admin_Actions and page classes.
 *  - Providing shared render helpers (notices, snapshot button, report section).
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Page render classes.
require_once __DIR__ . '/pages/class-wpg-page-dashboard.php';
require_once __DIR__ . '/pages/class-wpg-page-plugin-isolation.php';
require_once __DIR__ . '/pages/class-wpg-page-url-tester.php';
require_once __DIR__ . '/pages/class-wpg-page-conflict-wizard.php';
require_once __DIR__ . '/pages/class-wpg-page-update-simulator.php';
require_once __DIR__ . '/pages/class-wpg-page-reports.php';
require_once __DIR__ . '/pages/class-wpg-page-diagnostics.php';

/**
 * Menu registration, routing, and shared admin page helpers.
 */
class WPG_Admin_Pages {

	/**
	 * Forced tab for submenu callback rendering.
	 *
	 * @var string
	 */
	private static string $forced_tab = '';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_muplugin_notice' ) );
	}

	/**
	 * Show an admin notice when the mu-plugin bootstrap loader is not installed.
	 *
	 * @return void
	 */
	public static function maybe_show_muplugin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, self::allowed_pages(), true ) ) {
			return;
		}

		if ( WPG_Bootstrap::is_mu_plugin_installed() ) {
			return;
		}

		$mu_dir = esc_html( dirname( WPG_Bootstrap::get_mu_plugin_path() ) );
		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: %s: path to the mu-plugins directory */
			esc_html__( 'WP Guardrail: the bootstrap loader could not be written to %s. Sandbox isolation is operating in fallback mode — plugins marked as disabled may still execute. Check write permissions on that directory, then deactivate and reactivate WP Guardrail to try again.', 'wp-guardrail' ),
			'<code>' . $mu_dir . '</code>'
		);
		echo '</p></div>';
	}

	/**
	 * Register top-level and submenu pages.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			esc_html__( 'WP Guardrail', 'wp-guardrail' ),
			esc_html__( 'WP Guardrail', 'wp-guardrail' ),
			'manage_options',
			WPG_Admin::SLUG_DASHBOARD,
			array( __CLASS__, 'render_dashboard_page' ),
			'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cGF0aCBmaWxsPSIjYTFhNWE5IiBkPSJNMyA0Yy0uNTUgMC0xIC40NS0xIDF2OGMwIC41NS40NSAxIDEgMWg1djJINXYxaDEwdi0xaC0zdi0yaDVjLjU1IDAgMS0uNDUgMS0xVjVjMC0uNTUtLjQ1LTEtMS0xSDN6bTAgMWgxNHY4SDNWNXoiLz48cGF0aCBmaWxsPSIjYTFhNWE5IiBkPSJNNSAxMGgyLjFsMS4xLTIuMkwxMCAxMmwxLjA1LTIuMS44NSAxLjFIMTV2LTFoLTIuN2wtMS4xLTEuNEwxMCAxMS4xIDguMyA2LjkgNi41IDEwSDV2MXoiLz48L3N2Zz4='
		);

		add_submenu_page(
			WPG_Admin::SLUG_DASHBOARD,
			esc_html__( 'Dashboard', 'wp-guardrail' ),
			esc_html__( 'Dashboard', 'wp-guardrail' ),
			'manage_options',
			WPG_Admin::SLUG_DASHBOARD,
			array( __CLASS__, 'render_dashboard_page' )
		);

		add_submenu_page(
			WPG_Admin::SLUG_DASHBOARD,
			esc_html__( 'Conflict Wizard', 'wp-guardrail' ),
			esc_html__( 'Conflict Wizard', 'wp-guardrail' ),
			'manage_options',
			WPG_Admin::SLUG_CONFLICT_WIZARD,
			array( __CLASS__, 'render_conflict_wizard_page' )
		);

		add_submenu_page(
			WPG_Admin::SLUG_DASHBOARD,
			esc_html__( 'URL Tester', 'wp-guardrail' ),
			esc_html__( 'URL Tester', 'wp-guardrail' ),
			'manage_options',
			WPG_Admin::SLUG_URL_TESTER,
			array( __CLASS__, 'render_url_tester_page' )
		);

		add_submenu_page(
			WPG_Admin::SLUG_DASHBOARD,
			esc_html__( 'Plugin Isolation', 'wp-guardrail' ),
			esc_html__( 'Plugin Isolation', 'wp-guardrail' ),
			'manage_options',
			WPG_Admin::SLUG_PLUGIN_ISOLATION,
			array( __CLASS__, 'render_plugin_isolation_page' )
		);

		add_submenu_page(
			WPG_Admin::SLUG_DASHBOARD,
			esc_html__( 'Update Simulator', 'wp-guardrail' ),
			esc_html__( 'Update Simulator', 'wp-guardrail' ),
			'manage_options',
			WPG_Admin::SLUG_UPDATE_SIMULATOR,
			array( __CLASS__, 'render_update_simulator_page' )
		);

		add_submenu_page(
			WPG_Admin::SLUG_DASHBOARD,
			esc_html__( 'Reports', 'wp-guardrail' ),
			esc_html__( 'Reports', 'wp-guardrail' ),
			'manage_options',
			WPG_Admin::SLUG_REPORTS,
			array( __CLASS__, 'render_reports_page' )
		);

		add_submenu_page(
			WPG_Admin::SLUG_DASHBOARD,
			esc_html__( 'Diagnostics', 'wp-guardrail' ),
			esc_html__( 'Diagnostics', 'wp-guardrail' ),
			'manage_options',
			WPG_Admin::SLUG_DIAGNOSTICS,
			array( __CLASS__, 'render_diagnostics_page' )
		);
	}

	/**
	 * Enqueue admin assets for WP Guardrail pages only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		$is_top_level = 0 === strpos( $hook_suffix, 'toplevel_page_' . WPG_Admin::SLUG_DASHBOARD );
		$is_sub_page  = 0 === strpos( $hook_suffix, WPG_Admin::SLUG_DASHBOARD . '_page_' );

		if ( ! $is_top_level && ! $is_sub_page ) {
			return;
		}

		wp_enqueue_style(
			'wsm-admin',
			WPG_URL . 'assets/css/admin.css',
			array(),
			WPG_VERSION
		);

		wp_enqueue_script(
			'wsm-admin',
			WPG_URL . 'assets/js/admin.js',
			array(),
			WPG_VERSION,
			true
		);
	}

	// -------------------------------------------------------------------------
	// Page render callbacks — set forced_tab then delegate to render_page().
	// -------------------------------------------------------------------------

	/** @return void */
	public static function render_dashboard_page(): void {
		self::$forced_tab = 'dashboard';
		self::render_page();
	}

	/** @return void */
	public static function render_conflict_wizard_page(): void {
		self::$forced_tab = 'conflict-wizard';
		self::render_page();
	}

	/** @return void */
	public static function render_url_tester_page(): void {
		self::$forced_tab = 'url-tester';
		self::render_page();
	}

	/** @return void */
	public static function render_plugin_isolation_page(): void {
		self::$forced_tab = 'plugin-disabler';
		self::render_page();
	}

	/** @return void */
	public static function render_update_simulator_page(): void {
		self::$forced_tab = 'update-simulator';
		self::render_page();
	}

	/** @return void */
	public static function render_reports_page(): void {
		self::$forced_tab = 'reports';
		self::render_page();
	}

	/** @return void */
	public static function render_diagnostics_page(): void {
		self::$forced_tab = 'diagnostics';
		self::render_page();
	}

	// =========================================================================
	// Main page renderer — resolves shared context, wraps output, dispatches.
	// =========================================================================

	/**
	 * Resolve shared context and dispatch to the appropriate page render class.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// --- Resolve session & shared data -----------------------------------
		$session   = WPG_Container::instance()->get_session();
		$is_active = false !== $session;
		$baseline  = $is_active && isset( $session['baseline_active'] ) && is_array( $session['baseline_active'] )
			? $session['baseline_active']
			: self::baseline_without_self();
		$disabled  = $is_active && isset( $session['disabled'] ) && is_array( $session['disabled'] )
			? $session['disabled']
			: array();

		$notice        = isset( $_GET['wsm_notice'] )      ? sanitize_key( wp_unslash( $_GET['wsm_notice'] ) ) : '';
		$page          = isset( $_GET['page'] )             ? sanitize_key( wp_unslash( $_GET['page'] ) ) : WPG_Admin::SLUG_DASHBOARD;
		$requested_tab = isset( $_GET['wsm_tab'] )          ? sanitize_key( wp_unslash( $_GET['wsm_tab'] ) ) : '';
		$tab           = self::$forced_tab ? self::$forced_tab : self::page_to_tab( $page );

		if ( WPG_Admin::SLUG_DASHBOARD === $page && '' !== $requested_tab ) {
			$tab = $requested_tab;
		}
		self::$forced_tab = '';

		if ( 'sandbox' === $tab ) {
			$tab = 'plugin-disabler';
		}
		$tab = in_array( $tab, self::valid_tabs(), true ) ? $tab : 'dashboard';

		$show_report   = isset( $_GET['wpg_show_report'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['wpg_show_report'] ) );
		$snapshot_hash = isset( $_GET['wpg_snapshot_hash'] ) ? sanitize_text_field( wp_unslash( $_GET['wpg_snapshot_hash'] ) ) : '';

		$url_tests       = WPG_URL_Tester::get_results();
		$compare_tests   = WPG_URL_Tester::get_compare_results();
		$wizard          = WPG_Conflict_Wizard::get_state();
		$simulation      = WPG_Update_Simulator::get_simulation();
		$pending_updates = WPG_Update_Simulator::get_pending_updates();
		$saved_test_urls = get_option( 'wp_guardrail_test_urls', array( '/', '/wp-admin/', '/wp-json/' ) );
		$saved_test_urls = is_array( $saved_test_urls ) ? $saved_test_urls : array( '/', '/wp-admin/', '/wp-json/' );
		$recovery_url      = WPG_Recovery::get_url();
		$protected_plugins = WPG_Safety_Guard::get_protected_plugins();
		$page_url          = self::page_url();

		// Shared context array passed to all page render classes.
		$ctx = array(
			'session'           => $session,
			'is_active'         => $is_active,
			'baseline'          => $baseline,
			'disabled'          => $disabled,
			'notice'            => $notice,
			'page'              => $page,
			'tab'               => $tab,
			'show_report'       => $show_report,
			'snapshot_hash'     => $snapshot_hash,
			'url_tests'         => $url_tests,
			'compare_tests'     => $compare_tests,
			'wizard'            => $wizard,
			'simulation'        => $simulation,
			'pending_updates'   => $pending_updates,
			'saved_test_urls'   => $saved_test_urls,
			'recovery_url'      => $recovery_url,
			'protected_plugins' => $protected_plugins,
			'page_url'          => $page_url,
		);

		?>
		<div class="wrap wsm-wrap wpg-wrap">

			<?php self::render_notice( $notice ); ?>

			<?php WPG_UI::mode_banner( $is_active, $session, $tab, $page_url ); ?>

			<?php self::render_snapshot_link_field( $snapshot_hash ); ?>

			<?php
			// Dispatch to the appropriate page render class.
			switch ( $tab ) {
				case 'dashboard':
					WPG_Page_Dashboard::render( $ctx );
					break;
				case 'plugin-disabler':
					WPG_Page_Plugin_Isolation::render( $ctx );
					break;
				case 'url-tester':
					WPG_Page_Url_Tester::render( $ctx );
					break;
				case 'conflict-wizard':
					WPG_Page_Conflict_Wizard::render( $ctx );
					break;
				case 'update-simulator':
					WPG_Page_Update_Simulator::render( $ctx );
					break;
				case 'reports':
					WPG_Page_Reports::render( $ctx );
					break;
				case 'diagnostics':
					WPG_Page_Diagnostics::render( $ctx );
					break;
			}
			?>

		</div>
		<?php
	}

	// =========================================================================
	// Shared render helpers (used by page classes and WPG_Admin_Actions).
	// =========================================================================

	/**
	 * Render a post-action notice.
	 *
	 * @param string $notice Notice slug.
	 * @return void
	 */
	private static function render_notice( string $notice ): void {
		$messages = array(
			'started'                      => esc_html__( 'Sandbox Mode started for your current session.', 'wp-guardrail' ),
			'stopped'                      => esc_html__( 'Sandbox Mode ended and session cleared.', 'wp-guardrail' ),
			'applied'                      => esc_html__( 'Sandbox Mode plugin selection updated.', 'wp-guardrail' ),
			'tested'                       => esc_html__( 'URL test completed and saved.', 'wp-guardrail' ),
			'tested_both'                  => esc_html__( 'Baseline and Sandbox URL tests completed and saved.', 'wp-guardrail' ),
			'compared'                     => esc_html__( 'Baseline vs Sandbox comparison completed and saved.', 'wp-guardrail' ),
			'tests_cleared'                => esc_html__( 'Saved URL test results were cleared.', 'wp-guardrail' ),
			'invalid_url'                  => esc_html__( 'Please provide a valid same-host URL or supported path.', 'wp-guardrail' ),
			'sandbox_required'             => esc_html__( 'Sandbox Mode must be active for Sandbox test mode and compare.', 'wp-guardrail' ),
			'wizard_requires_sandbox'      => esc_html__( 'Sandbox Mode must be active to run Conflict Wizard.', 'wp-guardrail' ),
			'wizard_invalid_url'           => esc_html__( 'Please enter a valid target URL for Conflict Wizard.', 'wp-guardrail' ),
			'wizard_empty_pool'            => esc_html__( 'No plugins left to test after exclusions. Remove some exclusions and try again.', 'wp-guardrail' ),
			'wizard_start_failed'          => esc_html__( 'Could not start Conflict Wizard. Please review inputs and try again.', 'wp-guardrail' ),
			'wizard_state_error'           => esc_html__( 'Conflict Wizard state is unavailable. Restart the wizard.', 'wp-guardrail' ),
			'wizard_started'               => esc_html__( 'Conflict Wizard started.', 'wp-guardrail' ),
			'wizard_next_step'             => esc_html__( 'Conflict Wizard moved to the next step.', 'wp-guardrail' ),
			'wizard_complete'              => esc_html__( 'Conflict Wizard completed. Review likely conflicting plugin(s).', 'wp-guardrail' ),
			'wizard_reset'                 => esc_html__( 'Conflict Wizard was reset.', 'wp-guardrail' ),
			'snapshot_saved'               => esc_html__( 'Session snapshot saved.', 'wp-guardrail' ),
			'snapshot_save_failed'         => esc_html__( 'Could not save session snapshot. Ensure Sandbox Mode is active.', 'wp-guardrail' ),
			'snapshot_restored'            => esc_html__( 'Session snapshot restored.', 'wp-guardrail' ),
			'snapshot_restore_failed'      => esc_html__( 'Snapshot link is invalid, expired, or could not be restored.', 'wp-guardrail' ),
			'update_sim_ran'               => esc_html__( 'Update simulation completed.', 'wp-guardrail' ),
			'update_sim_reset'             => esc_html__( 'Update simulation was reset.', 'wp-guardrail' ),
			'update_sim_failed'            => esc_html__( 'Update simulation failed. Check the Run Details section for preflight errors.', 'wp-guardrail' ),
			'update_sim_leftovers_cleared' => esc_html__( 'Leftover simulation data was cleared.', 'wp-guardrail' ),
			'protected_saved'              => esc_html__( 'Protected plugins list saved.', 'wp-guardrail' ),
			'applied_protected_stripped'   => esc_html__( 'Applied. One or more protected plugins were removed from your selection and kept active.', 'wp-guardrail' ),
			'applied_with_risk'            => esc_html__( 'Applied. Your disabled set includes security, authentication, or caching plugins — monitor your admin session closely.', 'wp-guardrail' ),
			'recovery_generated'           => esc_html__( 'Emergency recovery link generated. Copy it from the Dashboard before starting risky operations.', 'wp-guardrail' ),
			'recovery_cleared'             => esc_html__( 'Emergency recovery link cleared.', 'wp-guardrail' ),
			'recovery_applied'             => esc_html__( 'Emergency recovery: sandbox session stopped. All plugins are active again.', 'wp-guardrail' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$warning_notices = array( 'applied_with_risk', 'applied_protected_stripped' );
		$notice_class    = in_array( $notice, $warning_notices, true ) ? 'notice-warning' : 'notice-success';
		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
			<p><?php echo esc_html( $messages[ $notice ] ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render a snapshot action button form.
	 *
	 * @param string $tab Current tab slug.
	 * @return void
	 */
	public static function render_snapshot_button( string $tab ): void {
		$tab = sanitize_key( $tab );
		?>
		<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" style="margin:8px 0 0">
			<?php wp_nonce_field( 'wpg_save_snapshot', 'wpg_snapshot_nonce' ); ?>
			<input type="hidden" name="wsm_tab" value="<?php echo esc_attr( $tab ); ?>" />
			<input type="hidden" name="wsm_action" value="wpg_save_snapshot" />
			<p><button type="submit" class="button"><?php echo esc_html__( 'Save Session Snapshot', 'wp-guardrail' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Render a copyable snapshot link field.
	 *
	 * @param string $snapshot_hash Snapshot hash from GET param.
	 * @return void
	 */
	private static function render_snapshot_link_field( string $snapshot_hash ): void {
		$snapshot_hash = sanitize_text_field( $snapshot_hash );
		if ( '' === $snapshot_hash ) {
			return;
		}

		$link = WP_Guardrail_Snapshot::link( $snapshot_hash );
		if ( '' === $link ) {
			return;
		}
		?>
		<div class="wpg-card" style="margin-bottom:16px">
			<div class="wpg-card__body">
				<p><strong><?php echo esc_html__( 'Session Snapshot Link', 'wp-guardrail' ); ?></strong></p>
				<div class="wpg-copy-row">
					<input type="text" id="wsm-snapshot-link" class="wpg-copy-input code" readonly value="<?php echo esc_attr( $link ); ?>" />
					<button type="button" class="button wpg-copy-btn" data-copy-target="wsm-snapshot-link"><?php echo esc_html__( 'Copy', 'wp-guardrail' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render report generation UI and optional text output.
	 *
	 * @param string $tab         Current tab slug.
	 * @param bool   $show_report Whether to render the report text block.
	 * @return void
	 */
	public static function render_report_section( string $tab, bool $show_report ): void {
		$tab = sanitize_key( $tab );
		?>
		<p>
			<a href="<?php echo esc_url( self::page_url( array( 'wsm_tab' => $tab, 'wpg_show_report' => 1 ) ) ); ?>" class="button">
				<?php echo esc_html__( 'Generate Report', 'wp-guardrail' ); ?>
			</a>
		</p>
		<?php
		if ( ! $show_report ) {
			return;
		}

		$report = WP_Guardrail_Report::build();
		$text   = isset( $report['text'] ) ? (string) $report['text'] : '';
		?>
		<p><?php echo esc_html__( 'Copy diagnostic text report:', 'wp-guardrail' ); ?></p>
		<textarea class="large-text code" rows="14" readonly id="wpg-report-text"><?php echo esc_textarea( $text ); ?></textarea>
		<p style="margin-top:6px">
			<button type="button" class="button wpg-copy-btn" data-copy-target="wpg-report-text"><?php echo esc_html__( 'Copy to clipboard', 'wp-guardrail' ); ?></button>
		</p>
		<form method="post" action="<?php echo esc_url( self::page_url() ); ?>">
			<?php wp_nonce_field( 'wpg_download_report_json', 'wpg_report_nonce' ); ?>
			<input type="hidden" name="wsm_tab" value="<?php echo esc_attr( $tab ); ?>" />
			<input type="hidden" name="wsm_action" value="wpg_download_report_json" />
			<p>
				<button type="submit" class="button button-secondary"><?php echo esc_html__( 'Download JSON', 'wp-guardrail' ); ?></button>
			</p>
		</form>
		<?php
	}

	// =========================================================================
	// Public routing helpers (used by WPG_Admin_Actions and page classes).
	// =========================================================================

	/**
	 * Get all valid plugin page slugs.
	 *
	 * @return string[]
	 */
	public static function allowed_pages(): array {
		return array(
			WPG_Admin::SLUG_DASHBOARD,
			WPG_Admin::SLUG_CONFLICT_WIZARD,
			WPG_Admin::SLUG_URL_TESTER,
			WPG_Admin::SLUG_PLUGIN_ISOLATION,
			WPG_Admin::SLUG_UPDATE_SIMULATOR,
			WPG_Admin::SLUG_REPORTS,
			WPG_Admin::SLUG_DIAGNOSTICS,
		);
	}

	/**
	 * Get all valid tab slugs.
	 *
	 * @return string[]
	 */
	public static function valid_tabs(): array {
		return array(
			'dashboard',
			'plugin-disabler',
			'url-tester',
			'conflict-wizard',
			'update-simulator',
			'reports',
			'diagnostics',
		);
	}

	/**
	 * Map page slug to tab slug.
	 *
	 * @param string $page Page slug.
	 * @return string
	 */
	public static function page_to_tab( string $page ): string {
		$page = sanitize_key( $page );

		switch ( $page ) {
			case WPG_Admin::SLUG_CONFLICT_WIZARD:
				return 'conflict-wizard';
			case WPG_Admin::SLUG_URL_TESTER:
				return 'url-tester';
			case WPG_Admin::SLUG_PLUGIN_ISOLATION:
				return 'plugin-disabler';
			case WPG_Admin::SLUG_UPDATE_SIMULATOR:
				return 'update-simulator';
			case WPG_Admin::SLUG_REPORTS:
				return 'reports';
			case WPG_Admin::SLUG_DIAGNOSTICS:
				return 'diagnostics';
			case WPG_Admin::SLUG_DASHBOARD:
			default:
				return 'dashboard';
		}
	}

	/**
	 * Map tab slug to page slug.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function tab_to_page( string $tab ): string {
		$tab = sanitize_key( $tab );

		switch ( $tab ) {
			case 'conflict-wizard':
				return WPG_Admin::SLUG_CONFLICT_WIZARD;
			case 'url-tester':
				return WPG_Admin::SLUG_URL_TESTER;
			case 'plugin-disabler':
				return WPG_Admin::SLUG_PLUGIN_ISOLATION;
			case 'update-simulator':
				return WPG_Admin::SLUG_UPDATE_SIMULATOR;
			case 'reports':
				return WPG_Admin::SLUG_REPORTS;
			case 'diagnostics':
				return WPG_Admin::SLUG_DIAGNOSTICS;
			case 'dashboard':
			default:
				return WPG_Admin::SLUG_DASHBOARD;
		}
	}

	/**
	 * Build a WP Guardrail admin URL.
	 *
	 * @param array $args Optional query args.
	 * @return string
	 */
	public static function page_url( array $args = array() ): string {
		$page = isset( $args['page'] ) ? sanitize_key( $args['page'] ) : WPG_Admin::SLUG_DASHBOARD;
		if ( isset( $args['wsm_tab'] ) && ! isset( $args['page'] ) ) {
			$page = self::tab_to_page( $args['wsm_tab'] );
		}
		unset( $args['page'] );

		$url = add_query_arg( 'page', $page, admin_url( 'admin.php' ) );
		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}
		return $url;
	}

	/**
	 * Redirect back to a page with a notice slug in the URL.
	 *
	 * @param string $notice Notice slug.
	 * @param string $tab    Tab slug to redirect to.
	 * @return void
	 */
	public static function redirect_with_notice( string $notice, string $tab = 'plugin-disabler' ): void {
		$tab = sanitize_key( $tab );
		if ( 'sandbox' === $tab ) {
			$tab = 'plugin-disabler';
		}
		if ( ! in_array( $tab, self::valid_tabs(), true ) ) {
			$tab = 'dashboard';
		}

		wp_safe_redirect(
			self::page_url(
				array(
					'page'       => self::tab_to_page( $tab ),
					'wsm_notice' => sanitize_key( $notice ),
					'wsm_tab'    => $tab,
				)
			)
		);
		exit;
	}

	// =========================================================================
	// Private data helpers.
	// =========================================================================

	/**
	 * Resolve a plugin file path to a human-readable plugin name.
	 *
	 * @param string $plugin_file Plugin file path (dir/file.php).
	 * @return string
	 */
	public static function plugin_label( string $plugin_file ): string {
		static $plugins = null;

		$plugin_file = sanitize_text_field( $plugin_file );
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
			return $plugins[ $plugin_file ]['Name'];
		}

		return $plugin_file;
	}

	/**
	 * Active plugin baseline excluding this plugin itself.
	 *
	 * @return string[]
	 */
	private static function baseline_without_self(): array {
		$active = get_option( 'active_plugins', array() );
		$active = is_array( $active ) ? $active : array();
		$self   = WPG_Bootstrap::self_plugin_basename();
		$active = array_map( 'sanitize_text_field', $active );

		return array_values( array_diff( $active, array( $self ) ) );
	}

	/**
	 * Stub for admin_init hook — actual handling is in WPG_Admin_Actions.
	 *
	 * @return void
	 */
	public static function handle_post_actions(): void {
		// Intentionally empty. Action handling lives in WPG_Admin_Actions.
	}
}
