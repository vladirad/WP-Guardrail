<?php
/**
 * Health page publisher for WP Guardrail.
 *
 * Publishes saved reports as protected, token-gated local health pages.
 * Health pages are snapshot-based — they display a fixed saved report,
 * not live diagnostics.
 *
 * Storage:
 *  - Index: wp_option 'wpg_health_pages' → array of health page metadata rows.
 *
 * Serving:
 *  - Health pages are served via WordPress 'init' hook by intercepting
 *    the 'wpg_health' query var: /?wpg_health={token}
 *
 * Access modes (v1):
 *  - 'admin' — only logged-in admins can view.
 *  - 'token' — anyone with the link can view.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health page publisher and server.
 */
class WPG_Health_Page_Publisher {

	const INDEX_OPTION   = 'wpg_health_pages';
	const MAX_PAGES      = 10;
	const DEFAULT_EXPIRY = 604800; // 7 days in seconds.

	// =========================================================================
	// Publishing API.
	// =========================================================================

	/**
	 * Publish a saved report as a health page.
	 *
	 * @param string $report_id   Saved report ID.
	 * @param string $access_mode 'admin' or 'token'.
	 * @param int    $expires_in  Seconds until expiry (0 = no expiry).
	 * @return string|WP_Error Token string on success, or WP_Error on failure.
	 */
	public static function publish( string $report_id, string $access_mode = 'token', int $expires_in = self::DEFAULT_EXPIRY ) {
		$report = WPG_Report_Repository::get( $report_id );
		if ( ! $report ) {
			return new WP_Error( 'wpg_report_not_found', __( 'Report not found.', 'wp-guardrail' ) );
		}

		$token      = self::generate_token();
		$expires_at = $expires_in > 0 ? ( time() + $expires_in ) : 0;

		$page = array(
			'token'        => $token,
			'report_id'    => sanitize_key( $report_id ),
			'access_mode'  => in_array( $access_mode, array( 'admin', 'token' ), true ) ? $access_mode : 'token',
			'expires_at'   => $expires_at,
			'created_at'   => time(),
			'report_title' => sanitize_text_field( $report['title'] ?? '' ),
		);

		$index   = self::get_index();
		$index[] = $page;

		// Trim oldest if over limit.
		if ( count( $index ) > self::MAX_PAGES ) {
			$index = array_slice( $index, -self::MAX_PAGES );
		}

		update_option( self::INDEX_OPTION, $index, false );

		return $token;
	}

	/**
	 * Revoke a health page by token.
	 *
	 * @param string $token Health page token.
	 * @return bool True always.
	 */
	public static function revoke( string $token ): bool {
		$token   = sanitize_text_field( $token );
		$index   = self::get_index();
		$updated = array_values( array_filter( $index, fn( $row ) => $row['token'] !== $token ) );
		update_option( self::INDEX_OPTION, $updated, false );
		return true;
	}

	/**
	 * List all published health pages.
	 *
	 * @return array Array of health page metadata rows.
	 */
	public static function list(): array {
		return self::get_index();
	}

	/**
	 * Build the public URL for a health page.
	 *
	 * @param string $token Health page token.
	 * @return string
	 */
	public static function get_url( string $token ): string {
		return add_query_arg( 'wpg_health', rawurlencode( sanitize_text_field( $token ) ), home_url( '/' ) );
	}

	// =========================================================================
	// Serving via WordPress init hook.
	// =========================================================================

	/**
	 * Register the init hook for health page serving.
	 *
	 * Called from the plugin bootstrap.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( __CLASS__, 'maybe_serve_health_page' ), 1 );
	}

	/**
	 * Intercept requests with ?wpg_health=token and serve the health page.
	 *
	 * Exits after output — never returns to normal WordPress execution.
	 *
	 * @return void
	 */
	public static function maybe_serve_health_page(): void {
		if ( ! isset( $_GET['wpg_health'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET['wpg_health'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $token ) {
			return;
		}

		$page = self::find_by_token( $token );
		if ( ! $page ) {
			wp_die(
				esc_html__( 'This health page does not exist or has been revoked.', 'wp-guardrail' ),
				esc_html__( 'Not Found', 'wp-guardrail' ),
				array( 'response' => 404 )
			);
		}

		// Check expiry.
		if ( $page['expires_at'] > 0 && time() > $page['expires_at'] ) {
			wp_die(
				esc_html__( 'This health page has expired.', 'wp-guardrail' ),
				esc_html__( 'Gone', 'wp-guardrail' ),
				array( 'response' => 410 )
			);
		}

		// Check access mode.
		if ( 'admin' === $page['access_mode'] && ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this health page.', 'wp-guardrail' ),
				esc_html__( 'Forbidden', 'wp-guardrail' ),
				array( 'response' => 403 )
			);
		}

		// Load the associated report.
		$report = WPG_Report_Repository::get( $page['report_id'] );
		if ( ! $report ) {
			wp_die(
				esc_html__( 'The report associated with this health page no longer exists.', 'wp-guardrail' ),
				esc_html__( 'Not Found', 'wp-guardrail' ),
				array( 'response' => 404 )
			);
		}

		self::render_health_page( $report, $page );
		exit;
	}

	// =========================================================================
	// Private helpers.
	// =========================================================================

	/**
	 * Render the health page HTML using the view template.
	 *
	 * @param array $report Report data.
	 * @param array $page   Health page metadata.
	 * @return void
	 */
	private static function render_health_page( array $report, array $page ): void {
		$template = WPG_PATH . 'admin/views/reports/health-page.php';
		if ( file_exists( $template ) ) {
			// Make $report and $page available to the template.
			include $template;
		} else {
			// Minimal inline fallback.
			nocache_headers();
			header( 'Content-Type: text/html; charset=UTF-8' );
			echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . esc_html( $report['title'] ?? 'Health Report' ) . '</title></head><body>';
			echo '<h1>' . esc_html( $report['title'] ?? 'Health Report' ) . '</h1>';
			echo '<p><strong>' . esc_html__( 'Status:', 'wp-guardrail' ) . '</strong> ' . esc_html( strtoupper( $report['status'] ?? 'unknown' ) ) . '</p>';
			echo '<p><em>' . esc_html__( 'This is a generated snapshot, not live monitoring.', 'wp-guardrail' ) . '</em></p>';
			echo '</body></html>';
		}
	}

	/**
	 * Find a health page record by token.
	 *
	 * @param string $token Token to search.
	 * @return array|null Record or null.
	 */
	private static function find_by_token( string $token ): ?array {
		foreach ( self::get_index() as $page ) {
			if ( isset( $page['token'] ) && hash_equals( $page['token'], $token ) ) {
				return $page;
			}
		}
		return null;
	}

	/**
	 * Generate a cryptographically secure random token.
	 *
	 * @return string 32-char lowercase hex string.
	 */
	private static function generate_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Load the health pages index.
	 *
	 * @return array
	 */
	private static function get_index(): array {
		$index = get_option( self::INDEX_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}
}
