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

	public function set_option( $key, $value ) {
		$this->values[ $key ] = $value;
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

class Timecapsule_V2_Protocol_Fixture extends MainWP_Child_Timecapsule {

	/** @var array */
	public $results = array();

	/** @var array */
	public $calls = array();

	protected function abilities_v2_provider_call( $operation, $payload ) {
		$this->calls[] = array( $operation, $payload );
		return isset( $this->results[ $operation ] ) ? $this->results[ $operation ] : new \WP_Error( 'provider_unavailable' );
	}
}

class Timecapsule_V2_Default_Provider_Fixture extends MainWP_Child_Timecapsule {

	/** @var array */
	public $backups = array();

	protected function get_backups( $last_time = false ) {
		return $this->backups;
	}

	public function start_fresh_backup_tc_callback_wptc() {
		Timecapsule_V2_Factory::$config->set_option( 'in_progress', true );
		return array( 'result' => 'success' );
	}

	public function stop_fresh_backup_tc_callback_wptc() {
		Timecapsule_V2_Factory::$config->set_option( 'in_progress', false );
		return array( 'result' => 'ok' );
	}
}

class Test_MainWP_Child_Timecapsule_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Timecapsule */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'mainwp_timecapsule_abilities_v2_receipts' );
		delete_option( 'mainwp_timecapsule_abilities_v2_operations' );
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

	public function tear_down(): void {
		delete_option( 'mainwp_timecapsule_abilities_v2_receipts' );
		delete_option( 'mainwp_timecapsule_abilities_v2_operations' );
		parent::tear_down();
	}

	public function test_capabilities_are_closed_and_publish_the_complete_typed_surface() {
		$result = $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array( 'site', 'policy', 'replace_policy', 'list_backups', 'start_backup', 'operation_status', 'cancel_operation', 'preview_restore', 'restore_backup', 'list_staging', 'start_staging', 'delete_staging' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_typed_mutations_require_request_ref_and_replay_the_exact_receipt() {
		$fixture = new Timecapsule_V2_Protocol_Fixture();
		$fixture->is_plugin_installed = true;
		$fixture->results['start_backup'] = array(
			'operation_ref' => str_repeat( 'a', 64 ),
			'state'         => 'queued',
			'scope'         => 'full',
		);
		$request = array(
			'protocol'    => '2',
			'operation'   => 'start_backup',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174961',
			'payload'     => array(
				'scope'             => 'full',
				'label'             => 'Before release',
				'policy_generation' => str_repeat( 'b', 64 ),
			),
		);

		$result = $fixture->abilities_v2( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( $request['request_ref'], $result['request_ref'] );
		$this->assertSame( str_repeat( 'a', 64 ), $result['operation_ref'] );
		$this->assertSame( $result, $fixture->abilities_v2( $request ) );
		$this->assertCount( 1, $fixture->calls );

		$request['payload']['scope'] = 'files';
		$conflict = $fixture->abilities_v2( $request );
		$this->assertFalse( $conflict['ok'] );
		$this->assertSame( 'request_conflict', $conflict['code'] );

		unset( $request['request_ref'] );
		$request['request_id'] = '123e4567-e89b-42d3-a456-426614174962';
		$this->assertSame( 'invalid_request', $fixture->abilities_v2( $request )['code'] );
	}

	public function test_read_status_restore_and_staging_operations_are_closed() {
		$fixture = new Timecapsule_V2_Protocol_Fixture();
		$fixture->is_plugin_installed = true;
		$fixture->results = array(
			'list_backups'    => array( 'backups' => array(), 'snapshot_generation' => str_repeat( 'c', 64 ), 'next_after_backup_ref' => null, 'truncated' => false ),
			'operation_status' => array( 'operation_ref' => str_repeat( 'd', 64 ), 'kind' => 'restore', 'state' => 'running', 'progress_percent' => 50, 'started_at' => '2026-08-16T10:00:00Z', 'finished_at' => null, 'result_ref' => null, 'generation' => str_repeat( 'e', 64 ) ),
			'preview_restore' => array( 'preview_token' => str_repeat( 'P', 43 ), 'expires_at' => '2026-08-16T10:15:00Z', 'backup_ref' => str_repeat( 'f', 64 ), 'scope' => 'full', 'file_count' => 10, 'table_count' => 5, 'overwrite_expected' => true, 'preflight' => 'ready' ),
			'list_staging'    => array( 'clones' => array(), 'snapshot_generation' => str_repeat( '1', 64 ), 'next_after_clone_ref' => null, 'truncated' => false ),
		);

		$cases = array(
			array( 'list_backups', array( 'limit' => 50, 'after_backup_ref' => null ) ),
			array( 'operation_status', array( 'operation_ref' => str_repeat( 'd', 64 ) ) ),
			array( 'preview_restore', array( 'backup_ref' => str_repeat( 'f', 64 ), 'backup_generation' => str_repeat( '2', 64 ), 'scope' => 'full' ) ),
			array( 'list_staging', array( 'limit' => 20, 'after_clone_ref' => null ) ),
		);
		foreach ( $cases as $case ) {
			$result = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => $case[0], 'payload' => $case[1] ) );
			$this->assertTrue( $result['ok'], $case[0] );
			$this->assertSame( $case[0], $result['operation'] );
		}
	}

	public function test_default_provider_replaces_policy_and_projects_backups_without_raw_ids() {
		$fixture                      = new Timecapsule_V2_Default_Provider_Fixture();
		$fixture->is_plugin_installed = true;
		$fixture->backups             = array( (object) array( 'backupID' => 1723456789 ), (object) array( 'backupID' => 1723456790 ) );
		$policy                       = $this->request( 'policy' );
		$replace                      = $fixture->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'replace_policy',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174962',
				'payload'     => array( 'schedule_time' => '7:00 am', 'retention_days' => 45, 'backup_before_update' => false, 'if_match' => $policy['policy_generation'] ),
			)
		);
		$this->assertTrue( $replace['ok'] );
		$this->assertSame( '7:00 am', Timecapsule_V2_Factory::$config->get_option( 'schedule_time_str' ) );
		$this->assertSame( '45', Timecapsule_V2_Factory::$config->get_option( 'revision_limit' ) );

		$list = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'list_backups', 'payload' => array( 'limit' => 1, 'after_backup_ref' => null ) ) );
		$this->assertTrue( $list['ok'] );
		$this->assertCount( 1, $list['backups'] );
		$this->assertTrue( $list['truncated'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', 1723456790 ), $list['backups'][0]['created_at'] );
		$this->assertStringNotContainsString( '1723456790', wp_json_encode( $list ) );
	}

	public function test_default_provider_starts_tracks_and_cancels_one_backup() {
		$fixture                      = new Timecapsule_V2_Default_Provider_Fixture();
		$fixture->is_plugin_installed = true;
		$policy                       = $this->request( 'policy' );
		$start                        = $fixture->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'start_backup',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174963',
				'payload'     => array( 'scope' => 'full', 'label' => null, 'policy_generation' => $policy['policy_generation'] ),
			)
		);
		$this->assertTrue( $start['ok'] );
		$this->assertSame( 'running', $start['state'] );

		$status = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'operation_status', 'payload' => array( 'operation_ref' => $start['operation_ref'] ) ) );
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 'running', $status['state'] );

		$cancel = $fixture->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'cancel_operation',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174964',
				'payload'     => array( 'operation_ref' => $start['operation_ref'], 'if_match' => $status['generation'] ),
			)
		);
		$this->assertTrue( $cancel['ok'] );
		$this->assertSame( 'cancelled', $cancel['state'] );
		$this->assertTrue( $cancel['quiescent'] );
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
