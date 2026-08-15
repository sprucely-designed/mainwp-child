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

	public function test_install_callable_truthfully_advertises_no_mutation() {
		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$result  = $subject->install_verified_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array(), $result['operations'] );
		$this->assertFalse( $result['mutation_supported'] );
		$this->assertSame(
			'unsupported_operation',
			$subject->install_verified_v2(
				array(
					'protocol'  => '2',
					'operation' => 'install',
					'payload'   => array(),
				)
			)['code']
		);
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

	private function state_request( $type, $slug ) {
		return array(
			'protocol'    => '2',
			'operation'   => 'package_state',
			'request_ref' => '123e4567-e89b-42d3-a456-426614173020',
			'type'        => $type,
			'slug'        => $slug,
		);
	}
}

class Testable_MainWP_Child_Favorites_V2 extends MainWP_Child_Favorites {

	private $test_plugins;

	private $test_active_plugins;

	private $test_themes;

	public function __construct( $plugins, $active_plugins, $themes ) {
		$this->test_plugins        = $plugins;
		$this->test_active_plugins = $active_plugins;
		$this->test_themes         = $themes;
	}

	protected function package_runtime() {
		return array(
			'plugins'        => $this->test_plugins,
			'active_plugins' => $this->test_active_plugins,
			'themes'         => $this->test_themes,
		);
	}
}
