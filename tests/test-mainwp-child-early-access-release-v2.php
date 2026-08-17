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

	public function test_capabilities_advertise_verified_apply_and_status() {
		$result = ( new Testable_MainWP_Child_Early_Access_Release() )->release_v2( $this->request( 'capabilities', array() ) );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported', 'max_artifact_bytes' ), array_keys( $result ) );
		$this->assertSame( array( 'apply', 'status' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
		$this->assertSame( 52428800, $result['max_artifact_bytes'] );
	}

	public function test_apply_reserves_validates_reads_back_and_replays_without_secret() {
		$subject = new Testable_MainWP_Child_Early_Access_Release();
		$request = $this->apply_request();

		$result = $subject->release_v2( $request );
		$this->assertSame( array( 'protocol', 'operation', 'ok', 'request_ref', 'status', 'action', 'previous_version', 'installed_version', 'active', 'persistence', 'retry_safe', 'code' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'applied', $result['status'] );
		$this->assertSame( '6.0.0-beta.2', $result['installed_version'] );
		$this->assertTrue( $result['active'] );
		$this->assertSame( 1, $subject->downloads );
		$this->assertSame( 1, $subject->applies );
		$this->assertSame( 1, $subject->cleanups );
		$this->assertStringNotContainsString( 'one-use-private-token', wp_json_encode( $subject->receipts ) );

		$this->assertSame( $result, $subject->release_v2( $request ) );
		$this->assertSame( 1, $subject->downloads );
		$this->assertSame( 1, $subject->applies );
		$this->assertSame( $result, $subject->release_v2( $this->request( 'status', array( 'request_ref' => $request['payload']['request_ref'] ) ) ) );
	}

	public function test_dispatching_receipt_is_unknown_and_never_downloads_again() {
		$subject = new Testable_MainWP_Child_Early_Access_Release();
		$request = $this->apply_request();
		$subject->seed_dispatching( $request );

		$result = $subject->release_v2( $request );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertFalse( $result['retry_safe'] );
		$this->assertSame( 0, $subject->downloads );
		$this->assertSame( 0, $subject->applies );
	}

	public function test_transition_lock_contention_is_non_mutating() {
		$subject                 = new Testable_MainWP_Child_Early_Access_Release();
		$subject->lock_available = false;
		$result                  = $subject->release_v2( $this->apply_request() );

		$this->assertSame( 'lock_busy', $result['code'] );
		$this->assertSame( 0, $subject->downloads );
		$this->assertSame( 0, $subject->applies );
	}

	public function test_invalid_package_is_failed_before_installed_code_mutation() {
		$subject                = new Testable_MainWP_Child_Early_Access_Release();
		$subject->package_valid = false;
		$result                 = $subject->release_v2( $this->apply_request() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'package_invalid', $result['code'] );
		$this->assertSame( '5.4.1', $subject->version );
		$this->assertSame( 0, $subject->applies );
	}

	public function test_settled_install_failure_reports_verified_restoration() {
		$subject               = new Testable_MainWP_Child_Early_Access_Release();
		$subject->apply_status = 'restored';
		$result                = $subject->release_v2( $this->apply_request() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'restored', $result['status'] );
		$this->assertSame( 'restored', $result['persistence'] );
		$this->assertSame( '5.4.1', $result['installed_version'] );
		$this->assertFalse( $result['retry_safe'] );
		$this->assertSame( 'install_failed_restored', $result['code'] );
	}

	public function test_uuid_alias_untrusted_gateway_and_changed_replay_fail_closed() {
		$subject = new Testable_MainWP_Child_Early_Access_Release();
		$request = $this->apply_request();
		$request['payload']['request_id'] = $request['payload']['request_ref'];
		unset( $request['payload']['request_ref'] );
		$this->assertSame( 'invalid_request', $subject->release_v2( $request )['code'] );

		$request = $this->apply_request();
		$request['payload']['gateway_url'] = 'https://attacker.example/artifact';
		$this->assertSame( 'gateway_rejected', $subject->release_v2( $request )['code'] );
		$this->assertSame( 0, $subject->downloads );

		$request = $this->apply_request();
		$this->assertTrue( $subject->release_v2( $request )['ok'] );
		$request['payload']['target_version'] = '6.0.0-beta.3';
		$this->assertSame( 'request_conflict', $subject->release_v2( $request )['code'] );
		$this->assertSame( 1, $subject->applies );
	}

	public function test_production_zip_validator_requires_one_safe_exact_plugin_root() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is unavailable.' );
		}
		$probe = new Production_MainWP_Child_Early_Access_Package_Probe();
		$valid = wp_tempnam( 'mainwp-child-valid.zip' );
		$zip   = new \ZipArchive();
		$this->assertTrue( $zip->open( $valid, \ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'mainwp-child/mainwp-child.php', "<?php\n/*\nPlugin Name: MainWP Child\nVersion: 6.0.0-beta.2\n*/\n" );
		$zip->addFromString( 'mainwp-child/readme.txt', 'fixture' );
		$zip->close();
		$this->assertTrue( $probe->validate_fixture( $valid, '6.0.0-beta.2' ) );
		unlink( $valid );

		$hostile = wp_tempnam( 'mainwp-child-hostile.zip' );
		$zip     = new \ZipArchive();
		$this->assertTrue( $zip->open( $hostile, \ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'mainwp-child/mainwp-child.php', "<?php\n/* Version: 6.0.0-beta.2 */\n" );
		$zip->addFromString( '../outside.php', '<?php' );
		$zip->close();
		$this->assertFalse( $probe->validate_fixture( $hostile, '6.0.0-beta.2' ) );
		unlink( $hostile );
	}

	public function test_callable_map_exposes_only_the_authenticated_protocol_entry() {
		$reflection = new ReflectionClass( MainWP_Child_Callable::class );
		$property   = $reflection->getProperty( 'callableFunctions' );
		$property->setAccessible( true );
		$callables = $property->getValue( MainWP_Child_Callable::get_instance() );

		$this->assertSame( 'early_access_release_v2', $callables['early_access_release_v2'] );
	}

	private function apply_request() {
		return $this->request(
			'apply',
			array(
				'request_ref'     => '123e4567-e89b-42d3-a456-426614174000',
				'action'          => 'upgrade',
				'gateway_url'     => 'https://dashboard.example/wp-json/mainwp-early-access/v1/artifacts/fixture',
				'gateway_token'   => 'one-use-private-token',
				'expected_bytes'  => 1024,
				'expected_sha256' => str_repeat( 'a', 64 ),
				'target_version'  => '6.0.0-beta.2',
				'expires_at'      => time() + 300,
			)
		);
	}

	private function request( $operation, $payload ) {
		return array( 'protocol' => '2', 'operation' => $operation, 'payload' => $payload );
	}
}

class Testable_MainWP_Child_Early_Access_Release extends MainWP_Child_Early_Access_Release {

	public $installed = true;
	public $version = '5.4.1';
	public $active = true;
	public $package_valid = true;
	public $apply_status = 'applied';
	public $downloads = 0;
	public $applies = 0;
	public $cleanups = 0;
	public $receipts = array();
	public $lock_available = true;

	protected function current_state() {
		return array( 'installed' => $this->installed, 'version' => $this->version, 'active' => $this->active );
	}

	protected function gateway_allowed( $url ) {
		return 'https://dashboard.example/wp-json/mainwp-early-access/v1/artifacts/fixture' === $url;
	}

	protected function download_package( $url, $token, $expected_bytes ) {
		unset( $url, $token, $expected_bytes );
		++$this->downloads;
		return array( 'path' => '/private/fixture.zip', 'bytes' => 1024, 'sha256' => str_repeat( 'a', 64 ) );
	}

	protected function validate_package( $package, $target_version ) {
		unset( $package, $target_version );
		return $this->package_valid;
	}

	protected function apply_package( $package, $before, $target_version ) {
		unset( $package, $before );
		++$this->applies;
		if ( 'applied' === $this->apply_status ) {
			$this->installed = true;
			$this->version   = $target_version;
		}
		return array( 'status' => $this->apply_status, 'backup_ref' => 'fixture-backup' );
	}

	protected function cleanup_package( $package ) {
		unset( $package );
		++$this->cleanups;
		return true;
	}

	protected function acquire_transition_lock() {
		return $this->lock_available;
	}

	protected function release_transition_lock( $lock ) {
		unset( $lock );
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

	public function seed_dispatching( $request ) {
		$this->receipts[ $request['payload']['request_ref'] ] = $this->dispatching_receipt_for_test( $request );
	}
}

class Production_MainWP_Child_Early_Access_Package_Probe extends MainWP_Child_Early_Access_Release {

	public function validate_fixture( $path, $target_version ) {
		return $this->validate_package(
			array(
				'path'   => $path,
				'bytes'  => filesize( $path ),
				'sha256' => hash_file( 'sha256', $path ),
			),
			$target_version
		);
	}
}
