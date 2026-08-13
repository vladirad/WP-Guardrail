<?php
/**
 * Session-scoped logger for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight session-scoped logger.
 *
 * Entries are stored inside the active sandbox session transient under the
 * 'wpg_log' key.  A ring buffer of WPG_Logger::MAX_ENTRIES is maintained.
 * When no sandbox session is active all calls are silent no-ops, so callers
 * need no guard code.
 *
 * Usage:
 *   WPG_Logger::error( 'Download failed', array( 'plugin' => $basename ) );
 *   WPG_Logger::warning( 'Package skipped' );
 *   WPG_Logger::info( 'Simulation ready' );
 */
class WPG_Logger {

	/**
	 * Session key under which log entries are stored.
	 */
	const LOG_KEY = 'wpg_log';

	/**
	 * Maximum number of log entries retained per session.
	 */
	const MAX_ENTRIES = 50;

	/**
	 * Append a log entry to the current sandbox session.
	 *
	 * @param string $level   Severity level: 'info' | 'warning' | 'error'.
	 * @param string $message Human-readable description.
	 * @param array  $extra   Optional flat key/value context array.
	 * @return void
	 */
	public static function log( $level, $message, $extra = array() ) {
		$ctx     = WPG_Container::instance();
		$session = $ctx->get_session();

		if ( false === $session ) {
			return;
		}

		$entries = isset( $session[ self::LOG_KEY ] ) && is_array( $session[ self::LOG_KEY ] )
			? $session[ self::LOG_KEY ]
			: array();

		$entry = array(
			'time'    => current_time( 'mysql' ),
			'level'   => sanitize_key( (string) $level ),
			'message' => sanitize_text_field( (string) $message ),
		);

		if ( ! empty( $extra ) && is_array( $extra ) ) {
			$entry['extra'] = array_map( 'sanitize_text_field', $extra );
		}

		array_unshift( $entries, $entry );
		$entries = array_slice( $entries, 0, self::MAX_ENTRIES );

		$session[ self::LOG_KEY ] = $entries;
		$ctx->update_session( $session );
	}

	/**
	 * Log an informational message.
	 *
	 * @param string $message Message text.
	 * @param array  $extra   Optional context.
	 * @return void
	 */
	public static function info( $message, $extra = array() ) {
		self::log( 'info', $message, $extra );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Message text.
	 * @param array  $extra   Optional context.
	 * @return void
	 */
	public static function warning( $message, $extra = array() ) {
		self::log( 'warning', $message, $extra );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Message text.
	 * @param array  $extra   Optional context.
	 * @return void
	 */
	public static function error( $message, $extra = array() ) {
		self::log( 'error', $message, $extra );
	}

	/**
	 * Get all log entries from the current session, newest first.
	 *
	 * @return array
	 */
	public static function get_log() {
		$session = WPG_Container::instance()->get_session();

		if ( false === $session ) {
			return array();
		}

		return isset( $session[ self::LOG_KEY ] ) && is_array( $session[ self::LOG_KEY ] )
			? $session[ self::LOG_KEY ]
			: array();
	}

	/**
	 * Whether any error-level entries exist in the current session log.
	 *
	 * @return bool
	 */
	public static function has_errors() {
		foreach ( self::get_log() as $entry ) {
			if ( isset( $entry['level'] ) && 'error' === $entry['level'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Clear all log entries from the current session.
	 *
	 * @return void
	 */
	public static function clear() {
		$ctx     = WPG_Container::instance();
		$session = $ctx->get_session();

		if ( false === $session ) {
			return;
		}

		$session[ self::LOG_KEY ] = array();
		$ctx->update_session( $session );
	}
}
