<?php
/**
 * Post Dripper Child protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_Post_Dripper_Idempotency extends WP_UnitTestCase {

	public function test_capability_callable_is_authenticated_and_closed() {
		$this->assertTrue( MainWP_Child_Callable::get_instance()->is_callable_function( 'post_dripper_capabilities_v2' ) );

		$result = $this->request( 'capabilities', array() );
		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['operations'] );
		$this->assertFalse( $result['mutation_supported'] );
	}

	public function test_unimplemented_status_and_uuid_alias_fail_closed() {
		$result = $this->request( 'post_dripper_status_v2', array() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsupported_operation', $result['code'] );

		$result = MainWP_Child_Posts::get_instance()->post_dripper_capabilities_v2(
			array(
				'protocol'   => '2',
				'operation'  => 'capabilities',
				'payload'    => array(),
				'request_id' => '123e4567-e89b-42d3-a456-426614174511',
			)
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );
	}

	public function test_malformed_and_nonempty_capability_payloads_fail_closed() {
		$result = MainWP_Child_Posts::get_instance()->post_dripper_capabilities_v2( array() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );

		$result = $this->request( 'capabilities', array( 'extra' => true ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsupported_operation', $result['code'] );
	}

	private function request( $operation, $payload ) {
		return MainWP_Child_Posts::get_instance()->post_dripper_capabilities_v2(
			array(
				'payload'   => $payload,
				'operation' => $operation,
				'protocol'  => '2',
			)
		);
	}
}
