<?php
/**
 * Time Capsule abilities-v2 protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Timecapsule_V2_Config {

	/** @var array */
	private $values;

	public function __construct( $values ) {
		$this->values = $values;
	}

	public function get_option( $key ) {
		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : false;
	}
}

class Timecapsule_V2_Factory {

	/** @var Timecapsule_V2_Config */
	public static $config;

	public static function get( $name ) {
		return 'config' === $name ? self::$config : null;
	}
}

class_alias( __NAMESPACE__ . '\\Timecapsule_V2_Factory', 'WPTC_Factory' );

class Test_MainWP_Child_Timecapsule_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Timecapsule */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		$reflection    = new ReflectionClass( MainWP_Child_Timecapsule::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
		$this->subject->is_plugin_installed = true;
		Timecapsule_V2_Factory::$config     = new Timecapsule_V2_Config(
			array(
				'is_user_logged_in'           => true,
				'last_backup_time'            => 1723456789,
				'in_progress'                 => false,
				'schedule_time_str'           => '6:00 am',
				'revision_limit'              => '30',
				'backup_before_update_setting' => 'always',
			)
		);
	}

	public function test_capabilities_are_closed_and_mutations_are_not_claimed() {
		$result = $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array( 'site', 'policy' ), $result['operations'] );
		$this->assertFalse( $result['mutation_supported'] );
	}

	public function test_site_observation_is_redacted_and_generation_bound() {
		$result = $this->request( 'site' );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'complete', 'plugin_state', 'account_state', 'last_attempt_at', 'last_verified_at', 'active_operation_count', 'observed_at', 'generation' ), array_keys( $result ) );
		$this->assertSame( 'ready', $result['plugin_state'] );
		$this->assertSame( 'connected', $result['account_state'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', 1723456789 ), $result['last_attempt_at'] );
		$this->assertNull( $result['last_verified_at'] );
		$this->assertSame( 0, $result['active_operation_count'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $result['generation'] );
		$this->assertStringNotContainsString( 'email', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( 'token', wp_json_encode( $result ) );
	}

	public function test_policy_is_bounded_and_contains_no_secret_fields() {
		$result = $this->request( 'policy' );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'complete', 'schedule_time', 'retention_days', 'backup_before_update', 'policy_generation' ), array_keys( $result ) );
		$this->assertSame( '6:00 am', $result['schedule_time'] );
		$this->assertSame( 30, $result['retention_days'] );
		$this->assertTrue( $result['backup_before_update'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $result['policy_generation'] );
		$this->assertStringNotContainsString( 'encrypt', wp_json_encode( $result ) );
	}

	public function test_malformed_and_unusable_provider_values_fail_closed() {
		$this->assertFalse( $this->subject->abilities_v2( array() )['ok'] );
		$this->assertFalse(
			$this->subject->abilities_v2(
				array(
					'protocol'  => '2',
					'operation' => 'site',
					'payload'   => array( 'extra' => true ),
				)
			)['ok']
		);

		Timecapsule_V2_Factory::$config = new Timecapsule_V2_Config(
			array(
				'is_user_logged_in'           => 'yes',
				'schedule_time_str'           => '6:30 am',
				'revision_limit'              => 1000,
				'backup_before_update_setting' => array(),
			)
		);
		$this->assertFalse( $this->request( 'site' )['ok'] );
		$this->assertFalse( $this->request( 'policy' )['ok'] );
	}

	private function request( $operation ) {
		return $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => $operation,
				'payload'   => array(),
			)
		);
	}
}
