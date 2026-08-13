<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( ( $report['title'] ?? 'Health Report' ) . ' — ' . get_bloginfo( 'name' ) ); ?></title>
	<style>
		*,*::before,*::after{box-sizing:border-box}
		body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.6;color:#1d2327;background:#f0f0f1}
		.hp-wrap{max-width:860px;margin:32px auto;padding:0 16px 48px}
		.hp-header{background:#fff;border-radius:8px;padding:24px 28px;margin-bottom:20px;border-left:4px solid #ccc}
		.hp-header--ok{border-color:#00a32a}
		.hp-header--warning{border-color:#dba617}
		.hp-header--critical{border-color:#b32d2e}
		.hp-header__title{margin:0 0 4px;font-size:22px;font-weight:700}
		.hp-header__meta{color:#6c757d;font-size:12px}
		.hp-snapshot-notice{background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 16px;margin-bottom:20px;font-size:13px;color:#6a4a05}
		.hp-card{background:#fff;border-radius:8px;padding:20px 24px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
		.hp-card__title{margin:0 0 14px;font-size:15px;font-weight:600;border-bottom:1px solid #f0f0f1;padding-bottom:10px}
		.hp-tiles{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:4px}
		.hp-tile{flex:1 1 140px;background:#f6f7f7;border-radius:6px;padding:12px 14px;text-align:center;border-top:3px solid #ccc}
		.hp-tile--ok{border-color:#00a32a}
		.hp-tile--warning{border-color:#dba617}
		.hp-tile--critical{border-color:#b32d2e}
		.hp-tile__value{font-size:22px;font-weight:700;display:block}
		.hp-tile__label{font-size:11px;color:#6c757d;text-transform:uppercase;letter-spacing:.5px}
		.hp-issue{display:flex;gap:10px;padding:10px 14px;border-radius:6px;margin-bottom:8px;background:#f6f7f7}
		.hp-issue--critical{background:#fde8e8;border-left:3px solid #b32d2e}
		.hp-issue--warning{background:#fdf3cd;border-left:3px solid #dba617}
		.hp-issue--info{background:#e7f0fd;border-left:3px solid #2271b1}
		.hp-issue__icon{font-weight:700;min-width:18px;margin-top:1px}
		.hp-issue__title{font-weight:600;margin-bottom:2px}
		.hp-issue__detail{color:#555;font-size:13px;margin:0}
		.hp-rec{padding:10px 14px;background:#f0f6fc;border-radius:6px;margin-bottom:8px;border-left:3px solid #2271b1}
		.hp-rec__label{font-size:10px;font-weight:700;text-transform:uppercase;color:#2271b1;margin-bottom:2px}
		.hp-rec__title{font-weight:600}
		.hp-rec__action{color:#555;font-size:13px;margin:4px 0 0}
		.hp-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase}
		.hp-badge--ok{background:#d1e7dd;color:#0a3622}
		.hp-badge--warning{background:#fff3cd;color:#6a4a05}
		.hp-badge--critical{background:#fde8e8;color:#58151c}
		table.hp-table{width:100%;border-collapse:collapse;font-size:13px}
		table.hp-table th,table.hp-table td{padding:6px 8px;text-align:left;border-bottom:1px solid #f0f0f1}
		table.hp-table th{color:#6c757d;font-weight:600;width:48%}
		.hp-footer{text-align:center;color:#6c757d;font-size:12px;margin-top:24px}
	</style>
</head>
<body>
<?php
/**
 * Variables available:
 *   $report  — normalized report array from WPG_Report_Repository::get()
 *   $page    — health page metadata row
 */

$status          = $report['status']          ?? 'ok';
$title           = $report['title']           ?? 'Health Report';
$created_at      = $report['created_at']      ?? '';
$meta            = $report['meta']            ?? array();
$summary_tiles   = $report['summary']         ?? array();
$issues          = $report['issues']          ?? array();
$recommendations = $report['recommendations'] ?? array();
$sections        = $report['sections']        ?? array();

$critical_issues = array_values( array_filter( $issues, fn( $i ) => ( $i['severity'] ?? '' ) === 'critical' ) );
$warning_issues  = array_values( array_filter( $issues, fn( $i ) => ( $i['severity'] ?? '' ) === 'warning' ) );
$info_issues     = array_values( array_filter( $issues, fn( $i ) => ( $i['severity'] ?? '' ) === 'info' ) );

$status_labels = array(
	'ok'       => 'Healthy',
	'warning'  => 'Warning',
	'critical' => 'Critical',
	'info'     => 'Info',
);
$status_label = $status_labels[ $status ] ?? ucfirst( $status );
?>
<div class="hp-wrap">

	<!-- Snapshot notice -->
	<div class="hp-snapshot-notice">
		<strong><?php esc_html_e( 'Note:', 'wp-guardrail' ); ?></strong>
		<?php esc_html_e( 'This is a generated snapshot, not live monitoring. Data reflects the state at the time the report was created.', 'wp-guardrail' ); ?>
	</div>

	<!-- Header -->
	<div class="hp-header hp-header--<?php echo esc_attr( $status ); ?>">
		<h1 class="hp-header__title">
			<?php echo esc_html( $title ); ?>
			<span class="hp-badge hp-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_label ); ?></span>
		</h1>
		<div class="hp-header__meta">
			<?php if ( $created_at ) : ?>
				<?php printf( esc_html__( 'Generated: %s', 'wp-guardrail' ), esc_html( $created_at ) ); ?>
			<?php endif; ?>
			<?php if ( ! empty( $meta['wp_version'] ) ) : ?>
				&nbsp;&middot;&nbsp; WordPress <?php echo esc_html( $meta['wp_version'] ); ?>
			<?php endif; ?>
			<?php if ( ! empty( $meta['php_version'] ) ) : ?>
				&nbsp;&middot;&nbsp; PHP <?php echo esc_html( $meta['php_version'] ); ?>
			<?php endif; ?>
			<?php if ( ! empty( $meta['active_theme'] ) ) : ?>
				&nbsp;&middot;&nbsp; <?php echo esc_html( $meta['active_theme'] ); ?>
			<?php endif; ?>
		</div>
	</div>

	<!-- Summary tiles -->
	<?php if ( ! empty( $summary_tiles ) ) : ?>
	<div class="hp-card">
		<div class="hp-card__title"><?php esc_html_e( 'Summary', 'wp-guardrail' ); ?></div>
		<div class="hp-tiles">
			<?php foreach ( $summary_tiles as $tile ) :
				$t_label    = $tile['label']    ?? '';
				$t_value    = $tile['value']    ?? '';
				$t_severity = $tile['severity'] ?? 'neutral';
				$t_class    = in_array( $t_severity, array( 'ok', 'warning', 'critical' ), true ) ? $t_severity : '';
			?>
				<div class="hp-tile<?php echo $t_class ? ' hp-tile--' . esc_attr( $t_class ) : ''; ?>">
					<span class="hp-tile__value"><?php echo esc_html( $t_value ); ?></span>
					<span class="hp-tile__label"><?php echo esc_html( $t_label ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<!-- Issues -->
	<div class="hp-card">
		<div class="hp-card__title">
			<?php esc_html_e( 'Issues', 'wp-guardrail' ); ?>
			<?php if ( count( $issues ) > 0 ) : ?>
				<span class="hp-badge hp-badge--<?php echo esc_attr( $status ); ?>" style="margin-left:8px;font-size:10px;">
					<?php echo esc_html( count( $issues ) ); ?>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( empty( $issues ) ) : ?>
			<p style="color:#00a32a;font-weight:600;"><?php esc_html_e( 'No issues found.', 'wp-guardrail' ); ?></p>
		<?php else : ?>

			<?php foreach ( $critical_issues as $issue ) : ?>
				<div class="hp-issue hp-issue--critical">
					<div class="hp-issue__icon">!</div>
					<div>
						<div class="hp-issue__title"><?php echo esc_html( $issue['title'] ?? '' ); ?></div>
						<?php if ( ! empty( $issue['detail'] ) ) : ?>
							<p class="hp-issue__detail"><?php echo esc_html( $issue['detail'] ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<?php foreach ( $warning_issues as $issue ) : ?>
				<div class="hp-issue hp-issue--warning">
					<div class="hp-issue__icon">⚠</div>
					<div>
						<div class="hp-issue__title"><?php echo esc_html( $issue['title'] ?? '' ); ?></div>
						<?php if ( ! empty( $issue['detail'] ) ) : ?>
							<p class="hp-issue__detail"><?php echo esc_html( $issue['detail'] ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<?php foreach ( $info_issues as $issue ) : ?>
				<div class="hp-issue hp-issue--info">
					<div class="hp-issue__icon">i</div>
					<div>
						<div class="hp-issue__title"><?php echo esc_html( $issue['title'] ?? '' ); ?></div>
						<?php if ( ! empty( $issue['detail'] ) ) : ?>
							<p class="hp-issue__detail"><?php echo esc_html( $issue['detail'] ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>

		<?php endif; ?>
	</div>

	<!-- Recommendations -->
	<?php if ( ! empty( $recommendations ) ) : ?>
	<div class="hp-card">
		<div class="hp-card__title"><?php esc_html_e( 'Recommendations', 'wp-guardrail' ); ?></div>
		<?php foreach ( $recommendations as $rec ) :
			$r_priority = $rec['priority'] ?? 'low';
			$r_title    = $rec['title']    ?? '';
			$r_action   = $rec['action']   ?? '';
		?>
			<div class="hp-rec">
				<div class="hp-rec__label"><?php echo esc_html( strtoupper( $r_priority ) ); ?></div>
				<div class="hp-rec__title"><?php echo esc_html( $r_title ); ?></div>
				<?php if ( $r_action ) : ?>
					<p class="hp-rec__action"><?php echo esc_html( $r_action ); ?></p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<!-- Module details (if general report) -->
	<?php if ( ! empty( $sections ) ) : ?>
	<div class="hp-card">
		<div class="hp-card__title"><?php esc_html_e( 'Module Overview', 'wp-guardrail' ); ?></div>
		<table class="hp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Module', 'wp-guardrail' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-guardrail' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sections as $sec ) :
					$sec_status = $sec['status'] ?? 'ok';
					$sec_label  = $status_labels[ $sec_status ] ?? ucfirst( $sec_status );
				?>
					<tr>
						<td><?php echo esc_html( $sec['title'] ?? '' ); ?></td>
						<td><span class="hp-badge hp-badge--<?php echo esc_attr( $sec_status ); ?>"><?php echo esc_html( $sec_label ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>

	<div class="hp-footer">
		<?php
		printf(
			/* translators: %s: site name */
			esc_html__( 'WP Guardrail health report for %s', 'wp-guardrail' ),
			esc_html( get_bloginfo( 'name' ) )
		);
		?>
		&nbsp;&middot;&nbsp;
		<?php esc_html_e( 'This is a snapshot, not live monitoring.', 'wp-guardrail' ); ?>
	</div>

</div><!-- .hp-wrap -->
</body>
</html>
