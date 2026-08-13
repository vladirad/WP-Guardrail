<?php
/**
 * Dashboard page renderer for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Dashboard admin page.
 */
class WPG_Page_Dashboard {

	/**
	 * Render the full dashboard page content.
	 *
	 * @param array $ctx Shared page context from WPG_Admin_Pages::render_page().
	 * @return void
	 */
	public static function render( array $ctx ): void {
		$is_active    = $ctx['is_active'];
		$session      = $ctx['session'];
		$disabled     = $ctx['disabled'];
		$wizard        = $ctx['wizard'];
		$url_tests    = $ctx['url_tests'];
		$recovery_url = $ctx['recovery_url'];
		$page_url     = $ctx['page_url'];

		global $wp_version;
		$theme         = wp_get_theme();
		$active_count  = count( get_option( 'active_plugins', array() ) );
		$wizard_active = ! empty( $wizard['active'] );

		// Quick actions for the page header.
		ob_start();
		if ( ! $is_active ) : ?>
			<form method="post" action="<?php echo esc_url( $page_url ); ?>" style="margin:0">
				<?php wp_nonce_field( 'wsm_start_action' ); ?>
				<input type="hidden" name="wsm_tab" value="dashboard" />
				<input type="hidden" name="wsm_action" value="start" />
				<button type="submit" class="button button-primary"><?php echo esc_html__( 'Start Sandbox', 'wp-guardrail' ); ?></button>
			</form>
		<?php endif;
		$header_actions = ob_get_clean();

		WPG_UI::page_header( array(
			'title'          => __( 'Dashboard', 'wp-guardrail' ),
			'subtitle'       => __( 'Site diagnostics & safe-testing toolkit', 'wp-guardrail' ),
			'sandbox_active' => $is_active,
			'actions_html'   => $header_actions,
		) );

		WPG_UI::summary_strip( array(
			array(
				'label'    => __( 'Mode', 'wp-guardrail' ),
				'value'    => $is_active ? __( 'Sandbox', 'wp-guardrail' ) : __( 'Normal', 'wp-guardrail' ),
				'severity' => $is_active ? 'warning' : 'neutral',
			),
			array(
				'label'    => __( 'Plugins disabled', 'wp-guardrail' ),
				'value'    => (string) count( $disabled ),
				'severity' => count( $disabled ) > 0 ? 'warning' : 'neutral',
			),
			array(
				'label'    => __( 'Wizard', 'wp-guardrail' ),
				'value'    => $wizard_active ? __( 'Running', 'wp-guardrail' ) : __( 'Idle', 'wp-guardrail' ),
				'severity' => $wizard_active ? 'info' : 'neutral',
			),
			array(
				'label'    => __( 'URL tests', 'wp-guardrail' ),
				'value'    => (string) count( $url_tests ),
				'severity' => 'neutral',
			),
			array(
				'label'    => __( 'Active plugins', 'wp-guardrail' ),
				'value'    => (string) $active_count,
				'severity' => 'neutral',
			),
		) );

		// Quick-action cards.
		?>
		<div class="wpg-card-grid">
			<div class="wpg-card">
				<div class="wpg-card__header">
					<h2 class="wpg-card__title"><?php echo esc_html__( 'Conflict Wizard', 'wp-guardrail' ); ?></h2>
				</div>
				<div class="wpg-card__body">
					<p class="wpg-text-muted"><?php echo esc_html__( 'Binary plugin isolation to pinpoint conflicts quickly.', 'wp-guardrail' ); ?></p>
					<a class="button" href="<?php echo esc_url( WPG_Admin_Pages::page_url( array( 'page' => WPG_Admin::SLUG_CONFLICT_WIZARD ) ) ); ?>">
						<?php echo esc_html__( 'Open Conflict Wizard', 'wp-guardrail' ); ?>
					</a>
				</div>
			</div>

			<div class="wpg-card">
				<div class="wpg-card__header">
					<h2 class="wpg-card__title"><?php echo esc_html__( 'URL Tester', 'wp-guardrail' ); ?></h2>
				</div>
				<div class="wpg-card__body">
					<p class="wpg-text-muted"><?php echo esc_html__( 'Compare baseline and sandbox responses side-by-side.', 'wp-guardrail' ); ?></p>
					<a class="button" href="<?php echo esc_url( WPG_Admin_Pages::page_url( array( 'page' => WPG_Admin::SLUG_URL_TESTER ) ) ); ?>">
						<?php echo esc_html__( 'Open URL Tester', 'wp-guardrail' ); ?>
					</a>
				</div>
			</div>

			<div class="wpg-card">
				<div class="wpg-card__header">
					<h2 class="wpg-card__title"><?php echo esc_html__( 'Plugin Isolation', 'wp-guardrail' ); ?></h2>
				</div>
				<div class="wpg-card__body">
					<p class="wpg-text-muted"><?php echo esc_html__( 'Toggle session-only plugin disablement for troubleshooting.', 'wp-guardrail' ); ?></p>
					<a class="button" href="<?php echo esc_url( WPG_Admin_Pages::page_url( array( 'page' => WPG_Admin::SLUG_PLUGIN_ISOLATION ) ) ); ?>">
						<?php echo esc_html__( 'Open Plugin Isolation', 'wp-guardrail' ); ?>
					</a>
				</div>
			</div>

			<div class="wpg-card">
				<div class="wpg-card__header">
					<h2 class="wpg-card__title"><?php echo esc_html__( 'Diagnostics', 'wp-guardrail' ); ?></h2>
				</div>
				<div class="wpg-card__body">
					<p class="wpg-text-muted"><?php echo esc_html__( 'Autoload, cron, memory, transients, and DB table sizes.', 'wp-guardrail' ); ?></p>
					<a class="button" href="<?php echo esc_url( WPG_Admin_Pages::page_url( array( 'page' => WPG_Admin::SLUG_DIAGNOSTICS ) ) ); ?>">
						<?php echo esc_html__( 'Open Diagnostics', 'wp-guardrail' ); ?>
					</a>
				</div>
			</div>
		</div>

		<?php
		// Environment summary card.
		WPG_UI::card(
			__( 'Environment', 'wp-guardrail' ),
			function() use ( $wp_version, $theme, $active_count ) {
				?>
				<table class="widefat striped wpg-table">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'WordPress', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( (string) $wp_version ); ?></td>
							<th scope="row"><?php echo esc_html__( 'PHP', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( (string) phpversion() ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Active theme', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( $theme->get( 'Name' ) ); ?></td>
							<th scope="row"><?php echo esc_html__( 'Active plugins', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( (string) $active_count ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Memory limit', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : __( 'Unknown', 'wp-guardrail' ) ); ?></td>
							<th scope="row"><?php echo esc_html__( 'Debug mode', 'wp-guardrail' ); ?></th>
							<td><?php echo ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? esc_html__( 'On', 'wp-guardrail' ) : esc_html__( 'Off', 'wp-guardrail' ); ?></td>
						</tr>
					</tbody>
				</table>
				<?php
			}
		);

		// Emergency Recovery card.
		WPG_UI::card(
			__( 'Emergency Recovery', 'wp-guardrail' ),
			function() use ( $recovery_url, $page_url ) {
				echo '<p>' . esc_html__( 'Generate a one-time link that stops your sandbox session even if you cannot reach this page. Copy and save it before starting risky operations.', 'wp-guardrail' ) . '</p>';

				if ( $recovery_url ) : ?>
					<p><strong><?php echo esc_html__( 'Current recovery link (valid 24 h):', 'wp-guardrail' ); ?></strong></p>
					<div class="wpg-copy-row">
						<input type="text" class="wpg-copy-input" id="wpg-recovery-url" readonly value="<?php echo esc_attr( $recovery_url ); ?>" />
						<button type="button" class="button wpg-copy-btn" data-copy-target="wpg-recovery-url"><?php echo esc_html__( 'Copy', 'wp-guardrail' ); ?></button>
					</div>
					<div class="wpg-wrap-forms">
						<form method="post" action="<?php echo esc_url( $page_url ); ?>">
							<?php wp_nonce_field( 'wpg_generate_recovery' ); ?>
							<input type="hidden" name="wsm_tab" value="dashboard" />
							<input type="hidden" name="wsm_action" value="wpg_generate_recovery" />
							<button type="submit" class="button button-secondary"><?php echo esc_html__( 'Refresh Link', 'wp-guardrail' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( $page_url ); ?>">
							<?php wp_nonce_field( 'wpg_clear_recovery' ); ?>
							<input type="hidden" name="wsm_tab" value="dashboard" />
							<input type="hidden" name="wsm_action" value="wpg_clear_recovery" />
							<button type="submit" class="button wpg-btn-danger"><?php echo esc_html__( 'Clear Link', 'wp-guardrail' ); ?></button>
						</form>
					</div>
				<?php else : ?>
					<p class="description"><?php echo esc_html__( 'No recovery link is currently active.', 'wp-guardrail' ); ?></p>
					<form method="post" action="<?php echo esc_url( $page_url ); ?>">
						<?php wp_nonce_field( 'wpg_generate_recovery' ); ?>
						<input type="hidden" name="wsm_tab" value="dashboard" />
						<input type="hidden" name="wsm_action" value="wpg_generate_recovery" />
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Generate Recovery Link', 'wp-guardrail' ); ?></button>
					</form>
				<?php endif;
			}
		);
	}
}
