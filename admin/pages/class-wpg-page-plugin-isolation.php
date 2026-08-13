<?php
/**
 * Plugin Isolation page renderer for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Plugin Isolation admin page.
 */
class WPG_Page_Plugin_Isolation {

	/**
	 * Render the plugin isolation page.
	 *
	 * @param array $ctx Shared page context.
	 * @return void
	 */
	public static function render( array $ctx ): void {
		$is_active         = $ctx['is_active'];
		$baseline          = $ctx['baseline'];
		$disabled          = $ctx['disabled'];
		$protected_plugins = $ctx['protected_plugins'];
		$page_url          = $ctx['page_url'];

		WPG_UI::page_header( array(
			'title'          => __( 'Plugin Isolation', 'wp-guardrail' ),
			'subtitle'       => __( 'Session-only plugin disabling and protected-plugin management', 'wp-guardrail' ),
			'sandbox_active' => $is_active,
		) );

		// Protected Plugins card (always visible).
		WPG_UI::card(
			__( 'Protected Plugins', 'wp-guardrail' ),
			function() use ( $baseline, $protected_plugins, $page_url ) {
				echo '<p class="description">' . esc_html__( 'Protected plugins cannot be added to the sandbox disabled list and are automatically excluded from Conflict Wizard runs.', 'wp-guardrail' ) . '</p>';

				if ( empty( $baseline ) ) {
					WPG_UI::empty_state( '🔌', __( 'No plugins found', 'wp-guardrail' ), __( 'No active plugins are available.', 'wp-guardrail' ) );
					return;
				}
				?>
				<form method="post" action="<?php echo esc_url( $page_url ); ?>">
					<?php wp_nonce_field( 'wpg_set_protected' ); ?>
					<input type="hidden" name="wsm_tab" value="plugin-disabler" />
					<input type="hidden" name="wsm_action" value="wpg_set_protected" />
					<table class="widefat striped wpg-table wpg-plugins-table">
						<thead>
							<tr>
								<th class="wpg-col-checkbox"><?php echo esc_html__( 'Protect', 'wp-guardrail' ); ?></th>
								<th><?php echo esc_html__( 'Plugin', 'wp-guardrail' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $baseline as $plugin_file ) :
								$plugin_label        = WPG_Admin_Pages::plugin_label( $plugin_file );
								$plugin_is_protected = in_array( $plugin_file, $protected_plugins, true );
								$plugin_is_flagged   = WPG_Safety_Guard::is_flagged( $plugin_file );
							?>
								<tr>
									<td class="wpg-col-checkbox">
										<label>
											<input type="checkbox" name="wpg_protected[]" value="<?php echo esc_attr( $plugin_file ); ?>" <?php checked( $plugin_is_protected ); ?> />
										</label>
									</td>
									<td>
										<?php echo esc_html( $plugin_label ); ?>
										<?php if ( $plugin_is_flagged && ! $plugin_is_protected ) : ?>
											<span class="wsm-badge-risk" title="<?php echo esc_attr__( 'Authentication, security, or caching plugin — consider protecting it.', 'wp-guardrail' ); ?>"><?php echo esc_html__( '⚠ Risky', 'wp-guardrail' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p style="margin-top:12px">
						<button type="submit" class="button button-secondary"><?php echo esc_html__( 'Save Protected List', 'wp-guardrail' ); ?></button>
					</p>
				</form>
				<?php
			}
		);

		// Disabled Plugins card (sandbox required).
		if ( $is_active ) :
			WPG_UI::card(
				__( 'Disabled Plugins (This Session)', 'wp-guardrail' ),
				function() use ( $baseline, $disabled, $protected_plugins, $page_url ) {
					if ( empty( $baseline ) ) {
						WPG_UI::empty_state( '🔌', __( 'No plugins found', 'wp-guardrail' ), '' );
						return;
					}
					?>
					<form method="post" action="<?php echo esc_url( $page_url ); ?>">
						<?php wp_nonce_field( 'wsm_apply_action' ); ?>
						<input type="hidden" name="wsm_tab" value="plugin-disabler" />
						<input type="hidden" name="wsm_action" value="apply" />
						<table class="widefat striped wpg-table wpg-plugins-table">
							<thead>
								<tr>
									<th class="wpg-col-checkbox"><?php echo esc_html__( 'Disable', 'wp-guardrail' ); ?></th>
									<th><?php echo esc_html__( 'Plugin', 'wp-guardrail' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $baseline as $plugin_file ) :
									$plugin_label        = WPG_Admin_Pages::plugin_label( $plugin_file );
									$plugin_is_protected = in_array( $plugin_file, $protected_plugins, true );
									$plugin_is_flagged   = WPG_Safety_Guard::is_flagged( $plugin_file );
									$row_class           = $plugin_is_protected ? 'is-protected' : ( $plugin_is_flagged ? 'is-flagged' : '' );
								?>
									<tr class="<?php echo esc_attr( $row_class ); ?>">
										<td class="wpg-col-checkbox">
											<?php if ( $plugin_is_protected ) : ?>
												<span class="wpg-lock-icon" title="<?php echo esc_attr__( 'Protected — cannot be disabled.', 'wp-guardrail' ); ?>">&#128274;</span>
											<?php else : ?>
												<label>
													<input type="checkbox" name="wsm_disabled[]" value="<?php echo esc_attr( $plugin_file ); ?>" <?php checked( in_array( $plugin_file, $disabled, true ) ); ?> />
												</label>
											<?php endif; ?>
										</td>
										<td>
											<?php echo esc_html( $plugin_label ); ?>
											<?php if ( $plugin_is_protected ) : ?>
												<span class="wsm-badge-protected"><?php echo esc_html__( 'Protected', 'wp-guardrail' ); ?></span>
											<?php elseif ( $plugin_is_flagged ) : ?>
												<span class="wsm-badge-risk" title="<?php echo esc_attr__( 'Authentication, security, or caching plugin — disabling may affect admin access.', 'wp-guardrail' ); ?>"><?php echo esc_html__( '⚠ Risky', 'wp-guardrail' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p style="margin-top:12px">
							<button type="submit" class="button button-primary"><?php echo esc_html__( 'Save / Apply', 'wp-guardrail' ); ?></button>
						</p>
					</form>
					<?php
				}
			);
		else :
			WPG_UI::alert( 'info', __( 'Sandbox Mode must be active to disable plugins for your session.', 'wp-guardrail' ) );
		endif;
	}
}
