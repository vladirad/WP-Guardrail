<?php
/**
 * Technical report view template.
 *
 * Receives:
 *   $view   — prepared view data from WPG_Report_Formatter::prepare_view_data().
 *   $report — raw normalized report array.
 *
 * Designed for developers and support teams.
 * Shows: all issues with detail, module section breakdowns, environment data,
 *        full recommendations.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$v_title           = esc_html( $view['title']           ?? '' );
$v_status          = $view['status']          ?? 'ok';
$v_id              = esc_html( $view['id']              ?? '' );
$v_module          = $view['module']          ?? '';
$v_created_at      = esc_html( $view['created_at']      ?? '' );
$v_meta            = $view['meta']            ?? array();
$v_summary_tiles   = $view['summary_tiles']   ?? array();
$v_sections        = $view['sections']        ?? array();
$v_critical_issues = $view['critical_issues'] ?? array();
$v_warning_issues  = $view['warning_issues']  ?? array();
$v_info_issues     = $view['info_issues']     ?? array();
$v_recommendations = $view['recommendations'] ?? array();
$v_issue_count     = $view['issue_count']     ?? 0;

$status_labels = array(
	'ok'       => __( 'Healthy', 'wp-guardrail' ),
	'warning'  => __( 'Warning', 'wp-guardrail' ),
	'critical' => __( 'Critical', 'wp-guardrail' ),
	'info'     => __( 'Info', 'wp-guardrail' ),
);
$status_label = $status_labels[ $v_status ] ?? ucfirst( $v_status );
?>

<!-- Report header card -->
<div class="wpg-card">
	<div class="wpg-card__header">
		<h2 class="wpg-card__title"><?php echo $v_title; ?></h2>
		<div style="display:flex;align-items:center;gap:8px;">
			<?php WPG_UI::badge( $v_status, $status_label ); ?>
			<span class="description"><?php esc_html_e( 'Technical Report', 'wp-guardrail' ); ?></span>
		</div>
	</div>
	<div class="wpg-card__body">
		<p class="description">
			<?php printf( esc_html__( 'ID: %s', 'wp-guardrail' ), '<code>' . esc_html( $v_id ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			&nbsp;&middot;&nbsp;
			<?php printf( esc_html__( 'Generated: %s', 'wp-guardrail' ), esc_html( $v_created_at ) ); ?>
		</p>

		<!-- Summary stat tiles -->
		<?php if ( ! empty( $v_summary_tiles ) ) : ?>
			<?php WPG_UI::summary_strip( $v_summary_tiles ); ?>
		<?php endif; ?>
	</div>
</div>

<!-- Environment card -->
<?php if ( ! empty( $v_meta ) ) : ?>
<div class="wpg-card" style="margin-top:16px;">
	<div class="wpg-card__header">
		<h2 class="wpg-card__title"><?php esc_html_e( 'Environment', 'wp-guardrail' ); ?></h2>
	</div>
	<div class="wpg-card__body">
		<table class="widefat" style="max-width:520px;">
			<tbody>
				<?php foreach ( $v_meta as $key => $val ) :
					if ( ! is_scalar( $val ) ) continue;
				?>
					<tr>
						<th style="width:200px;"><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></th>
						<td><code><?php echo esc_html( (string) $val ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php endif; ?>

<!-- Issues card -->
<div class="wpg-card" style="margin-top:16px;">
	<div class="wpg-card__header">
		<h2 class="wpg-card__title"><?php esc_html_e( 'Issues', 'wp-guardrail' ); ?></h2>
		<?php if ( $v_issue_count > 0 ) : ?>
			<?php WPG_UI::badge( $v_status, (string) $v_issue_count . ' ' . _n( 'issue', 'issues', $v_issue_count, 'wp-guardrail' ) ); ?>
		<?php endif; ?>
	</div>
	<div class="wpg-card__body">
		<?php if ( 0 === $v_issue_count ) : ?>
			<?php WPG_UI::alert( 'success', __( 'No issues found.', 'wp-guardrail' ) ); ?>
		<?php else : ?>

			<?php foreach ( $v_critical_issues as $issue ) : ?>
				<?php WPG_UI::issue_card( 'critical', $issue['title'] ?? '', $issue['detail'] ?? '' ); ?>
			<?php endforeach; ?>

			<?php foreach ( $v_warning_issues as $issue ) : ?>
				<?php WPG_UI::issue_card( 'warning', $issue['title'] ?? '', $issue['detail'] ?? '' ); ?>
			<?php endforeach; ?>

			<?php foreach ( $v_info_issues as $issue ) : ?>
				<?php WPG_UI::issue_card( 'info', $issue['title'] ?? '', $issue['detail'] ?? '' ); ?>
			<?php endforeach; ?>

		<?php endif; ?>
	</div>
</div>

<!-- Module section details -->
<?php if ( ! empty( $v_sections ) ) : ?>
<div class="wpg-card" style="margin-top:16px;">
	<div class="wpg-card__header">
		<h2 class="wpg-card__title"><?php esc_html_e( 'Module Details', 'wp-guardrail' ); ?></h2>
	</div>
	<div class="wpg-card__body">
		<?php foreach ( $v_sections as $section ) :
			$s_title  = $section['title']  ?? 'Section';
			$s_status = $section['status'] ?? 'ok';
			$s_label  = $status_labels[ $s_status ] ?? ucfirst( $s_status );
			$s_sum    = $section['summary'] ?? array();
			$s_detail = $section['details'] ?? array();
		?>
			<div style="margin-bottom:20px;">
				<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
					<strong><?php echo esc_html( $s_title ); ?></strong>
					<?php WPG_UI::badge( $s_status, $s_label ); ?>
				</div>

				<?php if ( ! empty( $s_sum ) ) : ?>
					<?php WPG_UI::summary_strip( $s_sum ); ?>
				<?php endif; ?>

				<?php if ( ! empty( $s_detail ) ) : ?>
					<table class="widefat" style="margin-top:8px;max-width:600px;">
						<tbody>
							<?php foreach ( $s_detail as $row ) :
								if ( ! is_array( $row ) ) continue;
							?>
								<tr>
									<th style="width:240px;"><?php echo esc_html( $row['label'] ?? '' ); ?></th>
									<td><code><?php echo esc_html( $row['value'] ?? '' ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<hr style="border:none;border-top:1px solid #eee;margin:0 0 16px;" />
		<?php endforeach; ?>
	</div>
</div>
<?php endif; ?>

<!-- Recommendations card -->
<?php if ( ! empty( $v_recommendations ) ) : ?>
<div class="wpg-card" style="margin-top:16px;">
	<div class="wpg-card__header">
		<h2 class="wpg-card__title"><?php esc_html_e( 'Recommendations', 'wp-guardrail' ); ?></h2>
	</div>
	<div class="wpg-card__body">
		<?php foreach ( $v_recommendations as $rec ) :
			$priority = $rec['priority'] ?? 'low';
			$title    = $rec['title']    ?? '';
			$action   = $rec['action']   ?? '';
			$sev_map  = array( 'high' => 'critical', 'medium' => 'warning', 'low' => 'info' );
			$sev      = $sev_map[ $priority ] ?? 'info';
		?>
			<div class="wpg-issue-card wpg-issue-card--<?php echo esc_attr( $sev ); ?>" style="margin-bottom:8px;">
				<div class="wpg-issue-card__icon" aria-hidden="true"><?php echo ( 'critical' === $sev ? '!' : ( 'warning' === $sev ? '⚠' : '→' ) ); ?></div>
				<div class="wpg-issue-card__body">
					<strong class="wpg-issue-card__title">
						<code style="font-size:10px;margin-right:6px;"><?php echo esc_html( strtoupper( $priority ) ); ?></code>
						<?php echo esc_html( $title ); ?>
					</strong>
					<?php if ( $action ) : ?>
						<p class="wpg-issue-card__desc"><?php echo esc_html( $action ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</div>
<?php endif; ?>
