<?php
/**
 * Performance diagnostics collector for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects site performance metrics for display in the Diagnostics tab.
 *
 * All methods are read-only queries — nothing is written to the database.
 * Each method returns a data array plus a `status` key: 'ok', 'warning',
 * or 'critical', suitable for colour-coded UI display.
 */
class WPG_Diagnostics {

	/**
	 * Autoload size thresholds (bytes).
	 */
	const AUTOLOAD_WARN     = 921600;   // 900 KB
	const AUTOLOAD_CRITICAL = 3145728;  // 3 MB

	/**
	 * Overdue cron event thresholds.
	 */
	const CRON_WARN     = 5;
	const CRON_CRITICAL = 20;

	/**
	 * Peak memory / limit ratio thresholds (%).
	 */
	const MEMORY_WARN     = 50;
	const MEMORY_CRITICAL = 75;

	/**
	 * Transient count thresholds.
	 */
	const TRANSIENT_WARN     = 500;
	const TRANSIENT_CRITICAL = 2000;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Get autoload options size statistics.
	 *
	 * Queries wp_options for all autoloaded rows, totals their serialised size,
	 * and returns the top 10 offenders by size.
	 *
	 * @return array {
	 *     @type int    $total_bytes   Sum of all autoloaded option value lengths.
	 *     @type int    $total_count   Number of autoloaded options.
	 *     @type array  $top_options   Up to 10 largest options { name, bytes }.
	 *     @type string $status        'ok' | 'warning' | 'critical'
	 * }
	 */
	public static function get_autoload_stats() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) AS byte_size
			 FROM {$wpdb->options}
			 WHERE autoload = 'yes'
			 ORDER BY byte_size DESC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$total_bytes = 0;
		$total_count = count( $rows );
		$top_options = array();

		foreach ( $rows as $index => $row ) {
			$total_bytes += (int) $row['byte_size'];
			if ( $index < 10 ) {
				$top_options[] = array(
					'name'  => sanitize_text_field( (string) $row['option_name'] ),
					'bytes' => (int) $row['byte_size'],
				);
			}
		}

		return array(
			'total_bytes' => $total_bytes,
			'total_count' => $total_count,
			'top_options' => $top_options,
			'status'      => self::threshold_status( $total_bytes, self::AUTOLOAD_WARN, self::AUTOLOAD_CRITICAL ),
		);
	}

	/**
	 * Get WP-Cron backlog statistics.
	 *
	 * Parses the cron option, counts total scheduled events, and identifies
	 * overdue events (scheduled timestamp < current time).
	 *
	 * @return array {
	 *     @type int    $total_events   Total events across all timestamps.
	 *     @type int    $overdue_count  Number of overdue events.
	 *     @type array  $overdue_hooks  List of overdue hook names (deduplicated).
	 *     @type string $status         'ok' | 'warning' | 'critical'
	 * }
	 */
	public static function get_cron_stats() {
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			$cron = array();
		}

		$now           = time();
		$total_events  = 0;
		$overdue_count = 0;
		$overdue_hooks = array();

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			$is_overdue = (int) $timestamp < $now;
			foreach ( $hooks as $hook_name => $events ) {
				$event_count    = is_array( $events ) ? count( $events ) : 1;
				$total_events  += $event_count;
				if ( $is_overdue ) {
					$overdue_count += $event_count;
					$overdue_hooks[] = sanitize_text_field( (string) $hook_name );
				}
			}
		}

		$overdue_hooks = array_values( array_unique( $overdue_hooks ) );

		return array(
			'total_events'  => $total_events,
			'overdue_count' => $overdue_count,
			'overdue_hooks' => $overdue_hooks,
			'status'        => self::threshold_status( $overdue_count, self::CRON_WARN, self::CRON_CRITICAL ),
		);
	}

	/**
	 * Get PHP memory usage statistics for the current request.
	 *
	 * @return array {
	 *     @type int    $current_bytes  Current memory usage in bytes.
	 *     @type int    $peak_bytes     Peak memory usage in bytes.
	 *     @type int    $limit_bytes    WP_MEMORY_LIMIT resolved to bytes (0 = unknown).
	 *     @type int    $peak_pct       Peak as % of limit (0 when limit is unknown).
	 *     @type string $status         'ok' | 'warning' | 'critical'
	 * }
	 */
	public static function get_memory_stats() {
		$current_bytes = memory_get_usage( true );
		$peak_bytes    = memory_get_peak_usage( true );
		$limit_bytes   = self::parse_size( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : ini_get( 'memory_limit' ) );

		$peak_pct = 0;
		if ( $limit_bytes > 0 ) {
			$peak_pct = (int) round( ( $peak_bytes / $limit_bytes ) * 100 );
		}

		return array(
			'current_bytes' => $current_bytes,
			'peak_bytes'    => $peak_bytes,
			'limit_bytes'   => $limit_bytes,
			'peak_pct'      => $peak_pct,
			'status'        => $limit_bytes > 0
				? self::threshold_status( $peak_pct, self::MEMORY_WARN, self::MEMORY_CRITICAL )
				: 'ok',
		);
	}

	/**
	 * Get transient count statistics.
	 *
	 * Counts rows in wp_options that are transient entries (both live and
	 * expired timeout markers).
	 *
	 * @return array {
	 *     @type int    $total_count    Total transient value rows.
	 *     @type int    $expired_count  Transients whose timeout is in the past.
	 *     @type string $status         'ok' | 'warning' | 'critical'
	 * }
	 */
	public static function get_transient_stats() {
		global $wpdb;

		$total_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_%'
			   AND option_name NOT LIKE '_transient_timeout_%'"
		);

		$now           = time();
		$expired_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				 WHERE option_name LIKE '_transient_timeout_%'
				   AND option_value < %d",
				$now
			)
		);

		return array(
			'total_count'   => $total_count,
			'expired_count' => $expired_count,
			'status'        => self::threshold_status( $total_count, self::TRANSIENT_WARN, self::TRANSIENT_CRITICAL ),
		);
	}

	/**
	 * Get database table size statistics for core WordPress tables.
	 *
	 * Returns rows, data size, and index size for each table found via
	 * SHOW TABLE STATUS.  Only tables whose name starts with the current
	 * table prefix are included.
	 *
	 * @return array Array of table rows: { name, rows, data_bytes, index_bytes }.
	 */
	public static function get_db_table_stats() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( ! is_array( $results ) ) {
			return array();
		}

		$prefix = $wpdb->prefix;
		$tables = array();

		foreach ( $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['Name'] ) ? (string) $row['Name'] : '';
			if ( '' === $name || 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			$tables[] = array(
				'name'        => sanitize_text_field( $name ),
				'rows'        => isset( $row['Rows'] ) ? (int) $row['Rows'] : 0,
				'data_bytes'  => isset( $row['Data_length'] ) ? (int) $row['Data_length'] : 0,
				'index_bytes' => isset( $row['Index_length'] ) ? (int) $row['Index_length'] : 0,
			);
		}

		return $tables;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return a status string based on a value vs. warn/critical thresholds.
	 *
	 * @param int|float $value    Measured value.
	 * @param int|float $warn     Threshold above which status is 'warning'.
	 * @param int|float $critical Threshold above which status is 'critical'.
	 * @return string 'ok' | 'warning' | 'critical'
	 */
	public static function threshold_status( $value, $warn, $critical ) {
		if ( $value >= $critical ) {
			return 'critical';
		}
		if ( $value >= $warn ) {
			return 'warning';
		}
		return 'ok';
	}

	/**
	 * Format bytes as a human-readable string.
	 *
	 * @param int $bytes Number of bytes.
	 * @return string e.g. "1.4 MB"
	 */
	public static function format_bytes( $bytes ) {
		$bytes = max( 0, (int) $bytes );
		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, 1 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		return $bytes . ' B';
	}

	/**
	 * Parse a PHP size shorthand string (e.g. "128M", "1G") into bytes.
	 *
	 * @param string|int $size Size string or integer.
	 * @return int Bytes, or 0 if unparseable.
	 */
	private static function parse_size( $size ) {
		if ( is_numeric( $size ) ) {
			return (int) $size;
		}

		$size = trim( (string) $size );
		if ( '' === $size || '-1' === $size ) {
			return 0;
		}

		$unit  = strtoupper( substr( $size, -1 ) );
		$value = (float) $size;

		switch ( $unit ) {
			case 'G':
				return (int) ( $value * 1073741824 );
			case 'M':
				return (int) ( $value * 1048576 );
			case 'K':
				return (int) ( $value * 1024 );
		}

		return (int) $value;
	}
}
