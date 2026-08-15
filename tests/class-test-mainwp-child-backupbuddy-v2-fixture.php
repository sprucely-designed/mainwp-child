<?php

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.Files.FileName.InvalidClassFileName -- The focused fixture and its test case intentionally share this integration-test file.
/**
 * BackupBuddy abilities v2 protocol tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Back_Up_Buddy;

/** Deterministic BackupBuddy v2 boundary fixture. */
class Test_MainWP_Child_BackupBuddy_V2_Fixture extends MainWP_Child_Back_Up_Buddy {

	/** @var int */
	public $now = 1786300000;

	/** @var array */
	public $options = array();

	/** @var array */
	public $archives = array();

	/** @var array */
	public $records = array();

	/** @var array */
	public $effects = array();

	/** @var int */
	public $writes = 0;

	/** @var bool */
	public $lock_busy = false;

	/** @var bool */
	public $lock_held = false;

	/** @var bool */
	public $delete_saw_pending = false;

	/** @var bool */
	public $transfer_result = true;

	/** Avoid product hooks in the isolated fixture. */
	public function __construct() {
		$this->is_backupbuddy_installed = true;
	}

	/** @return int */
	protected function abilities_v2_now() {
		return $this->now;
	}

	/** @return string */
	protected function abilities_v2_secret() {
		return 'fixture-secret-without-private-provider-data';
	}

	/** @return array */
	protected function abilities_v2_read_options() {
		return $this->options;
	}

	/** @return array */
	protected function abilities_v2_archive_candidates() {
		return $this->archives;
	}

	/** @return array */
	protected function abilities_v2_read_records() {
		return $this->records;
	}

	/**
	 * @param array $records Records.
	 * @return bool
	 */
	protected function abilities_v2_write_records( $records ) {
		++$this->writes;
		$this->records = $records;
		return true;
	}

	/** @return string|false */
	protected function abilities_v2_acquire_effect_lock() {
		if ( $this->lock_busy || $this->lock_held ) {
			return false;
		}
		$this->lock_held = true;
		return 'fixture-lock-owner';
	}

	/**
	 * @param string $owner Lock owner.
	 * @return bool
	 */
	protected function abilities_v2_release_effect_lock( $owner ) {
		if ( ! $this->lock_held || 'fixture-lock-owner' !== $owner ) {
			return false;
		}
		$this->lock_held = false;
		return true;
	}

	/**
	 * @param array  $profile Profile.
	 * @param string $serial  Serial.
	 * @param array  $steps   Post-backup steps.
	 * @return bool
	 */
	protected function abilities_v2_start_backup_effect( $profile, $serial, $steps ) {
		$this->effects[] = array( 'backup', $profile['type'], $serial, $steps );
		return true;
	}

	/**
	 * @param array $record Record.
	 * @return array
	 */
	protected function abilities_v2_probe_operation( $record ) {
		return $record;
	}

	/**
	 * @param string $serial Serial.
	 * @return bool
	 */
	protected function abilities_v2_set_stop_signal( $serial ) {
		$this->effects[] = array( 'cancel', $serial );
		return true;
	}

	/**
	 * @param array $archive Archive.
	 * @return array
	 */
	protected function abilities_v2_delete_archive_effect( $archive ) {
		$receipts                 = isset( $this->records['receipts'] ) && is_array( $this->records['receipts'] ) ? array_values( $this->records['receipts'] ) : array();
		$this->delete_saw_pending = 1 === count( $receipts ) && isset( $receipts[0]['state'] ) && 'pending' === $receipts[0]['state'];
		$this->effects[]          = array( 'delete', $archive['internal_id'] );
		foreach ( $this->archives as $index => $candidate ) {
			if ( $candidate['internal_id'] === $archive['internal_id'] ) {
				unset( $this->archives[ $index ] );
			}
		}
		$this->archives = array_values( $this->archives );
		return array(
			'deleted'                   => true,
			'auxiliary_records_removed' => 2,
		);
	}

	/**
	 * @param array  $archive     Archive.
	 * @param string $destination Destination identifier.
	 * @param string $serial      Serial.
	 * @return bool
	 */
	protected function abilities_v2_start_transfer_effect( $archive, $destination, $serial ) {
		$this->effects[] = array( 'transfer', $archive['internal_id'], $destination, $serial );
		return $this->transfer_result;
	}
}

/** BackupBuddy v2 protocol contract tests. */
class Test_MainWP_Child_BackupBuddy_Abilities_V2 extends WP_UnitTestCase {

	/** @var string */
	private $request_ref = '123e4567-e89b-42d3-a456-426614174000';

	/**
	 * @return Test_MainWP_Child_BackupBuddy_V2_Fixture
	 */
	private function fixture() {
		$fixture           = new Test_MainWP_Child_BackupBuddy_V2_Fixture();
		$fixture->options  = array(
			'archive_limit'       => 1,
			'archive_limit_full'  => 1,
			'profiles'            => array(
				0 => array(
					'title' => 'Full',
					'type'  => 'full',
				),
				4 => array(
					'title' => 'Database',
					'type'  => 'db',
				),
			),
			'schedules'           => array(
				8 => array(
					'title'               => 'Nightly',
					'profile'             => 0,
					'interval'            => 'daily',
					'last_run'            => 1786200000,
					'remote_destinations' => array( 3 ),
					'delete_after'        => false,
				),
			),
			'remote_destinations' => array(
				3 => array(
					'title'     => 'Offsite',
					'type'      => 's3',
					'accesskey' => 'PRIVATE-ACCESS',
					'secretkey' => 'PRIVATE-SECRET',
					'bucket'    => 'private-bucket',
				),
			),
		);
		$fixture->archives = array(
			array(
				'internal_id' => 'backup-old-full.zip',
				'type'        => 'full',
				'size_bytes'  => 100,
				'modified_at' => 1786100000,
				'status'      => 'complete',
				'path'        => '/private/backup-old-full.zip',
			),
			array(
				'internal_id' => 'backup-new-full.zip',
				'type'        => 'full',
				'size_bytes'  => 200,
				'modified_at' => 1786200000,
				'status'      => 'complete',
				'path'        => '/private/backup-new-full.zip',
			),
		);
		return $fixture;
	}

	/**
	 * @param MainWP_Child_Back_Up_Buddy $fixture Fixture.
	 * @param array                       $request Request.
	 * @return array
	 */
	private function dispatch( $fixture, $request ) {
		$this->assertTrue( method_exists( $fixture, 'abilities_v2' ) );
		return $fixture->abilities_v2( $request );
	}

	/**
	 * @param array $profiles Profile list response.
	 * @return string
	 */
	private function full_profile_ref( $profiles ) {
		foreach ( $profiles['profiles'] as $profile ) {
			if ( 'full' === $profile['type'] ) {
				return $profile['profile_ref'];
			}
		}
		$this->fail( 'The fixture did not expose a full profile.' );
	}

	/** Capability and malformed request envelopes are closed. */
	public function test_capabilities_and_closed_request_validation() {
		$fixture = $this->fixture();
		$result  = $this->dispatch( $fixture, array( 'operation' => 'capabilities' ) );
		$this->assertSame( array( 'protocol', 'operations' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertContains( 'start_transfer', $result['operations'] );
		$this->assertSame( 0, $fixture->writes );

		$result = $this->dispatch(
			$fixture,
			array(
				'operation' => 'capabilities',
				'extra'     => 'PRIVATE',
			)
		);
		$this->assertSame( 'invalid_request', $result['error']['code'] );
		$this->assertStringNotContainsString( 'PRIVATE', wp_json_encode( $result ) );

		$result = $this->dispatch(
			$fixture,
			array(
				'per_page'  => 25,
				'operation' => 'list_profiles',
				'page'      => 1,
			)
		);
		$this->assertSame( 2, $result['total'], 'JSON object member order must not affect validation.' );
	}

	/** List projections are bounded, opaque, stable, and secret-free. */
	public function test_list_projections_are_read_only_and_redacted() {
		$fixture      = $this->fixture();
		$profiles     = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$schedules    = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_schedules',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$destinations = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_destinations',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$archives     = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		);

		$this->assertSame( 2, $profiles['total'] );
		$this->assertStringStartsWith( 'prf.v1.', $profiles['profiles'][0]['profile_ref'] );
		$this->assertStringStartsWith( 'sch.v1.', $schedules['schedules'][0]['schedule_ref'] );
		$this->assertStringStartsWith( 'dst.v1.', $destinations['destinations'][0]['destination_ref'] );
		$this->assertStringStartsWith( 'arc.v1.', $archives['archives'][0]['archive_ref'] );
		$this->assertStringNotContainsString( 'PRIVATE', wp_json_encode( array( $profiles, $schedules, $destinations, $archives ) ) );
		$this->assertStringNotContainsString( '.zip', wp_json_encode( $archives ) );
		$this->assertSame( 0, $fixture->writes );
	}

	/** Preview is pure and binds the exact retention and schedule snapshot. */
	public function test_preview_is_pure_and_snapshot_bound() {
		$fixture     = $this->fixture();
		$profiles    = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$profile_ref = $this->full_profile_ref( $profiles );
		$preview     = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $profile_ref,
			)
		);

		$this->assertSame( 'full', $preview['backup_type'] );
		$this->assertCount( 1, $preview['retention_delete_archive_refs'] );
		$this->assertStringStartsWith( 'pre.v1.', $preview['preview_token'] );
		$this->assertSame( $fixture->now + 600, $preview['expires_at'] );
		$this->assertArrayNotHasKey( '_snapshot', $preview );
		$this->assertSame( 0, $fixture->writes );

		++$fixture->archives[0]['modified_at'];
		$result = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'start_backup',
				'profile_ref'   => $profile_ref,
				'preview_token' => $preview['preview_token'],
				'request_ref'   => $this->request_ref,
			)
		);
		$this->assertSame( 'preview_stale', $result['error']['code'] );
		$this->assertSame( array(), $fixture->effects );
	}

	/** Preview applies age/count/size bounds and rejects unsupported destinations. */
	public function test_preview_retention_and_schedule_destination_bounds() {
		$fixture                                = $this->fixture();
		$fixture->options['archive_limit']      = 0;
		$fixture->options['archive_limit_full'] = 0;
		$fixture->options['archive_limit_age']  = 1;
		$profiles                               = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$profile_ref                            = $this->full_profile_ref( $profiles );
		$preview                                = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $profile_ref,
			)
		);
		$this->assertCount( 1, $preview['retention_delete_archive_refs'] );

		$fixture->options['archive_limit_age']      = 0;
		$fixture->options['archive_limit_size']     = 1;
		$fixture->options['archive_limit_size_big'] = 0;
		$fixture->archives[0]['size_bytes']         = 2 * MB_IN_BYTES;
		$fixture->archives[1]['size_bytes']         = 2 * MB_IN_BYTES;
		$preview                                    = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $profile_ref,
			)
		);
		$this->assertCount( 2, $preview['retention_delete_archive_refs'] );

		$schedules    = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_schedules',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$schedule_ref = $schedules['schedules'][0]['schedule_ref'];
		unset( $fixture->options['remote_destinations'][3]['bucket'] );
		$result = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'schedule',
				'target_ref'  => $schedule_ref,
			)
		);
		$this->assertSame( 'request_conflict', $result['error']['code'] );

		$fixture = $this->fixture();
		$fixture->options['schedules'][8]['remote_destinations'] = range( 1, 11 );
		$schedule_ref = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_schedules',
				'page'      => 1,
				'per_page'  => 25,
			)
		)['schedules'][0]['schedule_ref'];
		$result       = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'schedule',
				'target_ref'  => $schedule_ref,
			)
		);
		$this->assertSame( 'too_large', $result['error']['code'] );
	}

	/** Start, replay, status, and cancellation use one durable operation identity. */
	public function test_start_replay_status_and_cancel_state_machine() {
		$fixture     = $this->fixture();
		$profiles    = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$profile_ref = $this->full_profile_ref( $profiles );
		$preview     = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $profile_ref,
			)
		);
		$request     = array(
			'operation'     => 'start_backup',
			'profile_ref'   => $profile_ref,
			'preview_token' => $preview['preview_token'],
			'request_ref'   => $this->request_ref,
		);
		$started     = $this->dispatch( $fixture, $request );
		$replay      = $this->dispatch( $fixture, $request );

		$this->assertSame( $started, $replay );
		$this->assertSame( 'queued', $started['operation']['state'] );
		$this->assertStringStartsWith( 'op.v1.', $started['operation']['operation_ref'] );
		$this->assertCount( 1, $fixture->effects );

		$status = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'get_operation',
				'operation_ref' => $started['operation']['operation_ref'],
			)
		);
		$this->assertSame( 'queued', $status['operation']['state'] );
		$cancel = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'cancel_operation',
				'operation_ref' => $started['operation']['operation_ref'],
			)
		);
		$this->assertSame( 'cancel_requested', $cancel['operation']['state'] );
		$this->assertSame( 'cancel_not_immediate', $cancel['operation']['warning_codes'][0] );
	}

	/** Same request reference with a different effect is a stable conflict. */
	public function test_request_reference_hash_conflict() {
		$fixture     = $this->fixture();
		$profiles    = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$profile_ref = $this->full_profile_ref( $profiles );
		$preview     = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $profile_ref,
			)
		);
		$this->dispatch(
			$fixture,
			array(
				'operation'     => 'start_backup',
				'profile_ref'   => $profile_ref,
				'preview_token' => $preview['preview_token'],
				'request_ref'   => $this->request_ref,
			)
		);

		$result = $this->dispatch(
			$fixture,
			array(
				'operation'          => 'start_transfer',
				'archive_ref'        => $this->dispatch(
					$fixture,
					array(
						'operation' => 'list_archives',
						'page'      => 1,
						'per_page'  => 25,
						'type'      => 'all',
					)
				)['archives'][0]['archive_ref'],
				'destination_ref'    => $this->dispatch(
					$fixture,
					array(
						'operation' => 'list_destinations',
						'page'      => 1,
						'per_page'  => 25,
					)
				)['destinations'][0]['destination_ref'],
				'request_ref'        => $this->request_ref,
				'delete_local_after' => false,
			)
		);
		$this->assertSame( 'request_conflict', $result['error']['code'] );
	}

	/** Archive delete is metadata-bound, verified, replay-safe, and exact. */
	public function test_archive_delete_and_replay() {
		$fixture = $this->fixture();
		$archive = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		)['archives'][0];
		$request = array(
			'operation'            => 'delete_archive',
			'archive_ref'          => $archive['archive_ref'],
			'expected_size_bytes'  => $archive['size_bytes'],
			'expected_modified_at' => $archive['modified_at'],
			'request_ref'          => $this->request_ref,
		);
		$deleted = $this->dispatch( $fixture, $request );
		$replay  = $this->dispatch( $fixture, $request );

		$this->assertSame( $deleted, $replay );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertFalse( $deleted['already_absent'] );
		$this->assertSame( 2, $deleted['auxiliary_records_removed'] );
		$this->assertCount( 1, $fixture->archives );
	}

	/** Transfer schedules once and never requests local deletion. */
	public function test_transfer_is_replay_safe_and_never_deletes_local() {
		$fixture     = $this->fixture();
		$archive     = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		)['archives'][0];
		$destination = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_destinations',
				'page'      => 1,
				'per_page'  => 25,
			)
		)['destinations'][0];
		$request     = array(
			'operation'          => 'start_transfer',
			'archive_ref'        => $archive['archive_ref'],
			'destination_ref'    => $destination['destination_ref'],
			'request_ref'        => $this->request_ref,
			'delete_local_after' => false,
		);
		$first       = $this->dispatch( $fixture, $request );
		$replay      = $this->dispatch( $fixture, $request );

		$this->assertSame( $first, $replay );
		$this->assertSame( 'transfer', $first['operation']['kind'] );
		$this->assertCount( 1, $fixture->effects );
		$this->assertSame( 'transfer', $fixture->effects[0][0] );
		$this->assertCount( 2, $fixture->archives );
	}

	/** Mutations serialize, deletion is staged, and effect failure is durable. */
	public function test_mutation_lock_staging_and_failure_state() {
		$fixture            = $this->fixture();
		$fixture->lock_busy = true;
		$archive            = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		)['archives'][0];
		$delete_request     = array(
			'operation'            => 'delete_archive',
			'archive_ref'          => $archive['archive_ref'],
			'expected_size_bytes'  => $archive['size_bytes'],
			'expected_modified_at' => $archive['modified_at'],
			'request_ref'          => $this->request_ref,
		);
		$busy               = $this->dispatch( $fixture, $delete_request );
		$this->assertSame( 'lock_busy', $busy['error']['code'] );
		$this->assertSame( array(), $fixture->effects );

		$fixture->lock_busy = false;
		$deleted            = $this->dispatch( $fixture, $delete_request );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertTrue( $fixture->delete_saw_pending, 'The durable pending receipt must precede the file effect.' );
		$this->assertFalse( $fixture->lock_held );

		$fixture                  = $this->fixture();
		$fixture->transfer_result = false;
		$archive                  = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		)['archives'][0];
		$destination              = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_destinations',
				'page'      => 1,
				'per_page'  => 25,
			)
		)['destinations'][0];
		$request                  = array(
			'operation'          => 'start_transfer',
			'archive_ref'        => $archive['archive_ref'],
			'destination_ref'    => $destination['destination_ref'],
			'request_ref'        => $this->request_ref,
			'delete_local_after' => false,
		);
		$failed                   = $this->dispatch( $fixture, $request );
		$this->assertSame( 'effect_failed', $failed['error']['code'] );
		$replay = $this->dispatch( $fixture, $request );
		$this->assertSame( 'failed', $replay['operation']['state'] );
	}

	/** Malformed durable state fails closed instead of being normalized away. */
	public function test_corrupt_operation_ledger_fails_closed() {
		$fixture          = $this->fixture();
		$fixture->records = array(
			'operations' => array( 'bad' => array( 'request_ref' => $this->request_ref ) ),
			'receipts'   => array(),
		);
		$result           = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'get_operation',
				'operation_ref' => str_repeat( 'a', 24 ),
			)
		);
		$this->assertSame( 'storage_failed', $result['error']['code'] );
	}

	/** Tampered refs, invalid UUID names, and private input never escape. */
	public function test_tamper_bounds_and_redacted_errors() {
		$fixture  = $this->fixture();
		$profiles = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$tampered = $profiles['profiles'][0]['profile_ref'] . 'x';
		$result   = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $tampered,
			)
		);
		$this->assertSame( 'not_found', $result['error']['code'] );

		$result = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'start_backup',
				'profile_ref'   => $profiles['profiles'][0]['profile_ref'],
				'preview_token' => str_repeat( 'PRIVATE', 100 ),
				'request_ref'   => 'bad-id',
			)
		);
		$this->assertSame( 'invalid_request', $result['error']['code'] );
		$this->assertStringNotContainsString( 'PRIVATE', wp_json_encode( $result ) );
	}
}
