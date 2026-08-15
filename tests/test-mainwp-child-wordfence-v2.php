<?php
/**
 * Wordfence abilities-v2 protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Wordfence_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Wordfence */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		$reflection    = new ReflectionClass( MainWP_Child_Wordfence::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
	}

	public function test_capabilities_are_closed_and_claim_no_unimplemented_operation() {
		$result = $this->request( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array(), $result['operations'] );
		$this->assertFalse( $result['mutation_supported'] );
	}

	public function test_malformed_and_unknown_requests_fail_closed() {
		$this->assertSame(
			array(
				'protocol'  => '2',
				'operation' => 'unknown',
				'ok'        => false,
				'code'      => 'invalid_request',
			),
			$this->subject->abilities_v2( array() )
		);

		$result = $this->request( 'site_v2', array() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsupported_operation', $result['code'] );
	}

	public function test_reordered_envelope_is_accepted_but_extra_fields_are_not() {
		$result = $this->subject->abilities_v2(
			array(
				'payload'   => array(),
				'operation' => 'capabilities',
				'protocol'  => '2',
			)
		);
		$this->assertTrue( $result['ok'] );

		$result = $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
				'extra'     => true,
			)
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );
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
