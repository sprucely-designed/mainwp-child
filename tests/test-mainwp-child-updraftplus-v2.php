<?php
/**
 * UpdraftPlus abilities-v2 negotiation tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Updraftplus_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Updraft_Plus_Backups */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		$reflection    = new ReflectionClass( MainWP_Child_Updraft_Plus_Backups::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
		$this->subject->is_plugin_installed = true;
	}

	public function test_capabilities_are_closed_and_claim_no_unimplemented_operation() {
		$result = $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);

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

		$result = $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'history',
				'payload'   => array(),
			)
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsupported_operation', $result['code'] );
	}
}
