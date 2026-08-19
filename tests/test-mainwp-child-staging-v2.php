<?php
/**
 * Staging abilities-v2 protocol tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Staging;

require_once dirname( __DIR__ ) . '/class/class-mainwp-child-staging.php';

/** Provider-free Staging protocol fixture. */
class Test_MainWP_Child_Staging_V2_Fixture extends MainWP_Child_Staging {

	/** @var array */
	public $clones = array();

	/** @var string */
	public $clone_source = 'provider';

	/** @var array */
	public $settings = array();

	/** @var int */
	public $preview_calls = 0;

	/** @var int */
	public $settings_writes = 0;

	/** @var array */
	public $operation_results = array();

	/** @var array */
	public $operation_calls = array();

	/** Avoid installed-plugin lookup. */
	public function __construct() {
		$this->is_plugin_installed = true;
		$this->plugin_version      = '5.5.0';
	}

	/** @return array */
	protected function abilities_v2_provider_clones() {
		return $this->clones;
	}

	/** @return string */
	protected function abilities_v2_clone_source() {
		return $this->clone_source;
	}

	/** @return array */
	protected function abilities_v2_provider_settings() {
		return $this->settings;
	}

	/** @return bool */
	protected function abilities_v2_provider_supports_mutation() {
		return true;
	}

	/** @return array */
	protected function abilities_v2_provider_preview( $kind, $clone_ref, $inventory ) {
		++$this->preview_calls;
		return array(
			'table_count'      => 25,
			'file_count'       => 1200,
			'estimated_bytes'  => 500000000,
			'disk_sufficient'  => true,
			'isolation_ready'  => true,
			'warnings'         => array(),
		);
	}

	/** @return bool */
	protected function abilities_v2_provider_store_settings( $settings ) {
		++$this->settings_writes;
		$this->settings = $settings;
		return true;
	}

	/** @return array|WP_Error */
	protected function abilities_v2_provider_operation( $operation, $payload ) {
		$this->operation_calls[] = array( $operation, $payload );
		return isset( $this->operation_results[ $operation ] ) ? $this->operation_results[ $operation ] : new WP_Error( 'provider_unavailable' );
	}

	/** @return array|false */
	public function fixture_inventory_rows() {
		return $this->abilities_v2_inventory_rows();
	}
}

/** Staging protocol-v2 contract tests. */
class Test_MainWP_Child_Staging_V2 extends WP_UnitTestCase {

	/** @var Test_MainWP_Child_Staging_V2_Fixture */
	private $staging;

	/** @var string */
	private $clone_path;

	/** Set up provider-free clone truth. */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'mainwp_staging_abilities_v2_receipts' );
		delete_option( 'mainwp_staging_abilities_v2_operation_receipts' );
		$this->clone_path = WP_CONTENT_DIR;
		$this->staging         = new Test_MainWP_Child_Staging_V2_Fixture();
		$this->staging->clones = array(
			'provider-clone-secret' => array(
				'directoryName'             => 'private-clone-name',
				'path'                      => $this->clone_path,
				'url'                       => 'https://secret-clone.example.test',
				'databasePrefix'            => 'secret_prefix_',
				'status'                    => 'finished',
				'isolated'                  => true,
				'searchIndexBlocked'        => true,
				'outboundSideEffectsBlocked' => true,
				'createdAt'                 => '2026-08-01T14:00:00Z',
				'updatedAt'                 => '2026-08-01T14:10:00Z',
			),
		);
	}

	/** Finish the fixture. */
	public function tearDown(): void {
		delete_option( 'mainwp_staging_abilities_v2_operation_receipts' );
		parent::tearDown();
	}

	/** Capability negotiation is additive and exact. */
	public function test_capabilities_are_closed_and_do_not_touch_provider_preview() {
		$result = $this->invoke_v2( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported', 'wp_staging_version' ), array_keys( $result ) );
		$this->assertSame( array( 'inventory', 'settings', 'preview', 'replace_settings', 'create_clone', 'update_clone', 'delete_clone', 'operation_status', 'cancel_operation', 'reconcile_operation' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
		$this->assertSame( '5.5.0', $result['wp_staging_version'] );
		$this->assertSame( 0, $this->staging->preview_calls );
	}

	/** Inventory returns only opaque bounded facts. */
	public function test_inventory_is_complete_redacted_and_stable() {
		$this->assertNotFalse(
			$this->staging->fixture_inventory_rows(),
			wp_json_encode(
				array(
					'abspath'    => ABSPATH,
					'real_root'  => realpath( ABSPATH ),
					'clone_path' => $this->clone_path,
					'real_clone' => realpath( $this->clone_path ),
				)
			)
		);
		$result = $this->invoke_v2( 'inventory', array() );

		$this->assertTrue( $result['ok'], wp_json_encode( $result ) );
		$this->assertTrue( $result['complete'] );
		$this->assertCount( 1, $result['clones'] );
		$row = $result['clones'][0];
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $row['clone_ref'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $row['revision'] );
		$this->assertSame( 'ready', $row['state'] );
		$this->assertTrue( $row['isolated'] );
		$this->assertTrue( $row['search_index_blocked'] );
		$this->assertTrue( $row['outbound_side_effects_blocked'] );
		$encoded = wp_json_encode( $result );
		$this->assertStringNotContainsString( 'provider-clone-secret', $encoded );
		$this->assertStringNotContainsString( 'private-clone-name', $encoded );
		$this->assertStringNotContainsString( 'secret-clone.example.test', $encoded );
		$this->assertStringNotContainsString( 'secret_prefix_', $encoded );
		$this->assertStringNotContainsString( $this->clone_path, $encoded );
		$this->assertSame( 0, $this->staging->preview_calls );
	}

	/** Guards WP Staging never records report as unobserved, not as observed-off. */
	public function test_inventory_reports_unknown_state_and_guards_when_the_provider_records_none() {
		unset(
			$this->staging->clones['provider-clone-secret']['status'],
			$this->staging->clones['provider-clone-secret']['isolated'],
			$this->staging->clones['provider-clone-secret']['searchIndexBlocked'],
			$this->staging->clones['provider-clone-secret']['outboundSideEffectsBlocked']
		);

		$row = $this->invoke_v2( 'inventory', array() )['clones'][0];

		$this->assertSame( 'unknown', $row['state'] );
		$this->assertNull( $row['isolated'] );
		$this->assertNull( $row['search_index_blocked'] );
		$this->assertNull( $row['outbound_side_effects_blocked'] );
	}

	/** An interrupted clone is not reported as ready. */
	public function test_inventory_reports_an_unfinished_clone_as_incomplete() {
		$this->staging->clones['provider-clone-secret']['status'] = 'unfinished';

		$this->assertSame( 'incomplete', $this->invoke_v2( 'inventory', array() )['clones'][0]['state'] );
	}

	/** The pre-registry option fallback cannot claim a complete clone set. */
	public function test_legacy_option_fallback_is_not_reported_as_complete() {
		$this->assertTrue( $this->invoke_v2( 'inventory', array() )['complete'] );

		$this->staging->clone_source = 'legacy_option';

		$this->assertFalse( $this->invoke_v2( 'inventory', array() )['complete'] );
	}

	/** Settings are clamped to the public policy and private fields are absent. */
	public function test_settings_are_bounded_and_redacted() {
		$this->staging->settings = array(
			'queryLimit'        => 999999,
			'fileLimit'         => 500,
			'batchSize'         => 0,
			'maxFileSize'       => 5000,
			'cpuLoad'           => 'default',
			'delayRequests'     => 999,
			'debugMode'         => 1,
			'disableAdminLogin' => 1,
			'privatePath'       => '/secret',
		);
		$result                  = $this->invoke_v2( 'settings', array() );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 10000, $result['query_limit'] );
		$this->assertSame( 500, $result['file_limit'] );
		$this->assertSame( 1, $result['batch_size_mb'] );
		$this->assertSame( 1024, $result['max_file_size_mb'] );
		$this->assertSame( 'low', $result['cpu_load'] );
		$this->assertSame( 60, $result['delay_seconds'] );
		$this->assertTrue( $result['debug_enabled'] );
		$this->assertStringNotContainsString( 'disableAdminLogin', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( '/secret', wp_json_encode( $result ) );
	}

	/** Preview is exact, read-only and bound to the opaque inventory generation. */
	public function test_preview_validates_clone_reference_before_provider_work() {
		$inventory = $this->invoke_v2( 'inventory', array() );
		$clone_ref = $inventory['clones'][0]['clone_ref'];
		$result    = $this->invoke_v2(
			'preview',
			array(
				'kind'      => 'update',
				'clone_ref' => $clone_ref,
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $inventory['inventory_revision'], $result['inventory_revision'] );
		$this->assertSame( 25, $result['table_count'] );
		$this->assertSame( 1, $this->staging->preview_calls );

		$missing = $this->invoke_v2(
			'preview',
			array(
				'kind'      => 'update',
				'clone_ref' => str_repeat( 'a', 64 ),
			)
		);
		$this->assertFalse( $missing['ok'] );
		$this->assertSame( 'clone_not_found', $missing['error_code'] );
		$this->assertSame( 1, $this->staging->preview_calls );
	}

	/** Malformed requests fail before any provider preview effect. */
	public function test_malformed_requests_fail_closed_before_provider_work() {
		$cases = array(
			null,
			array( 'protocol' => '1', 'operation' => 'inventory', 'payload' => array() ),
			array( 'protocol' => '2', 'operation' => 'inventory', 'payload' => array(), 'extra' => true ),
			array( 'protocol' => '2', 'operation' => 'preview', 'payload' => array( 'kind' => 'create', 'clone_ref' => str_repeat( 'a', 64 ) ) ),
			array( 'protocol' => '2', 'operation' => 'preview', 'payload' => array( 'kind' => 'update', 'clone_ref' => null ) ),
			array( 'protocol' => '2', 'operation' => 'inventory', 'payload' => 'not-an-array' ),
			array( 'protocol' => '2', 'operation' => 'inventory' ),
			array( 'protocol' => '2', 'operation' => 'create_clone', 'request_ref' => '123e4567-e89b-42d3-a456-426614174940', 'payload' => 'not-an-array' ),
			array( 'protocol' => '2', 'operation' => 'create_clone', 'request_ref' => '123e4567-e89b-42d3-a456-426614174941', 'payload' => array( 'inventory_revision' => str_repeat( 'a', 64 ) ), 'extra' => true ),
			array( 'protocol' => '2', 'operation' => 'create_clone', 'payload' => array( 'inventory_revision' => str_repeat( 'a', 64 ) ) ),
		);
		foreach ( $cases as $case ) {
			$result = $this->staging->abilities_v2( $case );
			$this->assertFalse( $result['ok'] );
			$this->assertArrayHasKey( 'error_code', $result );
		}
		$this->assertSame( 0, $this->staging->preview_calls );
		$this->assertSame( array(), $this->staging->operation_calls );
	}

	/** Settings replacement preserves hidden fields and replays without a second write. */
	public function test_replace_settings_is_cas_bound_and_replay_safe() {
		$this->staging->settings = array(
			'queryLimit'        => 1000,
			'fileLimit'         => 500,
			'batchSize'         => 10,
			'maxFileSize'       => 50,
			'cpuLoad'           => 'medium',
			'delayRequests'     => 1,
			'debugMode'         => 0,
			'disableAdminLogin' => 0,
		);
		$current = $this->invoke_v2( 'settings', array() );
		$request = array(
			'protocol'    => '2',
			'operation'   => 'replace_settings',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174930',
			'payload'     => array(
				'if_match' => $current['revision'],
				'settings' => array(
					'query_limit'      => 900,
					'file_limit'       => 250,
					'batch_size_mb'    => 20,
					'max_file_size_mb' => 100,
					'cpu_load'         => 'low',
					'delay_seconds'    => 2,
					'debug_enabled'    => true,
				),
			),
		);
		$result = $this->staging->abilities_v2( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $this->staging->settings_writes );
		$this->assertSame( 0, $this->staging->settings['disableAdminLogin'] );
		$this->assertSame( 900, $this->staging->settings['queryLimit'] );

		$this->assertSame( $result, $this->staging->abilities_v2( $request ) );
		$this->assertSame( 1, $this->staging->settings_writes );

		$request['payload']['settings']['query_limit'] = 800;
		$conflict = $this->staging->abilities_v2( $request );
		$this->assertFalse( $conflict['ok'] );
		$this->assertSame( 'request_conflict', $conflict['error_code'] );
		$this->assertSame( 1, $this->staging->settings_writes );
	}

	/** Clone mutations use MCP-safe request references and exact receipt replay. */
	public function test_clone_mutation_contract_is_typed_and_replay_safe() {
		$this->staging->operation_results['create_clone'] = array(
			'operation_ref' => '123e4567-e89b-42d3-a456-426614174931',
			'clone_ref'     => null,
			'status'        => 'queued',
		);
		$request = array(
			'protocol'    => '2',
			'operation'   => 'create_clone',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174932',
			'payload'     => array( 'inventory_revision' => str_repeat( 'a', 64 ) ),
		);
		$result = $this->staging->abilities_v2( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( $request['request_ref'], $result['request_ref'] );
		$this->assertSame( $result, $this->staging->abilities_v2( $request ) );
		$this->assertCount( 1, $this->staging->operation_calls );

		$request['payload']['inventory_revision'] = str_repeat( 'b', 64 );
		$this->assertSame( 'request_conflict', $this->staging->abilities_v2( $request )['error_code'] );
		unset( $request['request_ref'] );
		$request['request_id'] = '123e4567-e89b-42d3-a456-426614174933';
		$this->assertSame( 'invalid_request', $this->staging->abilities_v2( $request )['error_code'] );
	}

	/** Status and reconciliation shapes reject raw or over-broad provider data. */
	public function test_operation_status_and_reconciliation_results_are_closed() {
		$operation_ref = '123e4567-e89b-42d3-a456-426614174934';
		$this->staging->operation_results['operation_status'] = array(
			'operation_ref'   => $operation_ref,
			'kind'            => 'update',
			'status'          => 'running',
			'progress_percent' => 40,
			'current_step'    => 'files',
			'generation'      => str_repeat( 'c', 64 ),
		);
		$status = $this->invoke_v2( 'operation_status', array( 'operation_ref' => $operation_ref ) );
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 40, $status['progress_percent'] );

		$this->staging->operation_results['reconcile_operation'] = array(
			'operation_ref' => $operation_ref,
			'status'        => 'queued',
			'affected_steps' => 2,
			'generation'    => str_repeat( 'd', 64 ),
		);
		$reconcile = $this->staging->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'reconcile_operation',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174935',
				'payload'     => array( 'operation_ref' => $operation_ref, 'if_match' => str_repeat( 'c', 64 ) ),
			)
		);
		$this->assertTrue( $reconcile['ok'] );
		$this->assertSame( 2, $reconcile['affected_steps'] );
	}

	/** Legacy action names remain available. */
	public function test_legacy_dispatcher_contract_is_preserved() {
		$source = file_get_contents( dirname( __DIR__ ) . '/class/class-mainwp-child-staging.php' );
		foreach ( array( 'get_overview', 'get_scan', 'start_clone', 'clone_database', 'copy_files', 'delete_clone', 'staging_update' ) as $action ) {
			$this->assertStringContainsString( "case '" . $action . "':", $source );
		}
	}

	/** @param string $operation Operation. @param array $payload Payload. @return array */
	private function invoke_v2( $operation, $payload ) {
		return $this->staging->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => $operation,
				'payload'   => $payload,
			)
		);
	}
}
