<?php
/**
 * File Uploader deployment protocol-v2 tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_MainWP_Child_File_Deployment_V2 extends WP_UnitTestCase {

	public function test_preflight_is_closed_read_only_and_rejects_hostile_paths() {
		$subject = new Testable_MainWP_Child_File_Deployment();
		$result  = $subject->preflight_v2( $this->preflight_request() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'request_ref', 'relative_destination', 'destination_class', 'target_exists', 'prior_bytes', 'prior_sha256', 'prior_mode', 'writable', 'rollback_available', 'state_revision' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'wp-content/uploads/report.txt', $result['relative_destination'] );
		$this->assertFalse( $result['target_exists'] );
		$this->assertTrue( $result['rollback_available'] );
		$this->assertSame( 0, $subject->writes );

		$request = $this->preflight_request();
		$request['payload']['relative_destination'] = 'wp-content/uploads/../wp-config.php';
		$this->assertSame( 'destination_forbidden', $subject->preflight_v2( $request )['code'] );
		$request['payload']['relative_destination'] = 'wp-content/plugins/acme/shell.phpfile.txt';
		$request['payload']['destination_class']     = 'plugins';
		$result                                      = $subject->preflight_v2( $request );
		$this->assertSame( 'wp-content/plugins/acme/shell.php', $result['relative_destination'] );
	}

	public function test_deploy_reserves_once_verifies_digest_and_replays_without_redispatch() {
		$subject = new Testable_MainWP_Child_File_Deployment();
		$bytes   = 'verified fixture bytes';
		$subject->gateway_bytes = $bytes;
		$preflight              = $subject->preflight_v2( $this->preflight_request() );
		$request                = $this->deploy_request( $preflight, $bytes );

		$result = $subject->deploy_v2( $request );
		$this->assertSame( array( 'protocol', 'operation', 'ok', 'request_ref', 'status', 'bytes', 'sha256', 'rollback_available', 'code' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 1, $subject->gateway_reads );
		$this->assertSame( 1, $subject->writes );
		$this->assertSame( $bytes, $subject->target_bytes );
		$this->assertStringNotContainsString( 'one-use-private-token', wp_json_encode( $subject->receipts ) );

		$this->assertSame( $result, $subject->deploy_v2( $request ) );
		$this->assertSame( 1, $subject->gateway_reads );
		$this->assertSame( 1, $subject->writes );

		$status = $subject->deployment_state_v2( $this->status_request( $request['payload']['request_ref'] ) );
		$this->assertSame( $result, $status );
	}

	public function test_dispatching_receipt_is_unknown_and_never_blindly_retries() {
		$subject = new Testable_MainWP_Child_File_Deployment();
		$bytes   = 'verified fixture bytes';
		$subject->gateway_bytes = $bytes;
		$preflight              = $subject->preflight_v2( $this->preflight_request() );
		$request                = $this->deploy_request( $preflight, $bytes );
		$subject->seed_dispatching( $request );

		$result = $subject->deploy_v2( $request );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertSame( 0, $subject->gateway_reads );
		$this->assertSame( 0, $subject->writes );

		$status = $subject->deployment_state_v2( $this->status_request( $request['payload']['request_ref'] ) );
		$this->assertSame( 'unknown', $status['status'] );
		$this->assertSame( 0, $subject->gateway_reads );
	}

	public function test_destination_lock_contention_is_non_mutating() {
		$subject                 = new Testable_MainWP_Child_File_Deployment();
		$subject->lock_available = false;
		$bytes                   = 'verified fixture bytes';
		$subject->gateway_bytes  = $bytes;
		$preflight               = $subject->preflight_v2( $this->preflight_request() );
		$result                  = $subject->deploy_v2( $this->deploy_request( $preflight, $bytes ) );

		$this->assertSame( 'lock_busy', $result['code'] );
		$this->assertSame( 0, $subject->gateway_reads );
		$this->assertSame( 0, $subject->writes );
	}

	public function test_digest_failure_preserves_prior_target_and_settles_failed() {
		$subject               = new Testable_MainWP_Child_File_Deployment();
		$subject->target_exists = true;
		$subject->target_bytes  = 'prior bytes';
		$subject->gateway_bytes = 'wrong bytes';
		$preflight              = $subject->preflight_v2( $this->preflight_request() );
		$request                = $this->deploy_request( $preflight, 'expected bytes' );

		$result = $subject->deploy_v2( $request );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'digest_mismatch', $result['code'] );
		$this->assertSame( 'prior bytes', $subject->target_bytes );
		$this->assertSame( 0, $subject->writes );
	}

	public function test_rollback_restores_prior_bytes_and_refuses_current_drift() {
		$subject               = new Testable_MainWP_Child_File_Deployment();
		$subject->target_exists = true;
		$subject->target_bytes  = 'prior bytes';
		$subject->gateway_bytes = 'deployed bytes';
		$preflight              = $subject->preflight_v2( $this->preflight_request() );
		$deploy                 = $this->deploy_request( $preflight, 'deployed bytes' );
		$this->assertTrue( $subject->deploy_v2( $deploy )['ok'] );

		$rollback = $this->rollback_request( $deploy['payload']['request_ref'], hash( 'sha256', 'deployed bytes' ) );
		$result   = $subject->rollback_v2( $rollback );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'rolled_back', $result['status'] );
		$this->assertSame( 'prior bytes', $subject->target_bytes );

		$drifted               = new Testable_MainWP_Child_File_Deployment();
		$drifted->target_exists = true;
		$drifted->target_bytes  = 'prior bytes';
		$drifted->gateway_bytes = 'deployed bytes';
		$preview                = $drifted->preflight_v2( $this->preflight_request() );
		$deploy                 = $this->deploy_request( $preview, 'deployed bytes' );
		$this->assertTrue( $drifted->deploy_v2( $deploy )['ok'] );
		$drifted->target_bytes = 'external drift';
		$result                = $drifted->rollback_v2( $this->rollback_request( $deploy['payload']['request_ref'], hash( 'sha256', 'deployed bytes' ) ) );
		$this->assertSame( 'stale_revision', $result['code'] );
		$this->assertSame( 'external drift', $drifted->target_bytes );
	}

	public function test_uuid_id_aliases_and_malformed_gateway_are_rejected() {
		$subject = new Testable_MainWP_Child_File_Deployment();
		$request = $this->preflight_request();
		$request['payload']['request_id'] = $request['payload']['request_ref'];
		unset( $request['payload']['request_ref'] );
		$this->assertSame( 'invalid_request', $subject->preflight_v2( $request )['code'] );

		$subject->gateway_bytes = 'bytes';
		$preflight              = $subject->preflight_v2( $this->preflight_request() );
		$deploy                 = $this->deploy_request( $preflight, 'bytes' );
		$deploy['payload']['gateway_url'] = 'https://attacker.example/private';
		$this->assertSame( 'gateway_rejected', $subject->deploy_v2( $deploy )['code'] );
		$this->assertSame( 0, $subject->gateway_reads );
	}

	public function test_authenticated_callable_map_exposes_the_four_v2_operations() {
		$callable   = MainWP_Child_Callable::get_instance();
		$reflection = new \ReflectionClass( $callable );
		$property   = $reflection->getProperty( 'callableFunctions' );
		$property->setAccessible( true );
		$map = $property->getValue( $callable );

		$this->assertSame( 'uploader_preflight_v2', $map['uploader_preflight_v2'] );
		$this->assertSame( 'uploader_deploy_v2', $map['uploader_deploy_v2'] );
		$this->assertSame( 'uploader_deployment_state_v2', $map['uploader_deployment_state_v2'] );
		$this->assertSame( 'uploader_rollback_v2', $map['uploader_rollback_v2'] );
	}

	private function preflight_request() {
		return array(
			'protocol'  => '2',
			'operation' => 'preflight',
			'payload'   => array(
				'request_ref'         => '123e4567-e89b-42d3-a456-426614174200',
				'destination_class'   => 'uploads',
				'relative_destination' => 'wp-content/uploads/report.txt',
			),
		);
	}

	private function deploy_request( $preflight, $bytes ) {
		return array(
			'protocol'  => '2',
			'operation' => 'deploy',
			'payload'   => array(
				'request_ref'         => '123e4567-e89b-42d3-a456-426614174201',
				'object_ref'          => '123e4567-e89b-42d3-a456-426614174202',
				'destination_class'   => $preflight['destination_class'],
				'relative_destination' => $preflight['relative_destination'],
				'expected_bytes'      => strlen( $bytes ),
				'expected_sha256'     => hash( 'sha256', $bytes ),
				'gateway_url'         => 'https://dashboard.example/mainwp-file-object',
				'gateway_token'       => 'one-use-private-token',
				'state_revision'      => $preflight['state_revision'],
				'expires_at'          => time() + 300,
			),
		);
	}

	private function status_request( $request_ref ) {
		return array( 'protocol' => '2', 'operation' => 'status', 'payload' => array( 'request_ref' => $request_ref ) );
	}

	private function rollback_request( $deployment_ref, $digest ) {
		return array(
			'protocol'  => '2',
			'operation' => 'rollback',
			'payload'   => array(
				'request_ref'       => '123e4567-e89b-42d3-a456-426614174203',
				'deployment_ref'    => $deployment_ref,
				'if_current_sha256' => $digest,
				'expires_at'        => time() + 300,
			),
		);
	}
}

class Testable_MainWP_Child_File_Deployment extends MainWP_Child_File_Deployment {

	public $target_exists = false;
	public $target_bytes = '';
	public $target_mode = 0644;
	public $gateway_bytes = '';
	public $gateway_reads = 0;
	public $writes = 0;
	public $receipts = array();
	public $backups = array();
	public $lock_available = true;

	protected function target_snapshot( $destination_class, $relative_destination ) {
		unset( $destination_class, $relative_destination );
		return array(
			'exists' => $this->target_exists,
			'bytes'  => $this->target_exists ? strlen( $this->target_bytes ) : null,
			'sha256' => $this->target_exists ? hash( 'sha256', $this->target_bytes ) : null,
			'mode'   => $this->target_exists ? $this->target_mode : null,
			'writable' => true,
			'rollback_available' => true,
		);
	}

	protected function gateway_allowed( $url ) {
		return 'https://dashboard.example/mainwp-file-object' === $url;
	}

	protected function download_gateway_bytes( $url, $token, $expected_bytes ) {
		unset( $url, $token, $expected_bytes );
		++$this->gateway_reads;
		return $this->gateway_bytes;
	}

	protected function apply_deployment( $destination_class, $relative_destination, $bytes, $before ) {
		unset( $destination_class, $relative_destination );
		++$this->writes;
		if ( $before['exists'] ) {
			$this->backups['fixture-backup'] = $this->target_bytes;
		}
		$this->target_exists = true;
		$this->target_bytes  = $bytes;
		return array( 'backup_ref' => $before['exists'] ? 'fixture-backup' : null );
	}

	protected function apply_rollback( $receipt ) {
		++$this->writes;
		$this->target_exists = $receipt['prior_exists'];
		$this->target_bytes  = $receipt['prior_exists'] && isset( $this->backups[ $receipt['backup_ref'] ] ) ? $this->backups[ $receipt['backup_ref'] ] : '';
		return true;
	}

	protected function load_receipt( $request_ref ) {
		return isset( $this->receipts[ $request_ref ] ) ? $this->receipts[ $request_ref ] : null;
	}

	protected function create_receipt( $request_ref, $receipt ) {
		if ( isset( $this->receipts[ $request_ref ] ) ) {
			return false;
		}
		$this->receipts[ $request_ref ] = $receipt;
		return true;
	}

	protected function settle_receipt( $request_ref, $expected, $receipt ) {
		if ( ! isset( $this->receipts[ $request_ref ] ) || $expected !== $this->receipts[ $request_ref ] ) {
			return false;
		}
		$this->receipts[ $request_ref ] = $receipt;
		return true;
	}

	protected function acquire_destination_lock( $destination_class, $relative_destination ) {
		unset( $destination_class, $relative_destination );
		return $this->lock_available;
	}

	protected function release_destination_lock( $lock ) {
		unset( $lock );
	}

	public function seed_dispatching( $request ) {
		$this->receipts[ $request['payload']['request_ref'] ] = $this->dispatching_receipt_for_test( $request['payload'] );
	}
}
