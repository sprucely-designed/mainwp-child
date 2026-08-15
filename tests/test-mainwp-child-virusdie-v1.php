<?php
/**
 * Virusdie signed installer protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Virusdie_V1 extends WP_UnitTestCase {

	/** @var MainWP_Child_Misc */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		$reflection    = new ReflectionClass( MainWP_Child_Misc::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
	}

	public function test_capabilities_are_closed_and_claim_no_executable_mutation() {
		$result = $this->subject->virusdie_sync_install_v1_response(
			array(
				'protocol'  => '1',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array(), $result['operations'] );
		$this->assertFalse( $result['mutation_supported'] );
	}

	public function test_malformed_and_install_requests_fail_closed_without_effects() {
		$this->assertSame(
			array(
				'protocol'  => '1',
				'operation' => 'unknown',
				'ok'        => false,
				'code'      => 'invalid_request',
			),
			$this->subject->virusdie_sync_install_v1_response( array() )
		);

		$result = $this->subject->virusdie_sync_install_v1_response(
			array(
				'protocol'  => '1',
				'operation' => 'install',
				'payload'   => array(
					'request_ref' => '123e4567-e89b-42d3-a456-426614175000',
				),
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsupported_operation', $result['code'] );
	}

	public function test_callable_dispatch_map_registers_the_narrow_protocol() {
		$reflection = new ReflectionClass( MainWP_Child_Callable::class );
		$callable   = $reflection->newInstanceWithoutConstructor();
		$property   = $reflection->getProperty( 'callableFunctions' );
		$property->setAccessible( true );

		$this->assertSame( 'virusdie_sync_install_v1', $property->getValue( $callable )['virusdie_sync_install_v1'] );
		$this->assertTrue( method_exists( $callable, 'virusdie_sync_install_v1' ) );
	}
}
