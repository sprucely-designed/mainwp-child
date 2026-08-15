<?php
/**
 * Maintenance abilities-v2 negotiation tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Maintenance_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Maintenance */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		delete_option( MainWP_Child_Maintenance::ABILITIES_V2_LOCK_OPTION );
		delete_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION );
		$reflection    = new ReflectionClass( Testable_MainWP_Child_Maintenance::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
	}

	public function tear_down(): void {
		delete_option( MainWP_Child_Maintenance::ABILITIES_V2_LOCK_OPTION );
		delete_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION );
		parent::tear_down();
	}

	public function test_capabilities_are_closed_and_claim_no_unimplemented_operation() {
		$result = $this->request( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'ability_maintenance_preview_v2', 'ability_maintenance_execute_v2', 'ability_maintenance_operation_v2' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_execute_stages_replays_and_reports_exactly_once() {
		$this->subject->preview_impacts = array(
			'revisions'          => array( 'would_affect' => 3, 'capability' => true ),
			'transients_expired' => array( 'would_affect' => 2, 'capability' => true ),
		);
		$this->subject->action_results  = array(
			'revisions'          => array( 'status' => 'succeeded', 'affected' => 3, 'error_code' => null ),
			'transients_expired' => array( 'status' => 'succeeded', 'affected' => 2, 'error_code' => null ),
		);
		$preview                       = $this->request(
			'ability_maintenance_preview_v2',
			array( 'actions' => array( 'transients_expired', 'revisions' ), 'revision_retention' => 5 )
		);
		$payload                       = array(
			'operation_ref'     => '123e4567-e89b-42d3-a456-426614174506',
			'actions'           => array( 'transients_expired', 'revisions' ),
			'revision_retention' => 5,
			'snapshot_revision' => $preview['snapshot_revision'],
			'action_hash'       => hash( 'sha256', wp_json_encode( array( array( 'revisions', 'transients_expired' ), 5 ) ) ),
		);

		$result = $this->request( 'ability_maintenance_execute_v2', $payload );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( array( 'revisions', 'transients_expired' ), array_column( $result['outcomes'], 'action' ) );
		$this->assertSame( array( 'revisions', 'transients_expired' ), $this->subject->execution_log );
		$this->assertSame( 1, $this->subject->report_count );
		$this->assertNotEmpty( $this->subject->write_snapshots );
		$this->assertSame( 'running', $this->subject->write_snapshots[0][ $payload['operation_ref'] ]['status'] );

		$replay = $this->request( 'ability_maintenance_execute_v2', $payload );
		$this->assertSame( $result, $replay );
		$this->assertSame( array( 'revisions', 'transients_expired' ), $this->subject->execution_log );
		$this->assertSame( 1, $this->subject->report_count );

		$status = $this->request( 'ability_maintenance_operation_v2', array( 'operation_ref' => $payload['operation_ref'] ) );
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 'ability_maintenance_operation_v2', $status['operation'] );
		$this->assertSame( 'succeeded', $status['status'] );
		$this->assertSame( $result['outcomes'], $status['outcomes'] );
	}

	public function test_execute_rejects_stale_preview_conflicts_and_failed_staging() {
		$this->subject->preview_impacts = array( 'spam' => array( 'would_affect' => 1, 'capability' => true ) );
		$preview                       = $this->request( 'ability_maintenance_preview_v2', array( 'actions' => array( 'spam' ), 'revision_retention' => 5 ) );
		$payload                       = array(
			'operation_ref'      => '123e4567-e89b-42d3-a456-426614174507',
			'actions'            => array( 'spam' ),
			'revision_retention' => 5,
			'snapshot_revision'  => $preview['snapshot_revision'],
			'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'spam' ), 5 ) ) ),
		);

		$stale                      = $payload;
		$stale['snapshot_revision'] = str_repeat( 'a', 64 );
		$this->assertSame( 'stale_snapshot', $this->request( 'ability_maintenance_execute_v2', $stale )['code'] );

		$this->subject->write_operations_succeeds = false;
		$this->assertSame( 'storage_unavailable', $this->request( 'ability_maintenance_execute_v2', $payload )['code'] );
		$this->assertSame( array(), $this->subject->execution_log );
		$this->subject->write_operations_succeeds = true;
		$this->subject->begin_mutation_succeeds    = false;
		$this->assertSame( 'lock_busy', $this->request( 'ability_maintenance_execute_v2', $payload )['code'] );
		$this->assertSame( array(), $this->subject->execution_log );
		$this->subject->begin_mutation_succeeds = true;
		$this->subject->action_results['spam']     = array( 'status' => 'succeeded', 'affected' => 1, 'error_code' => null );
		$this->subject->end_mutation_succeeds      = false;
		$this->assertSame( 'outcome_unknown', $this->request( 'ability_maintenance_execute_v2', $payload )['code'] );
		$this->assertSame( array( 'spam' ), $this->subject->execution_log );
		$this->subject->end_mutation_succeeds = true;
		$this->assertTrue( $this->request( 'ability_maintenance_execute_v2', $payload )['ok'] );
		$this->assertSame( array( 'spam' ), $this->subject->execution_log );

		$conflict                     = $payload;
		$conflict['revision_retention'] = 4;
		$conflict['action_hash']        = hash( 'sha256', wp_json_encode( array( array( 'spam' ), 4 ) ) );
		$this->subject->preview_impacts = array( 'spam' => array( 'would_affect' => 1, 'capability' => true ) );
		$conflict_preview               = $this->request( 'ability_maintenance_preview_v2', array( 'actions' => array( 'spam' ), 'revision_retention' => 4 ) );
		$conflict['snapshot_revision']  = $conflict_preview['snapshot_revision'];
		$this->assertSame( 'request_conflict', $this->request( 'ability_maintenance_execute_v2', $conflict )['code'] );
	}

	public function test_execute_persists_partial_results_and_status_fails_closed() {
		$this->subject->preview_impacts = array(
			'autodraft' => array( 'would_affect' => 2, 'capability' => true ),
			'optimize'  => array( 'would_affect' => 1, 'capability' => true ),
		);
		$this->subject->action_results  = array(
			'autodraft' => array( 'status' => 'succeeded', 'affected' => 2, 'error_code' => null ),
			'optimize'  => array( 'status' => 'failed', 'affected' => null, 'error_code' => 'mutation_failed' ),
		);
		$preview                       = $this->request( 'ability_maintenance_preview_v2', array( 'actions' => array( 'optimize', 'autodraft' ), 'revision_retention' => 5 ) );
		$payload                       = array(
			'operation_ref'      => '123e4567-e89b-42d3-a456-426614174508',
			'actions'            => array( 'optimize', 'autodraft' ),
			'revision_retention' => 5,
			'snapshot_revision'  => $preview['snapshot_revision'],
			'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'autodraft', 'optimize' ), 5 ) ) ),
		);

		$result = $this->request( 'ability_maintenance_execute_v2', $payload );
		$this->assertSame( 'partial', $result['status'] );
		$this->assertSame( array( 'succeeded', 'failed' ), array_column( $result['outcomes'], 'status' ) );
		$this->assertFalse( $result['retryable'] );
		$this->assertSame( 1, $this->subject->report_count );

		$missing = $this->request( 'ability_maintenance_operation_v2', array( 'operation_ref' => '123e4567-e89b-42d3-a456-426614174509' ) );
		$this->assertFalse( $missing['ok'] );
		$this->assertSame( 'operation_not_found', $missing['code'] );
		$invalid = $this->request( 'ability_maintenance_operation_v2', array( 'operation_ref' => 'not-a-uuid' ) );
		$this->assertSame( 'invalid_request', $invalid['code'] );
	}

	public function test_real_storage_lock_and_checked_sql_action_converge() {
		$reflection = new ReflectionClass( MainWP_Child_Maintenance::class );
		$subject    = $reflection->newInstanceWithoutConstructor();
		$preview    = $subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'ability_maintenance_preview_v2',
				'payload'   => array( 'actions' => array( 'autodraft' ), 'revision_retention' => 5 ),
			)
		);
		$this->assertTrue( $preview['ok'] );
		$payload = array(
			'operation_ref'      => '123e4567-e89b-42d3-a456-426614174510',
			'actions'            => array( 'autodraft' ),
			'revision_retention' => 5,
			'snapshot_revision'  => $preview['snapshot_revision'],
			'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'autodraft' ), 5 ) ) ),
		);
		$request = array(
			'protocol'  => '2',
			'operation' => 'ability_maintenance_execute_v2',
			'payload'   => $payload,
		);

		$result = $subject->abilities_v2( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( 'autodraft', $result['outcomes'][0]['action'] );
		$this->assertIsInt( $result['outcomes'][0]['affected'] );
		$this->assertNull( get_option( MainWP_Child_Maintenance::ABILITIES_V2_LOCK_OPTION, null ) );
		$this->assertArrayHasKey( $payload['operation_ref'], get_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, array() ) );
		$this->assertSame( $result, $subject->abilities_v2( $request ) );

		$status = $subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'ability_maintenance_operation_v2',
				'payload'   => array( 'operation_ref' => $payload['operation_ref'] ),
			)
		);
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 'succeeded', $status['status'] );
	}

	public function test_preview_returns_actions_in_canonical_order_with_stable_risk() {
		$actions = array( 'revisions', 'autodraft', 'trashpost', 'spam', 'pending', 'trashcomment', 'tags', 'categories', 'optimize', 'transients_expired', 'transients_all' );
		foreach ( $actions as $index => $action ) {
			$this->subject->preview_impacts[ $action ] = array(
				'would_affect' => $index + 1,
				'capability'   => true,
			);
		}

		$result = $this->request(
			'ability_maintenance_preview_v2',
			array(
				'actions'            => array_reverse( array_slice( $actions, 0, 10 ) ),
				'revision_retention' => 5,
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'impacts', 'snapshot_revision', 'observed_at' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array_slice( $actions, 0, 10 ), array_column( $result['impacts'], 'action' ) );
		$this->assertSame( range( 1, 10 ), array_column( $result['impacts'], 'would_affect' ) );
		$this->assertSame( array_fill( 0, 10, true ), array_column( $result['impacts'], 'capability' ) );
		$this->assertSame(
			array( 'standard', 'standard', 'standard', 'standard', 'elevated', 'standard', 'elevated', 'elevated', 'elevated', 'standard' ),
			array_column( $result['impacts'], 'risk' )
		);
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['snapshot_revision'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['observed_at'] );

		$all_transients = $this->request(
			'ability_maintenance_preview_v2',
			array(
				'actions'            => array( 'transients_all' ),
				'revision_retention' => 5,
			)
		);
		$this->assertTrue( $all_transients['ok'] );
		$this->assertSame( 'transients_all', $all_transients['impacts'][0]['action'] );
		$this->assertSame( 'elevated', $all_transients['impacts'][0]['risk'] );
	}

	public function test_preview_is_read_only_saturates_counts_and_marks_revision_zero_elevated() {
		$this->subject->preview_impacts = array(
			'revisions' => array( 'would_affect' => 1000001, 'capability' => true ),
			'optimize'  => array( 'would_affect' => 4, 'capability' => false ),
		);
		$reports                       = 0;
		$report                        = static function () use ( &$reports ) {
			++$reports;
		};
		add_action( 'mainwp_reports_maintenance', $report );

		$result = $this->request(
			'ability_maintenance_preview_v2',
			array(
				'actions'            => array( 'optimize', 'revisions' ),
				'revision_retention' => 0,
			)
		);
		remove_action( 'mainwp_reports_maintenance', $report );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'revisions', $result['impacts'][0]['action'] );
		$this->assertSame( 1000000, $result['impacts'][0]['would_affect'] );
		$this->assertSame( 'elevated', $result['impacts'][0]['risk'] );
		$this->assertFalse( $result['impacts'][1]['capability'] );
		$this->assertSame( 0, $reports );
	}

	public function test_preview_rejects_malformed_duplicate_conflicting_and_failed_counts() {
		$invalid_payloads = array(
			array(),
			array( 'actions' => array(), 'revision_retention' => 5 ),
			array( 'actions' => array( 'unknown' ), 'revision_retention' => 5 ),
			array( 'actions' => array( 'spam', 'spam' ), 'revision_retention' => 5 ),
			array( 'actions' => array( 'transients_all', 'transients_expired' ), 'revision_retention' => 5 ),
			array( 'actions' => array( 'spam' ), 'revision_retention' => -1 ),
			array( 'actions' => array( 'spam' ), 'revision_retention' => 1001 ),
			array( 'actions' => array( 'spam' ), 'revision_retention' => '5' ),
			array( 'actions' => array( 'spam' ), 'revision_retention' => 5, 'extra' => true ),
		);
		foreach ( $invalid_payloads as $payload ) {
			$result = $this->request( 'ability_maintenance_preview_v2', $payload );
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'invalid_request', $result['code'] );
		}

		$this->subject->preview_impacts = array( 'spam' => false );
		$result                         = $this->request(
			'ability_maintenance_preview_v2',
			array( 'actions' => array( 'spam' ), 'revision_retention' => 5 )
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_snapshot', $result['code'] );
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

		$unknown = $this->request( 'ability_maintenance_future_v2', array() );
		$this->assertFalse( $unknown['ok'] );
		$this->assertSame( 'unsupported_operation', $unknown['code'] );

		$alias = $this->subject->abilities_v2(
			array(
				'protocol'     => '2',
				'operation'    => 'capabilities',
				'payload'      => array(),
				'operation_id' => '123e4567-e89b-42d3-a456-426614174510',
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

class Testable_MainWP_Child_Maintenance extends MainWP_Child_Maintenance {

	public $preview_impacts = array();
	public $action_results = array();
	public $execution_log = array();
	public $operation_records = array();
	public $write_snapshots = array();
	public $write_operations_succeeds = true;
	public $report_count = 0;
	public $begin_mutation_succeeds = true;
	public $end_mutation_succeeds = true;

	protected function abilities_v2_preview_impact( $action, $revision_retention ) {
		unset( $revision_retention );
		return array_key_exists( $action, $this->preview_impacts ) ? $this->preview_impacts[ $action ] : false;
	}

	protected function abilities_v2_execute_action( $action, $revision_retention ) {
		unset( $revision_retention );
		$this->execution_log[] = $action;
		return array_key_exists( $action, $this->action_results ) ? $this->action_results[ $action ] : false;
	}

	protected function abilities_v2_read_operations() {
		return $this->operation_records;
	}

	protected function abilities_v2_write_operations( $operations ) {
		$this->write_snapshots[] = $operations;
		if ( $this->write_operations_succeeds ) {
			$this->operation_records = $operations;
		}
		return $this->write_operations_succeeds;
	}

	protected function abilities_v2_emit_report( $actions, $revision_retention ) {
		unset( $actions, $revision_retention );
		++$this->report_count;
	}

	protected function abilities_v2_begin_mutation() {
		return $this->begin_mutation_succeeds;
	}

	protected function abilities_v2_end_mutation() {
		return $this->end_mutation_succeeds;
	}

	protected function abilities_v2_renew_mutation() {
		return true;
	}
}
