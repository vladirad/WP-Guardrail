<?php
/**
 * Conflict Wizard state machine for WP Guardrail.
 *
 * @package WPGuardrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guided binary plugin conflict isolation workflow.
 *
 * Each wizard step automatically runs a sandbox URL test against the target
 * URL after the disabled set is updated.  The result is stored in the wizard
 * state as `step_auto_test` and also attached to the decision history entry
 * when the admin submits their decision.  On completion a confidence score is
 * computed from how many automated test results matched the admin's reported
 * outcomes.
 */
class WPG_Conflict_Wizard {

	/**
	 * Get current wizard state from sandbox session.
	 *
	 * @return array
	 */
	public static function get_state() {
		$session = WPG_Container::instance()->get_session();
		if ( false === $session ) {
			return self::default_state();
		}

		$state = isset( $session['wizard'] ) && is_array( $session['wizard'] )
			? $session['wizard']
			: self::default_state();

		return self::normalize_state( $state );
	}

	/**
	 * Start wizard state.
	 *
	 * Immediately runs an automated sandbox URL test against the target URL
	 * with Group A disabled so the first step screen has evidence to show.
	 *
	 * @param string $target_url       Target URL/path.
	 * @param array  $excluded_plugins Plugins to keep enabled.
	 * @return array|WP_Error
	 */
	public static function start( $target_url, $excluded_plugins ) {
		$context = WPG_Container::instance();
		$session = $context->get_session();
		if ( false === $session ) {
			return new WP_Error( 'wizard_no_session', __( 'Sandbox Mode must be active to run Conflict Wizard.', 'wp-guardrail' ) );
		}

		$resolved_url = WPG_URL_Tester::resolve_test_url( $target_url );
		if ( is_wp_error( $resolved_url ) ) {
			return $resolved_url;
		}

		$baseline = isset( $session['baseline_active'] ) && is_array( $session['baseline_active'] )
			? $session['baseline_active']
			: array();

		$self_plugin = WPG_Bootstrap::self_plugin_basename();
		$baseline    = array_map( 'sanitize_text_field', $baseline );
		$baseline    = array_values( array_unique( array_diff( $baseline, array( $self_plugin ) ) ) );

		$excluded_plugins = is_array( $excluded_plugins ) ? $excluded_plugins : array();
		$excluded_plugins = array_map( 'sanitize_text_field', $excluded_plugins );
		$excluded_plugins = array_values( array_unique( array_intersect( $excluded_plugins, $baseline ) ) );

		$pool = array_values( array_diff( $baseline, $excluded_plugins ) );
		if ( empty( $pool ) ) {
			return new WP_Error( 'wizard_empty_pool', __( 'No plugins are available for Conflict Wizard after exclusions.', 'wp-guardrail' ) );
		}

		$groups = self::split_pool( $pool );
		$state  = array(
			'active'            => true,
			'target_url'        => $resolved_url,
			'plugins_pool'      => $pool,
			'excluded_plugins'  => $excluded_plugins,
			'current_group_a'   => $groups['a'],
			'current_group_b'   => $groups['b'],
			'step'              => 1,
			'completed_steps'   => 0,
			'history'           => array(),
			'initial_pool_size' => count( $pool ),
			'state_error'       => '',
			'step_auto_test'    => array(),
		);

		$state['history'][] = array(
			'event'      => 'start',
			'step'       => 1,
			'pool_count' => count( $pool ),
			'timestamp'  => current_time( 'mysql' ),
		);

		$session['wizard']   = $state;
		$session['disabled'] = $groups['a'];
		$context->update_session( $session );

		// Run automated test with Group A disabled so the step screen has evidence.
		$state['step_auto_test'] = self::run_step_test( $resolved_url );
		$session['wizard']       = $state;
		$context->update_session( $session );

		return self::normalize_state( $state );
	}

	/**
	 * Apply wizard decision and transition state machine.
	 *
	 * Captures the current step's automated test result in the history entry,
	 * then runs a fresh test for the next step if the wizard is still active.
	 *
	 * @param bool $issue_still_happens Whether issue still reproduces.
	 * @return array|WP_Error
	 */
	public static function submit_decision( $issue_still_happens ) {
		$context = WPG_Container::instance();
		$session = $context->get_session();
		if ( false === $session ) {
			return new WP_Error( 'wizard_no_session', __( 'Sandbox Mode must be active to run Conflict Wizard.', 'wp-guardrail' ) );
		}

		$state = isset( $session['wizard'] ) && is_array( $session['wizard'] )
			? self::normalize_state( $session['wizard'] )
			: self::default_state();

		if ( empty( $state['active'] ) ) {
			return new WP_Error( 'wizard_not_active', __( 'Conflict Wizard is not active.', 'wp-guardrail' ) );
		}

		$suspected = $issue_still_happens ? $state['current_group_b'] : $state['current_group_a'];
		$suspected = array_values( array_unique( array_map( 'sanitize_text_field', $suspected ) ) );

		$state['history'][] = array(
			'event'          => 'decision',
			'step'           => (int) $state['step'],
			'issue_persists' => (bool) $issue_still_happens,
			'tested_group_a' => $state['current_group_a'],
			'suspected_size' => count( $suspected ),
			'step_test'      => $state['step_auto_test'],
			'timestamp'      => current_time( 'mysql' ),
		);

		$state['step']            = (int) $state['step'] + 1;
		$state['completed_steps'] = (int) $state['completed_steps'] + 1;
		$state['plugins_pool']    = $suspected;
		$state['state_error']     = '';
		$state['step_auto_test']  = array();

		if ( count( $suspected ) <= 0 ) {
			$state['active']          = false;
			$state['current_group_a'] = array();
			$state['current_group_b'] = array();
			$state['state_error']     = 'empty_pool';
			$session['disabled']      = array();
			$state['history'][]       = array(
				'event'      => 'empty_pool',
				'step'       => (int) $state['step'],
				'pool_count' => 0,
				'timestamp'  => current_time( 'mysql' ),
			);
		} elseif ( 1 === count( $suspected ) ) {
			$state['active']          = false;
			$state['current_group_a'] = array();
			$state['current_group_b'] = array();
			$session['disabled']      = array();
			$state['history'][]       = array(
				'event'      => 'complete',
				'step'       => (int) $state['step'],
				'pool_count' => 1,
				'timestamp'  => current_time( 'mysql' ),
			);
		} else {
			$groups                   = self::split_pool( $suspected );
			$state['current_group_a'] = $groups['a'];
			$state['current_group_b'] = $groups['b'];
			$session['disabled']      = $groups['a'];
		}

		$session['wizard'] = $state;
		$context->update_session( $session );

		// Run automated test for the next step if wizard is still active.
		if ( ! empty( $state['active'] ) ) {
			$state['step_auto_test'] = self::run_step_test( $state['target_url'] );
			$session['wizard']       = $state;
			$context->update_session( $session );
		}

		return self::normalize_state( $state );
	}

	/**
	 * Reset wizard state.
	 *
	 * @return void
	 */
	public static function reset() {
		$context = WPG_Container::instance();
		$session = $context->get_session();
		if ( false === $session ) {
			return;
		}

		$session['wizard']   = self::default_state();
		$session['disabled'] = array();
		$context->update_session( $session );
	}

	/**
	 * Get estimated total wizard steps for current state.
	 *
	 * @param array $state Wizard state.
	 * @return int
	 */
	public static function estimated_total_steps( $state ) {
		$state        = self::normalize_state( $state );
		$initial_size = isset( $state['initial_pool_size'] ) ? (int) $state['initial_pool_size'] : count( $state['plugins_pool'] );
		$initial_size = max( 1, $initial_size );

		if ( $initial_size <= 1 ) {
			return 1;
		}

		return max( 1, (int) ceil( log( $initial_size ) / log( 2 ) ) );
	}

	/**
	 * Compute a test corroboration score from decision history.
	 *
	 * Iterates decision history entries that carry an automated test result
	 * (step_test) and counts how many test outcomes matched the admin's
	 * reported outcome:
	 *  - "Issue still happens" + test indicates a problem  → corroborated
	 *  - "Issue resolved"      + test indicates clean      → corroborated
	 *
	 * Returns null when no test data is available (e.g., automated tests were
	 * disabled or all failed with network errors).
	 *
	 * @param array $state Wizard state.
	 * @return array|null {
	 *     @type int $score        Percentage 0–100.
	 *     @type int $corroborated Count of matching decisions.
	 *     @type int $tested       Count of decisions with test data.
	 *     @type int $total        Total decision count.
	 * }
	 */
	public static function compute_confidence( $state ) {
		$state   = self::normalize_state( $state );
		$history = $state['history'];

		$decisions = array_values(
			array_filter(
				$history,
				static function ( $entry ) {
					return isset( $entry['event'] ) && 'decision' === $entry['event'];
				}
			)
		);

		if ( empty( $decisions ) ) {
			return null;
		}

		$total        = count( $decisions );
		$tested       = 0;
		$corroborated = 0;

		foreach ( $decisions as $entry ) {
			if ( empty( $entry['step_test'] ) || ! is_array( $entry['step_test'] ) ) {
				continue;
			}

			$tested++;
			$issue_persists   = ! empty( $entry['issue_persists'] );
			$test_problematic = self::test_indicates_problem( $entry['step_test'] );

			if ( $issue_persists === $test_problematic ) {
				$corroborated++;
			}
		}

		if ( 0 === $tested ) {
			return null;
		}

		return array(
			'score'        => (int) round( ( $corroborated / $tested ) * 100 ),
			'corroborated' => $corroborated,
			'tested'       => $tested,
			'total'        => $total,
		);
	}

	/**
	 * Normalize wizard state shape.
	 *
	 * @param array $state Raw state.
	 * @return array
	 */
	private static function normalize_state( $state ) {
		$defaults = self::default_state();
		$state    = is_array( $state ) ? $state : array();
		$state    = wp_parse_args( $state, $defaults );

		$state['active']           = ! empty( $state['active'] );
		$state['target_url']       = esc_url_raw( (string) $state['target_url'] );
		$state['plugins_pool']     = self::sanitize_plugins_list( $state['plugins_pool'] );
		$state['excluded_plugins'] = self::sanitize_plugins_list( $state['excluded_plugins'] );
		$state['current_group_a']  = self::sanitize_plugins_list( $state['current_group_a'] );
		$state['current_group_b']  = self::sanitize_plugins_list( $state['current_group_b'] );
		$state['step']             = max( 1, (int) $state['step'] );
		$state['completed_steps']  = max( 0, (int) $state['completed_steps'] );
		$state['history']          = is_array( $state['history'] ) ? $state['history'] : array();
		$state['initial_pool_size'] = isset( $state['initial_pool_size'] ) ? max( 1, (int) $state['initial_pool_size'] ) : count( $state['plugins_pool'] );
		$state['state_error']      = isset( $state['state_error'] ) ? sanitize_key( (string) $state['state_error'] ) : '';
		$state['step_auto_test']   = isset( $state['step_auto_test'] ) && is_array( $state['step_auto_test'] ) ? $state['step_auto_test'] : array();

		return $state;
	}

	/**
	 * Default wizard state.
	 *
	 * @return array
	 */
	private static function default_state() {
		return array(
			'active'            => false,
			'target_url'        => '',
			'plugins_pool'      => array(),
			'excluded_plugins'  => array(),
			'current_group_a'   => array(),
			'current_group_b'   => array(),
			'step'              => 1,
			'completed_steps'   => 0,
			'history'           => array(),
			'initial_pool_size' => 1,
			'state_error'       => '',
			'step_auto_test'    => array(),
		);
	}

	/**
	 * Split plugin pool into A/B groups.
	 *
	 * @param array $pool Pool list.
	 * @return array
	 */
	private static function split_pool( $pool ) {
		$pool  = self::sanitize_plugins_list( $pool );
		$count = count( $pool );
		$half  = (int) ceil( $count / 2 );

		return array(
			'a' => array_slice( $pool, 0, $half ),
			'b' => array_slice( $pool, $half ),
		);
	}

	/**
	 * Sanitize plugin file list.
	 *
	 * @param array $plugins Plugin list.
	 * @return array
	 */
	private static function sanitize_plugins_list( $plugins ) {
		$plugins = is_array( $plugins ) ? $plugins : array();
		$plugins = array_map( 'sanitize_text_field', $plugins );

		return array_values( array_unique( $plugins ) );
	}

	/**
	 * Run a sandbox URL test against the target URL and return a minimal summary.
	 *
	 * The summary is stored in wizard state and later attached to decision
	 * history entries.  Only the fields needed for confidence scoring and UI
	 * display are kept; the full result is not stored to avoid bloating the
	 * session transient.
	 *
	 * @param string $target_url Absolute URL to test.
	 * @return array
	 */
	private static function run_step_test( $target_url ) {
		if ( '' === (string) $target_url ) {
			return array();
		}

		$result = WPG_URL_Tester::run_test( $target_url, 'sandbox' );

		return array(
			'http_code'       => isset( $result['http_code'] ) ? (int) $result['http_code'] : 0,
			'fatal_suspected' => ! empty( $result['fatal_suspected'] ),
			'time_ms'         => isset( $result['time_ms'] ) ? (int) $result['time_ms'] : 0,
			'error_message'   => isset( $result['error_message'] ) ? sanitize_textarea_field( (string) $result['error_message'] ) : '',
			'timestamp'       => current_time( 'mysql' ),
		);
	}

	/**
	 * Determine whether an automated step test result indicates a problem.
	 *
	 * Returns true for: network errors (code 0), 5xx responses, or suspected
	 * PHP fatals.  Used when computing the confidence score.
	 *
	 * @param array $test Step test summary.
	 * @return bool
	 */
	private static function test_indicates_problem( $test ) {
		if ( empty( $test ) || ! is_array( $test ) ) {
			return false;
		}

		if ( ! empty( $test['fatal_suspected'] ) ) {
			return true;
		}

		$code = isset( $test['http_code'] ) ? (int) $test['http_code'] : 0;

		return 0 === $code || $code >= 500;
	}
}
