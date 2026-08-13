<?php
/**
 * Update Safety Simulator for WP Guardrail.
 *
 * Downloads plugin update packages, extracts them to a temporary directory
 * inside wp-content/uploads/wp-guardrail/, creates shim loader files, and
 * runs baseline-vs-simulated HTTP comparisons via WPG_URL_Tester.
 *
 * The uploads subdirectory is protected with index.php and .htaccess on
 * first use so that PHP source files are not publicly readable.
 *
 * Important limitations:
 *  - This is a compatibility smoke test, not a full staging environment.
 *  - Simulated plugins are shimmed via require_once; plugins depending on
 *    exact installed directory paths may not behave accurately.
 *  - A passing result confirms HTTP compatibility for tested URLs only; it
 *    does not guarantee full feature compatibility or database schema safety.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles pre-update simulation lifecycle and tests.
 */
class WPG_Update_Simulator {

	// -------------------------------------------------------------------------
	// Structured error code constants.
	// -------------------------------------------------------------------------

	const ERR_NO_PLUGINS           = 'no_plugins_selected';
	const ERR_NO_PENDING           = 'no_pending_updates';
	const ERR_UPLOAD_DIR           = 'upload_dir_unavailable';
	const ERR_SIM_DIR_CREATE       = 'sim_dir_create_failed';
	const ERR_NO_PACKAGE_URL       = 'no_package_url';
	const ERR_DOWNLOAD_FAILED      = 'download_failed';
	const ERR_ZIP_OPEN_FAILED      = 'zip_open_failed';
	const ERR_ENTRY_NOT_FOUND      = 'plugin_entry_not_found';
	const ERR_FILESYSTEM_NOT_WRITE = 'filesystem_not_writable';
	const ERR_SHIM_DIR_CREATE      = 'shim_dir_create_failed';
	const ERR_SHIM_WRITE_FAILED    = 'shim_generation_failed';
	const ERR_NO_SESSION           = 'no_session';
	const ERR_SIM_NOT_ACTIVE       = 'simulation_not_active';
	const ERR_NO_VALID_URLS        = 'no_valid_urls';
	const ERR_EXTRACT_ALL_FAILED   = 'all_packages_failed';

	/**
	 * Get plugins with pending updates.
	 *
	 * @return array
	 */
	public static function get_pending_updates(): array {
		$updates = get_site_transient( 'update_plugins' );
		if ( ! is_object( $updates ) || empty( $updates->response ) || ! is_array( $updates->response ) ) {
			return array();
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$pending = array();

		foreach ( $updates->response as $plugin_basename => $update ) {
			$plugin_basename = sanitize_text_field( (string) $plugin_basename );
			if ( '' === $plugin_basename || ! is_object( $update ) ) {
				continue;
			}

			$plugin_data = isset( $plugins[ $plugin_basename ] ) && is_array( $plugins[ $plugin_basename ] )
				? $plugins[ $plugin_basename ]
				: array();

			$pending[ $plugin_basename ] = array(
				'plugin'          => $plugin_basename,
				'name'            => isset( $plugin_data['Name'] ) ? sanitize_text_field( $plugin_data['Name'] ) : $plugin_basename,
				'current_version' => isset( $plugin_data['Version'] ) ? sanitize_text_field( $plugin_data['Version'] ) : '',
				'new_version'     => isset( $update->new_version ) ? sanitize_text_field( (string) $update->new_version ) : '',
				'package'         => isset( $update->package ) ? esc_url_raw( (string) $update->package ) : '',
			);
		}

		return $pending;
	}

	// =========================================================================
	// Preflight checks.
	// =========================================================================

	/**
	 * Run preflight checks before attempting a simulation.
	 *
	 * Returns a structured result array:
	 *   passed  bool     — true only if all required checks pass.
	 *   checks  array    — individual check outcomes.
	 *   errors  array    — structured error entries for failed checks.
	 *
	 * Each error entry:
	 *   code    string   — machine-friendly error code constant.
	 *   summary string   — human-readable one-line summary.
	 *   details string   — technical detail (path, message, etc.).
	 *   next    string   — suggested corrective action.
	 *
	 * @param array $plugins Sanitized plugin basenames to simulate.
	 * @return array
	 */
	public static function run_preflight_checks( array $plugins ): array {
		$result = array(
			'passed' => false,
			'checks' => array(),
			'errors' => array(),
		);

		// Check 1: sandbox session exists.
		$session = WPG_Container::instance()->get_session();
		$result['checks']['session_active'] = false !== $session;
		if ( false === $session ) {
			$result['errors'][] = array(
				'code'    => self::ERR_NO_SESSION,
				'summary' => __( 'No active sandbox session.', 'wp-guardrail' ),
				'details' => '',
				'next'    => __( 'Start a sandbox session before running the simulator.', 'wp-guardrail' ),
			);
			return $result;
		}

		// Check 2: plugins are in the pending updates list.
		$pending = self::get_pending_updates();
		$valid   = array_values( array_intersect( $plugins, array_keys( $pending ) ) );
		$result['checks']['plugins_have_pending_updates'] = ! empty( $valid );
		if ( empty( $valid ) ) {
			$result['errors'][] = array(
				'code'    => self::ERR_NO_PENDING,
				'summary' => __( 'Selected plugins do not have pending updates.', 'wp-guardrail' ),
				'details' => implode( ', ', $plugins ),
				'next'    => __( 'Check for plugin updates in WordPress or select plugins that have available updates.', 'wp-guardrail' ),
			);
			return $result;
		}

		// Check 3: upload base dir is resolvable and writable.
		$base_dir = self::guardrail_upload_base_dir();
		$result['checks']['upload_dir_writable'] = '' !== $base_dir && is_writable( $base_dir );
		if ( '' === $base_dir || ! is_writable( $base_dir ) ) {
			$dir_label = '' !== $base_dir ? $base_dir : '(wp-content/uploads/wp-guardrail/)';
			$result['errors'][] = array(
				'code'    => self::ERR_FILESYSTEM_NOT_WRITE,
				'summary' => __( 'Simulation directory is not writable.', 'wp-guardrail' ),
				'details' => $dir_label,
				'next'    => __( 'Check write permissions on the uploads directory and ensure it is accessible to the web server.', 'wp-guardrail' ),
			);
			return $result;
		}

		// Check 4: shims subdirectory is creatable/writable.
		$shims_dir = trailingslashit( $base_dir ) . 'shims/';
		$shims_ok  = is_dir( $shims_dir ) ? is_writable( $shims_dir ) : wp_mkdir_p( $shims_dir );
		$result['checks']['shim_dir_writable'] = (bool) $shims_ok;
		if ( ! $shims_ok ) {
			$result['errors'][] = array(
				'code'    => self::ERR_SHIM_DIR_CREATE,
				'summary' => __( 'Shim loader directory could not be created or is not writable.', 'wp-guardrail' ),
				'details' => $shims_dir,
				'next'    => __( 'Check write permissions on the uploads directory.', 'wp-guardrail' ),
			);
			return $result;
		}

		// Check 5: each valid plugin has a package URL.
		$missing_packages = array();
		foreach ( $valid as $basename ) {
			if ( empty( $pending[ $basename ]['package'] ) ) {
				$missing_packages[] = $basename;
			}
		}
		$result['checks']['all_plugins_have_package_url'] = empty( $missing_packages );
		if ( ! empty( $missing_packages ) ) {
			$result['errors'][] = array(
				'code'    => self::ERR_NO_PACKAGE_URL,
				'summary' => __( 'Some plugins do not have a downloadable update package.', 'wp-guardrail' ),
				'details' => implode( ', ', $missing_packages ),
				'next'    => __( 'This plugin may not provide a public package URL. Simulation is not possible for these plugins.', 'wp-guardrail' ),
			);
			// Non-fatal: continue with the remaining plugins.
		}

		$result['passed'] = empty( $result['errors'] );
		return $result;
	}

	// =========================================================================
	// Download and extraction.
	// =========================================================================

	/**
	 * Download and extract selected plugin update packages.
	 *
	 * Individual download or extraction failures are logged via WPG_Logger
	 * and skipped rather than aborting the entire run. The caller can check
	 * the log for partial-failure details.
	 *
	 * @param array $plugins Plugin basenames to simulate.
	 * @return array|WP_Error
	 */
	public static function download_and_extract_updates( array $plugins ): array|WP_Error {
		$plugins = self::sanitize_plugins( $plugins );
		if ( empty( $plugins ) ) {
			return new WP_Error( self::ERR_NO_PLUGINS, __( 'No plugins selected for simulation.', 'wp-guardrail' ) );
		}

		$pending = self::get_pending_updates();
		$plugins = array_values( array_intersect( $plugins, array_keys( $pending ) ) );
		if ( empty( $plugins ) ) {
			return new WP_Error( self::ERR_NO_PENDING, __( 'Selected plugins do not have pending updates.', 'wp-guardrail' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$base_dir = self::guardrail_upload_base_dir();
		if ( '' === $base_dir ) {
			return new WP_Error( self::ERR_UPLOAD_DIR, __( 'Could not resolve uploads directory.', 'wp-guardrail' ) );
		}

		if ( ! is_writable( $base_dir ) ) {
			return new WP_Error(
				self::ERR_FILESYSTEM_NOT_WRITE,
				sprintf(
					/* translators: %s: directory path */
					__( 'Simulation directory is not writable: %s', 'wp-guardrail' ),
					$base_dir
				)
			);
		}

		// Protect the guardrail uploads directory on first use.
		self::maybe_protect_uploads_dir( $base_dir );

		$hash    = self::generate_hash();
		$sim_dir = trailingslashit( $base_dir ) . 'sim/' . $hash . '/';

		if ( ! wp_mkdir_p( $sim_dir ) ) {
			return new WP_Error( self::ERR_SIM_DIR_CREATE, __( 'Could not create simulation directory.', 'wp-guardrail' ) );
		}

		$file_map = array();

		foreach ( $plugins as $plugin_basename ) {
			$package_url = isset( $pending[ $plugin_basename ]['package'] ) ? $pending[ $plugin_basename ]['package'] : '';
			if ( '' === $package_url ) {
				WPG_Logger::warning(
					'Skipped plugin: no package URL.',
					array( 'plugin' => $plugin_basename, 'error_code' => self::ERR_NO_PACKAGE_URL )
				);
				continue;
			}

			$tmp_file = download_url( $package_url, 120 );
			if ( is_wp_error( $tmp_file ) ) {
				WPG_Logger::error(
					'Download failed: ' . $tmp_file->get_error_message(),
					array( 'plugin' => $plugin_basename, 'error_code' => self::ERR_DOWNLOAD_FAILED )
				);
				continue;
			}

			$plugin_slug = self::plugin_slug( $plugin_basename );
			$target_dir  = trailingslashit( $sim_dir ) . $plugin_slug . '/';
			wp_mkdir_p( $target_dir );

			if ( ! is_writable( $target_dir ) ) {
				WPG_Logger::error(
					'Extraction directory not writable.',
					array( 'plugin' => $plugin_basename, 'dir' => basename( $target_dir ), 'error_code' => self::ERR_FILESYSTEM_NOT_WRITE )
				);
				if ( file_exists( $tmp_file ) ) {
					unlink( $tmp_file );
				}
				continue;
			}

			$unzipped = unzip_file( $tmp_file, $target_dir );

			// Always remove the temp download; log if it fails.
			if ( file_exists( $tmp_file ) && ! unlink( $tmp_file ) ) {
				WPG_Logger::warning(
					'Could not remove temp download file.',
					array( 'file' => basename( $tmp_file ) )
				);
			}

			if ( is_wp_error( $unzipped ) ) {
				WPG_Logger::error(
					'Extraction failed: ' . $unzipped->get_error_message(),
					array( 'plugin' => $plugin_basename, 'error_code' => self::ERR_ZIP_OPEN_FAILED )
				);
				continue;
			}

			$entry_file = basename( $plugin_basename );
			$found_file = self::find_file_recursive( $target_dir, $entry_file );
			if ( '' === $found_file ) {
				WPG_Logger::error(
					'Entry file not found in extracted package.',
					array( 'plugin' => $plugin_basename, 'entry' => $entry_file, 'error_code' => self::ERR_ENTRY_NOT_FOUND )
				);
				continue;
			}

			$file_map[ $plugin_basename ] = $found_file;
			WPG_Logger::info(
				'Package ready.',
				array( 'plugin' => $plugin_basename )
			);
		}

		if ( empty( $file_map ) ) {
			return new WP_Error(
				self::ERR_EXTRACT_ALL_FAILED,
				__( 'Could not prepare updated plugin files for simulation. Check the session log for per-plugin error details.', 'wp-guardrail' )
			);
		}

		return array(
			'hash'             => $hash,
			'sim_dir'          => $sim_dir,
			'file_map'         => $file_map,
			'selected_plugins' => array_keys( $file_map ),
		);
	}

	/**
	 * Create shim loader files in the guardrail uploads/shims directory.
	 *
	 * Each shim is a small PHP file that require_once-s the extracted updated
	 * plugin entry point. The mu-plugin bootstrap loads these shims at the
	 * correct phase (before original plugins are loaded).
	 *
	 * @param string $simulation_hash Simulation hash.
	 * @param array  $file_map        Original plugin basename => extracted absolute path.
	 * @return array|WP_Error Shim map on success.
	 */
	public static function create_shims( string $simulation_hash, array $file_map ): array|WP_Error {
		$simulation_hash = sanitize_key( $simulation_hash );
		$file_map        = is_array( $file_map ) ? $file_map : array();

		if ( '' === $simulation_hash || empty( $file_map ) ) {
			return new WP_Error( 'wpg_sim_invalid_shim_args', __( 'Invalid shim creation input.', 'wp-guardrail' ) );
		}

		$base_dir = self::guardrail_upload_base_dir();
		if ( '' === $base_dir ) {
			return new WP_Error( self::ERR_UPLOAD_DIR, __( 'Could not resolve uploads directory for shims.', 'wp-guardrail' ) );
		}

		$shims_dir = trailingslashit( $base_dir ) . 'shims/';
		if ( ! wp_mkdir_p( $shims_dir ) || ! is_dir( $shims_dir ) ) {
			return new WP_Error( self::ERR_SHIM_DIR_CREATE, __( 'Could not create shim plugin directory.', 'wp-guardrail' ) );
		}

		// Remove legacy plugin-dir shim folder from pre-0.2 implementation.
		$legacy_shim_dir = trailingslashit( WP_PLUGIN_DIR ) . 'wp-guardrail-shims/';
		if ( is_dir( $legacy_shim_dir ) ) {
			$legacy_files = glob( $legacy_shim_dir . '*.php' );
			if ( is_array( $legacy_files ) ) {
				foreach ( $legacy_files as $legacy_file ) {
					if ( is_file( $legacy_file ) ) {
						unlink( $legacy_file );
					}
				}
			}
			rmdir( $legacy_shim_dir );
		}

		$shim_map = array();

		foreach ( $file_map as $plugin_basename => $extracted_file ) {
			$plugin_basename = sanitize_text_field( (string) $plugin_basename );
			$extracted_file  = (string) $extracted_file;

			if ( '' === $plugin_basename || '' === $extracted_file || ! file_exists( $extracted_file ) ) {
				WPG_Logger::warning(
					'Skipped shim: extracted file missing.',
					array( 'plugin' => $plugin_basename )
				);
				continue;
			}

			if ( ! is_readable( $extracted_file ) ) {
				WPG_Logger::error(
					'Extracted file is not readable.',
					array( 'plugin' => $plugin_basename, 'error_code' => self::ERR_FILESYSTEM_NOT_WRITE )
				);
				continue;
			}

			$shim_filename = $simulation_hash . '-' . substr( md5( $plugin_basename ), 0, 8 ) . '.php';
			$shim_path     = $shims_dir . $shim_filename;
			$shim_content  = "<?php\n";
			$shim_content .= "\$wpg_sim_target = " . var_export( $extracted_file, true ) . ";\n";
			$shim_content .= "if ( file_exists( \$wpg_sim_target ) && is_readable( \$wpg_sim_target ) ) {\n\trequire_once \$wpg_sim_target;\n}\n";

			$written = file_put_contents( $shim_path, $shim_content );
			if ( false === $written || 0 === $written ) {
				WPG_Logger::error(
					'Failed to write shim file.',
					array( 'plugin' => $plugin_basename, 'shim' => $shim_filename, 'error_code' => self::ERR_SHIM_WRITE_FAILED )
				);
				continue;
			}

			$shim_map[ $plugin_basename ] = $shim_path;
		}

		if ( empty( $shim_map ) ) {
			return new WP_Error(
				self::ERR_SHIM_WRITE_FAILED,
				__( 'Could not create shim loader files.', 'wp-guardrail' )
			);
		}

		return $shim_map;
	}

	/**
	 * Enable simulation state in the current sandbox session.
	 *
	 * @param array $data Simulation data.
	 * @return bool
	 */
	public static function enable_simulation( array $data ): bool {
		$context = WPG_Container::instance();
		$session = $context->get_session();

		if ( false === $session || ! is_array( $data ) ) {
			return false;
		}

		$simulation = array(
			'active'    => true,
			'hash'      => isset( $data['hash'] )    ? sanitize_key( (string) $data['hash'] )    : '',
			'plugins'   => isset( $data['plugins'] ) ? self::sanitize_plugins( $data['plugins'] ) : array(),
			'paths'     => isset( $data['paths'] )   && is_array( $data['paths'] )   ? $data['paths']   : array(),
			'shims'     => isset( $data['shims'] )   && is_array( $data['shims'] )   ? $data['shims']   : array(),
			'sim_dir'   => isset( $data['sim_dir'] ) ? sanitize_text_field( (string) $data['sim_dir'] ) : '',
			'results'   => isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array(),
			'preflight' => isset( $data['preflight'] ) && is_array( $data['preflight'] ) ? $data['preflight'] : array(),
			'cleanup'   => array( 'attempted' => array(), 'failed' => array() ),
			'created'   => time(),
		);

		$session['simulation'] = $simulation;

		return $context->update_session( $session );
	}

	/**
	 * Disable simulation and clean up temporary files.
	 *
	 * @return bool
	 */
	public static function disable_simulation(): bool {
		$context = WPG_Container::instance();
		$session = $context->get_session();

		if ( false === $session ) {
			return false;
		}

		$simulation = isset( $session['simulation'] ) && is_array( $session['simulation'] ) ? $session['simulation'] : array();
		$hash       = isset( $simulation['hash'] ) ? sanitize_key( (string) $simulation['hash'] ) : '';

		$cleanup_result = array( 'attempted' => array(), 'failed' => array() );
		if ( '' !== $hash ) {
			$cleanup_result = self::cleanup_simulation_artifacts( $hash );
		}

		$session['simulation'] = array(
			'active'    => false,
			'hash'      => '',
			'plugins'   => array(),
			'paths'     => array(),
			'shims'     => array(),
			'sim_dir'   => '',
			'results'   => array(),
			'preflight' => array(),
			'cleanup'   => $cleanup_result,
			'created'   => 0,
		);

		return $context->update_session( $session );
	}

	/**
	 * Clean up all artifacts for one simulation hash.
	 *
	 * Returns a structured cleanup report with attempted and failed paths.
	 *
	 * @param string $hash Simulation hash.
	 * @return array { attempted: string[], failed: string[] }
	 */
	public static function cleanup_simulation_artifacts( string $hash ): array {
		$report = array( 'attempted' => array(), 'failed' => array() );

		$hash = sanitize_key( $hash );
		if ( '' === $hash ) {
			return $report;
		}

		$base_dir = self::guardrail_upload_base_dir();
		if ( '' === $base_dir ) {
			$report['failed'][] = '(base_dir unavailable)';
			return $report;
		}

		// Clean sim directory.
		$sim_dir = trailingslashit( $base_dir ) . 'sim/' . $hash . '/';
		if ( self::is_safe_guardrail_path( $sim_dir, $base_dir ) && is_dir( $sim_dir ) ) {
			$report['attempted'][] = $sim_dir;
			self::delete_dir_recursive( $sim_dir, $base_dir );
			if ( is_dir( $sim_dir ) ) {
				$report['failed'][] = $sim_dir;
				WPG_Logger::warning(
					'Cleanup: sim directory could not be fully removed.',
					array( 'dir' => basename( $sim_dir ) )
				);
			}
		}

		// Clean shim files for this hash.
		$shims_dir = trailingslashit( $base_dir ) . 'shims/';
		if ( self::is_safe_guardrail_path( $shims_dir, $base_dir ) && is_dir( $shims_dir ) ) {
			$files = glob( $shims_dir . $hash . '-*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( self::is_safe_guardrail_path( $file, $base_dir ) && is_file( $file ) ) {
						$report['attempted'][] = $file;
						if ( ! unlink( $file ) ) {
							$report['failed'][] = $file;
							WPG_Logger::warning(
								'Cleanup: could not remove shim file.',
								array( 'file' => basename( $file ) )
							);
						}
					}
				}
			}
		}

		return $report;
	}

	/**
	 * Clean up all simulation leftovers from uploads/wp-guardrail.
	 *
	 * @return array { attempted: string[], failed: string[] }
	 */
	public static function cleanup_all_leftovers(): array {
		$report = array( 'attempted' => array(), 'failed' => array() );

		$base_dir = self::guardrail_upload_base_dir();
		if ( '' === $base_dir ) {
			$report['failed'][] = '(base_dir unavailable)';
			return $report;
		}

		$sim_root   = trailingslashit( $base_dir ) . 'sim/';
		$shims_root = trailingslashit( $base_dir ) . 'shims/';

		if ( self::is_safe_guardrail_path( $sim_root, $base_dir ) && is_dir( $sim_root ) ) {
			$sim_entries = glob( $sim_root . '*' );
			if ( is_array( $sim_entries ) ) {
				foreach ( $sim_entries as $entry ) {
					if ( ! self::is_safe_guardrail_path( $entry, $base_dir ) ) {
						continue;
					}
					$report['attempted'][] = $entry;
					if ( is_dir( $entry ) ) {
						self::delete_dir_recursive( $entry, $base_dir );
						if ( is_dir( $entry ) ) {
							$report['failed'][] = $entry;
						}
					} elseif ( is_file( $entry ) ) {
						if ( ! unlink( $entry ) ) {
							$report['failed'][] = $entry;
						}
					}
				}
			}
		}

		if ( self::is_safe_guardrail_path( $shims_root, $base_dir ) && is_dir( $shims_root ) ) {
			$shim_entries = glob( $shims_root . '*' );
			if ( is_array( $shim_entries ) ) {
				foreach ( $shim_entries as $entry ) {
					if ( self::is_safe_guardrail_path( $entry, $base_dir ) && is_file( $entry ) ) {
						$report['attempted'][] = $entry;
						if ( ! unlink( $entry ) ) {
							$report['failed'][] = $entry;
						}
					}
				}
			}
		}

		return $report;
	}

	/**
	 * Run baseline vs simulated URL tests.
	 *
	 * @param array $urls URL list.
	 * @return array|WP_Error
	 */
	public static function run_simulation_tests( array $urls ): array|WP_Error {
		$context = WPG_Container::instance();
		$session = $context->get_session();

		if ( false === $session ) {
			return new WP_Error( self::ERR_NO_SESSION, __( 'Sandbox session is required for simulation tests.', 'wp-guardrail' ) );
		}

		$simulation = isset( $session['simulation'] ) && is_array( $session['simulation'] ) ? $session['simulation'] : array();
		if ( empty( $simulation['active'] ) ) {
			return new WP_Error( self::ERR_SIM_NOT_ACTIVE, __( 'Simulation is not active.', 'wp-guardrail' ) );
		}

		$urls = is_array( $urls ) ? $urls : array();
		$rows = array();

		foreach ( $urls as $url_input ) {
			$resolved = WPG_URL_Tester::resolve_test_url( $url_input );
			if ( is_wp_error( $resolved ) ) {
				continue;
			}

			$comparison = WPG_URL_Tester::run_compare( $resolved );
			$baseline   = isset( $comparison['baseline'] ) && is_array( $comparison['baseline'] ) ? $comparison['baseline'] : array();
			$simulated  = isset( $comparison['sandbox'] )  && is_array( $comparison['sandbox'] )  ? $comparison['sandbox']  : array();
			$issue      = (
				( isset( $baseline['http_code'], $simulated['http_code'] ) && (int) $baseline['http_code'] !== (int) $simulated['http_code'] ) ||
				! empty( $simulated['fatal_suspected'] ) ||
				( isset( $simulated['http_code'] ) && (int) $simulated['http_code'] >= 500 )
			);

			$rows[] = array(
				'timestamp' => current_time( 'mysql' ),
				'url'       => esc_url_raw( $resolved ),
				'baseline'  => $baseline,
				'simulated' => $simulated,
				'summary'   => $issue ? 'possible_issues' : 'no_issues',
			);
		}

		if ( empty( $rows ) ) {
			return new WP_Error( self::ERR_NO_VALID_URLS, __( 'No valid same-host URLs were provided.', 'wp-guardrail' ) );
		}

		$existing = isset( $simulation['results'] ) && is_array( $simulation['results'] ) ? $simulation['results'] : array();
		foreach ( array_reverse( $rows ) as $row ) {
			array_unshift( $existing, $row );
		}
		$existing = array_slice( $existing, 0, 20 );

		$session['simulation']['results']         = $existing;
		$session['simulation']['active']          = true;
		$session['simulation']['last_tested_url'] = $rows[0]['url'];
		$context->update_session( $session );

		return $rows;
	}

	/**
	 * Get simulation state from the current session.
	 *
	 * @return array
	 */
	public static function get_simulation(): array {
		$session = WPG_Container::instance()->get_session();
		if ( false === $session ) {
			return array();
		}

		return isset( $session['simulation'] ) && is_array( $session['simulation'] ) ? $session['simulation'] : array();
	}

	/**
	 * Sanitize and validate a URL list from textarea input.
	 *
	 * @param string $raw Raw textarea input.
	 * @return array
	 */
	public static function normalize_urls_input( string $raw ): array {
		$raw   = wp_unslash( $raw );
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$urls  = array();

		if ( ! is_array( $lines ) ) {
			return $urls;
		}

		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' === $line ) {
				continue;
			}

			$resolved = WPG_URL_Tester::resolve_test_url( $line );
			if ( is_wp_error( $resolved ) ) {
				continue;
			}

			$urls[] = $line;
		}

		return array_values( array_unique( $urls ) );
	}

	// =========================================================================
	// Private helpers.
	// =========================================================================

	/**
	 * Write index.php and .htaccess protection into the guardrail uploads dir.
	 *
	 * @param string $base_dir Absolute path to uploads/wp-guardrail/.
	 * @return void
	 */
	private static function maybe_protect_uploads_dir( string $base_dir ): void {
		$base_dir = trailingslashit( $base_dir );

		$index_file = $base_dir . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		$htaccess_file = $base_dir . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			file_put_contents( $htaccess_file, "deny from all\n" );
		}
	}

	/**
	 * Delete a directory and its contents recursively.
	 *
	 * Only removes paths confirmed to be within $base_dir.
	 *
	 * @param string $path     Directory path to delete.
	 * @param string $base_dir Safety boundary.
	 * @return void
	 */
	private static function delete_dir_recursive( string $path, string $base_dir = '' ): void {
		if ( '' === $path || ! is_dir( $path ) ) {
			return;
		}

		$base_dir = '' !== $base_dir ? $base_dir : self::guardrail_upload_base_dir();
		if ( '' === $base_dir || ! self::is_safe_guardrail_path( $path, $base_dir ) ) {
			return;
		}

		$items = scandir( $path );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$full = trailingslashit( $path ) . $item;
			if ( ! self::is_safe_guardrail_path( $full, $base_dir ) ) {
				continue;
			}

			if ( is_dir( $full ) ) {
				self::delete_dir_recursive( $full, $base_dir );
			} elseif ( is_file( $full ) ) {
				unlink( $full );
			}
		}

		rmdir( $path );
	}

	/**
	 * Find a file by name recursively under a directory.
	 *
	 * @param string $directory Directory path.
	 * @param string $filename  Target filename.
	 * @return string Absolute path or empty string.
	 */
	private static function find_file_recursive( string $directory, string $filename ): string {
		$filename = sanitize_file_name( $filename );

		if ( '' === $directory || '' === $filename || ! is_dir( $directory ) ) {
			return '';
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info instanceof SplFileInfo || ! $file_info->isFile() ) {
					continue;
				}

				if ( $filename === $file_info->getFilename() ) {
					return $file_info->getPathname();
				}
			}
		} catch ( Exception $e ) {
			WPG_Logger::error(
				'Directory traversal failed: ' . $e->getMessage(),
				array( 'dir' => basename( $directory ) )
			);
			return '';
		}

		return '';
	}

	/**
	 * Build plugin slug from basename.
	 *
	 * @param string $plugin_basename Plugin basename.
	 * @return string
	 */
	private static function plugin_slug( string $plugin_basename ): string {
		$plugin_basename = sanitize_text_field( $plugin_basename );
		$dirname         = dirname( $plugin_basename );
		if ( '.' === $dirname || '' === $dirname ) {
			$dirname = basename( $plugin_basename, '.php' );
		}

		return sanitize_file_name( $dirname );
	}

	/**
	 * Sanitize an array of plugin basenames.
	 *
	 * @param array $plugins Plugin list.
	 * @return array
	 */
	private static function sanitize_plugins( array $plugins ): array {
		$plugins = array_map( 'sanitize_text_field', $plugins );
		return array_values( array_unique( array_filter( $plugins ) ) );
	}

	/**
	 * Generate a short random simulation hash.
	 *
	 * @return string
	 */
	private static function generate_hash(): string {
		return substr( md5( wp_generate_password( 64, true, true ) . microtime( true ) ), 0, 12 );
	}

	/**
	 * Resolve and create the guardrail uploads base directory.
	 *
	 * @return string Absolute path with trailing slash, or '' on failure.
	 */
	private static function guardrail_upload_base_dir(): string {
		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return '';
		}

		$base_dir = trailingslashit( $upload['basedir'] ) . 'wp-guardrail/';
		if ( ! wp_mkdir_p( $base_dir ) || ! is_dir( $base_dir ) ) {
			return '';
		}

		return wp_normalize_path( $base_dir );
	}

	/**
	 * Check that $path is inside $base_dir (path traversal guard).
	 *
	 * @param string $path     Path to validate.
	 * @param string $base_dir Guardrail base path.
	 * @return bool
	 */
	private static function is_safe_guardrail_path( string $path, string $base_dir ): bool {
		$path     = wp_normalize_path( $path );
		$base_dir = trailingslashit( wp_normalize_path( $base_dir ) );

		return '' !== $path && '' !== $base_dir && 0 === strpos( $path, $base_dir );
	}
}
