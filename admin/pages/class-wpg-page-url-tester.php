<?php
/**
 * URL Tester page renderer for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the URL Tester admin page.
 */
class WPG_Page_Url_Tester {

	/**
	 * Render the URL Tester page.
	 *
	 * @param array $ctx Shared page context.
	 * @return void
	 */
	public static function render( array $ctx ): void {
		$is_active     = $ctx['is_active'];
		$url_tests     = $ctx['url_tests'];
		$compare_tests = $ctx['compare_tests'];
		$show_report   = $ctx['show_report'];
		$page_url      = $ctx['page_url'];

		ob_start(); ?>
		<form method="post" action="<?php echo esc_url( $page_url ); ?>" style="margin:0">
			<?php wp_nonce_field( 'wpsm_clear_tests' ); ?>
			<input type="hidden" name="wsm_tab" value="url-tester" />
			<input type="hidden" name="wsm_action" value="wpsm_clear_tests" />
			<button type="submit" class="button button-secondary"><?php echo esc_html__( 'Clear Results', 'wp-guardrail' ); ?></button>
		</form>
		<?php $header_actions = ob_get_clean();

		WPG_UI::page_header( array(
			'title'          => __( 'URL Tester', 'wp-guardrail' ),
			'subtitle'       => __( 'Test URLs in baseline or sandbox mode and compare results side-by-side', 'wp-guardrail' ),
			'sandbox_active' => $is_active,
			'actions_html'   => $header_actions,
		) );

		WPG_UI::card(
			__( 'Configure & Run', 'wp-guardrail' ),
			function() use ( $is_active, $page_url ) {
				?>
				<form method="post" action="<?php echo esc_url( $page_url ); ?>">
					<?php wp_nonce_field( 'wpsm_run_test', 'wpsm_nonce_run_test' ); ?>
					<?php wp_nonce_field( 'wpsm_run_both', 'wpsm_nonce_run_both' ); ?>
					<?php wp_nonce_field( 'wpsm_compare_baseline_sandbox', 'wpsm_nonce_compare' ); ?>
					<input type="hidden" name="wsm_tab" value="url-tester" />

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="wpsm-test-url"><?php echo esc_html__( 'URL or Path', 'wp-guardrail' ); ?></label>
								</th>
								<td>
									<input type="text" class="regular-text code" id="wpsm-test-url" name="wpsm_test_url"
										placeholder="/checkout/ or https://example.com/path" required />
									<p class="description"><?php echo esc_html__( 'Allowed: same-host absolute URLs, /wp-admin/* paths, frontend paths, and REST/API paths.', 'wp-guardrail' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wpsm-test-mode"><?php echo esc_html__( 'Mode', 'wp-guardrail' ); ?></label>
								</th>
								<td>
									<select id="wpsm-test-mode" name="wpsm_test_mode">
										<option value="baseline"><?php echo esc_html__( 'Baseline (Normal)', 'wp-guardrail' ); ?></option>
										<?php if ( $is_active ) : ?>
											<option value="sandbox"><?php echo esc_html__( 'Sandbox', 'wp-guardrail' ); ?></option>
										<?php endif; ?>
									</select>
									<?php if ( ! $is_active ) : ?>
										<p class="description"><?php echo esc_html__( 'Sandbox tests and comparison are available only when Sandbox Mode is active.', 'wp-guardrail' ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wpg-forward-auth"><?php echo esc_html__( 'Auth cookies', 'wp-guardrail' ); ?></label>
								</th>
								<td>
									<label>
										<input type="checkbox" id="wpg-forward-auth" name="wpg_forward_auth" value="1" />
										<?php echo esc_html__( 'Forward WordPress authentication cookies', 'wp-guardrail' ); ?>
									</label>
									<p class="description"><?php echo esc_html__( 'Enable only when testing /wp-admin/ paths.', 'wp-guardrail' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<div class="wpg-wrap-forms" style="margin-top:8px">
						<button type="submit" name="wsm_action" value="wpsm_run_test" class="button button-primary"><?php echo esc_html__( 'Run Test', 'wp-guardrail' ); ?></button>
						<?php if ( $is_active ) : ?>
							<button type="submit" name="wsm_action" value="wpsm_compare_baseline_sandbox" class="button"><?php echo esc_html__( 'Compare Baseline vs Sandbox', 'wp-guardrail' ); ?></button>
						<?php endif; ?>
					</div>
				</form>
				<?php
			}
		);

		// Comparison results.
		if ( ! empty( $compare_tests ) ) :
			WPG_UI::section_header(
				__( 'Latest Comparison', 'wp-guardrail' ),
				__( 'Baseline vs Sandbox for the most recently tested URL', 'wp-guardrail' )
			);

			$latest_compare = $compare_tests[0];
			$b_data         = isset( $latest_compare['baseline'] ) && is_array( $latest_compare['baseline'] ) ? $latest_compare['baseline'] : array();
			$s_data         = isset( $latest_compare['sandbox'] )  && is_array( $latest_compare['sandbox'] )  ? $latest_compare['sandbox']  : array();
			$compare_rows   = WPG_UI::build_compare_rows( $b_data, $s_data );
			$compare_url    = isset( $latest_compare['url'] ) ? (string) $latest_compare['url'] : '';

			WPG_UI::compare_panel( $compare_rows['before'], $compare_rows['after'], $compare_url );

			WPG_UI::section_header( __( 'Comparison History', 'wp-guardrail' ) );
			?>
			<table class="widefat striped wpg-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Timestamp', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'URL', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Baseline status', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Sandbox status', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Baseline time', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Sandbox time', 'wp-guardrail' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $compare_tests as $comparison ) :
						$comparison   = is_array( $comparison ) ? $comparison : array();
						$b_row        = isset( $comparison['baseline'] ) && is_array( $comparison['baseline'] ) ? $comparison['baseline'] : array();
						$s_row        = isset( $comparison['sandbox'] )  && is_array( $comparison['sandbox'] )  ? $comparison['sandbox']  : array();
						$b_code       = isset( $b_row['http_code'] ) ? (int) $b_row['http_code'] : 0;
						$s_code       = isset( $s_row['http_code'] ) ? (int) $s_row['http_code'] : 0;
						$row_severity = ( $s_code >= 400 || 0 === $s_code ) ? 'is-critical' : ( $s_code !== $b_code ? 'is-warning' : '' );
					?>
						<tr class="<?php echo esc_attr( $row_severity ); ?>">
							<td><?php echo esc_html( isset( $comparison['timestamp'] ) ? $comparison['timestamp'] : '' ); ?></td>
							<td><code class="wpg-inline-code"><?php echo esc_html( isset( $comparison['url'] ) ? $comparison['url'] : '' ); ?></code></td>
							<td><?php echo esc_html( $b_code > 0 ? (string) $b_code : '—' ); ?></td>
							<td><?php echo esc_html( $s_code > 0 ? (string) $s_code : '—' ); ?></td>
							<td><?php echo esc_html( isset( $b_row['time_ms'] ) ? (string) (int) $b_row['time_ms'] . ' ms' : '—' ); ?></td>
							<td><?php echo esc_html( isset( $s_row['time_ms'] ) ? (string) (int) $s_row['time_ms'] . ' ms' : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			WPG_Admin_Pages::render_snapshot_button( 'url-tester' );
			WPG_Admin_Pages::render_report_section( 'url-tester', $show_report );
		endif;

		// Recent URL tests.
		WPG_UI::section_header( __( 'Recent URL Tests', 'wp-guardrail' ) );
		if ( empty( $url_tests ) ) :
			WPG_UI::empty_state( '🔗', __( 'No tests yet', 'wp-guardrail' ), __( 'Enter a URL above and click Run Test to get started.', 'wp-guardrail' ) );
		else : ?>
			<table class="widefat striped wpg-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Timestamp', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Mode', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'URL', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Time', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Size', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Fatal', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Auth', 'wp-guardrail' ); ?></th>
						<th><?php echo esc_html__( 'Error', 'wp-guardrail' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $url_tests as $row ) :
						$row       = is_array( $row ) ? $row : array();
						$http_code = isset( $row['http_code'] ) ? (int) $row['http_code'] : 0;
						$is_fatal  = ! empty( $row['fatal_suspected'] );
						$row_class = ( $is_fatal || $http_code >= 500 || 0 === $http_code ) ? 'is-critical' : ( $http_code >= 400 ? 'is-warning' : '' );
						$error_text = '';
						if ( ! empty( $row['error_message'] ) ) {
							$error_text = (string) $row['error_message'];
						} elseif ( ! empty( $row['fatal_snippet'] ) ) {
							$error_text = (string) $row['fatal_snippet'];
						}
					?>
						<tr class="<?php echo esc_attr( $row_class ); ?>">
							<td><?php echo esc_html( isset( $row['timestamp'] ) ? $row['timestamp'] : '' ); ?></td>
							<td>
								<?php
								$mode = isset( $row['mode'] ) ? (string) $row['mode'] : '';
								WPG_UI::badge( 'baseline' === $mode ? 'neutral' : 'info', ucfirst( $mode ) );
								?>
							</td>
							<td><code class="wpg-inline-code"><?php echo esc_html( isset( $row['url'] ) ? $row['url'] : '' ); ?></code></td>
							<td>
								<?php
								if ( $http_code >= 200 && $http_code < 300 ) {
									echo '<span class="wpg-table wpg-status-ok">' . esc_html( (string) $http_code ) . '</span>';
								} elseif ( $http_code >= 300 && $http_code < 400 ) {
									echo '<span>' . esc_html( (string) $http_code ) . '</span>';
								} elseif ( $http_code >= 400 ) {
									echo '<span class="wpg-table wpg-status-fail">' . esc_html( (string) $http_code ) . '</span>';
								} else {
									echo '<span class="wpg-table wpg-status-fail">' . esc_html__( 'Error', 'wp-guardrail' ) . '</span>';
								}
								?>
							</td>
							<td><?php echo esc_html( isset( $row['time_ms'] ) ? (string) (int) $row['time_ms'] . ' ms' : '—' ); ?></td>
							<td><?php echo esc_html( isset( $row['bytes'] ) ? WPG_UI::format_bytes( (int) $row['bytes'] ) : '—' ); ?></td>
							<td><?php echo $is_fatal ? '<span class="wpg-table wpg-status-fail">' . esc_html__( 'Yes', 'wp-guardrail' ) . '</span>' : esc_html__( 'No', 'wp-guardrail' ); ?></td>
							<td><?php echo ! empty( $row['auth_forwarded'] ) ? esc_html__( 'Yes', 'wp-guardrail' ) : esc_html__( 'No', 'wp-guardrail' ); ?></td>
							<td>
								<?php if ( '' !== $error_text ) : ?>
									<details class="wpg-error-details">
										<summary><?php echo esc_html__( 'View error', 'wp-guardrail' ); ?></summary>
										<pre class="wpg-error-text"><?php echo esc_html( $error_text ); ?></pre>
									</details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;
	}
}
