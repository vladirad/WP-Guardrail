<?php
/**
 * Admin POST/GET action handler for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all form submissions and GET-triggered actions.
 *
 * Routing helpers (redirect_with_notice, page_url, etc.) are
 * delegated to WPG_Admin_Pages, which owns the canonical
 * implementations.
 */
class WPG_Admin_Actions {

	/**
	 * Handle form submissions.
	 *
	 * @return void
	 */
	public static function handle_post_actions() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, WPG_Admin_Pages::allowed_pages(), true ) ) {
			return;
		}

		$snapshot_hash = isset( $_GET['guardrail_snapshot'] ) ? sanitize_text_field( wp_unslash( $_GET['guardrail_snapshot'] ) ) : '';
		if ( '' !== $snapshot_hash ) {
			check_admin_referer( 'wpg_restore_snapshot' );
			$result = WP_Guardrail_Snapshot::restore( $snapshot_hash );
			$notice = is_wp_error( $result ) ? 'snapshot_restore_failed' : 'snapshot_restored';

			wp_safe_redirect(
				WPG_Admin_Pages::page_url(
					array(
						'page'       => WPG_Admin::SLUG_DASHBOARD,
						'wsm_tab'    => 'dashboard',
						'wsm_notice' => $notice,
					)
				)
			);
			exit;
		}

		if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		$action = isset( $_POST['wsm_action'] ) ? sanitize_key( wp_unslash( $_POST['wsm_action'] ) ) : '';
		$tab    = isset( $_POST['wsm_tab'] ) ? sanitize_key( wp_unslash( $_POST['wsm_tab'] ) ) : WPG_Admin_Pages::page_to_tab( $page );
		if ( 'sandbox' === $tab ) {
			$tab = 'plugin-disabler';
		}
		if ( ! in_array( $tab, WPG_Admin_Pages::valid_tabs(), true ) ) {
			$tab = WPG_Admin_Pages::page_to_tab( $page );
		}

		switch ( $action ) {
			case 'start':
				check_admin_referer( 'wsm_start_action' );
				WPG_Session_Manager::start();
				WPG_Admin_Pages::redirect_with_notice( 'started', $tab );
				break;

			case 'stop':
				check_admin_referer( 'wsm_stop_action' );
				WPG_Session_Manager::stop();
				WPG_Admin_Pages::redirect_with_notice( 'stopped', $tab );
				break;

			case 'apply':
				check_admin_referer( 'wsm_apply_action' );
				$disabled = isset( $_POST['wsm_disabled'] ) ? (array) wp_unslash( $_POST['wsm_disabled'] ) : array();
				$disabled = array_map( 'sanitize_text_field', $disabled );

				// Strip protected plugins — they cannot be disabled regardless of selection.
				$protected = WPG_Safety_Guard::get_protected_plugins();
				$stripped  = array_values( array_intersect( $disabled, $protected ) );
				$disabled  = array_values( array_diff( $disabled, $protected ) );

				// Flag risky (auth/security/cache) plugins in the remaining set.
				$flagged   = WPG_Safety_Guard::get_flagged_plugins( $disabled );

				WPG_Session_Manager::apply_disabled( $disabled );

				if ( ! empty( $stripped ) ) {
					WPG_Admin_Pages::redirect_with_notice( 'applied_protected_stripped', 'plugin-disabler' );
				} elseif ( ! empty( $flagged ) ) {
					WPG_Admin_Pages::redirect_with_notice( 'applied_with_risk', 'plugin-disabler' );
				} else {
					WPG_Admin_Pages::redirect_with_notice( 'applied', 'plugin-disabler' );
				}
				break;

			case 'wpsm_run_test':
				check_admin_referer( 'wpsm_run_test', 'wpsm_nonce_run_test' );
				$input        = isset( $_POST['wpsm_test_url'] ) ? sanitize_text_field( wp_unslash( $_POST['wpsm_test_url'] ) ) : '';
				$mode         = isset( $_POST['wpsm_test_mode'] ) ? sanitize_key( wp_unslash( $_POST['wpsm_test_mode'] ) ) : 'baseline';
				$mode         = in_array( $mode, array( 'baseline', 'sandbox' ), true ) ? $mode : 'baseline';
				$forward_auth = ! empty( $_POST['wpg_forward_auth'] );

				if ( 'sandbox' === $mode && ! WPG_Container::instance()->is_sandbox_active() ) {
					WPG_Admin_Pages::redirect_with_notice( 'sandbox_required', 'url-tester' );
				}

				$url = WPG_URL_Tester::resolve_test_url( $input );

				if ( is_wp_error( $url ) ) {
					WPG_Admin_Pages::redirect_with_notice( 'invalid_url', 'url-tester' );
				}

				$result = WPG_URL_Tester::run_test( $url, $mode, $forward_auth );
				WPG_URL_Tester::append_result_to_session( $result );
				WPG_Admin_Pages::redirect_with_notice( 'tested', 'url-tester' );
				break;

			case 'wpsm_run_both':
				check_admin_referer( 'wpsm_run_both', 'wpsm_nonce_run_both' );
				if ( ! WPG_Container::instance()->is_sandbox_active() ) {
					WPG_Admin_Pages::redirect_with_notice( 'sandbox_required', 'url-tester' );
				}

				$input        = isset( $_POST['wpsm_test_url'] ) ? sanitize_text_field( wp_unslash( $_POST['wpsm_test_url'] ) ) : '';
				$forward_auth = ! empty( $_POST['wpg_forward_auth'] );
				$url          = WPG_URL_Tester::resolve_test_url( $input );

				if ( is_wp_error( $url ) ) {
					WPG_Admin_Pages::redirect_with_notice( 'invalid_url', 'url-tester' );
				}

				$baseline_result = WPG_URL_Tester::run_test( $url, 'baseline', $forward_auth );
				$sandbox_result  = WPG_URL_Tester::run_test( $url, 'sandbox', $forward_auth );
				WPG_URL_Tester::append_result_to_session( $baseline_result );
				WPG_URL_Tester::append_result_to_session( $sandbox_result );
				WPG_Admin_Pages::redirect_with_notice( 'tested_both', 'url-tester' );
				break;

			case 'wpsm_compare_baseline_sandbox':
				check_admin_referer( 'wpsm_compare_baseline_sandbox', 'wpsm_nonce_compare' );
				if ( ! WPG_Container::instance()->is_sandbox_active() ) {
					WPG_Admin_Pages::redirect_with_notice( 'sandbox_required', 'url-tester' );
				}

				$input        = isset( $_POST['wpsm_test_url'] ) ? sanitize_text_field( wp_unslash( $_POST['wpsm_test_url'] ) ) : '';
				$forward_auth = ! empty( $_POST['wpg_forward_auth'] );
				$url          = WPG_URL_Tester::resolve_test_url( $input );
				if ( is_wp_error( $url ) ) {
					WPG_Admin_Pages::redirect_with_notice( 'invalid_url', 'url-tester' );
				}

				$comparison = WPG_URL_Tester::run_compare( $url, $forward_auth );
				WPG_URL_Tester::append_compare_to_session( $comparison );
				WPG_Admin_Pages::redirect_with_notice( 'compared', 'url-tester' );
				break;

			case 'wpsm_clear_tests':
				check_admin_referer( 'wpsm_clear_tests' );
				WPG_URL_Tester::clear_results();
				WPG_Admin_Pages::redirect_with_notice( 'tests_cleared', 'url-tester' );
				break;

			case 'wpg_update_sim_run':
				check_admin_referer( 'wpg_update_sim_run', 'wpg_update_sim_nonce' );
				if ( ! WPG_Container::instance()->is_sandbox_active() ) {
					WPG_Admin_Pages::redirect_with_notice( 'sandbox_required', 'update-simulator' );
				}

				$selected_plugins = isset( $_POST['wpg_sim_plugins'] ) ? (array) wp_unslash( $_POST['wpg_sim_plugins'] ) : array();
				$raw_urls         = isset( $_POST['wpg_sim_urls'] ) ? (string) wp_unslash( $_POST['wpg_sim_urls'] ) : '';
				$test_urls        = WPG_Update_Simulator::normalize_urls_input( $raw_urls );
				update_option( 'wp_guardrail_test_urls', $test_urls, false );

				$prepared = WPG_Update_Simulator::download_and_extract_updates( $selected_plugins );
				if ( is_wp_error( $prepared ) ) {
					WPG_Admin_Pages::redirect_with_notice( 'update_sim_failed', 'update-simulator' );
				}

				$shims = WPG_Update_Simulator::create_shims( $prepared['hash'], $prepared['file_map'] );
				if ( is_wp_error( $shims ) ) {
					WPG_Admin_Pages::redirect_with_notice( 'update_sim_failed', 'update-simulator' );
				}

				WPG_Update_Simulator::enable_simulation(
					array(
						'hash'    => $prepared['hash'],
						'plugins' => $prepared['selected_plugins'],
						'paths'   => $prepared['file_map'],
						'shims'   => $shims,
						'sim_dir' => $prepared['sim_dir'],
						'results' => array(),
					)
				);

				$ran = WPG_Update_Simulator::run_simulation_tests( $test_urls );
				if ( is_wp_error( $ran ) ) {
					WPG_Admin_Pages::redirect_with_notice( 'update_sim_failed', 'update-simulator' );
				}

				WPG_Admin_Pages::redirect_with_notice( 'update_sim_ran', 'update-simulator' );
				break;

			case 'wpg_update_sim_reset':
				check_admin_referer( 'wpg_update_sim_reset', 'wpg_update_sim_reset_nonce' );
				WPG_Update_Simulator::disable_simulation();
				WPG_Admin_Pages::redirect_with_notice( 'update_sim_reset', 'update-simulator' );
				break;

			case 'wpg_update_sim_clear_leftovers':
				check_admin_referer( 'wpg_update_sim_clear_leftovers', 'wpg_update_sim_clear_leftovers_nonce' );
				WPG_Update_Simulator::cleanup_all_leftovers();
				WPG_Admin_Pages::redirect_with_notice( 'update_sim_leftovers_cleared', 'update-simulator' );
				break;

			case 'wpg_save_snapshot':
				check_admin_referer( 'wpg_save_snapshot', 'wpg_snapshot_nonce' );
				$hash = WP_Guardrail_Snapshot::create();
				if ( is_wp_error( $hash ) ) {
					WPG_Admin_Pages::redirect_with_notice( 'snapshot_save_failed', $tab );
				}

				wp_safe_redirect(
					WPG_Admin_Pages::page_url(
						array(
							'page'              => WPG_Admin_Pages::tab_to_page( $tab ),
							'wsm_notice'        => 'snapshot_saved',
							'wsm_tab'           => $tab,
							'wpg_snapshot_hash' => $hash,
						)
					)
				);
				exit;

			case 'wpg_download_report_json':
				check_admin_referer( 'wpg_download_report_json', 'wpg_report_nonce' );
				$report = WP_Guardrail_Report::build();
				$file   = 'wp-guardrail-report-' . gmdate( 'Ymd-His' ) . '.json';

				nocache_headers();
				header( 'Content-Type: application/json; charset=' . get_bloginfo( 'charset' ) );
				header( 'Content-Disposition: attachment; filename="' . $file . '"' );
				echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				exit;

			case 'wpg_wizard_start':
				check_admin_referer( 'wpg_wizard_start' );
				if ( ! WPG_Container::instance()->is_sandbox_active() ) {
					WPG_Admin_Pages::redirect_with_notice( 'wizard_requires_sandbox', 'conflict-wizard' );
				}

				$target_url = isset( $_POST['wpg_wizard_target_url'] ) ? sanitize_text_field( wp_unslash( $_POST['wpg_wizard_target_url'] ) ) : '';
				$excluded   = isset( $_POST['wpg_wizard_excluded'] ) ? (array) wp_unslash( $_POST['wpg_wizard_excluded'] ) : array();
				// Auto-add protected plugins to the wizard exclusion list.
				$excluded   = array_values( array_unique( array_merge( $excluded, WPG_Safety_Guard::get_protected_plugins() ) ) );
				$state      = WPG_Conflict_Wizard::start( $target_url, $excluded );

				if ( is_wp_error( $state ) ) {
					$notice = 'wizard_start_failed';
					if ( 'wsm_invalid_url' === $state->get_error_code() || 'wsm_invalid_host' === $state->get_error_code() ) {
						$notice = 'wizard_invalid_url';
					} elseif ( 'wizard_empty_pool' === $state->get_error_code() ) {
						$notice = 'wizard_empty_pool';
					}
					WPG_Admin_Pages::redirect_with_notice( $notice, 'conflict-wizard' );
				}

				WPG_Admin_Pages::redirect_with_notice( 'wizard_started', 'conflict-wizard' );
				break;

			case 'wpg_wizard_issue_still_happens':
				check_admin_referer( 'wpg_wizard_decision' );
				$state = WPG_Conflict_Wizard::submit_decision( true );
				if ( is_wp_error( $state ) ) {
					WPG_Admin_Pages::redirect_with_notice( 'wizard_state_error', 'conflict-wizard' );
				}
				WPG_Admin_Pages::redirect_with_notice( ! empty( $state['active'] ) ? 'wizard_next_step' : 'wizard_complete', 'conflict-wizard' );
				break;

			case 'wpg_wizard_issue_resolved':
				check_admin_referer( 'wpg_wizard_decision' );
				$state = WPG_Conflict_Wizard::submit_decision( false );
				if ( is_wp_error( $state ) ) {
					WPG_Admin_Pages::redirect_with_notice( 'wizard_state_error', 'conflict-wizard' );
				}
				WPG_Admin_Pages::redirect_with_notice( ! empty( $state['active'] ) ? 'wizard_next_step' : 'wizard_complete', 'conflict-wizard' );
				break;

			case 'wpg_wizard_reset':
				check_admin_referer( 'wpg_wizard_reset' );
				WPG_Conflict_Wizard::reset();
				WPG_Admin_Pages::redirect_with_notice( 'wizard_reset', 'conflict-wizard' );
				break;

			case 'wpg_set_protected':
				check_admin_referer( 'wpg_set_protected' );
				$new_protected = isset( $_POST['wpg_protected'] ) ? (array) wp_unslash( $_POST['wpg_protected'] ) : array();
				WPG_Safety_Guard::set_protected_plugins( $new_protected );
				WPG_Admin_Pages::redirect_with_notice( 'protected_saved', 'plugin-disabler' );
				break;

			case 'wpg_generate_recovery':
				check_admin_referer( 'wpg_generate_recovery' );
				WPG_Recovery::generate();
				WPG_Admin_Pages::redirect_with_notice( 'recovery_generated', 'dashboard' );
				break;

			case 'wpg_clear_recovery':
				check_admin_referer( 'wpg_clear_recovery' );
				WPG_Recovery::clear();
				WPG_Admin_Pages::redirect_with_notice( 'recovery_cleared', 'dashboard' );
				break;
		}
	}


}
