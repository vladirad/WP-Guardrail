<?php
/**
 * Plugin safety guard for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the protected-plugins list and flags known high-risk plugins.
 *
 * Two distinct concepts:
 *
 *  - Protected plugins: admin-curated. These are hard-blocked from the
 *    disabled set when applying sandbox isolation and are automatically
 *    added to Conflict Wizard exclusions.  Stored in wp_options so the
 *    list persists across sessions.
 *
 *  - Flagged (risky) plugins: auto-detected heuristic.  Plugins whose
 *    directory slug matches known authentication, security, or caching
 *    patterns are flagged as potentially risky to disable.  Flagging is
 *    advisory — it triggers a warning notice but does not block the apply.
 */
class WPG_Safety_Guard {

	/**
	 * wp_options key for the persisted protected-plugins list.
	 */
	const PROTECTED_OPTION = 'wpg_protected_plugins';

	/**
	 * Slug substrings that identify potentially risky plugins.
	 *
	 * Matched against the first path segment of the plugin file
	 * (e.g. "wordfence" from "wordfence/wordfence.php"), lowercased.
	 * Extend this list as needed for your environment.
	 *
	 * @var string[]
	 */
	private static $risk_slugs = array(
		// Security / firewall
		'wordfence',
		'sucuri',
		'better-wp-security',
		'ithemes-security',
		'all-in-one-wp-security',
		'shield-security',
		'wp-cerber',
		'anti-malware',
		'bbq-firewall',
		// Authentication / login / SSO
		'wp-native-php-sessions',
		'nextend-social-login',
		'miniorange',
		'simple-ldap-login',
		'wp-google-login',
		'two-factor',
		// Membership / access control
		'members',
		'user-role-editor',
		'capability-manager',
		'restrict-content',
		'paid-memberships-pro',
		'woocommerce-memberships',
		'woocommerce-subscriptions',
		'simple-membership',
		'wp-members',
		'buddypress',
		'bbpress',
		// Caching (can break admin redirect loops)
		'w3-total-cache',
		'wp-super-cache',
		'wp-rocket',
		'wp-fastest-cache',
		'litespeed-cache',
		'hummingbird-performance',
		'sg-cachepress',
		// Maintenance / coming-soon (blocks frontend access)
		'maintenance',
		'coming-soon',
		// Core-adjacent
		'jetpack',
	);

	// -------------------------------------------------------------------------
	// Protected plugins list
	// -------------------------------------------------------------------------

	/**
	 * Get the persisted protected-plugins list.
	 *
	 * @return string[] Plugin file paths (e.g. "folder/plugin.php").
	 */
	public static function get_protected_plugins() {
		$plugins = get_option( self::PROTECTED_OPTION, array() );
		if ( ! is_array( $plugins ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'sanitize_text_field', $plugins ) ) );
	}

	/**
	 * Overwrite the persisted protected-plugins list.
	 *
	 * @param string[] $plugins Plugin file paths.
	 * @return void
	 */
	public static function set_protected_plugins( $plugins ) {
		$plugins = is_array( $plugins ) ? $plugins : array();
		$plugins = array_values(
			array_unique(
				array_map( 'sanitize_text_field', array_filter( $plugins ) )
			)
		);
		update_option( self::PROTECTED_OPTION, $plugins, false );
	}

	/**
	 * Whether a plugin file is in the protected list.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @return bool
	 */
	public static function is_protected( $plugin_file ) {
		return in_array(
			sanitize_text_field( (string) $plugin_file ),
			self::get_protected_plugins(),
			true
		);
	}

	// -------------------------------------------------------------------------
	// Risk heuristics
	// -------------------------------------------------------------------------

	/**
	 * Return the subset of $plugins whose slug matches a known-risk pattern.
	 *
	 * This does NOT check the protected list — protection and flagging are
	 * orthogonal: a plugin can be risky without being protected and vice versa.
	 *
	 * @param string[] $plugins Plugin file paths.
	 * @return string[] Flagged plugin file paths.
	 */
	public static function get_flagged_plugins( $plugins ) {
		$plugins = is_array( $plugins ) ? $plugins : array();
		$flagged = array();
		foreach ( $plugins as $plugin_file ) {
			$plugin_file = sanitize_text_field( (string) $plugin_file );
			if ( self::matches_risk_slug( $plugin_file ) ) {
				$flagged[] = $plugin_file;
			}
		}
		return $flagged;
	}

	/**
	 * Whether a plugin file matches any known-risk slug pattern.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @return bool
	 */
	public static function is_flagged( $plugin_file ) {
		return self::matches_risk_slug( sanitize_text_field( (string) $plugin_file ) );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Match a plugin file path against the risk slug list.
	 *
	 * @param string $plugin_file Sanitised plugin file path.
	 * @return bool
	 */
	private static function matches_risk_slug( $plugin_file ) {
		$parts = explode( '/', (string) $plugin_file );
		$slug  = strtolower( $parts[0] );
		if ( '' === $slug ) {
			return false;
		}
		foreach ( self::$risk_slugs as $pattern ) {
			if ( false !== strpos( $slug, $pattern ) ) {
				return true;
			}
		}
		return false;
	}
}
