<?php
/**
 * Summary report view template.
 *
 * Receives:
 *   $view   — prepared view data from WPG_Report_Formatter::prepare_view_data().
 *   $report — raw normalized report array.
 *
 * Designed for clients and project managers.
 * Shows: status, key stats, issues (no raw data), recommendations.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$v_title           = esc_html( $view['title']           ?? '' );
$v_status          = $view['status']          ?? 'ok';
$v_created_at      = esc_html( $view['created_at']      ?? '' );
$v_meta            = $view['meta']            ?? array();
$v_summary_tiles   = $view['summary_tiles']   ?? array();
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
			<span class="description"><?php echo esc_html__( 'Summary Report', 'wp-guardrail' ); ?></span>
		</div>
	</div>
	<div class="wpg-card__body">
		<p class="description">
			<?php
			printf(
				/* translators: %s: date/time string */
				esc_html__( 'Generated: %s', 'wp-guardrail' ),
				esc_html( $v_created_at )
			);
			?>
			<?php if ( ! empty( $v_meta['wp_version'] ) ) : ?>
				&nbsp;&middot;&nbsp; WordPress <?php echo esc_html( $v_meta['wp_version'] ); ?>
			<?php endif; ?>
			<?php if ( ! empty( $v_meta['php_version'] ) ) : ?>
				&nbsp;&middot;&nbsp; PHP <?php echo esc_html( $v_meta['php_version'] ); ?>
			<?php endif; ?>
		</p>

		<!-- Summary stat tiles -->
		<?php if ( ! empty( $v_summary_tiles ) ) : ?>
			<?php WPG_UI::summary_strip( $v_summary_tiles ); ?>
		<?php endif; ?>
	</div>
</div>

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

<!-- Recommendations card -->
<?php if ( ! empty( $v_recommendations ) ) : ?>
<div class="wpg-card" style="margin-top:16px;">
	<div class="wpg-card__header">
		<h2 class="wpg-card__title"><?php esc_html_e( 'What To Do Next', 'wp-guardrail' ); ?></h2>
	</div>
	<div class="wpg-card__body">
		<?php foreach ( $v_recommendations as $rec ) :
			$priority = $rec['priority'] ?? 'low';
			$title    = $rec['title']    ?? '';
			$action   = $rec['action']   ?? '';
			$sev_map  = array( 'high' => 'critical', 'medium' => 'warning', 'low' => 'info' );
			$sev      = $sev_map[ $priority ] ?? 'info';
			$badge    = strtoupper( $priority );
		?>
			<div class="wpg-issue-card wpg-issue-card--<?php echo esc_attr( $sev ); ?>" style="margin-bottom:8px;">
				<div class="wpg-issue-card__icon" aria-hidden="true"><?php echo ( 'critical' === $sev ? '!' : ( 'warning' === $sev ? '⚠' : '→' ) ); ?></div>
				<div class="wpg-issue-card__body">
					<strong class="wpg-issue-card__title">
						<span class="wpg-badge wpg-badge--<?php echo esc_attr( $sev ); ?>" style="font-size:10px;margin-right:6px;"><?php echo esc_html( $badge ); ?></span>
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
