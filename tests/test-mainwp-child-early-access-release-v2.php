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

	public function test_package_invalid_failure_reports_the_unchanged_installed_version() {
		$subject                = new Testable_MainWP_Child_Early_Access_Release();
		$subject->package_valid = false;
		$request                = $this->apply_request();
		$result                 = $subject->release_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'package_invalid', $result['code'] );
		$this->assertSame( 'not_attempted', $result['persistence'] );
		$this->assertTrue( $result['retry_safe'] );
		$this->assertSame( '5.4.1', $result['previous_version'] );
		$this->assertSame( '5.4.1', $result['installed_version'] );
		$this->assertTrue( $result['active'] );
		$this->assertSame( 0, $subject->applies );
		$this->assertSame( $result, $subject->release_v2( $this->request( 'status', array( 'request_ref' => $request['payload']['request_ref'] ) ) ) );

		$mismatch                = new Testable_MainWP_Child_Early_Access_Release();
		$mismatch->package_bytes = 2048;
		$digest                  = $mismatch->release_v2( $this->apply_request() );

		$this->assertSame( 'package_invalid', $digest['code'] );
		$this->assertSame( '5.4.1', $digest['installed_version'] );
		$this->assertSame( 0, $mismatch->applies );
	}

	public function test_stale_state_failure_reports_the_version_actually_found() {
		$subject                = new Testable_MainWP_Child_Early_Access_Release();
		$subject->recheck_state = '5.4.2';
		$result                 = $subject->release_v2( $this->apply_request() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'stale_state', $result['code'] );
		$this->assertSame( 'not_attempted', $result['persistence'] );
		$this->assertSame( '5.4.1', $result['previous_version'] );
		$this->assertSame( '5.4.2', $result['installed_version'] );
		$this->assertTrue( $result['active'] );
		$this->assertSame( 0, $subject->applies );
	}

	public function test_unreadable_recheck_is_unknown_and_claims_no_version() {
		$subject                = new Testable_MainWP_Child_Early_Access_Release();
		$subject->recheck_state = false;
		$result                 = $subject->release_v2( $this->apply_request() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 'read_failed', $result['code'] );
		$this->assertSame( 'unknown', $result['persistence'] );
		$this->assertSame( '', $result['installed_version'] );
		$this->assertFalse( $result['retry_safe'] );
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

	public function test_verified_apply_reclaims_its_backup_and_an_unresolved_one_survives() {
		$root = $this->private_root();

		$applied            = new Testable_MainWP_Child_Early_Access_Release();
		$applied->test_root = $root;
		$result             = $applied->release_v2( $this->apply_request() );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'applied', $result['status'] );
		$this->assertNotSame( '', $applied->last_backup_ref );
		$this->assertDirectoryDoesNotExist( $root . '/' . $applied->last_backup_ref );

		$unresolved               = new Testable_MainWP_Child_Early_Access_Release();
		$unresolved->test_root    = $root;
		$unresolved->apply_status = 'unknown';
		$ambiguous                = $unresolved->release_v2( $this->apply_request() );

		$this->assertSame( 'unknown', $ambiguous['status'] );
		$this->assertDirectoryExists( $root . '/' . $unresolved->last_backup_ref );
		$this->assertFileExists( $root . '/' . $unresolved->last_backup_ref . '/mainwp-child.php' );

		unlink( $root . '/' . $unresolved->last_backup_ref . '/mainwp-child.php' );
		rmdir( $root . '/' . $unresolved->last_backup_ref );
		rmdir( $root );
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

	/**
	 * Nothing else prunes these receipt options, so a settled result past retention has to stop
	 * being served and stop occupying its reference. The production reader is the layer that has
	 * to do it - every fixture in this file replaces the receipt store.
	 */
	public function test_settled_receipt_past_retention_reads_as_absent_and_is_reclaimed() {
		$subject = new MainWP_Child_Early_Access_Release();

		$live = '123e4567-e89b-42d3-a456-426614174701';
		add_option( $this->receipt_option( $live ), $this->settled_receipt( $live, time() + 600 ), '', false );
		$served = $subject->release_v2( $this->request( 'status', array( 'request_ref' => $live ) ) );
		$this->assertTrue( $served['ok'] );
		$this->assertSame( 'applied', $served['status'] );

		$aged = '123e4567-e89b-42d3-a456-426614174702';
		add_option( $this->receipt_option( $aged ), $this->settled_receipt( $aged, time() - 1 ), '', false );
		$result = $subject->release_v2( $this->request( 'status', array( 'request_ref' => $aged ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['code'] );
		$this->assertNull( get_option( $this->receipt_option( $aged ), null ) );

		delete_option( $this->receipt_option( $live ) );
	}

	/**
	 * An aged dispatch marker is the one thing retention may not drop: its effect was never
	 * resolved, so forgetting it would let the next request run the transition a second time.
	 */
	public function test_aged_dispatch_marker_still_answers_unknown_and_is_kept() {
		$subject     = new MainWP_Child_Early_Access_Release();
		$request_ref = '123e4567-e89b-42d3-a456-426614174703';
		$marker      = $this->settled_receipt( $request_ref, time() - 1 );
		$marker['state']  = 'dispatching';
		$marker['result'] = null;
		add_option( $this->receipt_option( $request_ref ), $marker, '', false );

		$result = $subject->release_v2( $this->request( 'status', array( 'request_ref' => $request_ref ) ) );

		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertIsArray( get_option( $this->receipt_option( $request_ref ), null ) );

		delete_option( $this->receipt_option( $request_ref ) );
	}

	/**
	 * A cleanup walk that meets an unreadable directory must report failure, not throw out of a
	 * transition that already succeeded.
	 */
	public function test_unreadable_backup_tree_does_not_throw_out_of_a_successful_apply() {
		$root                     = $this->private_root();
		$subject                  = new Testable_MainWP_Child_Early_Access_Release();
		$subject->test_root       = $root;
		$subject->unreadable_backup = true;

		$result = $subject->release_v2( $this->apply_request() );
		$sealed = $root . '/' . $subject->last_backup_ref . '/sealed';
		$this->assertDirectoryExists( $sealed );
		if ( is_readable( $sealed ) ) {
			chmod( $sealed, 0700 );
			$this->remove_root( $root );
			$this->markTestSkipped( 'This user can read a 0000 directory, so the failing walk cannot be staged.' );
		}

		chmod( $sealed, 0700 );
		$this->remove_root( $root );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'applied', $result['status'] );
	}

	private function receipt_option( $request_ref ) {
		return 'mainwp_child_early_access_v2_' . hash( 'sha256', $request_ref );
	}

	/**
	 * One stored settled receipt the production validator accepts, expiring at the given moment.
	 */
	private function settled_receipt( $request_ref, $expires_at ) {
		return array(
			'effect_hash'      => hash( 'sha256', 'effect-' . $request_ref ),
			'request_ref'      => $request_ref,
			'action'           => 'upgrade',
			'target_version'   => '6.0.0-beta.2',
			'expected_sha256'  => str_repeat( 'a', 64 ),
			'previous_version' => '5.4.1',
			'previous_active'  => true,
			'state'            => 'settled',
			'result'           => array(
				'protocol'          => '2',
				'operation'         => 'apply',
				'ok'                => true,
				'request_ref'       => $request_ref,
				'status'            => 'applied',
				'action'            => 'upgrade',
				'previous_version'  => '5.4.1',
				'installed_version' => '6.0.0-beta.2',
				'active'            => true,
				'persistence'       => 'installed',
				'retry_safe'        => false,
				'code'              => null,
			),
			'updated_at'       => $expires_at - 86400,
			'expires_at'       => $expires_at,
		);
	}

	private function remove_root( $root ) {
		$walk = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $walk as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $root );
	}

	public function test_callable_map_exposes_only_the_authenticated_protocol_entry() {
		$reflection = new ReflectionClass( MainWP_Child_Callable::class );
		$property   = $reflection->getProperty( 'callableFunctions' );
		$property->setAccessible( true );
		$callables = $property->getValue( MainWP_Child_Callable::get_instance() );

		$this->assertSame( 'early_access_release_v2', $callables['early_access_release_v2'] );
	}

	private function private_root() {
		$root = rtrim( get_temp_dir(), '/' ) . '/mainwp-early-access-' . wp_generate_password( 12, false, false );
		if ( ! mkdir( $root, 0700, true ) ) {
			$this->markTestSkipped( 'A private release root could not be created.' );
		}
		return $root;
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
	public $package_bytes = 1024;
	public $apply_status = 'applied';
	public $downloads = 0;
	public $applies = 0;
	public $cleanups = 0;
	public $receipts = array();
	public $lock_available = true;
	public $state_reads = 0;
	public $test_root = '';
	public $last_backup_ref = '';

	/** Stage the superseded tree with a directory the cleanup walk cannot descend into. */
	public $unreadable_backup = false;

	/** Version reported by the post-download state re-read, or false for a tree that no longer parses. */
	public $recheck_state = null;

	protected function current_state() {
		++$this->state_reads;
		if ( null !== $this->recheck_state && 2 === $this->state_reads ) {
			if ( false === $this->recheck_state ) {
				return false;
			}
			$this->version = $this->recheck_state;
		}
		return array( 'installed' => $this->installed, 'version' => $this->version, 'active' => $this->active );
	}

	protected function gateway_allowed( $url ) {
		return 'https://dashboard.example/wp-json/mainwp-early-access/v1/artifacts/fixture' === $url;
	}

	protected function download_package( $url, $token, $expected_bytes ) {
		unset( $url, $token, $expected_bytes );
		++$this->downloads;
		return array( 'path' => '/private/fixture.zip', 'bytes' => $this->package_bytes, 'sha256' => str_repeat( 'a', 64 ) );
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
		return array( 'status' => $this->apply_status, 'backup_ref' => $this->stage_backup_tree() );
	}

	protected function storage_root( $create ) {
		unset( $create );
		return '' === $this->test_root ? false : $this->test_root;
	}

	/** Rename fixture: leave a real superseded tree in the private root the way the production apply does. */
	private function stage_backup_tree() {
		if ( '' === $this->test_root ) {
			return 'fixture-backup';
		}
		$this->last_backup_ref = 'backup-' . wp_generate_password( 12, false, false );
		$path                  = $this->test_root . '/' . $this->last_backup_ref;
		mkdir( $path, 0700, true );
		file_put_contents( $path . '/mainwp-child.php', "<?php\n/*\nPlugin Name: MainWP Child\nVersion: 5.4.1\n*/\n" );
		if ( $this->unreadable_backup ) {
			mkdir( $path . '/sealed', 0700, true );
			chmod( $path . '/sealed', 0000 );
		}
		return $this->last_backup_ref;
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
