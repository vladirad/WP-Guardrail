<?php
/**
 * Reports admin page for WP Guardrail.
 *
 * Provides:
 *  - Report generation form (type, mode, module selection).
 *  - Saved reports table with view, copy, export, publish, and delete actions.
 *  - Health pages management table.
 *  - Inline report viewer for summary and technical modes.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Reports admin page.
 */
class WPG_Page_Reports {

	/**
	 * Render the Reports page.
	 *
	 * @param array $ctx Shared page context from WPG_Admin_Pages::render_page().
	 * @return void
	 */
	public static function render( array $ctx ): void {
		$is_active = $ctx['is_active'];

		// Determine active sub-action.
		$view_id = isset( $_GET['wpg_view_report'] ) ? sanitize_key( wp_unslash( $_GET['wpg_view_report'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		WPG_UI::page_header( array(
			'title'          => __( 'Reports', 'wp-guardrail' ),
			'subtitle'       => __( 'Generate, save, and publish diagnostic snapshots', 'wp-guardrail' ),
			'sandbox_active' => $is_active,
		) );

		// --- Inline report viewer ----------------------------------------
		if ( '' !== $view_id ) {
			self::render_view_report( $view_id );
			return;
		}

		// --- Generate report card ----------------------------------------
		WPG_UI::card(
			__( 'Generate Report', 'wp-guardrail' ),
			function () {
				self::render_generate_form();
			}
		);

		// --- Saved reports table card ------------------------------------
		WPG_UI::card(
			__( 'Saved Reports', 'wp-guardrail' ),
			function () {
				self::render_saved_reports_table();
			}
		);

		// --- Health pages management card --------------------------------
		WPG_UI::card(
			__( 'Health Pages', 'wp-guardrail' ),
			function () {
				self::render_health_pages_table();
			}
		);
	}

	// =========================================================================
	// Generate form.
	// =========================================================================

	/**
	 * Render the report generation form.
	 *
	 * @return void
	 */
	private static function render_generate_form(): void {
		$page_url = WPG_Admin_Pages::page_url( array( 'wsm_tab' => 'reports' ) );
		?>
		<p><?php esc_html_e( 'Generate a report from the current module data and save it. Reports capture a snapshot of the state at the moment of generation.', 'wp-guardrail' ); ?></p>

		<form method="post" action="<?php echo esc_url( $page_url ); ?>">
			<?php wp_nonce_field( 'wpg_generate_report', 'wpg_report_nonce' ); ?>
			<input type="hidden" name="wsm_action" value="wpg_generate_report" />
			<input type="hidden" name="wsm_tab"    value="reports" />

			<table class="form-table" role="presentation" style="max-width:600px;">
				<tr>
					<th scope="row">
						<label for="wpg_report_type"><?php esc_html_e( 'Report Type', 'wp-guardrail' ); ?></label>
					</th>
					<td>
						<select name="wpg_report_type" id="wpg_report_type">
							<option value="general"><?php esc_html_e( 'General (all modules)', 'wp-guardrail' ); ?></option>
							<optgroup label="<?php esc_attr_e( 'Module Reports', 'wp-guardrail' ); ?>">
								<option value="url_tester"><?php esc_html_e( 'URL Tester', 'wp-guardrail' ); ?></option>
								<option value="conflict"><?php esc_html_e( 'Conflict Detector', 'wp-guardrail' ); ?></option>
								<option value="update_simulator"><?php esc_html_e( 'Update Simulator', 'wp-guardrail' ); ?></option>
								<option value="diagnostics"><?php esc_html_e( 'Site Diagnostics', 'wp-guardrail' ); ?></option>
								<option value="sandbox"><?php esc_html_e( 'Plugin Isolation', 'wp-guardrail' ); ?></option>
							</optgroup>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wpg_report_mode"><?php esc_html_e( 'Report Mode', 'wp-guardrail' ); ?></label>
					</th>
					<td>
						<select name="wpg_report_mode" id="wpg_report_mode">
							<option value="summary"><?php esc_html_e( 'Summary — readable overview for clients and managers', 'wp-guardrail' ); ?></option>
							<option value="technical"><?php esc_html_e( 'Technical — full detail for developers and support', 'wp-guardrail' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<p>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Generate &amp; Save Report', 'wp-guardrail' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	// =========================================================================
	// Saved reports table.
	// =========================================================================

	/**
	 * Render the saved reports table.
	 *
	 * @return void
	 */
	private static function render_saved_reports_table(): void {
		$reports  = WPG_Report_Repository::list();
		$page_url = WPG_Admin_Pages::page_url( array( 'wsm_tab' => 'reports' ) );

		if ( empty( $reports ) ) {
			WPG_UI::empty_state(
				'📋',
				__( 'No saved reports yet', 'wp-guardrail' ),
				__( 'Generate a report above to create your first snapshot.', 'wp-guardrail' )
			);
			return;
		}

		$status_labels = array(
			'ok'       => __( 'Healthy', 'wp-guardrail' ),
			'warning'  => __( 'Warning', 'wp-guardrail' ),
			'critical' => __( 'Critical', 'wp-guardrail' ),
			'info'     => __( 'Info', 'wp-guardrail' ),
		);

		$module_labels = array(
			'general'          => __( 'General', 'wp-guardrail' ),
			'url_tester'       => __( 'URL Tester', 'wp-guardrail' ),
			'conflict'         => __( 'Conflict Detector', 'wp-guardrail' ),
			'update_simulator' => __( 'Update Simulator', 'wp-guardrail' ),
			'diagnostics'      => __( 'Site Diagnostics', 'wp-guardrail' ),
			'sandbox'          => __( 'Plugin Isolation', 'wp-guardrail' ),
		);
		?>
		<table class="widefat striped" style="border-radius:4px;overflow:hidden;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'wp-guardrail' ); ?></th>
					<th><?php esc_html_e( 'Module', 'wp-guardrail' ); ?></th>
					<th><?php esc_html_e( 'Mode', 'wp-guardrail' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-guardrail' ); ?></th>
					<th><?php esc_html_e( 'Generated', 'wp-guardrail' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wp-guardrail' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $reports as $row ) :
					$id         = $row['id']         ?? '';
					$title      = $row['title']       ?? '';
					$module     = $row['module']      ?? '';
					$mode       = $row['mode']        ?? '';
					$status     = $row['status']      ?? 'ok';
					$created_at = $row['created_at']  ?? '';

					$module_label = $module_labels[ $module ] ?? ucwords( str_replace( '_', ' ', $module ) );
					$status_label = $status_labels[ $status ] ?? ucwords( $status );
					$view_url     = add_query_arg( 'wpg_view_report', rawurlencode( $id ), $page_url );
				?>
				<tr>
					<td>
						<a href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $title ); ?></a>
					</td>
					<td><?php echo esc_html( $module_label ); ?></td>
					<td><?php echo esc_html( ucfirst( $mode ) ); ?></td>
					<td><?php WPG_UI::badge( $status, $status_label ); ?></td>
					<td><?php echo esc_html( $created_at ); ?></td>
					<td>
						<div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
							<!-- View -->
							<a href="<?php echo esc_url( $view_url ); ?>" class="button button-small">
								<?php esc_html_e( 'View', 'wp-guardrail' ); ?>
							</a>

							<!-- Export JSON -->
							<form method="post" action="<?php echo esc_url( $page_url ); ?>" style="display:inline">
								<?php wp_nonce_field( 'wpg_export_report_json', 'wpg_export_nonce' ); ?>
								<input type="hidden" name="wsm_action"       value="wpg_export_report_json" />
								<input type="hidden" name="wpg_report_id"    value="<?php echo esc_attr( $id ); ?>" />
								<input type="hidden" name="wsm_tab"          value="reports" />
								<button type="submit" class="button button-small">
									<?php esc_html_e( 'Export JSON', 'wp-guardrail' ); ?>
								</button>
							</form>

							<!-- Publish as health page -->
							<form method="post" action="<?php echo esc_url( $page_url ); ?>" style="display:inline">
								<?php wp_nonce_field( 'wpg_publish_health_page', 'wpg_health_nonce' ); ?>
								<input type="hidden" name="wsm_action"       value="wpg_publish_health_page" />
								<input type="hidden" name="wpg_report_id"    value="<?php echo esc_attr( $id ); ?>" />
								<input type="hidden" name="wsm_tab"          value="reports" />
								<button type="submit" class="button button-small">
									<?php esc_html_e( 'Publish Health Page', 'wp-guardrail' ); ?>
								</button>
							</form>

							<!-- Delete -->
							<form method="post" action="<?php echo esc_url( $page_url ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this report?', 'wp-guardrail' ) ); ?>')">
								<?php wp_nonce_field( 'wpg_delete_report', 'wpg_delete_nonce' ); ?>
								<input type="hidden" name="wsm_action"       value="wpg_delete_report" />
								<input type="hidden" name="wpg_report_id"    value="<?php echo esc_attr( $id ); ?>" />
								<input type="hidden" name="wsm_tab"          value="reports" />
								<button type="submit" class="button button-small" style="color:#b32d2e;border-color:#b32d2e;">
									<?php esc_html_e( 'Delete', 'wp-guardrail' ); ?>
								</button>
							</form>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// =========================================================================
	// Health pages table.
	// =========================================================================

	/**
	 * Render the health pages management table.
	 *
	 * @return void
	 */
	private static function render_health_pages_table(): void {
		$pages    = WPG_Health_Page_Publisher::list();
		$page_url = WPG_Admin_Pages::page_url( array( 'wsm_tab' => 'reports' ) );

		echo '<p>' . esc_html__( 'Health pages are shareable snapshots of saved reports. They can be viewed without logging in (token mode) or restricted to admins only.', 'wp-guardrail' ) . '</p>';

		if ( empty( $pages ) ) {
			echo '<p class="description">' . esc_html__( 'No health pages published yet. Use "Publish Health Page" on a saved report to create one.', 'wp-guardrail' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped" style="border-radius:4px;overflow:hidden;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Report Title', 'wp-guardrail' ); ?></th>
					<th><?php esc_html_e( 'Access', 'wp-guardrail' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'wp-guardrail' ); ?></th>
					<th><?php esc_html_e( 'URL', 'wp-guardrail' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wp-guardrail' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pages as $hp ) :
					$token        = $hp['token']        ?? '';
					$report_title = $hp['report_title'] ?? '';
					$access_mode  = $hp['access_mode']  ?? 'token';
					$expires_at   = (int) ( $hp['expires_at'] ?? 0 );
					$health_url   = WPG_Health_Page_Publisher::get_url( $token );

					$expires_label = $expires_at > 0
						? ( time() > $expires_at
							? '<span style="color:#b32d2e">' . esc_html__( 'Expired', 'wp-guardrail' ) . '</span>'
							: esc_html( gmdate( 'Y-m-d H:i', $expires_at ) ) )
						: esc_html__( 'Never', 'wp-guardrail' );
				?>
				<tr>
					<td><?php echo esc_html( $report_title ); ?></td>
					<td><?php echo esc_html( 'admin' === $access_mode ? __( 'Admin only', 'wp-guardrail' ) : __( 'Token link', 'wp-guardrail' ) ); ?></td>
					<td><?php echo $expires_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td>
						<div class="wpg-copy-row" style="max-width:320px;">
							<input type="text" class="wpg-copy-input code" readonly
								value="<?php echo esc_attr( $health_url ); ?>"
								id="wpg-hpurl-<?php echo esc_attr( $token ); ?>"
							/>
							<button type="button" class="button button-small wpg-copy-btn"
								data-copy-target="wpg-hpurl-<?php echo esc_attr( $token ); ?>">
								<?php esc_html_e( 'Copy', 'wp-guardrail' ); ?>
							</button>
						</div>
					</td>
					<td>
						<a href="<?php echo esc_url( $health_url ); ?>" target="_blank" class="button button-small">
							<?php esc_html_e( 'Open', 'wp-guardrail' ); ?>
						</a>
						<form method="post" action="<?php echo esc_url( $page_url ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this health page?', 'wp-guardrail' ) ); ?>')">
							<?php wp_nonce_field( 'wpg_revoke_health_page', 'wpg_revoke_nonce' ); ?>
							<input type="hidden" name="wsm_action"        value="wpg_revoke_health_page" />
							<input type="hidden" name="wpg_health_token"  value="<?php echo esc_attr( $token ); ?>" />
							<input type="hidden" name="wsm_tab"           value="reports" />
							<button type="submit" class="button button-small" style="color:#b32d2e;border-color:#b32d2e;">
								<?php esc_html_e( 'Revoke', 'wp-guardrail' ); ?>
							</button>
						</form>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// =========================================================================
	// Inline report viewer.
	// =========================================================================

	/**
	 * Render a saved report inline in the admin page.
	 *
	 * @param string $report_id Report ID to display.
	 * @return void
	 */
	private static function render_view_report( string $report_id ): void {
		$report = WPG_Report_Repository::get( $report_id );
		if ( ! $report ) {
			WPG_UI::alert( 'error', __( 'Report not found.', 'wp-guardrail' ) );
			return;
		}

		$mode     = $report['mode'] ?? 'summary';
		$view     = WPG_Report_Formatter::prepare_view_data( $report, $mode );
		$back_url = WPG_Admin_Pages::page_url( array( 'wsm_tab' => 'reports' ) );
		$page_url = WPG_Admin_Pages::page_url( array( 'wsm_tab' => 'reports' ) );

		?>
		<p>
			<a href="<?php echo esc_url( $back_url ); ?>" class="button">
				&larr; <?php esc_html_e( 'Back to Reports', 'wp-guardrail' ); ?>
			</a>
		</p>
		<?php

		if ( 'summary' === $mode ) {
			$template = WPG_PATH . 'admin/views/reports/report-summary.php';
		} else {
			$template = WPG_PATH . 'admin/views/reports/report-technical.php';
		}

		if ( file_exists( $template ) ) {
			include $template;
		} else {
			WPG_UI::alert( 'error', __( 'Report template not found.', 'wp-guardrail' ) );
		}

		// Copy/export actions below the report.
		$text_id = 'wpg-report-text-' . esc_attr( sanitize_html_class( $report_id ) );
		$text    = 'summary' === $mode
			? WPG_Report_Formatter::to_summary_text( $report )
			: WPG_Report_Formatter::to_technical_text( $report );
		?>
		<div class="wpg-card" style="margin-top:16px;">
			<div class="wpg-card__header">
				<h2 class="wpg-card__title"><?php esc_html_e( 'Plain Text Copy', 'wp-guardrail' ); ?></h2>
			</div>
			<div class="wpg-card__body">
				<textarea id="<?php echo esc_attr( $text_id ); ?>" class="large-text code" rows="10" readonly><?php echo esc_textarea( $text ); ?></textarea>
				<p style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;">
					<button type="button" class="button wpg-copy-btn" data-copy-target="<?php echo esc_attr( $text_id ); ?>">
						<?php esc_html_e( 'Copy to Clipboard', 'wp-guardrail' ); ?>
					</button>
					<!-- Export JSON -->
					<form method="post" action="<?php echo esc_url( $page_url ); ?>" style="display:inline">
						<?php wp_nonce_field( 'wpg_export_report_json', 'wpg_export_nonce' ); ?>
						<input type="hidden" name="wsm_action"    value="wpg_export_report_json" />
						<input type="hidden" name="wpg_report_id" value="<?php echo esc_attr( $report_id ); ?>" />
						<input type="hidden" name="wsm_tab"       value="reports" />
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Export JSON', 'wp-guardrail' ); ?>
						</button>
					</form>
				</p>
			</div>
		</div>
		<?php
	}
}
