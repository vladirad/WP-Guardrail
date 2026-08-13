<?php
/**
 * Diagnostics page renderer for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Diagnostics admin page.
 */
class WPG_Page_Diagnostics {

	/**
	 * Render the Diagnostics page.
	 *
	 * @param array $ctx Shared page context.
	 * @return void
	 */
	public static function render( array $ctx ): void {
		$is_active = $ctx['is_active'];
		$page_url  = $ctx['page_url'];

		ob_start();
		?>
		<a href="<?php echo esc_url( WPG_Admin_Pages::page_url( array( 'page' => WPG_Admin::SLUG_DIAGNOSTICS ) ) ); ?>" class="button"><?php echo esc_html__( 'Refresh', 'wp-guardrail' ); ?></a>
		<a href="<?php echo esc_url( WPG_Admin_Pages::page_url( array( 'page' => WPG_Admin::SLUG_REPORTS, 'wpg_show_report' => 1 ) ) ); ?>" class="button button-secondary"><?php echo esc_html__( 'Download Report', 'wp-guardrail' ); ?></a>
		<?php $header_actions = ob_get_clean();

		WPG_UI::page_header( array(
			'title'          => __( 'Diagnostics', 'wp-guardrail' ),
			'subtitle'       => __( 'Read-only snapshot of site performance metrics — no changes are made to your site', 'wp-guardrail' ),
			'sandbox_active' => $is_active,
			'actions_html'   => $header_actions,
		) );

		self::render_diagnostics_section();
	}

	/**
	 * Render all diagnostics metric cards.
	 *
	 * @return void
	 */
	public static function render_diagnostics_section(): void {
		$autoload   = WPG_Diagnostics::get_autoload_stats();
		$cron       = WPG_Diagnostics::get_cron_stats();
		$memory     = WPG_Diagnostics::get_memory_stats();
		$transients = WPG_Diagnostics::get_transient_stats();
		$db_tables  = WPG_Diagnostics::get_db_table_stats();

		$severity_map = array( 'ok' => 'ok', 'warning' => 'warning', 'critical' => 'critical' );

		$autoload_sev  = isset( $severity_map[ $autoload['status'] ] )   ? $severity_map[ $autoload['status'] ]   : 'neutral';
		$cron_sev      = isset( $severity_map[ $cron['status'] ] )       ? $severity_map[ $cron['status'] ]       : 'neutral';
		$memory_sev    = isset( $severity_map[ $memory['status'] ] )     ? $severity_map[ $memory['status'] ]     : 'neutral';
		$transient_sev = isset( $severity_map[ $transients['status'] ] ) ? $severity_map[ $transients['status'] ] : 'neutral';

		$label_map = array(
			'ok'       => __( 'OK', 'wp-guardrail' ),
			'warning'  => __( 'Warning', 'wp-guardrail' ),
			'critical' => __( 'Critical', 'wp-guardrail' ),
			'neutral'  => __( 'Unknown', 'wp-guardrail' ),
		);

		WPG_UI::summary_strip( array(
			array( 'label' => __( 'Autoload', 'wp-guardrail' ),    'value' => WPG_Diagnostics::format_bytes( $autoload['total_bytes'] ),                          'severity' => $autoload_sev ),
			array( 'label' => __( 'Cron events', 'wp-guardrail' ), 'value' => (string) $cron['total_events'] . ' / ' . $cron['overdue_count'] . ' overdue',       'severity' => $cron_sev ),
			array( 'label' => __( 'Memory (peak)', 'wp-guardrail' ),'value' => WPG_Diagnostics::format_bytes( $memory['peak_bytes'] ),                            'severity' => $memory_sev ),
			array( 'label' => __( 'Transients', 'wp-guardrail' ),  'value' => (string) $transients['total_count'],                                               'severity' => $transient_sev ),
		) );

		echo '<div class="wpg-diag-grid">';

		// Autoload card.
		WPG_UI::card(
			__( 'Autoload Options', 'wp-guardrail' ),
			function() use ( $autoload ) {
				?>
				<table class="widefat striped wpg-table">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Total autoloaded size', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( WPG_Diagnostics::format_bytes( $autoload['total_bytes'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Autoloaded option count', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( $autoload['total_count'] ) ); ?></td>
						</tr>
					</tbody>
				</table>
				<?php if ( ! empty( $autoload['top_options'] ) ) : ?>
					<h3 style="margin:12px 0 6px;font-size:13px"><?php echo esc_html__( 'Top 10 largest', 'wp-guardrail' ); ?></h3>
					<table class="widefat striped wpg-table">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Option name', 'wp-guardrail' ); ?></th>
								<th><?php echo esc_html__( 'Size', 'wp-guardrail' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $autoload['top_options'] as $opt ) : ?>
								<tr>
									<td><code class="wpg-inline-code"><?php echo esc_html( $opt['name'] ); ?></code></td>
									<td><?php echo esc_html( WPG_Diagnostics::format_bytes( $opt['bytes'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php
			},
			array( 'badge_severity' => $autoload_sev, 'badge_label' => $label_map[ $autoload_sev ] )
		);

		// Cron card.
		WPG_UI::card(
			__( 'WP-Cron Backlog', 'wp-guardrail' ),
			function() use ( $cron ) {
				?>
				<table class="widefat striped wpg-table">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Total scheduled events', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( $cron['total_events'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Overdue events', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( $cron['overdue_count'] ) ); ?></td>
						</tr>
					</tbody>
				</table>
				<?php if ( ! empty( $cron['overdue_hooks'] ) ) : ?>
					<h3 style="margin:12px 0 4px;font-size:13px"><?php echo esc_html__( 'Overdue hook names', 'wp-guardrail' ); ?></h3>
					<ul style="margin:.25em 0 0 1.5em;list-style:disc">
						<?php foreach ( $cron['overdue_hooks'] as $hook ) : ?>
							<li><code class="wpg-inline-code"><?php echo esc_html( $hook ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php
			},
			array( 'badge_severity' => $cron_sev, 'badge_label' => $label_map[ $cron_sev ] )
		);

		// Memory card.
		WPG_UI::card(
			__( 'PHP Memory', 'wp-guardrail' ),
			function() use ( $memory ) {
				$pct = $memory['limit_bytes'] > 0 ? (int) $memory['peak_pct'] : 0;
				if ( $memory['limit_bytes'] > 0 ) {
					WPG_UI::progress_bar(
						$pct,
						100,
						sprintf(
							/* translators: 1: peak usage, 2: limit */
							__( 'Peak %1$s of %2$s', 'wp-guardrail' ),
							WPG_Diagnostics::format_bytes( $memory['peak_bytes'] ),
							WPG_Diagnostics::format_bytes( $memory['limit_bytes'] )
						),
						$pct >= 90 ? 'critical' : ( $pct >= 75 ? 'warning' : 'neutral' )
					);
				}
				?>
				<table class="widefat striped wpg-table">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Current usage', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( WPG_Diagnostics::format_bytes( $memory['current_bytes'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Peak usage', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( WPG_Diagnostics::format_bytes( $memory['peak_bytes'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Limit (WP_MEMORY_LIMIT)', 'wp-guardrail' ); ?></th>
							<td><?php echo $memory['limit_bytes'] > 0 ? esc_html( WPG_Diagnostics::format_bytes( $memory['limit_bytes'] ) ) : esc_html__( 'Unknown', 'wp-guardrail' ); ?></td>
						</tr>
						<?php if ( $memory['limit_bytes'] > 0 ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Peak as % of limit', 'wp-guardrail' ); ?></th>
								<td><?php echo esc_html( $memory['peak_pct'] . '%' ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<?php
			},
			array( 'badge_severity' => $memory_sev, 'badge_label' => $label_map[ $memory_sev ] )
		);

		// Transients card.
		WPG_UI::card(
			__( 'Transients', 'wp-guardrail' ),
			function() use ( $transients ) {
				?>
				<table class="widefat striped wpg-table">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Total transients', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( $transients['total_count'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Expired transients', 'wp-guardrail' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( $transients['expired_count'] ) ); ?></td>
						</tr>
					</tbody>
				</table>
				<?php
			},
			array( 'badge_severity' => $transient_sev, 'badge_label' => $label_map[ $transient_sev ] )
		);

		echo '</div>'; // end .wpg-diag-grid

		// Database tables (full width).
		WPG_UI::card(
			__( 'Database Table Sizes', 'wp-guardrail' ),
			function() use ( $db_tables ) {
				if ( empty( $db_tables ) ) {
					WPG_UI::empty_state( '🗄️', __( 'No table data available', 'wp-guardrail' ), '' );
					return;
				}
				?>
				<table class="widefat striped wpg-table">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Table', 'wp-guardrail' ); ?></th>
							<th><?php echo esc_html__( 'Rows', 'wp-guardrail' ); ?></th>
							<th><?php echo esc_html__( 'Data size', 'wp-guardrail' ); ?></th>
							<th><?php echo esc_html__( 'Index size', 'wp-guardrail' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $db_tables as $tbl ) : ?>
							<tr>
								<td><code class="wpg-inline-code"><?php echo esc_html( $tbl['name'] ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( $tbl['rows'] ) ); ?></td>
								<td><?php echo esc_html( WPG_Diagnostics::format_bytes( $tbl['data_bytes'] ) ); ?></td>
								<td><?php echo esc_html( WPG_Diagnostics::format_bytes( $tbl['index_bytes'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
			}
		);
	}
}
