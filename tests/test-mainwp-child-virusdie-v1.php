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

	public function test_capabilities_advertise_only_the_narrow_operations() {
		$result = ( new Testable_MainWP_Child_Virusdie() )->request_v1( $this->request( 'capabilities', array() ) );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'max_artifact_bytes' ), array_keys( $result ) );
		$this->assertSame( array( 'install', 'status', 'remove' ), $result['operations'] );
		$this->assertSame( 262144, $result['max_artifact_bytes'] );
	}

	public function test_install_reserves_downloads_once_and_replays_truth() {
		$subject                = new Testable_MainWP_Child_Virusdie();
		$subject->gateway_bytes = '<?php // signed fixture';
		$request                = $this->install_request( $subject->gateway_bytes );

		$result = $subject->request_v1( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertTrue( $result['installed'] );
		$this->assertSame( 1, $subject->gateway_reads );
		$this->assertSame( 1, $subject->installs );
		$this->assertStringNotContainsString( 'one-use-private-token', wp_json_encode( $subject->receipts ) );

		$this->assertSame( $result, $subject->request_v1( $request ) );
		$this->assertSame( 1, $subject->gateway_reads );
		$this->assertSame( 1, $subject->installs );
		$this->assertSame( $result, $subject->request_v1( $this->request( 'status', array( 'request_ref' => $request['payload']['request_ref'] ) ) ) );
	}

	public function test_dispatching_install_is_unknown_without_blind_retry() {
		$subject                = new Testable_MainWP_Child_Virusdie();
		$subject->gateway_bytes = '<?php // signed fixture';
		$request                = $this->install_request( $subject->gateway_bytes );
		$subject->seed_dispatching( $request );

		$result = $subject->request_v1( $request );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertSame( 0, $subject->gateway_reads );
		$this->assertSame( 0, $subject->installs );
	}

	public function test_digest_failure_and_existing_target_are_non_mutating() {
		$subject                = new Testable_MainWP_Child_Virusdie();
		$subject->gateway_bytes = 'wrong';
		$result                 = $subject->request_v1( $this->install_request( 'expected' ) );
		$this->assertSame( 'digest_mismatch', $result['code'] );
		$this->assertSame( 0, $subject->installs );

		$existing                = new Testable_MainWP_Child_Virusdie();
		$existing->target_exists = true;
		$existing->target_bytes  = 'existing';
		$result                  = $existing->request_v1( $this->install_request( 'expected' ) );
		$this->assertSame( 'target_exists', $result['code'] );
		$this->assertSame( 0, $existing->gateway_reads );
	}

	public function test_failed_install_reports_the_observed_absent_target() {
		$subject                = new Testable_MainWP_Child_Virusdie();
		$subject->gateway_bytes = 'wrong';
		$request                = $this->install_request( 'expected' );

		$result = $subject->request_v1( $request );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'digest_mismatch', $result['code'] );
		$this->assertFalse( $result['installed'] );
		$this->assertSame( 0, $subject->installs );

		$status = $subject->request_v1( $this->request( 'status', array( 'request_ref' => $request['payload']['request_ref'] ) ) );
		$this->assertSame( $result, $status );
	}

	public function test_failed_remove_reports_the_still_present_target() {
		$subject                = new Testable_MainWP_Child_Virusdie();
		$subject->target_exists = true;
		$subject->target_bytes  = 'installed bytes';
		$subject->remove_fails  = true;
		$request                = $this->remove_request( $subject->target_bytes );

		$result = $subject->request_v1( $request );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertTrue( $result['installed'] );
		$this->assertTrue( $subject->target_exists );

		$status = $subject->request_v1( $this->request( 'status', array( 'request_ref' => $request['payload']['request_ref'] ) ) );
		$this->assertSame( $result, $status );
	}

	public function test_unreadable_target_settles_installed_as_unknown() {
		$subject                             = new Testable_MainWP_Child_Virusdie();
		$subject->durable_receipts           = true;
		$subject->gateway_bytes              = 'wrong';
		$subject->break_snapshot_on_download = true;
		$request                             = $this->install_request( 'expected' );

		$result = $subject->request_v1( $request );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'digest_mismatch', $result['code'] );
		$this->assertNull( $result['installed'] );

		$status = $subject->request_v1( $this->request( 'status', array( 'request_ref' => $request['payload']['request_ref'] ) ) );
		$this->assertSame( $result, $status );
	}

	public function test_remove_requires_exact_current_hash_and_replays() {
		$subject                = new Testable_MainWP_Child_Virusdie();
		$subject->target_exists = true;
		$subject->target_bytes  = 'installed bytes';
		$request                = $this->request(
			'remove',
			array(
				'request_ref'     => '123e4567-e89b-42d3-a456-426614175003',
				'basename'        => 'virusdie_fixture.php',
				'expected_sha256' => hash( 'sha256', 'installed bytes' ),
				'site_generation' => str_repeat( 'b', 64 ),
			)
		);

		$result = $subject->request_v1( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertFalse( $result['installed'] );
		$this->assertSame( 1, $subject->removals );
		$this->assertSame( $result, $subject->request_v1( $request ) );
		$this->assertSame( 1, $subject->removals );

		$drifted                = new Testable_MainWP_Child_Virusdie();
		$drifted->target_exists = true;
		$drifted->target_bytes  = 'drifted';
		$this->assertSame( 'stale_target', $drifted->request_v1( $request )['code'] );
		$this->assertSame( 0, $drifted->removals );
	}

	public function test_malformed_alias_path_and_gateway_fail_closed() {
		$subject = new Testable_MainWP_Child_Virusdie();
		$request = $this->install_request( 'bytes' );
		$request['payload']['request_id'] = $request['payload']['request_ref'];
		unset( $request['payload']['request_ref'] );
		$this->assertSame( 'invalid_request', $subject->request_v1( $request )['code'] );

		$request = $this->install_request( 'bytes' );
		$request['payload']['basename'] = '../virusdie.php';
		$this->assertSame( 'invalid_request', $subject->request_v1( $request )['code'] );
		$request = $this->install_request( 'bytes' );
		$request['payload']['gateway_url'] = 'https://attacker.example/file';
		$this->assertSame( 'gateway_rejected', $subject->request_v1( $request )['code'] );
	}

	public function test_expired_receipt_is_never_replayed_and_status_does_not_delete_it() {
		$subject                   = new Testable_MainWP_Child_Virusdie();
		$subject->durable_receipts = true;
		$subject->gateway_bytes    = '<?php // signed fixture';
		$request                   = $this->install_request( $subject->gateway_bytes );
		$ref                       = $request['payload']['request_ref'];
		$key                       = 'mainwp_child_virusdie_v1_' . hash( 'sha256', $ref );
		$expired                   = $this->expired_receipt( $ref );
		$this->assertTrue( $subject->seed_durable_receipt( $ref, $expired ) );

		$status = $subject->request_v1( $this->request( 'status', array( 'request_ref' => $ref ) ) );
		$this->assertFalse( $status['ok'] );
		$this->assertSame( 'not_found', $status['code'] );
		$this->assertSame( $expired, get_option( $key, false ) );

		$result = $subject->request_v1( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 1, $subject->gateway_reads );
		$this->assertSame( 1, $subject->installs );
		$this->assertSame( $result, get_option( $key, false )['result'] );

		delete_option( $key );
	}

	public function test_callable_dispatch_map_registers_the_narrow_protocol() {
		$reflection = new ReflectionClass( MainWP_Child_Callable::class );
		$callable   = $reflection->newInstanceWithoutConstructor();
		$property   = $reflection->getProperty( 'callableFunctions' );
		$property->setAccessible( true );

		$this->assertSame( 'virusdie_sync_install_v1', $property->getValue( $callable )['virusdie_sync_install_v1'] );
		$this->assertTrue( method_exists( $callable, 'virusdie_sync_install_v1' ) );
	}

	public function test_the_staging_file_carries_a_php_suffix_in_the_web_root() {
		$token = 'stagingsuffixprobe';
		add_filter(
			'random_password',
			static function () use ( $token ) {
				return $token;
			}
		);
		$probe         = new Virusdie_Staging_Probe();
		$extensionless = ABSPATH . '.mainwp-virusdie-' . $token;
		$suffixed      = $extensionless . '.php';
		$target        = ABSPATH . 'virusdie_staging_probe.php';

		try {
			// The staging file is unlinked before install_artifact() returns, so occupying a
			// candidate name and watching the exclusive create collide is the only way to see
			// which of the two names it actually opened.
			file_put_contents( $suffixed, 'occupied' );
			$this->assertFalse( $probe->stage( 'virusdie_staging_probe.php', '<?php // artifact' ) );
			unlink( $suffixed );

			file_put_contents( $extensionless, 'occupied' );
			$this->assertTrue( $probe->stage( 'virusdie_staging_probe.php', '<?php // artifact' ) );
			$this->assertSame( '<?php // artifact', file_get_contents( $target ) );
		} finally {
			foreach ( array( $extensionless, $suffixed, $target ) as $leftover ) {
				if ( file_exists( $leftover ) ) {
					unlink( $leftover );
				}
			}
		}
	}

	private function install_request( $bytes ) {
		return $this->request(
			'install',
			array(
				'request_ref'     => '123e4567-e89b-42d3-a456-426614175001',
				'basename'        => 'virusdie_fixture.php',
				'expected_bytes'  => strlen( $bytes ),
				'expected_sha256' => hash( 'sha256', $bytes ),
				'gateway_url'     => 'https://dashboard.example/mainwp-virusdie-artifact',
				'gateway_token'   => 'one-use-private-token',
				'site_generation' => str_repeat( 'a', 64 ),
				'expires_at'      => time() + 300,
			)
		);
	}

	private function expired_receipt( $ref ) {
		return array(
			'effect_hash'     => str_repeat( 'd', 64 ),
			'operation'       => 'install',
			'request_ref'     => $ref,
			'basename'        => 'virusdie_fixture.php',
			'expected_sha256' => str_repeat( 'e', 64 ),
			'site_generation' => str_repeat( 'a', 64 ),
			'state'           => 'settled',
			'result'          => array(
				'protocol'    => '1',
				'operation'   => 'install',
				'ok'          => false,
				'request_ref' => $ref,
				'status'      => 'failed',
				'installed'   => false,
				'bytes'       => null,
				'sha256'      => null,
				'code'        => 'digest_mismatch',
			),
			'updated_at'      => time() - 172800,
			'expires_at'      => time() - 86400,
		);
	}

	private function remove_request( $bytes ) {
		return $this->request(
			'remove',
			array(
				'request_ref'     => '123e4567-e89b-42d3-a456-426614175004',
				'basename'        => 'virusdie_fixture.php',
				'expected_sha256' => hash( 'sha256', $bytes ),
				'site_generation' => str_repeat( 'b', 64 ),
			)
		);
	}

	private function request( $operation, $payload ) {
		return array( 'protocol' => '1', 'operation' => $operation, 'payload' => $payload );
	}
}

class Testable_MainWP_Child_Virusdie extends MainWP_Child_Virusdie {

	public $target_exists = false;
	public $target_bytes = '';
	public $gateway_bytes = '';
	public $gateway_reads = 0;
	public $installs = 0;
	public $removals = 0;
	public $receipts = array();
	public $remove_fails = false;
	public $durable_receipts = false;
	public $break_snapshot_on_download = false;

	/** Target becomes unreadable once the gateway has been read. */
	private $snapshot_unreadable = false;

	protected function target_snapshot( $basename ) {
		unset( $basename );
		if ( $this->snapshot_unreadable ) {
			return false;
		}
		return array(
			'exists' => $this->target_exists,
			'bytes'  => $this->target_exists ? strlen( $this->target_bytes ) : null,
			'sha256' => $this->target_exists ? hash( 'sha256', $this->target_bytes ) : null,
		);
	}

	protected function gateway_allowed( $url ) {
		return 'https://dashboard.example/mainwp-virusdie-artifact' === $url;
	}

	protected function download_artifact( $url, $token, $expected_bytes ) {
		unset( $url, $token, $expected_bytes );
		++$this->gateway_reads;
		if ( $this->break_snapshot_on_download ) {
			$this->snapshot_unreadable = true;
		}
		return $this->gateway_bytes;
	}

	protected function install_artifact( $basename, $bytes ) {
		unset( $basename );
		++$this->installs;
		$this->target_exists = true;
		$this->target_bytes  = $bytes;
		return true;
	}

	protected function remove_artifact( $basename ) {
		unset( $basename );
		++$this->removals;
		if ( $this->remove_fails ) {
			return false;
		}
		$this->target_exists = false;
		$this->target_bytes  = '';
		return true;
	}

	protected function load_receipt( $request_ref ) {
		if ( $this->durable_receipts ) {
			return parent::load_receipt( $request_ref );
		}
		return isset( $this->receipts[ $request_ref ] ) ? $this->receipts[ $request_ref ] : null;
	}

	protected function create_receipt( $request_ref, $receipt ) {
		if ( $this->durable_receipts ) {
			return parent::create_receipt( $request_ref, $receipt );
		}
		if ( isset( $this->receipts[ $request_ref ] ) ) {
			return false;
		}
		$this->receipts[ $request_ref ] = $receipt;
		return true;
	}

	protected function settle_receipt( $request_ref, $expected, $receipt ) {
		if ( $this->durable_receipts ) {
			return parent::settle_receipt( $request_ref, $expected, $receipt );
		}
		if ( ! isset( $this->receipts[ $request_ref ] ) || $expected !== $this->receipts[ $request_ref ] ) {
			return false;
		}
		$this->receipts[ $request_ref ] = $receipt;
		return true;
	}

	protected function delete_receipt( $request_ref ) {
		if ( $this->durable_receipts ) {
			return parent::delete_receipt( $request_ref );
		}
		unset( $this->receipts[ $request_ref ] );
		return true;
	}

	public function seed_dispatching( $request ) {
		$this->receipts[ $request['payload']['request_ref'] ] = $this->dispatching_receipt_for_test( $request );
	}

	public function seed_durable_receipt( $request_ref, $receipt ) {
		return parent::create_receipt( $request_ref, $receipt );
	}
}

/** Reaches the real staging and publication path, which Testable_MainWP_Child_Virusdie replaces. */
class Virusdie_Staging_Probe extends MainWP_Child_Virusdie {

	public function stage( $basename, $bytes ) {
		return $this->install_artifact( $basename, $bytes );
	}
}
