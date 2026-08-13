<?php
/**
 * Update Simulator page renderer for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Update Simulator admin page.
 */
class WPG_Page_Update_Simulator {

	/**
	 * Render the Update Simulator page.
	 *
	 * @param array $ctx Shared page context.
	 * @return void
	 */
	public static function render( array $ctx ): void {
		$is_active       = $ctx['is_active'];
		$simulation      = $ctx['simulation'];
		$pending_updates = $ctx['pending_updates'];
		$saved_test_urls = $ctx['saved_test_urls'];
		$page_url        = $ctx['page_url'];

		WPG_UI::page_header( array(
			'title'          => __( 'Update Simulator', 'wp-guardrail' ),
			'subtitle'       => __( 'Test plugin updates in your sandbox session before applying globally', 'wp-guardrail' ),
			'sandbox_active' => $is_active,
		) );

		// Limitation notice — always visible.
		self::render_limitation_notice();

		if ( ! $is_active ) :
			WPG_UI::alert( 'info', __( 'Sandbox Mode must be active to run the Update Simulator.', 'wp-guardrail' ) );
			return;
		endif;

		// Show preflight errors from the previous run if any.
		self::render_preflight_errors( $simulation );

		WPG_UI::card(
			__( 'Step 1 — Select Plugins', 'wp-guardrail' ),
			function() use ( $pending_updates, $page_url, $saved_test_urls ) {
				if ( empty( $pending_updates ) ) {
					WPG_UI::empty_state( '✅', __( 'No pending updates', 'wp-guardrail' ), __( 'WordPress reports no plugin updates are currently available.', 'wp-guardrail' ) );
					return;
				}
				?>
				<form method="post" action="<?php echo esc_url( $page_url ); ?>" id="wpg-update-sim-form">
					<?php wp_nonce_field( 'wpg_update_sim_run', 'wpg_update_sim_nonce' ); ?>
					<input type="hidden" name="wsm_tab" value="update-simulator" />
					<input type="hidden" name="wsm_action" value="wpg_update_sim_run" />

					<table class="widefat striped wpg-table">
						<thead>
							<tr>
								<th class="wpg-col-checkbox"><?php echo esc_html__( 'Select', 'wp-guardrail' ); ?></th>
								<th><?php echo esc_html__( 'Plugin', 'wp-guardrail' ); ?></th>
								<th><?php echo esc_html__( 'Current', 'wp-guardrail' ); ?></th>
								<th><?php echo esc_html__( 'New version', 'wp-guardrail' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pending_updates as $plugin_basename => $update_row ) : ?>
								<tr>
									<td class="wpg-col-checkbox">
										<input type="checkbox" name="wpg_sim_plugins[]" value="<?php echo esc_attr( $plugin_basename ); ?>" />
									</td>
									<td><?php echo esc_html( isset( $update_row['name'] ) ? $update_row['name'] : $plugin_basename ); ?></td>
									<td><?php echo esc_html( isset( $update_row['current_version'] ) ? $update_row['current_version'] : '' ); ?></td>
									<td><?php echo esc_html( isset( $update_row['new_version'] ) ? $update_row['new_version'] : '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<h3 style="margin-top:20px"><?php echo esc_html__( 'Step 2 — Test URLs', 'wp-guardrail' ); ?></h3>
					<p class="description"><?php echo esc_html__( 'One URL per line. Relative and same-host absolute URLs are supported.', 'wp-guardrail' ); ?></p>
					<p>
						<button type="button" class="button wpg-url-preset" data-url="/"><?php echo esc_html__( 'Homepage (/)', 'wp-guardrail' ); ?></button>
						<button type="button" class="button wpg-url-preset" data-url="/wp-admin/">/wp-admin/</button>
						<button type="button" class="button wpg-url-preset" data-url="/wp-json/">/wp-json/</button>
					</p>
					<textarea name="wpg_sim_urls" id="wpg-sim-urls" class="large-text code" rows="5"><?php echo esc_textarea( implode( "\n", $saved_test_urls ) ); ?></textarea>

					<p style="margin-top:12px">
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Run Update Simulation', 'wp-guardrail' ); ?></button>
					</p>
				</form>
				<?php
			}
		);

		// Simulation results.
		self::render_simulation_results( $simulation );

		// Reset / cleanup actions.
		?>
		<div class="wpg-wrap-forms">
			<form method="post" action="<?php echo esc_url( $page_url ); ?>">
				<?php wp_nonce_field( 'wpg_update_sim_reset', 'wpg_update_sim_reset_nonce' ); ?>
				<input type="hidden" name="wsm_tab" value="update-simulator" />
				<input type="hidden" name="wsm_action" value="wpg_update_sim_reset" />
				<button type="submit" class="button"><?php echo esc_html__( 'Reset Simulation', 'wp-guardrail' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( $page_url ); ?>">
				<?php wp_nonce_field( 'wpg_update_sim_clear_leftovers', 'wpg_update_sim_clear_leftovers_nonce' ); ?>
				<input type="hidden" name="wsm_tab" value="update-simulator" />
				<input type="hidden" name="wsm_action" value="wpg_update_sim_clear_leftovers" />
				<button type="submit" class="button"><?php echo esc_html__( 'Clear Leftover Data', 'wp-guardrail' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the simulation limitations notice.
	 *
	 * @return void
	 */
	private static function render_limitation_notice(): void {
		?>
		<div class="notice notice-info wpg-sim-limitation-notice" style="margin:12px 0;padding:12px 16px;border-left-color:#0073aa">
			<p>
				<strong><?php echo esc_html__( 'About Update Simulator', 'wp-guardrail' ); ?></strong> &mdash;
				<?php echo esc_html__( 'This tool is a compatibility smoke test, not a full staging environment.', 'wp-guardrail' ); ?>
			</p>
			<ul style="list-style:disc;margin:.5em 0 .5em 1.5em">
				<li><?php echo esc_html__( 'Downloads and extracts the update package, then shims the new version into your sandbox session.', 'wp-guardrail' ); ?></li>
				<li><?php echo esc_html__( 'Runs baseline vs simulated HTTP comparisons on the URLs you specify.', 'wp-guardrail' ); ?></li>
				<li><?php echo esc_html__( 'A passing result means the tested URLs responded without HTTP errors or detected fatals — it does not guarantee full feature compatibility.', 'wp-guardrail' ); ?></li>
				<li><?php echo esc_html__( 'Plugins that rely on exact installed directory paths, compiled assets, or database schema migrations may not behave accurately under simulation.', 'wp-guardrail' ); ?></li>
				<li><?php echo esc_html__( 'Always take a full site backup before applying real updates.', 'wp-guardrail' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render any preflight or structured errors from the last simulation run.
	 *
	 * @param array $simulation Current simulation state.
	 * @return void
	 */
	private static function render_preflight_errors( array $simulation ): void {
		$preflight = isset( $simulation['preflight'] ) && is_array( $simulation['preflight'] ) ? $simulation['preflight'] : array();
		if ( empty( $preflight ) || ! empty( $preflight['passed'] ) ) {
			return;
		}

		$errors = isset( $preflight['errors'] ) && is_array( $preflight['errors'] ) ? $preflight['errors'] : array();
		if ( empty( $errors ) ) {
			return;
		}

		echo '<div class="notice notice-error" style="margin:12px 0">';
		echo '<p><strong>' . esc_html__( 'Simulation preflight failed', 'wp-guardrail' ) . '</strong></p>';
		echo '<ul style="list-style:disc;margin:.5em 0 .5em 1.5em">';
		foreach ( $errors as $error ) {
			$code    = isset( $error['code'] )    ? sanitize_key( $error['code'] ) : '';
			$summary = isset( $error['summary'] ) ? (string) $error['summary'] : '';
			$details = isset( $error['details'] ) ? (string) $error['details'] : '';
			$next    = isset( $error['next'] )    ? (string) $error['next'] : '';

			echo '<li>';
			if ( '' !== $code ) {
				echo '<code class="wpg-inline-code">' . esc_html( $code ) . '</code> &mdash; ';
			}
			echo esc_html( $summary );
			if ( '' !== $details ) {
				echo ' <small class="wpg-text-muted">(' . esc_html( $details ) . ')</small>';
			}
			if ( '' !== $next ) {
				echo '<br><em>' . esc_html__( 'Next step:', 'wp-guardrail' ) . '</em> ' . esc_html( $next );
			}
			echo '</li>';
		}
		echo '</ul></div>';

		// Stale artifacts warning.
		$cleanup = isset( $simulation['cleanup'] ) && is_array( $simulation['cleanup'] ) ? $simulation['cleanup'] : array();
		if ( ! empty( $cleanup['failed'] ) ) {
			WPG_UI::alert( 'warning', __( 'Temporary simulation files from a previous run could not be fully removed. Use "Clear Leftover Data" to clean them up.', 'wp-guardrail' ) );
		}
	}

	/**
	 * Render simulation run results if available.
	 *
	 * @param array $simulation Simulation state.
	 * @return void
	 */
	private static function render_simulation_results( array $simulation ): void {
		$sim_results   = isset( $simulation['results'] ) && is_array( $simulation['results'] ) ? $simulation['results'] : array();
		$latest_sim    = ! empty( $sim_results ) && is_array( $sim_results[0] ) ? $sim_results[0] : array();
		$baseline_sim  = isset( $latest_sim['baseline'] )  && is_array( $latest_sim['baseline'] )  ? $latest_sim['baseline']  : array();
		$simulated_sim = isset( $latest_sim['simulated'] ) && is_array( $latest_sim['simulated'] ) ? $latest_sim['simulated'] : array();

		if ( empty( $latest_sim ) ) {
			return;
		}

		$has_issues  = 'possible_issues' === ( isset( $latest_sim['summary'] ) ? $latest_sim['summary'] : '' );
		$compare_data = WPG_UI::build_compare_rows( $baseline_sim, $simulated_sim );
		$compare_data['after']['label'] = __( 'Simulated (new version)', 'wp-guardrail' );

		// Run summary report (preflight + cleanup + result).
		$preflight = isset( $simulation['preflight'] ) && is_array( $simulation['preflight'] ) ? $simulation['preflight'] : array();
		$cleanup   = isset( $simulation['cleanup'] )   && is_array( $simulation['cleanup'] )   ? $simulation['cleanup']   : array();

		WPG_UI::card(
			__( 'Simulation Results', 'wp-guardrail' ),
			function() use ( $compare_data, $has_issues, $latest_sim, $preflight, $cleanup ) {
				// Result summary.
				if ( $has_issues ) {
					WPG_UI::issue_card( 'warning', __( 'Possible issues detected', 'wp-guardrail' ), __( 'The simulated version produced different HTTP status, increased response time, or a fatal error compared to baseline.', 'wp-guardrail' ) );
				} else {
					WPG_UI::alert( 'success', __( 'No issues detected — simulated version behaved the same as baseline.', 'wp-guardrail' ) );
				}

				WPG_UI::compare_panel( $compare_data['before'], $compare_data['after'] );

				// Run details table.
				?>
				<h3 style="margin:16px 0 6px;font-size:13px"><?php echo esc_html__( 'Run Details', 'wp-guardrail' ); ?></h3>
				<table class="widefat striped wpg-table" style="max-width:480px">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Tested URL', 'wp-guardrail' ); ?></th>
							<td><code class="wpg-inline-code"><?php echo esc_html( isset( $latest_sim['url'] ) ? $latest_sim['url'] : '—' ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Timestamp', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( isset( $latest_sim['timestamp'] ) ? $latest_sim['timestamp'] : '—' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Preflight', 'wp-guardrail' ); ?></th>
							<td>
								<?php if ( ! empty( $preflight['passed'] ) ) : ?>
									<span class="wpg-table wpg-status-ok"><?php echo esc_html__( 'Passed', 'wp-guardrail' ); ?></span>
								<?php elseif ( ! empty( $preflight ) ) : ?>
									<span class="wpg-table wpg-status-fail"><?php echo esc_html__( 'Failed', 'wp-guardrail' ); ?></span>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Cleanup', 'wp-guardrail' ); ?></th>
							<td>
								<?php if ( ! empty( $cleanup['failed'] ) ) : ?>
									<span class="wpg-table wpg-status-fail"><?php echo esc_html__( 'Partial failure — use Clear Leftover Data', 'wp-guardrail' ); ?></span>
								<?php elseif ( ! empty( $cleanup['attempted'] ) ) : ?>
									<span class="wpg-table wpg-status-ok"><?php echo esc_html__( 'Complete', 'wp-guardrail' ); ?></span>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Final result', 'wp-guardrail' ); ?></th>
							<td>
								<?php if ( $has_issues ) : ?>
									<span class="wpg-table wpg-status-fail"><?php echo esc_html__( 'Warning — possible issues', 'wp-guardrail' ); ?></span>
								<?php else : ?>
									<span class="wpg-table wpg-status-ok"><?php echo esc_html__( 'Pass', 'wp-guardrail' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>
				<p class="description" style="margin-top:8px">
					<?php echo esc_html__( 'Reminder: a passing result confirms HTTP compatibility for the tested URLs only. It does not guarantee full feature compatibility.', 'wp-guardrail' ); ?>
				</p>
				<?php
			},
			array(
				'badge_severity' => $has_issues ? 'warning' : 'healthy',
				'badge_label'    => $has_issues ? __( 'Issues detected', 'wp-guardrail' ) : __( 'Pass', 'wp-guardrail' ),
			)
		);
	}
}
