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
		$this->subject->action_results['spam']  = array( 'status' => 'succeeded', 'affected' => 1, 'error_code' => null );
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

	public function test_reordered_envelope_is_accepted_and_capabilities_with_a_payload_is_invalid() {
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
		$this->assertSame( 'invalid_request', $result['code'] );
		$this->assertSame( 'capabilities', $result['operation'] );
	}

	public function test_invalid_execute_payload_is_rejected_before_the_lock_is_taken() {
		update_option(
			MainWP_Child_Maintenance::ABILITIES_V2_LOCK_OPTION,
			array(
				'owner'      => '123e4567-e89b-42d3-a456-426614174777',
				'expires_at' => time() + 300,
			),
			false
		);
		$subject = $this->real_subject();

		$result = $this->real_request( $subject, 'ability_maintenance_execute_v2', array( 'operation_ref' => 'not-a-uuid' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );

		$held = array(
			'operation_ref'      => '123e4567-e89b-42d3-a456-426614174511',
			'actions'            => array( 'autodraft' ),
			'revision_retention' => 5,
			'snapshot_revision'  => str_repeat( 'a', 64 ),
			'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'autodraft' ), 5 ) ) ),
		);
		$this->assertSame( 'lock_busy', $this->real_request( $subject, 'ability_maintenance_execute_v2', $held )['code'] );
		$this->assertSame( '123e4567-e89b-42d3-a456-426614174777', get_option( MainWP_Child_Maintenance::ABILITIES_V2_LOCK_OPTION )['owner'] );
	}

	public function test_committed_execute_survives_a_failed_lock_release() {
		$this->subject->preview_impacts       = array( 'spam' => array( 'would_affect' => 1, 'capability' => true ) );
		$this->subject->action_results        = array( 'spam' => array( 'status' => 'succeeded', 'affected' => 1, 'error_code' => null ) );
		$this->subject->end_mutation_succeeds = false;
		$preview                              = $this->request( 'ability_maintenance_preview_v2', array( 'actions' => array( 'spam' ), 'revision_retention' => 5 ) );
		$payload                              = array(
			'operation_ref'      => '123e4567-e89b-42d3-a456-426614174512',
			'actions'            => array( 'spam' ),
			'revision_retention' => 5,
			'snapshot_revision'  => $preview['snapshot_revision'],
			'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'spam' ), 5 ) ) ),
		);

		$result = $this->request( 'ability_maintenance_execute_v2', $payload );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( array( 'succeeded' ), array_column( $result['outcomes'], 'status' ) );
		$this->assertSame( array( 'lock_release_failed' ), $result['warning_codes'] );

		$this->subject->end_mutation_succeeds = true;
		$replay                               = $this->request( 'ability_maintenance_execute_v2', $payload );
		$this->assertTrue( $replay['ok'] );
		$this->assertArrayNotHasKey( 'warning_codes', $replay );
		$this->assertSame( array( 'spam' ), $this->subject->execution_log );
	}

	public function test_failed_action_outcome_reports_the_rows_it_destroyed() {
		$this->subject->preview_impacts = array( 'tags' => array( 'would_affect' => 9, 'capability' => true ) );
		$this->subject->action_results  = array( 'tags' => array( 'status' => 'failed', 'affected' => 4, 'error_code' => 'mutation_failed' ) );
		$preview                        = $this->request( 'ability_maintenance_preview_v2', array( 'actions' => array( 'tags' ), 'revision_retention' => 5 ) );

		$result = $this->request(
			'ability_maintenance_execute_v2',
			array(
				'operation_ref'      => '123e4567-e89b-42d3-a456-426614174513',
				'actions'            => array( 'tags' ),
				'revision_retention' => 5,
				'snapshot_revision'  => $preview['snapshot_revision'],
				'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'tags' ), 5 ) ) ),
			)
		);

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame(
			array(
				'action'     => 'tags',
				'status'     => 'failed',
				'affected'   => 4,
				'error_code' => 'mutation_failed',
			),
			$result['outcomes'][0]
		);
		$this->assertTrue( $result['retryable'] );
	}

	public function test_stale_running_records_settle_instead_of_bricking_the_operation_store() {
		$records = array();
		for ( $index = 1; $index <= 99; $index++ ) {
			$abandoned                             = $this->running_record( $index, 8 * DAY_IN_SECONDS );
			$records[ $abandoned['operation_ref'] ] = $abandoned;
		}
		$recent                              = $this->running_record( 900, 2 * DAY_IN_SECONDS );
		$records[ $recent['operation_ref'] ] = $recent;
		update_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, $records, false );

		list( , $result ) = $this->real_execute( $this->real_subject(), array( 'autodraft' ), 5, '123e4567-e89b-42d3-a456-426614174514' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$stored = get_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, array() );
		$this->assertCount( 2, $stored );
		$this->assertSame( 'unknown', $stored[ $recent['operation_ref'] ]['status'] );
		$this->assertSame( $recent['updated_at'], $stored[ $recent['operation_ref'] ]['finished_at'] );
		$this->assertSame(
			array(
				array(
					'action'     => 'autodraft',
					'status'     => 'unknown',
					'affected'   => null,
					'error_code' => 'outcome_unknown',
				),
			),
			$stored[ $recent['operation_ref'] ]['outcomes']
		);
	}

	/**
	 * A timeout row whose value option is already gone is a transient that is deleted, not one the
	 * Child was refused. The run clears the leftover row and counts it, instead of reporting a
	 * failure for work nothing is left to do.
	 */
	public function test_orphaned_transient_row_is_cleared_and_counted_instead_of_failing_the_run() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Transient maintenance reports unsupported behind an external object cache.' );
		}
		$this->clear_transients();
		set_transient( 'mwp_maint_a', 'a', -100 );
		set_transient( 'mwp_maint_b', 'b', -100 );
		add_option( '_transient_timeout_mwp_maint_ghost', time() - 100, '', false );

		list( $preview, $result ) = $this->real_execute( $this->real_subject(), array( 'transients_expired' ), 5, '123e4567-e89b-42d3-a456-426614174515' );

		$this->assertSame( 3, $preview['impacts'][0]['would_affect'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame(
			array(
				'action'     => 'transients_expired',
				'status'     => 'succeeded',
				'affected'   => 3,
				'error_code' => null,
			),
			$result['outcomes'][0]
		);
		$this->assertFalse( get_option( '_transient_mwp_maint_a' ) );
		$this->assertFalse( get_option( '_transient_mwp_maint_b' ) );
		$this->assertFalse( get_option( '_transient_timeout_mwp_maint_ghost' ) );
	}

	/**
	 * A transient can be named after the prefix itself, and `_transient_ghost` is stored as
	 * `_transient__transient_ghost`. Stripping every occurrence instead of the leading one turns
	 * that into `ghost`, a different transient the run would delete in its place.
	 */
	public function test_a_transient_named_after_the_prefix_round_trips_through_preview_and_execute() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Transient maintenance reports unsupported behind an external object cache.' );
		}
		$this->clear_transients();
		set_transient( '_transient_ghost', 'target', HOUR_IN_SECONDS );
		set_transient( 'ghost', 'bystander', HOUR_IN_SECONDS );

		list( $preview, $result ) = $this->real_execute( $this->real_subject(), array( 'transients_all' ), 5, '123e4567-e89b-42d3-a456-426614174519' );

		$this->assertSame( 2, $preview['impacts'][0]['would_affect'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( 2, $result['outcomes'][0]['affected'] );
		$this->assertFalse( get_option( '_transient__transient_ghost' ) );
		$this->assertFalse( get_option( '_transient_ghost' ) );
	}

	/**
	 * The legacy maintenance paths strip the same prefixes. Unanchored, an expired transient named
	 * `x_transient_timeout_y` resolves to `xy` and the sweep deletes that unexpired transient
	 * instead, and a `_transient_ghost` row survives a delete-all run for the same reason.
	 */
	public function test_legacy_transient_cleanup_strips_only_the_leading_prefix() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Transient maintenance reports unsupported behind an external object cache.' );
		}
		$this->clear_transients();
		$subject = $this->real_subject();
		set_transient( 'x_transient_timeout_y', 'target', -100 );
		set_transient( 'xy', 'bystander', HOUR_IN_SECONDS );

		$this->invoke_private( $subject, 'maintenance_delete_expired_transients' );

		$this->assertFalse( get_option( '_transient_x_transient_timeout_y' ) );
		$this->assertSame( 'bystander', get_transient( 'xy' ) );

		set_transient( '_transient_ghost', 'target', HOUR_IN_SECONDS );

		$this->invoke_private( $subject, 'maintenance_delete_all_transients' );

		$this->assertFalse( get_option( '_transient__transient_ghost' ) );
		$this->assertFalse( get_option( '_transient_xy' ) );
	}

	public function test_transient_preview_counts_each_transient_once() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Transient maintenance reports unsupported behind an external object cache.' );
		}
		$this->clear_transients();
		set_transient( 'mwp_preview_a', 'a', HOUR_IN_SECONDS );
		set_transient( 'mwp_preview_b', 'b', HOUR_IN_SECONDS );
		set_transient( 'mwp_preview_c', 'c', HOUR_IN_SECONDS );
		set_transient( 'mwp_preview_d', 'd' );

		list( $preview, $result ) = $this->real_execute( $this->real_subject(), array( 'transients_all' ), 5, '123e4567-e89b-42d3-a456-426614174516' );

		$this->assertSame( 4, $preview['impacts'][0]['would_affect'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( $preview['impacts'][0]['would_affect'], $result['outcomes'][0]['affected'] );
	}

	public function test_revision_sweep_batches_its_deletes_and_keeps_the_newest() {
		global $wpdb;
		$parent_id = self::factory()->post->create();
		$revisions = array();
		for ( $index = 0; $index < 20; $index++ ) {
			$when        = gmdate( 'Y-m-d H:i:s', time() - ( 3600 * ( 20 - $index ) ) );
			$revisions[] = wp_insert_post(
				array(
					'post_type'         => 'revision',
					'post_parent'       => $parent_id,
					'post_status'       => 'inherit',
					'post_title'        => 'revision-' . $index,
					'post_name'         => $parent_id . '-revision-' . $index,
					'post_date'         => $when,
					'post_date_gmt'     => $when,
					'post_modified'     => $when,
					'post_modified_gmt' => $when,
				)
			);
		}
		$deletes = array();
		$spy     = static function ( $query ) use ( &$deletes ) {
			if ( 1 === preg_match( '/^\s*DELETE\s+FROM\s+\S*posts\b/i', $query ) ) {
				$deletes[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $spy );
		list( $preview, $result ) = $this->real_execute( $this->real_subject(), array( 'revisions' ), 2, '123e4567-e89b-42d3-a456-426614174517' );
		remove_filter( 'query', $spy );

		$this->assertSame( 18, $preview['impacts'][0]['would_affect'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( 18, $result['outcomes'][0]['affected'] );
		$this->assertLessThanOrEqual( 2, count( $deletes ) );
		$remaining = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_modified DESC, ID DESC", $parent_id ) );
		$this->assertSame( array( $revisions[19], $revisions[18] ), array_map( 'intval', $remaining ) );
	}

	/**
	 * A sweep that pages can run past the 300s mutation lock. Once the lock cannot be renewed the
	 * run has to stop deleting, and still report the rows it destroyed before it stopped.
	 */
	public function test_revision_sweep_stops_when_the_mutation_lock_cannot_be_renewed() {
		global $wpdb;
		$parent_id = self::factory()->post->create();
		for ( $index = 0; $index < 6; $index++ ) {
			$when = gmdate( 'Y-m-d H:i:s', time() - ( 3600 * ( 6 - $index ) ) );
			wp_insert_post(
				array(
					'post_type'         => 'revision',
					'post_parent'       => $parent_id,
					'post_status'       => 'inherit',
					'post_title'        => 'lock-revision-' . $index,
					'post_name'         => $parent_id . '-lock-revision-' . $index,
					'post_date'         => $when,
					'post_date_gmt'     => $when,
					'post_modified'     => $when,
					'post_modified_gmt' => $when,
				)
			);
		}
		$subject = new Renewal_Limited_MainWP_Child_Maintenance();
		// The execute loop renews once before the action, and the first parent page renews once more.
		$subject->renewals_before_failure = 2;

		list( , $result ) = $this->real_execute( $subject, array( 'revisions' ), 2, '123e4567-e89b-42d3-a456-426614174518' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame(
			array(
				'action'     => 'revisions',
				'status'     => 'unknown',
				'affected'   => 4,
				'error_code' => 'outcome_unknown',
			),
			$result['outcomes'][0]
		);
		$this->assertFalse( $result['retryable'] );
		$this->assertSame(
			'2',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d", $parent_id ) ),
			'The rows the outcome reports as destroyed must really be gone.'
		);
	}

	/**
	 * One parent can hold enough surplus to outlive the 300s lock on its own, so the lock is renewed
	 * between its batches too. Once it cannot be renewed the sweep stops mid-parent, and still reports
	 * every row it destroyed before it stopped.
	 */
	public function test_revision_sweep_stops_between_the_batches_of_one_parent_when_the_lock_is_lost() {
		global $wpdb;
		$parent_id = self::factory()->post->create();
		$this->insert_revisions( $parent_id, 503 );

		$subject = new Renewal_Limited_MainWP_Child_Maintenance();
		// The execute loop renews once before the action and the parent page renews once more, which
		// leaves this parent's first batch as the last one that runs under a lock it can prove it holds.
		$subject->renewals_before_failure = 2;

		list( , $result ) = $this->real_execute( $subject, array( 'revisions' ), 2, '123e4567-e89b-42d3-a456-426614174519' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame(
			array(
				'action'     => 'revisions',
				'status'     => 'unknown',
				'affected'   => MainWP_Child_Maintenance::ABILITIES_V2_REVISION_DELETE_BATCH,
				'error_code' => 'outcome_unknown',
			),
			$result['outcomes'][0]
		);
		$this->assertSame(
			'3',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d", $parent_id ) ),
			'The sweep must stop at the batch it lost the lock on instead of running this parent to the end.'
		);
	}

	/** Fill one parent with revisions cheaply; the sweep only ever reads them through SQL. */
	private function insert_revisions( $parent_id, $count ) {
		global $wpdb;
		for ( $index = 0; $index < $count; $index++ ) {
			$when = gmdate( 'Y-m-d H:i:s', time() - ( 60 * ( $count - $index ) ) );
			$wpdb->insert(
				$wpdb->posts,
				array(
					'post_author'           => 1,
					'post_date'             => $when,
					'post_date_gmt'         => $when,
					'post_content'          => 'batch revision',
					'post_title'            => 'batch-revision-' . $index,
					'post_excerpt'          => '',
					'post_status'           => 'inherit',
					'comment_status'        => 'closed',
					'ping_status'           => 'closed',
					'post_password'         => '',
					'post_name'             => $parent_id . '-batch-revision-' . $index,
					'to_ping'               => '',
					'pinged'                => '',
					'post_modified'         => $when,
					'post_modified_gmt'     => $when,
					'post_content_filtered' => '',
					'post_parent'           => $parent_id,
					'guid'                  => '',
					'menu_order'            => 0,
					'post_type'             => 'revision',
					'post_mime_type'        => '',
				)
			);
		}
	}

	private function invoke_private( $subject, $method ) {
		$reflection = new \ReflectionMethod( MainWP_Child_Maintenance::class, $method );
		$reflection->setAccessible( true );
		$reflection->invoke( $subject );
	}

	private function real_subject() {
		$reflection = new ReflectionClass( MainWP_Child_Maintenance::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	private function real_request( $subject, $operation, $payload ) {
		return $subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => $operation,
				'payload'   => $payload,
			)
		);
	}

	/**
	 * Preview then execute one action set through the real class, as the Dashboard does.
	 *
	 * @return array Preview response and execute response.
	 */
	private function real_execute( $subject, $actions, $retention, $operation_ref ) {
		$catalog   = array( 'revisions', 'autodraft', 'trashpost', 'spam', 'pending', 'trashcomment', 'tags', 'categories', 'optimize', 'transients_expired', 'transients_all' );
		$canonical = array_values(
			array_filter(
				$catalog,
				static function ( $action ) use ( $actions ) {
					return in_array( $action, $actions, true );
				}
			)
		);
		$preview   = $this->real_request(
			$subject,
			'ability_maintenance_preview_v2',
			array(
				'actions'            => $actions,
				'revision_retention' => $retention,
			)
		);
		$this->assertTrue( $preview['ok'] );
		$result = $this->real_request(
			$subject,
			'ability_maintenance_execute_v2',
			array(
				'operation_ref'      => $operation_ref,
				'actions'            => $actions,
				'revision_retention' => $retention,
				'snapshot_revision'  => $preview['snapshot_revision'],
				'action_hash'        => hash( 'sha256', wp_json_encode( array( $canonical, $retention ) ) ),
			)
		);
		return array( $preview, $result );
	}

	/** Build one durable record left in 'running' by a run that never came back. */
	private function running_record( $index, $age ) {
		$operation_ref = sprintf( '123e4567-e89b-42d3-a456-%012d', $index );
		$actions       = array( 'autodraft' );
		$retention     = 5;
		$snapshot      = str_repeat( 'b', 64 );
		$action_hash   = hash( 'sha256', wp_json_encode( array( $actions, $retention ) ) );
		$at            = time() - $age;
		return array(
			'operation_ref'      => $operation_ref,
			'effect_hash'        => hash( 'sha256', wp_json_encode( array( $operation_ref, $actions, $retention, $snapshot, $action_hash ) ) ),
			'action_hash'        => $action_hash,
			'snapshot_revision'  => $snapshot,
			'actions'            => $actions,
			'revision_retention' => $retention,
			'status'             => 'running',
			'outcomes'           => array(),
			'accepted_at'        => $at,
			'finished_at'        => null,
			'retryable'          => false,
			'report_emitted'     => false,
			'updated_at'         => $at,
		);
	}

	private function clear_transients() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%' ) );
		wp_cache_flush();
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

/** Real maintenance behaviour, with a mutation lock that stops renewing after a set number of calls. */
class Renewal_Limited_MainWP_Child_Maintenance extends MainWP_Child_Maintenance {

	public $renewals_before_failure = 0;
	public $renewal_calls = 0;

	protected function abilities_v2_renew_mutation() {
		++$this->renewal_calls;
		return $this->renewal_calls <= $this->renewals_before_failure && parent::abilities_v2_renew_mutation();
	}
}
