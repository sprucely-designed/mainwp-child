<?php
/**
 * UpdraftPlus abilities-v2 negotiation tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Updraftplus_V2_Protocol_Fixture extends MainWP_Child_Updraft_Plus_Backups {

	/** @var array */
	public $results = array();

	/** @var array */
	public $calls = array();

	protected function abilities_v2_provider_supports_mutation() {
		return true;
	}

	protected function abilities_v2_provider_operation( $operation, $payload ) {
		$this->calls[] = array( $operation, $payload );
		return isset( $this->results[ $operation ] ) ? $this->results[ $operation ] : new \WP_Error( 'provider_unavailable' );
	}
}

class Test_MainWP_Child_Updraftplus_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Updraft_Plus_Backups */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'mainwp_updraftplus_abilities_v2_receipts' );
		$reflection    = new ReflectionClass( MainWP_Child_Updraft_Plus_Backups::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
		$this->subject->is_plugin_installed = true;
	}

	public function tear_down(): void {
		delete_option( 'mainwp_updraftplus_abilities_v2_receipts' );
		parent::tear_down();
	}

	public function test_capabilities_are_closed_and_publish_the_typed_surface() {
		$result = $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array( 'site', 'policy', 'list_backups', 'backup_manifest', 'operation_status', 'preview_restore', 'replace_policy', 'start_backup', 'cancel_operation', 'prepare_download', 'delete_backup', 'restore_backup' ), $result['operations'] );
		$this->assertFalse( $result['mutation_supported'] );
	}

	public function test_typed_backup_mutation_uses_request_ref_and_exact_replay() {
		$fixture                      = new Updraftplus_V2_Protocol_Fixture();
		$fixture->is_plugin_installed = true;
		$fixture->results['start_backup'] = array(
			'operation_ref' => str_repeat( 'a', 64 ),
			'state'         => 'queued',
			'component_count' => 2,
			'placement'     => 'both',
		);
		$request = array(
			'protocol'    => '2',
			'operation'   => 'start_backup',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174981',
			'payload'     => array( 'components' => array( 'database', 'plugins' ), 'placement' => 'both', 'policy_generation' => str_repeat( 'b', 64 ) ),
		);
		$result = $fixture->abilities_v2( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( $request['request_ref'], $result['request_ref'] );
		$this->assertSame( $result, $fixture->abilities_v2( $request ) );
		$this->assertCount( 1, $fixture->calls );

		$request['payload']['placement'] = 'local';
		$this->assertSame( 'request_conflict', $fixture->abilities_v2( $request )['code'] );
		unset( $request['request_ref'] );
		$request['request_id'] = '123e4567-e89b-42d3-a456-426614174982';
		$this->assertSame( 'invalid_request', $fixture->abilities_v2( $request )['code'] );
	}

	public function test_manifest_and_restore_preview_results_are_closed() {
		$fixture = new Updraftplus_V2_Protocol_Fixture();
		$fixture->results['backup_manifest'] = array(
			'backup_ref'         => str_repeat( 'c', 64 ),
			'created_at'         => '2026-08-16T10:00:00Z',
			'components'         => array(),
			'complete'           => false,
			'manifest_generation' => str_repeat( 'd', 64 ),
		);
		$manifest = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'backup_manifest', 'payload' => array( 'backup_ref' => str_repeat( 'c', 64 ) ) ) );
		$this->assertTrue( $manifest['ok'] );

		$fixture->results['preview_restore'] = array(
			'preview_token'      => str_repeat( 'P', 43 ),
			'expires_at'        => '2026-08-16T10:05:00Z',
			'backup_ref'        => str_repeat( 'c', 64 ),
			'component_count'   => 1,
			'overwrite_expected' => true,
			'preflight'         => 'blocked',
		);
		$preview = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'preview_restore', 'payload' => array( 'backup_ref' => str_repeat( 'c', 64 ), 'manifest_generation' => str_repeat( 'd', 64 ), 'component_refs' => array( str_repeat( 'e', 64 ) ) ) ) );
		$this->assertTrue( $preview['ok'] );
		$this->assertSame( 'blocked', $preview['preflight'] );
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
