<?php
/**
 * Jetpack Scan abilities-v2 negotiation tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Jetpack_Scan_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Jetpack_Scan */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		$reflection    = new ReflectionClass( MainWP_Child_Jetpack_Scan::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
	}

	public function test_capabilities_are_closed_and_claim_no_unimplemented_operation() {
		$result = $this->request( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'visibility_get', 'visibility_set' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_visibility_get_returns_closed_active_state() {
		$this->subject->is_plugin_installed = true;
		delete_option( 'mainwp_child_jetpack_scan_hide_plugin' );

		$result = $this->request( 'visibility_get', array() );

		$this->assertSame(
			array( 'protocol', 'operation', 'ok', 'plugin_state', 'visibility', 'revision', 'observed_at' ),
			array_keys( $result )
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'active', $result['plugin_state'] );
		$this->assertSame( 'visible', $result['visibility'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['revision'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['observed_at'] );
	}

	public function test_visibility_get_normalizes_hidden_missing_and_malformed_state() {
		$this->subject->is_plugin_installed = true;
		update_option( 'mainwp_child_jetpack_scan_hide_plugin', 'hide' );
		$hidden = $this->request( 'visibility_get', array() );
		$this->assertSame( 'hidden', $hidden['visibility'] );

		update_option( 'mainwp_child_jetpack_scan_hide_plugin', array( 'hide' ) );
		$malformed = $this->request( 'visibility_get', array() );
		$this->assertSame( 'unknown', $malformed['visibility'] );

		$this->subject->is_plugin_installed = false;
		$missing                            = $this->request( 'visibility_get', array() );
		$this->assertContains( $missing['plugin_state'], array( 'inactive', 'missing' ) );
		$this->assertSame( 'unknown', $missing['visibility'] );
	}

	public function test_visibility_get_rejects_nonempty_payload() {
		$result = $this->request( 'visibility_get', array( 'request_ref' => '123e4567-e89b-42d3-a456-426614174507' ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );
	}

	public function test_visibility_set_is_revision_bound_and_readback_verified() {
		$this->subject->is_plugin_installed = true;
		delete_option( 'mainwp_child_jetpack_scan_hide_plugin' );
		$current = $this->request( 'visibility_get', array() );

		$result = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $current['revision'],
			)
		);

		$this->assertSame(
			array( 'protocol', 'operation', 'ok', 'plugin_state', 'visibility', 'revision', 'observed_at', 'changed' ),
			array_keys( $result )
		);
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertSame( 'hide', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );
		$this->assertSame( hash( 'sha256', 'active|hidden' ), $result['revision'] );

		$replay = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $result['revision'],
			)
		);
		$this->assertTrue( $replay['ok'] );
		$this->assertFalse( $replay['changed'] );
	}

	public function test_visibility_set_rejects_stale_malformed_and_unavailable_state_without_writing() {
		$this->subject->is_plugin_installed = true;
		update_option( 'mainwp_child_jetpack_scan_hide_plugin', 'show' );

		$stale = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => str_repeat( 'a', 64 ),
			)
		);
		$this->assertFalse( $stale['ok'] );
		$this->assertSame( 'stale_revision', $stale['code'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );

		foreach (
			array(
				array(),
				array( 'desired_state' => 'hidden', 'if_match' => str_repeat( 'a', 63 ) ),
				array( 'desired_state' => 'unknown', 'if_match' => str_repeat( 'a', 64 ) ),
				array( 'desired_state' => 'hidden', 'if_match' => str_repeat( 'a', 64 ), 'extra' => true ),
			) as $payload
		) {
			$invalid = $this->request( 'visibility_set', $payload );
			$this->assertFalse( $invalid['ok'] );
			$this->assertSame( 'invalid_request', $invalid['code'] );
		}
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );

		$this->subject->is_plugin_installed = false;
		$missing                            = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => hash( 'sha256', 'missing|unknown' ),
			)
		);
		$this->assertFalse( $missing['ok'] );
		$this->assertSame( 'unsupported_version', $missing['code'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );
	}

	public function test_visibility_set_reports_write_failure_and_restores_contradictory_readback() {
		$this->subject->is_plugin_installed = true;
		update_option( 'mainwp_child_jetpack_scan_hide_plugin', 'show' );
		$current = $this->request( 'visibility_get', array() );
		$block   = static function ( $value, $old_value ) {
			return $old_value;
		};
		add_filter( 'pre_update_option_mainwp_child_jetpack_scan_hide_plugin', $block, 10, 2 );
		$failed = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $current['revision'],
			)
		);
		remove_filter( 'pre_update_option_mainwp_child_jetpack_scan_hide_plugin', $block, 10 );
		$this->assertFalse( $failed['ok'] );
		$this->assertSame( 'write_failed', $failed['code'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );

		$contradict = null;
		$contradict = static function ( $value ) use ( &$contradict ) {
			if ( 'hide' === $value ) {
				remove_filter( 'option_mainwp_child_jetpack_scan_hide_plugin', $contradict );
				return 'show';
			}
			return $value;
		};
		add_filter( 'option_mainwp_child_jetpack_scan_hide_plugin', $contradict );
		$unknown = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $current['revision'],
			)
		);
		remove_filter( 'option_mainwp_child_jetpack_scan_hide_plugin', $contradict );
		$this->assertFalse( $unknown['ok'] );
		$this->assertSame( 'contradictory_readback', $unknown['code'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );
	}

	public function test_malformed_unknown_and_uuid_alias_requests_fail_closed() {
		$this->assertSame(
			array(
				'protocol'  => '2',
				'operation' => 'unknown',
				'ok'        => false,
				'code'      => 'invalid_request',
			),
			$this->subject->abilities_v2( array() )
		);

		$unknown = $this->request( 'visibility_set', array() );
		$this->assertFalse( $unknown['ok'] );
		$this->assertSame( 'invalid_request', $unknown['code'] );

		$alias = $this->subject->abilities_v2(
			array(
				'protocol'   => '2',
				'operation'  => 'capabilities',
				'payload'    => array(),
				'request_id' => '123e4567-e89b-42d3-a456-426614174507',
			)
		);
		$this->assertFalse( $alias['ok'] );
		$this->assertSame( 'invalid_request', $alias['code'] );
	}

	public function test_reordered_envelope_is_accepted_but_nonempty_payload_is_not() {
		$result = $this->subject->abilities_v2(
			array(
				'payload'   => array(),
				'operation' => 'capabilities',
				'protocol'  => '2',
			)
		);
		$this->assertTrue( $result['ok'] );

		$result = $this->request( 'capabilities', array( 'extra' => true ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsupported_operation', $result['code'] );
	}

	private function request( $operation, $payload ) {
		return $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => $operation,
				'payload'   => $payload,
			)
		);
	}
}
