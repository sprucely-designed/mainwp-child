<?php
/**
 * Early Access release protocol-v2 tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Early_Access_Release_V2 extends WP_UnitTestCase {

	public function test_capabilities_are_closed_and_do_not_claim_an_installer() {
		$subject = new MainWP_Child_Early_Access_Release();
		$result  = $subject->release_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported', 'max_artifact_bytes' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['operations'] );
		$this->assertFalse( $result['mutation_supported'] );
		$this->assertSame( 52428800, $result['max_artifact_bytes'] );
	}

	public function test_unknown_malformed_and_extra_fields_fail_closed() {
		$subject = new MainWP_Child_Early_Access_Release();
		$this->assertSame( 'invalid_request', $subject->release_v2( array() )['code'] );
		$this->assertSame(
			'unsupported_operation',
			$subject->release_v2(
				array(
					'protocol'  => '2',
					'operation' => 'install',
					'payload'   => array(),
				)
			)['code']
		);
		$this->assertSame(
			'invalid_request',
			$subject->release_v2(
				array(
					'protocol'  => '2',
					'operation' => 'capabilities',
					'payload'   => array(),
					'extra'     => true,
				)
			)['code']
		);
	}

	public function test_callable_map_exposes_only_the_authenticated_protocol_entry() {
		$reflection = new ReflectionClass( MainWP_Child_Callable::class );
		$property   = $reflection->getProperty( 'callableFunctions' );
		$property->setAccessible( true );
		$callables = $property->getValue( MainWP_Child_Callable::get_instance() );

		$this->assertSame( 'early_access_release_v2', $callables['early_access_release_v2'] );
	}
}
