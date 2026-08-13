<?php
/**
 * WPG_UI — Reusable admin UI component library for WP Guardrail.
 *
 * All methods are static and echo HTML directly.
 * Callers are responsible for escaping dynamic data passed as $args.
 * Internal escaping uses esc_html() / esc_attr() / esc_url() throughout.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static component library.
 *
 * Usage example:
 *   WPG_UI::page_header( [ 'title' => 'Dashboard', 'subtitle' => '...', ] );
 *   WPG_UI::badge( 'critical', 'Critical' );
 *   WPG_UI::card( 'Card title', function() { echo '<p>Body</p>'; } );
 */
class WPG_UI {

	/* =========================================================================
	   Page Header
	   ========================================================================= */

	/**
	 * Render the page header block.
	 *
	 * @param array $args {
	 *   @type string $title           Required. Page title.
	 *   @type string $subtitle        Optional subtitle / description.
	 *   @type bool   $sandbox_active  Whether sandbox session is active.
	 *   @type string $actions_html    Trusted HTML for quick-action buttons.
	 * }
	 */
	public static function page_header( array $args ) {
		$title          = isset( $args['title'] )          ? (string) $args['title'] : '';
		$subtitle       = isset( $args['subtitle'] )       ? (string) $args['subtitle'] : '';
		$sandbox_active = ! empty( $args['sandbox_active'] );
		$actions_html   = isset( $args['actions_html'] )   ? (string) $args['actions_html'] : '';
		?>
		<div class="wpg-page-header">
			<div class="wpg-page-header__left">
				<h1 class="wpg-page-header__title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( $subtitle ) : ?>
					<p class="wpg-page-header__subtitle"><?php echo esc_html( $subtitle ); ?></p>
				<?php endif; ?>
				<div class="wpg-page-header__meta">
					<?php if ( $sandbox_active ) : ?>
						<span class="wpg-badge wpg-badge--sandbox"><?php echo esc_html__( 'Sandbox Active', 'wp-guardrail' ); ?></span>
					<?php else : ?>
						<span class="wpg-badge wpg-badge--neutral"><?php echo esc_html__( 'Normal Mode', 'wp-guardrail' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( $actions_html ) : ?>
				<div class="wpg-page-header__actions">
					<?php echo $actions_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller is responsible ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =========================================================================
	   Mode Banner
	   ========================================================================= */

	/**
	 * Render the sticky sandbox-active mode banner.
	 *
	 * Renders nothing when $is_active is false.
	 *
	 * @param bool   $is_active Whether sandbox session is active.
	 * @param array  $session   Current session data array.
	 * @param string $tab       Current tab slug (passed through hidden fields).
	 * @param string $page_url  URL for the stop/snapshot form actions.
	 */
	public static function mode_banner( $is_active, $session, $tab, $page_url ) {
		if ( ! $is_active ) {
			return;
		}

		$session  = is_array( $session ) ? $session : array();
		$disabled = isset( $session['disabled'] ) && is_array( $session['disabled'] ) ? $session['disabled'] : array();
		$count    = count( $disabled );

		if ( $count > 0 ) {
			$label = sprintf(
				/* translators: %d: number of disabled plugins */
				_n(
					'Sandbox Active — %d plugin disabled',
					'Sandbox Active — %d plugins disabled',
					$count,
					'wp-guardrail'
				),
				$count
			);
		} else {
			$label = esc_html__( 'Sandbox Active — no plugins disabled yet', 'wp-guardrail' );
		}
		?>
		<div class="wpg-mode-banner wpg-mode-banner--sandbox" role="status" aria-live="polite">
			<span class="wpg-mode-banner__label">
				<span class="wpg-mode-banner__icon" aria-hidden="true">🧪</span>
				<?php echo esc_html( $label ); ?>
			</span>
			<div class="wpg-mode-banner__actions">
				<form method="post" action="<?php echo esc_url( $page_url ); ?>" class="wpg-mode-banner__form">
					<?php wp_nonce_field( 'wpg_save_snapshot', 'wpg_snapshot_nonce' ); ?>
					<input type="hidden" name="wsm_tab" value="<?php echo esc_attr( $tab ); ?>" />
					<input type="hidden" name="wsm_action" value="wpg_save_snapshot" />
					<button type="submit" class="button button-small wpg-mode-banner__btn-secondary">
						<?php echo esc_html__( 'Save Snapshot', 'wp-guardrail' ); ?>
					</button>
				</form>
				<form method="post" action="<?php echo esc_url( $page_url ); ?>" class="wpg-mode-banner__form">
					<?php wp_nonce_field( 'wsm_stop_action' ); ?>
					<input type="hidden" name="wsm_tab" value="<?php echo esc_attr( $tab ); ?>" />
					<input type="hidden" name="wsm_action" value="stop" />
					<button type="submit" class="button button-small wpg-mode-banner__btn-danger">
						<?php echo esc_html__( 'Stop Sandbox', 'wp-guardrail' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	/* =========================================================================
	   Badge
	   ========================================================================= */

	/**
	 * Render an inline severity badge.
	 *
	 * @param string $severity 'critical' | 'warning' | 'healthy' | 'ok' | 'info' | 'neutral'
	 * @param string $label    Visible badge label.
	 */
	public static function badge( $severity, $label ) {
		echo '<span class="wpg-badge wpg-badge--' . esc_attr( sanitize_html_class( $severity ) ) . '">'
			. esc_html( $label )
			. '</span>';
	}

	/* =========================================================================
	   Card
	   ========================================================================= */

	/**
	 * Render a content card.
	 *
	 * @param string        $title    Card header title (empty = no header).
	 * @param callable      $content  Callable that echoes the card body.
	 * @param array         $args {
	 *   @type string $badge_severity Severity for a badge beside the title.
	 *   @type string $badge_label    Badge label text.
	 *   @type string $extra_class    Additional CSS class on .wpg-card.
	 *   @type string $footer_html    Trusted HTML rendered in the card footer.
	 *   @type string $header_actions Trusted HTML for right-side header actions.
	 * }
	 */
	public static function card( $title, callable $content, array $args = array() ) {
		$badge_severity = isset( $args['badge_severity'] )  ? (string) $args['badge_severity'] : '';
		$badge_label    = isset( $args['badge_label'] )     ? (string) $args['badge_label'] : '';
		$extra_class    = isset( $args['extra_class'] )     ? ' ' . sanitize_html_class( $args['extra_class'] ) : '';
		$footer_html    = isset( $args['footer_html'] )     ? (string) $args['footer_html'] : '';
		$header_actions = isset( $args['header_actions'] )  ? (string) $args['header_actions'] : '';
		?>
		<div class="wpg-card<?php echo esc_attr( $extra_class ); ?>">
			<?php if ( $title ) : ?>
				<div class="wpg-card__header">
					<h2 class="wpg-card__title"><?php echo esc_html( $title ); ?></h2>
					<div style="display:flex;align-items:center;gap:8px;">
						<?php if ( $badge_severity && $badge_label ) : ?>
							<?php self::badge( $badge_severity, $badge_label ); ?>
						<?php endif; ?>
						<?php if ( $header_actions ) : ?>
							<?php echo $header_actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
			<div class="wpg-card__body">
				<?php call_user_func( $content ); ?>
			</div>
			<?php if ( $footer_html ) : ?>
				<div class="wpg-card__footer">
					<?php echo $footer_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =========================================================================
	   Summary Strip
	   ========================================================================= */

	/**
	 * Render a horizontal strip of summary stat tiles.
	 *
	 * @param array $items Array of tiles, each:
	 *   [ 'label' => string, 'value' => string, 'severity' => string ]
	 */
	public static function summary_strip( array $items ) {
		if ( empty( $items ) ) {
			return;
		}
		?>
		<div class="wpg-summary-strip">
			<?php foreach ( $items as $item ) :
				$label    = isset( $item['label'] )    ? (string) $item['label'] : '';
				$value    = isset( $item['value'] )    ? (string) $item['value'] : '';
				$severity = isset( $item['severity'] ) ? sanitize_html_class( (string) $item['severity'] ) : 'neutral';
			?>
				<div class="wpg-summary-tile wpg-summary-tile--<?php echo esc_attr( $severity ); ?>">
					<span class="wpg-summary-tile__value"><?php echo esc_html( $value ); ?></span>
					<span class="wpg-summary-tile__label"><?php echo esc_html( $label ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* =========================================================================
	   Issue Card
	   ========================================================================= */

	/**
	 * Render a severity-aware issue card with optional action.
	 *
	 * @param string $severity    'critical' | 'warning' | 'info'
	 * @param string $title       Issue title.
	 * @param string $description Issue description.
	 * @param string $action_html Trusted HTML for action button/link.
	 */
	public static function issue_card( $severity, $title, $description, $action_html = '' ) {
		$severity = sanitize_html_class( (string) $severity );

		$icon_map = array(
			'critical' => '!',
			'warning'  => '⚠',
			'info'     => 'i',
		);
		$icon = isset( $icon_map[ $severity ] ) ? $icon_map[ $severity ] : 'i';
		?>
		<div class="wpg-issue-card wpg-issue-card--<?php echo esc_attr( $severity ); ?>">
			<div class="wpg-issue-card__icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></div>
			<div class="wpg-issue-card__body">
				<strong class="wpg-issue-card__title"><?php echo esc_html( $title ); ?></strong>
				<?php if ( $description ) : ?>
					<p class="wpg-issue-card__desc"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
				<?php if ( $action_html ) : ?>
					<div class="wpg-issue-card__action">
						<?php echo $action_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* =========================================================================
	   Alert
	   ========================================================================= */

	/**
	 * Render an inline alert.
	 *
	 * @param string $type    'success' | 'warning' | 'error' | 'info'
	 * @param string $message Alert message text.
	 */
	public static function alert( $type, $message ) {
		?>
		<div class="wpg-alert wpg-alert--<?php echo esc_attr( sanitize_html_class( $type ) ); ?>" role="alert">
			<?php echo esc_html( $message ); ?>
		</div>
		<?php
	}

	/* =========================================================================
	   Empty State
	   ========================================================================= */

	/**
	 * Render an empty-state placeholder.
	 *
	 * @param string $icon        Icon character (emoji or single char).
	 * @param string $title       Empty-state title.
	 * @param string $message     Descriptive message.
	 * @param string $action_html Trusted HTML for optional action button.
	 */
	public static function empty_state( $icon, $title, $message, $action_html = '' ) {
		?>
		<div class="wpg-empty-state">
			<?php if ( $icon ) : ?>
				<div class="wpg-empty-state__icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></div>
			<?php endif; ?>
			<div class="wpg-empty-state__title"><?php echo esc_html( $title ); ?></div>
			<?php if ( $message ) : ?>
				<p class="wpg-empty-state__message"><?php echo esc_html( $message ); ?></p>
			<?php endif; ?>
			<?php if ( $action_html ) : ?>
				<div class="wpg-empty-state__action">
					<?php echo $action_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =========================================================================
	   Progress Bar
	   ========================================================================= */

	/**
	 * Render a labelled progress bar.
	 *
	 * @param int    $value    Current value.
	 * @param int    $max      Maximum value (minimum 1).
	 * @param string $label    Descriptive label.
	 * @param string $severity 'neutral' | 'warning' | 'critical' | 'healthy'
	 */
	public static function progress_bar( $value, $max, $label = '', $severity = 'neutral' ) {
		$max      = max( 1, (int) $max );
		$value    = min( $max, max( 0, (int) $value ) );
		$pct      = (int) round( ( $value / $max ) * 100 );
		$severity = sanitize_html_class( (string) $severity );
		?>
		<div class="wpg-progress-wrap">
			<?php if ( $label ) : ?>
				<div class="wpg-progress-label">
					<span><?php echo esc_html( $label ); ?></span>
					<span class="wpg-progress-pct"><?php echo esc_html( $pct . '%' ); ?></span>
				</div>
			<?php endif; ?>
			<div class="wpg-progress wpg-progress--<?php echo esc_attr( $severity ); ?>"
				role="progressbar"
				aria-valuemin="0"
				aria-valuemax="<?php echo esc_attr( (string) $max ); ?>"
				aria-valuenow="<?php echo esc_attr( (string) $value ); ?>"
				<?php if ( $label ) : ?>aria-label="<?php echo esc_attr( $label ); ?>"<?php endif; ?>>
				<span class="wpg-progress__bar" style="width:<?php echo esc_attr( $pct . '%' ); ?>;"></span>
			</div>
		</div>
		<?php
	}

	/* =========================================================================
	   Before/After Comparison Panel
	   ========================================================================= */

	/**
	 * Render a two-column before/after comparison panel.
	 *
	 * @param array  $before {
	 *   @type string $label  Column heading.
	 *   @type array  $rows   Array of [ 'key'=>string, 'value'=>string, 'flag'=>string ].
	 *                        'flag' values: '' | 'changed' | 'worse' | 'added' | 'removed'
	 * }
	 * @param array  $after  Same structure as $before.
	 * @param string $url    Optional URL being compared (shown above columns).
	 */
	public static function compare_panel( array $before, array $after, $url = '' ) {
		$before_label = isset( $before['label'] ) ? (string) $before['label'] : esc_html__( 'Baseline', 'wp-guardrail' );
		$after_label  = isset( $after['label'] )  ? (string) $after['label']  : esc_html__( 'Sandbox', 'wp-guardrail' );
		$before_rows  = isset( $before['rows'] ) && is_array( $before['rows'] ) ? $before['rows'] : array();
		$after_rows   = isset( $after['rows'] ) && is_array( $after['rows'] )   ? $after['rows']  : array();
		?>
		<div class="wpg-compare">
			<?php if ( $url ) : ?>
				<div class="wpg-compare__url">
					<code><?php echo esc_html( $url ); ?></code>
				</div>
			<?php endif; ?>
			<div class="wpg-compare__cols">
				<div class="wpg-compare__col wpg-compare__col--before">
					<div class="wpg-compare__col-header"><?php echo esc_html( $before_label ); ?></div>
					<table class="wpg-compare__table">
						<tbody>
							<?php foreach ( $before_rows as $row ) :
								$key  = isset( $row['key'] )   ? (string) $row['key'] : '';
								$val  = isset( $row['value'] ) ? (string) $row['value'] : '';
								$flag = isset( $row['flag'] )  ? sanitize_html_class( (string) $row['flag'] ) : '';
							?>
								<tr<?php echo $flag ? ' class="wpg-compare__' . esc_attr( $flag ) . '"' : ''; ?>>
									<th scope="row"><?php echo esc_html( $key ); ?></th>
									<td><?php echo esc_html( $val ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="wpg-compare__col wpg-compare__col--after">
					<div class="wpg-compare__col-header"><?php echo esc_html( $after_label ); ?></div>
					<table class="wpg-compare__table">
						<tbody>
							<?php foreach ( $after_rows as $row ) :
								$key  = isset( $row['key'] )   ? (string) $row['key'] : '';
								$val  = isset( $row['value'] ) ? (string) $row['value'] : '';
								$flag = isset( $row['flag'] )  ? sanitize_html_class( (string) $row['flag'] ) : '';
							?>
								<tr<?php echo $flag ? ' class="wpg-compare__' . esc_attr( $flag ) . '"' : ''; ?>>
									<th scope="row"><?php echo esc_html( $key ); ?></th>
									<td><?php echo esc_html( $val ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/* =========================================================================
	   Section Header
	   ========================================================================= */

	/**
	 * Render an H2-level section divider.
	 *
	 * @param string $title       Section title.
	 * @param string $description Optional description.
	 */
	public static function section_header( $title, $description = '' ) {
		?>
		<div class="wpg-section-header">
			<h2 class="wpg-section-header__title"><?php echo esc_html( $title ); ?></h2>
			<?php if ( $description ) : ?>
				<p class="wpg-section-header__desc"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =========================================================================
	   Helpers
	   ========================================================================= */

	/**
	 * Build comparison row data from a pair of URL test results.
	 *
	 * Used by the URL Tester and Update Tester pages to feed compare_panel().
	 *
	 * @param array $baseline  Baseline result row.
	 * @param array $sandbox   Sandbox/simulated result row.
	 * @return array [ 'before' => [...], 'after' => [...] ]
	 */
	public static function build_compare_rows( array $baseline, array $sandbox ) {
		$b_code  = isset( $baseline['http_code'] ) ? (int) $baseline['http_code'] : 0;
		$s_code  = isset( $sandbox['http_code'] )  ? (int) $sandbox['http_code']  : 0;
		$b_time  = isset( $baseline['time_ms'] )   ? (int) $baseline['time_ms']   : 0;
		$s_time  = isset( $sandbox['time_ms'] )    ? (int) $sandbox['time_ms']    : 0;
		$b_bytes = isset( $baseline['bytes'] )     ? (int) $baseline['bytes']     : 0;
		$s_bytes = isset( $sandbox['bytes'] )      ? (int) $sandbox['bytes']      : 0;
		$b_fatal = ! empty( $baseline['fatal_suspected'] );
		$s_fatal = ! empty( $sandbox['fatal_suspected'] );

		// Determine per-row change flags.
		$code_flag  = ( $b_code !== $s_code )                                                     ? ( $s_code >= 400 ? 'worse' : 'changed' ) : '';
		$time_flag  = ( $b_time > 0 && $s_time > 0 && $s_time > $b_time * 1.5 )                  ? 'worse' : '';
		$bytes_flag = ( $b_bytes > 0 && $s_bytes > 0 && abs( $s_bytes - $b_bytes ) > 1024 )       ? 'changed' : '';
		$fatal_flag = ( $s_fatal && ! $b_fatal )                                                   ? 'worse' : '';

		$before = array(
			'label' => __( 'Baseline', 'wp-guardrail' ),
			'rows'  => array(
				array( 'key' => __( 'HTTP Status', 'wp-guardrail' ), 'value' => $b_code > 0 ? (string) $b_code : __( 'Error', 'wp-guardrail' ), 'flag' => $code_flag ),
				array( 'key' => __( 'Response time', 'wp-guardrail' ), 'value' => $b_time . ' ms', 'flag' => '' ),
				array( 'key' => __( 'Size', 'wp-guardrail' ), 'value' => self::format_bytes( $b_bytes ), 'flag' => '' ),
				array( 'key' => __( 'Fatal suspected', 'wp-guardrail' ), 'value' => $b_fatal ? __( 'Yes', 'wp-guardrail' ) : __( 'No', 'wp-guardrail' ), 'flag' => '' ),
			),
		);

		$after = array(
			'label' => __( 'Sandbox', 'wp-guardrail' ),
			'rows'  => array(
				array( 'key' => __( 'HTTP Status', 'wp-guardrail' ), 'value' => $s_code > 0 ? (string) $s_code : __( 'Error', 'wp-guardrail' ), 'flag' => $code_flag ),
				array( 'key' => __( 'Response time', 'wp-guardrail' ), 'value' => $s_time . ' ms' . ( $time_flag ? ' ▲' : '' ), 'flag' => $time_flag ),
				array( 'key' => __( 'Size', 'wp-guardrail' ), 'value' => self::format_bytes( $s_bytes ), 'flag' => $bytes_flag ),
				array( 'key' => __( 'Fatal suspected', 'wp-guardrail' ), 'value' => $s_fatal ? __( 'Yes', 'wp-guardrail' ) : __( 'No', 'wp-guardrail' ), 'flag' => $fatal_flag ),
			),
		);

		return array( 'before' => $before, 'after' => $after );
	}

	/**
	 * Format a byte count as a human-readable string.
	 *
	 * @param int $bytes Byte count.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = max( 0, (int) $bytes );
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}
		if ( $bytes < 1048576 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		return round( $bytes / 1048576, 2 ) . ' MB';
	}
}
