<?php
/**
 * Conflict Wizard page renderer for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Conflict Wizard admin page.
 */
class WPG_Page_Conflict_Wizard {

	/**
	 * Render the Conflict Wizard page.
	 *
	 * @param array $ctx Shared page context.
	 * @return void
	 */
	public static function render( array $ctx ): void {
		$is_active   = $ctx['is_active'];
		$baseline    = $ctx['baseline'];
		$wizard      = $ctx['wizard'];
		$show_report = $ctx['show_report'];
		$page_url    = $ctx['page_url'];

		WPG_UI::page_header( array(
			'title'          => __( 'Conflict Wizard', 'wp-guardrail' ),
			'subtitle'       => __( 'Guided binary plugin isolation to pinpoint conflicts', 'wp-guardrail' ),
			'sandbox_active' => $is_active,
		) );

		if ( ! $is_active ) :
			WPG_UI::alert( 'info', __( 'Sandbox Mode must be active to run Conflict Wizard.', 'wp-guardrail' ) );
		else :
			if ( ! empty( $wizard['active'] ) ) :
				self::render_active_step( $wizard, $page_url );
			else :
				self::render_idle_state( $wizard, $baseline, $show_report, $page_url );
			endif;

			// Reset button (always available when sandbox active).
			?>
			<form method="post" action="<?php echo esc_url( $page_url ); ?>" style="margin-top:4px">
				<?php wp_nonce_field( 'wpg_wizard_reset' ); ?>
				<input type="hidden" name="wsm_tab" value="conflict-wizard" />
				<input type="hidden" name="wsm_action" value="wpg_wizard_reset" />
				<button type="submit" class="button button-secondary"><?php echo esc_html__( 'Reset Wizard', 'wp-guardrail' ); ?></button>
			</form>
			<?php
		endif;
	}

	/**
	 * Render the active wizard step UI.
	 *
	 * @param array  $wizard   Wizard state.
	 * @param string $page_url Admin page URL.
	 * @return void
	 */
	private static function render_active_step( array $wizard, string $page_url ): void {
		$total_steps     = WPG_Conflict_Wizard::estimated_total_steps( $wizard );
		$completed_steps = isset( $wizard['completed_steps'] ) ? max( 0, (int) $wizard['completed_steps'] ) : 0;
		$current_step    = min( max( 1, $completed_steps + 1 ), max( 1, $total_steps ) );
		$remaining_steps = max( 0, (int) $total_steps - (int) $current_step );
		$pool_remaining  = isset( $wizard['plugins_pool'] ) && is_array( $wizard['plugins_pool'] ) ? count( $wizard['plugins_pool'] ) : 0;
		$step_label      = sprintf(
			/* translators: 1: current step, 2: estimated total steps */
			__( 'Step %1$d of %2$d (estimated)', 'wp-guardrail' ),
			(int) $current_step,
			(int) $total_steps
		);

		WPG_UI::card(
			__( 'Current Step', 'wp-guardrail' ),
			function() use ( $wizard, $step_label, $current_step, $total_steps, $remaining_steps, $pool_remaining, $page_url ) {
				WPG_UI::progress_bar( $current_step, $total_steps, $step_label );
				?>
				<p class="wpg-text-muted">
					<?php echo esc_html( sprintf( __( 'Estimated steps remaining: %d', 'wp-guardrail' ), (int) $remaining_steps ) ); ?> &middot;
					<?php echo esc_html( sprintf( __( 'Plugins in pool: %d', 'wp-guardrail' ), (int) $pool_remaining ) ); ?>
				</p>
				<p>
					<strong><?php echo esc_html__( 'Target URL:', 'wp-guardrail' ); ?></strong>
					<code class="wpg-inline-code"><?php echo esc_html( $wizard['target_url'] ); ?></code>
				</p>
				<?php if ( ! empty( $wizard['current_group_a'] ) ) : ?>
					<p><?php echo esc_html__( 'Currently disabled (Group A):', 'wp-guardrail' ); ?></p>
					<ul style="margin:.5em 0 .5em 1.5em;list-style:disc">
						<?php foreach ( $wizard['current_group_a'] as $pf ) : ?>
							<li><?php echo esc_html( WPG_Admin_Pages::plugin_label( $pf ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php
				$step_test = isset( $wizard['step_auto_test'] ) && is_array( $wizard['step_auto_test'] ) ? $wizard['step_auto_test'] : array();
				$st_code   = isset( $step_test['http_code'] ) ? (int) $step_test['http_code'] : 0;
				$st_fatal  = ! empty( $step_test['fatal_suspected'] );
				$st_time   = isset( $step_test['time_ms'] ) ? (int) $step_test['time_ms'] : 0;
				$st_err    = isset( $step_test['error_message'] ) ? (string) $step_test['error_message'] : '';
				$st_problem = ( 0 === $st_code || $st_code >= 500 || $st_fatal );
				?>
				<?php if ( ! empty( $step_test ) ) : ?>
					<table class="widefat striped wpg-table" style="max-width:420px;margin:12px 0">
						<tbody>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Automated test — HTTP status', 'wp-guardrail' ); ?></th>
								<td><?php echo esc_html( $st_code > 0 ? (string) $st_code : __( 'Error / No response', 'wp-guardrail' ) ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Fatal / Error', 'wp-guardrail' ); ?></th>
								<td><?php echo $st_fatal ? esc_html__( 'Suspected', 'wp-guardrail' ) : esc_html__( 'None detected', 'wp-guardrail' ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Response time', 'wp-guardrail' ); ?></th>
								<td><?php echo esc_html( $st_time . ' ms' ); ?></td>
							</tr>
							<?php if ( '' !== $st_err ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html__( 'Error', 'wp-guardrail' ); ?></th>
									<td><code class="wpg-inline-code"><?php echo esc_html( $st_err ); ?></code></td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
					<?php if ( $st_problem ) : ?>
						<?php WPG_UI::alert( 'warning', __( 'Automated test suggests the issue may still be present with these plugins disabled.', 'wp-guardrail' ) ); ?>
					<?php else : ?>
						<?php WPG_UI::alert( 'success', __( 'Automated test suggests the issue may be resolved with these plugins disabled.', 'wp-guardrail' ) ); ?>
					<?php endif; ?>
				<?php endif; ?>

				<p style="margin-top:8px"><strong><?php echo esc_html__( 'Does the issue still happen on your site?', 'wp-guardrail' ); ?></strong></p>
				<div class="wpg-wizard-actions">
					<form method="post" action="<?php echo esc_url( $page_url ); ?>">
						<?php wp_nonce_field( 'wpg_wizard_decision' ); ?>
						<input type="hidden" name="wsm_tab" value="conflict-wizard" />
						<input type="hidden" name="wsm_action" value="wpg_wizard_issue_still_happens" />
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Yes, issue still happens', 'wp-guardrail' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( $page_url ); ?>">
						<?php wp_nonce_field( 'wpg_wizard_decision' ); ?>
						<input type="hidden" name="wsm_tab" value="conflict-wizard" />
						<input type="hidden" name="wsm_action" value="wpg_wizard_issue_resolved" />
						<button type="submit" class="button"><?php echo esc_html__( 'No, issue is resolved', 'wp-guardrail' ); ?></button>
					</form>
				</div>
				<?php
			}
		);
	}

	/**
	 * Render the wizard idle state (start form and optional result).
	 *
	 * @param array  $wizard      Wizard state.
	 * @param array  $baseline    Baseline plugin list.
	 * @param bool   $show_report Whether to show report output.
	 * @param string $page_url    Admin page URL.
	 * @return void
	 */
	private static function render_idle_state( array $wizard, array $baseline, bool $show_report, string $page_url ): void {
		$pool_count     = isset( $wizard['plugins_pool'] ) && is_array( $wizard['plugins_pool'] ) ? count( $wizard['plugins_pool'] ) : 0;
		$excluded_count = isset( $wizard['excluded_plugins'] ) && is_array( $wizard['excluded_plugins'] ) ? count( $wizard['excluded_plugins'] ) : 0;

		if ( 0 === $pool_count && 'empty_pool' === ( isset( $wizard['state_error'] ) ? $wizard['state_error'] : '' ) ) :
			WPG_UI::alert( 'warning', __( 'No plugins remain in the testable pool. Please restart the wizard and try again.', 'wp-guardrail' ) );

		elseif ( 1 === $pool_count && ! empty( $wizard['history'] ) ) :
			self::render_result( $wizard, $excluded_count, $show_report, $page_url );
		endif;

		// Start form.
		WPG_UI::card(
			__( 'Start New Detection Run', 'wp-guardrail' ),
			function() use ( $baseline, $page_url ) {
				?>
				<form method="post" action="<?php echo esc_url( $page_url ); ?>">
					<?php wp_nonce_field( 'wpg_wizard_start' ); ?>
					<input type="hidden" name="wsm_tab" value="conflict-wizard" />
					<input type="hidden" name="wsm_action" value="wpg_wizard_start" />
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="wpg-wizard-target-url"><?php echo esc_html__( 'Target URL', 'wp-guardrail' ); ?></label>
								</th>
								<td>
									<input type="text" class="regular-text code" id="wpg-wizard-target-url" name="wpg_wizard_target_url" placeholder="/checkout/ or https://example.com/page" required />
									<p class="description"><?php echo esc_html__( 'Use a URL where you can reproduce the issue.', 'wp-guardrail' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Exclude plugins (always enabled during test)', 'wp-guardrail' ); ?></th>
								<td>
									<?php if ( empty( $baseline ) ) : ?>
										<p><?php echo esc_html__( 'No active plugins available.', 'wp-guardrail' ); ?></p>
									<?php else : ?>
										<?php foreach ( $baseline as $plugin_file ) : ?>
											<label style="display:block;margin-bottom:4px">
												<input type="checkbox" name="wpg_wizard_excluded[]" value="<?php echo esc_attr( $plugin_file ); ?>" />
												<?php echo esc_html( WPG_Admin_Pages::plugin_label( $plugin_file ) ); ?>
											</label>
										<?php endforeach; ?>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>
					<p>
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Start Conflict Wizard', 'wp-guardrail' ); ?></button>
					</p>
				</form>
				<?php
			}
		);
	}

	/**
	 * Render the wizard result card and step history.
	 *
	 * @param array  $wizard         Wizard state.
	 * @param int    $excluded_count Number of excluded plugins.
	 * @param bool   $show_report    Whether to show report output.
	 * @param string $page_url       Admin page URL.
	 * @return void
	 */
	private static function render_result( array $wizard, int $excluded_count, bool $show_report, string $page_url ): void {
		$confidence = WPG_Conflict_Wizard::compute_confidence( $wizard );

		WPG_UI::card(
			__( 'Result: Likely Conflict Identified', 'wp-guardrail' ),
			function() use ( $wizard, $excluded_count, $confidence, $show_report ) {
				echo '<p>';
				WPG_UI::badge( 'critical', __( 'Likely conflict', 'wp-guardrail' ) );
				echo ' <strong>' . esc_html( WPG_Admin_Pages::plugin_label( $wizard['plugins_pool'][0] ) ) . '</strong>';
				echo '</p>';

				if ( null !== $confidence ) :
					echo '<p>' . esc_html(
						sprintf(
							/* translators: 1: score, 2: corroborated count, 3: tested count */
							__( 'Test corroboration: %1$s — %2$d of %3$d automated test results aligned with your reported outcomes.', 'wp-guardrail' ),
							$confidence['score'] . '%',
							$confidence['corroborated'],
							$confidence['tested']
						)
					) . '</p>';
					if ( $confidence['corroborated'] < $confidence['tested'] ) :
						WPG_UI::alert( 'info', __( 'Some automated test results did not align with your reports. The conflict may involve an issue not detectable by HTTP response analysis alone (e.g., visual bugs, JavaScript errors, content differences).', 'wp-guardrail' ) );
					endif;
				endif;

				if ( $excluded_count > 0 ) :
					echo '<p>' . esc_html__( 'Always active during test:', 'wp-guardrail' ) . '</p><ul style="margin:.5em 0 .5em 1.5em;list-style:disc">';
					foreach ( $wizard['excluded_plugins'] as $plugin_file ) {
						echo '<li>' . esc_html( WPG_Admin_Pages::plugin_label( $plugin_file ) ) . '</li>';
					}
					echo '</ul>';
				endif;

				echo '<p class="description">' . esc_html__( 'If the issue persists even with all other plugins disabled, the conflict may involve an always-active plugin or the active theme.', 'wp-guardrail' ) . '</p>';

				$export_lines   = array();
				$export_lines[] = __( 'WP Guardrail Conflict Wizard Result', 'wp-guardrail' );
				$export_lines[] = sprintf( __( 'Target URL: %s', 'wp-guardrail' ), $wizard['target_url'] );
				$export_lines[] = __( 'Most likely culprit:', 'wp-guardrail' );
				$export_lines[] = '- ' . WPG_Admin_Pages::plugin_label( $wizard['plugins_pool'][0] ) . ' (' . $wizard['plugins_pool'][0] . ')';
				if ( null !== $confidence ) {
					$export_lines[] = sprintf( __( 'Test Corroboration: %d%%', 'wp-guardrail' ), $confidence['score'] );
				}
				echo '<p>' . esc_html__( 'Export summary:', 'wp-guardrail' ) . '</p>';
				echo '<textarea class="large-text code" rows="6" readonly>' . esc_textarea( implode( "\n", $export_lines ) ) . '</textarea>';

				WPG_Admin_Pages::render_snapshot_button( 'conflict-wizard' );
				WPG_Admin_Pages::render_report_section( 'conflict-wizard', $show_report );
			},
			array( 'badge_severity' => 'critical', 'badge_label' => __( 'Complete', 'wp-guardrail' ) )
		);

		// Step history table.
		$decision_history = array_values(
			array_filter(
				$wizard['history'],
				static function ( $e ) {
					return isset( $e['event'] ) && 'decision' === $e['event'];
				}
			)
		);

		if ( ! empty( $decision_history ) ) :
			WPG_UI::section_header( __( 'Step History', 'wp-guardrail' ) );
			?>
			<table class="widefat striped wpg-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Step', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Decision', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'HTTP status', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Fatal', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Time', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Timestamp', 'wp-guardrail' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $decision_history as $hist_entry ) :
						$hist_entry = is_array( $hist_entry ) ? $hist_entry : array();
						$hist_test  = isset( $hist_entry['step_test'] ) && is_array( $hist_entry['step_test'] ) ? $hist_entry['step_test'] : array();
						$hist_code  = isset( $hist_test['http_code'] ) ? (int) $hist_test['http_code'] : 0;
						$hist_fatal = ! empty( $hist_test['fatal_suspected'] );
						$hist_time  = isset( $hist_test['time_ms'] ) ? (int) $hist_test['time_ms'] : 0;
						$persists   = ! empty( $hist_entry['issue_persists'] );
					?>
						<tr>
							<td><?php echo esc_html( isset( $hist_entry['step'] ) ? (string) (int) $hist_entry['step'] : '' ); ?></td>
							<td>
								<?php WPG_UI::badge(
									$persists ? 'warning' : 'healthy',
									$persists ? __( 'Issue persists', 'wp-guardrail' ) : __( 'Issue resolved', 'wp-guardrail' )
								); ?>
							</td>
							<td><?php echo esc_html( empty( $hist_test ) ? '—' : ( $hist_code > 0 ? (string) $hist_code : __( 'Error', 'wp-guardrail' ) ) ); ?></td>
							<td><?php echo empty( $hist_test ) ? '—' : ( $hist_fatal ? esc_html__( 'Yes', 'wp-guardrail' ) : esc_html__( 'No', 'wp-guardrail' ) ); ?></td>
							<td><?php echo esc_html( empty( $hist_test ) ? '—' : $hist_time . ' ms' ); ?></td>
							<td><?php echo esc_html( isset( $hist_entry['timestamp'] ) ? $hist_entry['timestamp'] : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endif;
	}
}
