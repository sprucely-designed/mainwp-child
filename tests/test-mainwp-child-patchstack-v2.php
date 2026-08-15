<?php
/**
 * Patchstack abilities-v2 negotiation tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Patchstack_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Patchstack */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		$reflection    = new ReflectionClass( Testable_MainWP_Child_Patchstack::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
	}

	public function test_capabilities_are_closed_and_claim_no_unimplemented_operation() {
		$result = $this->request( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'patchstack_protection_preview_v2', 'patchstack_visibility_replace_v2' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_protection_preview_is_local_bounded_and_generation_bound() {
		$this->subject->plugin_state = 'active';
		$this->subject->visibility   = 'hidden';
		$result                      = $this->request( 'patchstack_protection_preview_v2', $this->base_payload() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operation_ref', 'current_state', 'planned_action', 'package_state', 'visibility', 'state_revision', 'observed_at' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'active', $result['current_state'] );
		$this->assertSame( 'license', $result['planned_action'] );
		$this->assertSame( 'not_needed', $result['package_state'] );
		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['state_revision'] );
		$this->assertSame( 0, $this->subject->writes );

		$this->subject->plugin_state = 'absent';
		$result                      = $this->request( 'patchstack_protection_preview_v2', $this->base_payload( '123e4567-e89b-42d3-a456-426614174511' ) );
		$this->assertSame( 'install', $result['planned_action'] );
		$this->assertSame( 'verification_unavailable', $result['package_state'] );
	}

	public function test_visibility_replace_stages_replays_and_restores_on_contradiction() {
		$this->subject->plugin_state = 'active';
		$this->subject->visibility   = 'shown';
		$preview                     = $this->request( 'patchstack_protection_preview_v2', $this->base_payload() );
		$payload                     = array_merge(
			$this->base_payload(),
			array(
				'desired_state' => 'hidden',
				'if_match'      => $preview['state_revision'],
			)
		);

		$result = $this->request( 'patchstack_visibility_replace_v2', $payload );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 1, $this->subject->writes );
		$this->assertSame( 'pending', $this->subject->receipt_writes[0][ $payload['operation_ref'] ]['state'] );

		$replay = $this->request( 'patchstack_visibility_replace_v2', $payload );
		$this->assertSame( $result, $replay );
		$this->assertSame( 1, $this->subject->writes );

		$this->subject->visibility_write_readback = 'hidden';
		$failed_payload                            = array_merge(
			$this->base_payload( '123e4567-e89b-42d3-a456-426614174512' ),
			array(
				'desired_state' => 'shown',
				'if_match'      => $result['state_revision'],
			)
		);
		$failed = $this->request( 'patchstack_visibility_replace_v2', $failed_payload );
		$this->assertSame( 'write_failed', $failed['code'] );
		$this->assertSame( 'hidden', $this->subject->visibility );
	}

	public function test_patchstack_v2_rejects_stale_conflicting_and_malformed_effects() {
		$this->subject->plugin_state = 'installed';
		$this->subject->visibility   = 'shown';
		$preview                     = $this->request( 'patchstack_protection_preview_v2', $this->base_payload() );
		$payload                     = array_merge(
			$this->base_payload(),
			array(
				'desired_state' => 'hidden',
				'if_match'      => str_repeat( 'a', 64 ),
			)
		);
		$this->assertSame( 'stale_revision', $this->request( 'patchstack_visibility_replace_v2', $payload )['code'] );
		$payload['if_match'] = $preview['state_revision'];
		$this->assertTrue( $this->request( 'patchstack_visibility_replace_v2', $payload )['ok'] );
		$payload['desired_state'] = 'shown';
		$this->assertSame( 'request_conflict', $this->request( 'patchstack_visibility_replace_v2', $payload )['code'] );

		$invalid                     = $this->base_payload();
		$invalid['expected_plugin_slug'] = 'other/plugin.php';
		$this->assertSame( 'invalid_request', $this->request( 'patchstack_protection_preview_v2', $invalid )['code'] );
		$invalid                      = $this->base_payload();
		$invalid['expires_at']        = time() - 1;
		$this->assertSame( 'invalid_request', $this->request( 'patchstack_protection_preview_v2', $invalid )['code'] );
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

		$unknown = $this->request( 'patchstack_unknown_v2', array() );
		$this->assertFalse( $unknown['ok'] );
		$this->assertSame( 'unsupported_operation', $unknown['code'] );

		$alias = $this->subject->abilities_v2(
			array(
				'protocol'     => '2',
				'operation'    => 'capabilities',
				'payload'      => array(),
				'operation_id' => '123e4567-e89b-42d3-a456-426614174508',
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

	private function base_payload( $operation_ref = '123e4567-e89b-42d3-a456-426614174510' ) {
		return array(
			'operation_ref'          => $operation_ref,
			'expected_plugin_slug'   => 'patchstack/patchstack.php',
			'provider_binding_digest' => str_repeat( 'b', 64 ),
			'expires_at'             => time() + 300,
		);
	}
}

class Testable_MainWP_Child_Patchstack extends MainWP_Child_Patchstack {

	public $plugin_state = 'absent';
	public $visibility = 'shown';
	public $visibility_write_readback = null;
	public $writes = 0;
	public $receipts = array();
	public $receipt_writes = array();

	protected function abilities_v2_plugin_state() {
		return $this->plugin_state;
	}

	protected function abilities_v2_visibility() {
		return $this->visibility;
	}

	protected function abilities_v2_write_visibility( $visibility ) {
		++$this->writes;
		$prior            = $this->visibility;
		$this->visibility = null === $this->visibility_write_readback ? $visibility : $this->visibility_write_readback;
		return array( 'prior' => $prior, 'current' => $this->visibility );
	}

	protected function abilities_v2_restore_visibility( $visibility ) {
		$this->visibility = $visibility;
		return true;
	}

	protected function abilities_v2_read_receipts() {
		return $this->receipts;
	}

	protected function abilities_v2_write_receipts( $receipts ) {
		$this->receipt_writes[] = $receipts;
		$this->receipts         = $receipts;
		return true;
	}

	protected function abilities_v2_begin_mutation() {
		return true;
	}

	protected function abilities_v2_end_mutation() {
		return true;
	}
}
