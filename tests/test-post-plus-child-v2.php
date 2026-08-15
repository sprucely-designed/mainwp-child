<?php
/**
 * Post Plus Child protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_Post_Plus_Child_V2 extends WP_UnitTestCase {

	public function test_capability_callable_is_authenticated_and_closed() {
		$this->assertTrue( MainWP_Child_Callable::get_instance()->is_callable_function( 'post_plus_capabilities_v2' ) );

		$result = $this->request( 'capabilities', array() );
		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['operations'] );
		$this->assertFalse( $result['mutation_supported'] );
	}

	public function test_unimplemented_create_and_status_operations_fail_closed() {
		foreach ( array( 'post_plus_newpost_v2', 'post_plus_status_v2' ) as $operation ) {
			$result = $this->request( $operation, array() );
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'unsupported_operation', $result['code'] );
		}
	}

	public function test_malformed_extra_and_nonempty_capability_payloads_fail_closed() {
		$result = MainWP_Child_Posts::get_instance()->post_plus_capabilities_v2( array() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );

		$result = MainWP_Child_Posts::get_instance()->post_plus_capabilities_v2(
			array(
				'protocol'        => '2',
				'operation'       => 'capabilities',
				'payload'         => array(),
				'correlation_ref' => '123e4567-e89b-42d3-a456-426614174612',
			)
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );

		$result = $this->request( 'capabilities', array( 'extra' => true ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsupported_operation', $result['code'] );
	}

	private function request( $operation, $payload ) {
		return MainWP_Child_Posts::get_instance()->post_plus_capabilities_v2(
			array(
				'payload'   => $payload,
				'operation' => $operation,
				'protocol'  => '2',
			)
		);
	}
}
