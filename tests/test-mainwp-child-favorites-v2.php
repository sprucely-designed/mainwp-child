<?php
/**
 * Favorites package protocol-v2 tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Favorites_V2 extends WP_UnitTestCase {

	public function test_package_state_is_exact_closed_and_redacted() {
		$subject = new Testable_MainWP_Child_Favorites_V2(
			array( 'forms/forms.php' => array( 'Version' => '2.1.0', 'Name' => 'Private name' ) ),
			array( 'forms/forms.php' ),
			array()
		);
		$result  = $subject->package_state_v2( $this->state_request( 'plugin', 'forms/forms.php' ) );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'request_ref', 'type', 'slug', 'installed', 'version', 'active', 'state_generation' ), array_keys( $result ) );
		$this->assertTrue( $result['installed'] );
		$this->assertSame( '2.1.0', $result['version'] );
		$this->assertTrue( $result['active'] );
		$this->assertStringNotContainsString( 'Private name', wp_json_encode( $result ) );
	}

	public function test_package_state_reports_absence_and_rejects_ambiguous_slugs() {
		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$result  = $subject->package_state_v2( $this->state_request( 'theme', 'twentytwentyfive' ) );
		$this->assertFalse( $result['installed'] );
		$this->assertNull( $result['version'] );
		$this->assertNull( $result['active'] );

		$request         = $this->state_request( 'plugin', 'forms/forms.php' );
		$request['slug'] = '../forms.php';
		$this->assertSame( 'invalid_request', $subject->package_state_v2( $request )['code'] );
		$this->assertFalse( $subject->package_state_v2( $this->state_request( 'plugin', 'hello.php' ) )['installed'] );
	}

	public function test_install_callable_advertises_verified_mutation_and_status() {
		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$result  = $subject->install_verified_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array( 'install', 'status' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_verified_install_binds_digest_slug_version_and_final_activation() {
		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$request = $this->install_request();
		$result  = $subject->install_verified_v2( $request );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'request_ref', 'status', 'installed', 'type', 'slug', 'version', 'active', 'code' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 1, $subject->download_count );
		$this->assertSame( 1, $subject->install_count );
		$this->assertSame( 1, $subject->cache_refresh_count );
		$this->assertSame( 1, $subject->cleanup_count );
		$this->assertStringNotContainsString( 'dashboard.example', wp_json_encode( $subject->receipts ) );

		$this->assertSame( $result, $subject->install_verified_v2( $request ) );
		$this->assertSame( 1, $subject->download_count );
		$this->assertSame( 1, $subject->install_count );

		$changed = $request;
		$changed['payload']['activate'] = false;
		$this->assertSame( 'request_conflict', $subject->install_verified_v2( $changed )['code'] );
	}

	public function test_verified_install_rejects_bad_digest_before_dispatch() {
		$subject                              = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$subject->downloaded_package_digest   = str_repeat( 'b', 64 );
		$result                               = $subject->install_verified_v2( $this->install_request() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'digest_mismatch', $result['code'] );
		$this->assertSame( 0, $subject->install_count );
		$this->assertSame( 1, $subject->cleanup_count );
	}

	public function test_dispatching_receipt_never_blindly_retries_and_status_is_read_only() {
		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$request = $this->install_request();
		$subject->seed_dispatching( $request );

		$result = $subject->install_verified_v2( $request );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertSame( 0, $subject->download_count );
		$this->assertSame( 0, $subject->install_count );

		$status = $subject->install_verified_v2(
			array(
				'protocol'  => '2',
				'operation' => 'status',
				'payload'   => array( 'request_ref' => $request['payload']['request_ref'] ),
			)
		);
		$this->assertSame( 'unknown', $status['status'] );
		$this->assertSame( 0, $subject->download_count );
	}

	public function test_expired_receipt_is_not_found_and_the_status_read_never_deletes_it() {
		$subject                   = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$subject->durable_receipts = true;
		$ref                       = '123e4567-e89b-42d3-a456-426614173022';
		$receipt                   = $this->expired_receipt( $ref );
		$this->assertTrue( $subject->seed_durable_receipt( $ref, $receipt ) );

		$status = $subject->install_verified_v2(
			array(
				'protocol'  => '2',
				'operation' => 'status',
				'payload'   => array( 'request_ref' => $ref ),
			)
		);

		$this->assertFalse( $status['ok'] );
		$this->assertSame( 'not_found', $status['code'] );
		$this->assertSame( $receipt, get_option( 'mainwp_child_favorites_receipt_' . hash( 'sha256', $ref ), false ) );

		delete_option( 'mainwp_child_favorites_receipt_' . hash( 'sha256', $ref ) );
	}

	public function test_install_evicts_an_expired_receipt_instead_of_replaying_it() {
		$subject                   = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$subject->durable_receipts = true;
		$request                   = $this->install_request();
		$ref                       = $request['payload']['request_ref'];
		$this->assertTrue( $subject->seed_durable_receipt( $ref, $this->expired_receipt( $ref ) ) );

		$result = $subject->install_verified_v2( $request );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 1, $subject->download_count );
		$this->assertSame( 1, $subject->install_count );
		$stored = get_option( 'mainwp_child_favorites_receipt_' . hash( 'sha256', $ref ), false );
		$this->assertSame( 'settled', $stored['state'] );
		$this->assertSame( $result, $stored['result'] );

		delete_option( 'mainwp_child_favorites_receipt_' . hash( 'sha256', $ref ) );
	}

	public function test_single_file_plugin_archive_must_stay_at_the_archive_root() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is unavailable.' );
		}
		$probe = new Production_MainWP_Child_Favorites_Package_Probe();

		$valid = wp_tempnam( 'mainwp-favorites-single.zip' );
		$zip   = new \ZipArchive();
		$this->assertTrue( $zip->open( $valid, \ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'hello.php', "<?php\n/*\nPlugin Name: Hello\nVersion: 1.2.3\n*/\n" );
		$zip->close();
		$this->assertTrue( $probe->inspect( $valid, 'plugin', 'hello.php', '1.2.3' ) );
		unlink( $valid );

		$hostile = wp_tempnam( 'mainwp-favorites-escape.zip' );
		$zip     = new \ZipArchive();
		$this->assertTrue( $zip->open( $hostile, \ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'hello.php', "<?php\n/*\nPlugin Name: Hello\nVersion: 1.2.3\n*/\n" );
		$zip->addFromString( 'other-plugin/evil.php', '<?php // payload' );
		$zip->close();
		$this->assertFalse( $probe->inspect( $hostile, 'plugin', 'hello.php', '1.2.3' ) );
		unlink( $hostile );

		$nested = wp_tempnam( 'mainwp-favorites-nested.zip' );
		$zip    = new \ZipArchive();
		$this->assertTrue( $zip->open( $nested, \ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'forms/forms.php', "<?php\n/*\nPlugin Name: Forms\nVersion: 2.1.0\n*/\n" );
		$zip->addFromString( 'forms/readme.txt', 'fixture' );
		$zip->close();
		$this->assertTrue( $probe->inspect( $nested, 'plugin', 'forms/forms.php', '2.1.0' ) );
		unlink( $nested );
	}

	public function test_uuid_alias_and_callable_map_are_closed() {
		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$request = $this->state_request( 'plugin', 'forms/forms.php' );
		$request['request_id'] = $request['request_ref'];
		unset( $request['request_ref'] );
		$this->assertSame( 'invalid_request', $subject->package_state_v2( $request )['code'] );

		$reflection = new ReflectionClass( MainWP_Child_Callable::class );
		$property   = $reflection->getProperty( 'callableFunctions' );
		$property->setAccessible( true );
		$callables = $property->getValue( MainWP_Child_Callable::get_instance() );
		$this->assertSame( 'favorites_package_state_v2', $callables['favorites_package_state_v2'] );
		$this->assertSame( 'favorites_install_verified_v2', $callables['favorites_install_verified_v2'] );
	}

	private function expired_receipt( $ref ) {
		return array(
			'effect_hash'      => str_repeat( 'c', 64 ),
			'state'            => 'settled',
			'request_ref'      => $ref,
			'type'             => 'plugin',
			'slug'             => 'forms/forms.php',
			'expected_version' => '2.1.0',
			'activate'         => true,
			'installed'        => false,
			'previous_version' => null,
			'previous_active'  => null,
			'result'           => array(
				'protocol'    => '2',
				'operation'   => 'install',
				'ok'          => false,
				'request_ref' => $ref,
				'status'      => 'failed',
				'installed'   => false,
				'type'        => 'plugin',
				'slug'        => 'forms/forms.php',
				'version'     => null,
				'active'      => null,
				'code'        => 'download_failed',
			),
			'updated_at'       => time() - 8 * DAY_IN_SECONDS,
			'expires_at'       => time() - DAY_IN_SECONDS,
		);
	}

	private function state_request( $type, $slug ) {
		return array(
			'protocol'    => '2',
			'operation'   => 'package_state',
			'request_ref' => '123e4567-e89b-42d3-a456-426614173020',
			'type'        => $type,
			'slug'        => $slug,
		);
	}

	private function install_request() {
		return array(
			'protocol'  => '2',
			'operation' => 'install',
			'payload'   => array(
				'request_ref'     => '123e4567-e89b-42d3-a456-426614173021',
				'type'            => 'plugin',
				'slug'            => 'forms/forms.php',
				'expected_version' => '2.1.0',
				'expected_sha256' => str_repeat( 'a', 64 ),
				'download_url'    => 'https://example.com/private/favorite',
				'overwrite'       => true,
				'activate'        => true,
				'state_generation' => hash( 'sha256', wp_json_encode( array( 'type' => 'plugin', 'slug' => 'forms/forms.php', 'installed' => false, 'version' => null, 'active' => null ) ) ),
			),
		);
	}
}

class Testable_MainWP_Child_Favorites_V2 extends MainWP_Child_Favorites {

	private $test_plugins;

	private $test_active_plugins;

	private $test_themes;

	public $download_count = 0;

	public $install_count = 0;

	public $cleanup_count = 0;

	public $cache_refresh_count = 0;

	public $downloaded_package_digest;

	public $receipts = array();

	public $durable_receipts = false;

	public function __construct( $plugins, $active_plugins, $themes ) {
		$this->test_plugins        = $plugins;
		$this->test_active_plugins = $active_plugins;
		$this->test_themes         = $themes;
		$this->downloaded_package_digest = str_repeat( 'a', 64 );
	}

	protected function package_runtime() {
		return array(
			'plugins'        => $this->test_plugins,
			'active_plugins' => $this->test_active_plugins,
			'themes'         => $this->test_themes,
		);
	}

	protected function download_package( $url ) {
		unset( $url );
		++$this->download_count;
		return '/private/tmp/favorites-fixture.zip';
	}

	protected function package_digest( $path ) {
		unset( $path );
		return $this->downloaded_package_digest;
	}

	protected function inspect_package( $path, $type, $slug, $version ) {
		unset( $path, $type, $slug, $version );
		return true;
	}

	protected function dispatch_install( $path, $payload ) {
		unset( $path );
		++$this->install_count;
		$this->test_plugins[ $payload['slug'] ] = array( 'Version' => $payload['expected_version'] );
		if ( $payload['activate'] ) {
			$this->test_active_plugins[] = $payload['slug'];
		}
		return true;
	}

	protected function refresh_package_cache( $type ) {
		unset( $type );
		++$this->cache_refresh_count;
	}

	protected function cleanup_package( $path ) {
		unset( $path );
		++$this->cleanup_count;
	}

	protected function load_install_receipt( $request_ref ) {
		if ( $this->durable_receipts ) {
			return parent::load_install_receipt( $request_ref );
		}
		return isset( $this->receipts[ $request_ref ] ) ? $this->receipts[ $request_ref ] : null;
	}

	protected function create_install_receipt( $request_ref, $receipt ) {
		if ( $this->durable_receipts ) {
			return parent::create_install_receipt( $request_ref, $receipt );
		}
		if ( isset( $this->receipts[ $request_ref ] ) ) {
			return false;
		}
		$this->receipts[ $request_ref ] = $receipt;
		return true;
	}

	protected function settle_install_receipt( $request_ref, $expected, $receipt ) {
		if ( $this->durable_receipts ) {
			return parent::settle_install_receipt( $request_ref, $expected, $receipt );
		}
		if ( ! isset( $this->receipts[ $request_ref ] ) || $expected !== $this->receipts[ $request_ref ] ) {
			return false;
		}
		$this->receipts[ $request_ref ] = $receipt;
		return true;
	}

	protected function delete_install_receipt( $request_ref ) {
		if ( $this->durable_receipts ) {
			return parent::delete_install_receipt( $request_ref );
		}
		unset( $this->receipts[ $request_ref ] );
		return true;
	}

	public function seed_durable_receipt( $request_ref, $receipt ) {
		return parent::create_install_receipt( $request_ref, $receipt );
	}

	public function seed_dispatching( $request ) {
		$this->receipts[ $request['payload']['request_ref'] ] = array(
			'effect_hash'      => $this->effect_hash_for_test( $request['payload'] ),
			'state'            => 'dispatching',
			'request_ref'      => $request['payload']['request_ref'],
			'type'             => $request['payload']['type'],
			'slug'             => $request['payload']['slug'],
			'expected_version' => $request['payload']['expected_version'],
			'activate'         => $request['payload']['activate'],
			'installed'        => false,
			'previous_version' => null,
			'previous_active'  => null,
			'result'           => null,
			'updated_at'       => time(),
			'expires_at'       => time() + DAY_IN_SECONDS,
		);
	}

	public function effect_hash_for_test( $payload ) {
		return $this->install_effect_hash( $payload );
	}
}

/** Exposes the production archive validator with no stubbed seams. */
class Production_MainWP_Child_Favorites_Package_Probe extends MainWP_Child_Favorites {

	public function inspect( $path, $type, $slug, $version ) {
		return $this->inspect_package( $path, $type, $slug, $version );
	}
}
