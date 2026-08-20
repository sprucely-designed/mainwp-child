<?php
/**
 * Wordfence abilities-v2 protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Wordfence_V2_Protocol_Fixture extends MainWP_Child_Wordfence {

	/** @var array */
	public $results = array();

	/** @var array */
	public $calls = array();

	/** @var string|null IS_FREE_LOCK() observed while the provider ran. */
	public $lock_free_during_dispatch = null;

	protected function abilities_v2_provider_supports_mutation() {
		return true;
	}

	protected function abilities_v2_provider_operation( $operation, $payload, $request_ref ) {
		global $wpdb;
		$this->calls[]                   = array( $operation, $payload, $request_ref );
		$this->lock_free_during_dispatch = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $this->abilities_v2_lock_name() ) );
		return isset( $this->results[ $operation ] ) ? $this->results[ $operation ] : new \WP_Error( 'provider_unavailable' );
	}

	public function lock_name() {
		return $this->abilities_v2_lock_name();
	}
}

/** Stand-in for the Wordfence issue store the file-deletion paths construct directly. */
class Wordfence_Issue_Store_Stub {

	/** @var array */
	public static $issues = array();

	public function getIssueByID( $id ) {
		return isset( self::$issues[ $id ] ) ? self::$issues[ $id ] : false;
	}

	public function updateIssue( $id, $operation ) {
		unset( $id, $operation );
	}
}

// Wordfence is not installed in the harness, so the global name the Child constructs is aliased
// rather than declared - this file cannot open a global namespace block without being rewritten.
if ( ! class_exists( '\wfIssues', false ) ) {
	class_alias( Wordfence_Issue_Store_Stub::class, 'wfIssues' );
}

class Test_MainWP_Child_Wordfence_V2 extends WP_UnitTestCase {

	/** Web-root file the deletion paths are pointed at and must leave alone. */
	const VICTIM = 'mainwp-wordfence-delete-probe.php';

	/** Substring that only the earlier, unrelated warning can contribute to a message. */
	const STALE_MARKER = 'mainwp-unrelated-earlier-warning';

	/** @var MainWP_Child_Wordfence */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'mainwp_wordfence_abilities_v2_receipts' );
		$reflection    = new ReflectionClass( MainWP_Child_Wordfence::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
	}

	public function tear_down(): void {
		delete_option( 'mainwp_wordfence_abilities_v2_receipts' );
		parent::tear_down();
	}

	public function test_capabilities_are_closed_and_claim_no_unimplemented_operation() {
		$result = $this->request( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array( 'site_v2', 'scan_v2_status', 'findings_v2', 'firewall_v2_get', 'blocks_v2_list', 'operation_v2_status', 'file_v2_prepare', 'scan_v2_start', 'scan_v2_cancel', 'finding_v2_classify', 'file_v2_repair', 'firewall_v2_replace', 'blocks_v2_replace' ), $result['operations'] );
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
		$this->assertSame( 'provider_unavailable', $result['code'] );
	}

	public function test_typed_blocks_replace_uses_request_ref_and_exact_replay() {
		$fixture = new Wordfence_V2_Protocol_Fixture();
		$fixture->results['blocks_v2_replace'] = array(
			'operation_ref'   => str_repeat( 'a', 64 ),
			'state'           => 'completed',
			'added'           => 1,
			'removed'         => 0,
			'management_probe'=> 'safe',
			'generation'      => str_repeat( 'b', 64 ),
		);
		$request = array(
			'protocol'    => '2',
			'operation'   => 'blocks_v2_replace',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174601',
			'payload'     => array(
				'if_match' => str_repeat( 'c', 64 ),
				'blocks'   => array( array( 'kind' => 'cidr', 'value' => '203.0.113.0/24', 'reason' => 'abuse', 'expires_at' => null ) ),
			),
		);

		$result = $fixture->abilities_v2( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( $request['request_ref'], $result['request_ref'] );
		$this->assertSame( $result, $fixture->abilities_v2( $request ) );
		$this->assertCount( 1, $fixture->calls );

		$request['payload']['blocks'][0]['reason'] = 'changed';
		$this->assertSame( 'request_conflict', $fixture->abilities_v2( $request )['code'] );
		unset( $request['request_ref'] );
		$request['request_id'] = '123e4567-e89b-42d3-a456-426614174602';
		$this->assertSame( 'invalid_request', $fixture->abilities_v2( $request )['code'] );
	}

	public function test_read_results_are_closed_and_malformed_provider_data_fails() {
		$fixture = new Wordfence_V2_Protocol_Fixture();
		$fixture->results['site_v2'] = array(
			'plugin_version'        => '8.0.5',
			'state'                 => 'complete',
			'definitions_generation'=> str_repeat( 'a', 64 ),
			'config_generation'     => str_repeat( 'b', 64 ),
			'scan_ref'              => null,
			'scan_state'            => 'never',
			'finding_count'         => 0,
			'firewall_mode'         => 'enabled',
			'blocked_attack_count'  => 4,
			'observed_at'           => '2026-08-16T12:00:00Z',
			'generation'            => str_repeat( 'c', 64 ),
		);
		$result = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'site_v2', 'payload' => array() ) );
		$this->assertTrue( $result['ok'] );
		$this->assertArrayNotHasKey( 'request_ref', $result );

		$fixture->results['site_v2']['private_path'] = '/secret';
		$this->assertSame( 'provider_schema_invalid', $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'site_v2', 'payload' => array() ) )['code'] );
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

	public function test_mutation_dispatch_runs_under_the_named_lock() {
		$fixture = $this->blocks_fixture();
		$request = $this->blocks_request();

		$result = $fixture->abilities_v2( $request );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '0', (string) $fixture->lock_free_during_dispatch );
		$this->assertSame( '1', (string) $this->lock_state( $fixture->lock_name() ) );
	}

	public function test_a_held_lock_refuses_the_mutation_without_dispatching_or_storing() {
		$fixture = $this->blocks_fixture();
		$holder  = $this->hold_lock_elsewhere( $fixture->lock_name() );

		$result           = $fixture->abilities_v2( $this->blocks_request() );
		$dispatched_calls = count( $fixture->calls );
		$read             = $fixture->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'firewall_v2_get',
				'payload'   => array(),
			)
		);
		$this->release_lock_elsewhere( $holder, $fixture->lock_name() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'lock_busy', $result['code'] );
		$this->assertSame( 0, $dispatched_calls );
		$this->assertSame( array(), get_option( 'mainwp_wordfence_abilities_v2_receipts', array() ) );
		$this->assertSame( 'provider_unavailable', $read['code'] );
	}

	public function test_a_failed_deletion_reports_its_own_error_and_not_an_earlier_warning() {
		$victim = ABSPATH . self::VICTIM;
		file_put_contents( $victim, 'survives' );
		Wordfence_Issue_Store_Stub::$issues = array( 'issue-1' => array( 'data' => array( 'file' => self::VICTIM ) ) );
		// An empty path makes wp_delete_file() skip unlink() altogether, so the file survives and
		// nothing at all is raised for this deletion - exactly when a stale error gets borrowed.
		add_filter( 'wp_delete_file', '__return_empty_string' );

		$_POST['issueID'] = 'issue-1';
		$_POST['op']      = 'del';
		$_POST['ids']     = array( 'issue-1' );
		try {
			$this->raise_unrelated_warning();
			$single = $this->subject->delete_file();
			$this->raise_unrelated_warning();
			$bulk = $this->subject->bulk_operation();
		} finally {
			unset( $_POST['issueID'], $_POST['op'], $_POST['ids'] );
			if ( file_exists( $victim ) ) {
				unlink( $victim );
			}
		}

		$this->assertStringNotContainsString( self::STALE_MARKER, $single['errorMsg'] );
		$this->assertStringContainsString( 'unknown', $single['errorMsg'] );
		$this->assertStringNotContainsString( self::STALE_MARKER, $bulk['bulkBody'] );
		$this->assertStringContainsString( 'unknown', $bulk['bulkBody'] );
	}

	/** Leave a warning of the kind any other plugin can raise earlier in the same request. */
	private function raise_unrelated_warning() {
		@file_get_contents( ABSPATH . self::STALE_MARKER );
		$last = error_get_last();
		$this->assertIsArray( $last );
		$this->assertStringContainsString( self::STALE_MARKER, $last['message'] );
	}

	private function blocks_fixture() {
		$fixture                               = new Wordfence_V2_Protocol_Fixture();
		$fixture->results['blocks_v2_replace'] = array(
			'operation_ref'    => str_repeat( 'a', 64 ),
			'state'            => 'completed',
			'added'            => 1,
			'removed'          => 0,
			'management_probe' => 'safe',
			'generation'       => str_repeat( 'b', 64 ),
		);
		return $fixture;
	}

	private function blocks_request() {
		return array(
			'protocol'    => '2',
			'operation'   => 'blocks_v2_replace',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174603',
			'payload'     => array(
				'if_match' => str_repeat( 'c', 64 ),
				'blocks'   => array(
					array(
						'kind'       => 'cidr',
						'value'      => '203.0.113.0/24',
						'reason'     => 'abuse',
						'expires_at' => null,
					),
				),
			),
		);
	}

	private function lock_state( $name ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) );
	}

	private function hold_lock_elsewhere( $name ) {
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->suppress_errors( true );
		$held = (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $name ) );
		if ( '1' !== $held ) {
			// A lock this connection never took needs no release, but the connection is ours either
			// way and a failing assertion would otherwise strand it for the rest of the run.
			$other->close();
		}
		$this->assertSame( '1', $held );
		return $other;
	}

	private function release_lock_elsewhere( $other, $name ) {
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		$other->close();
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
