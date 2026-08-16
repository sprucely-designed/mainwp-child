<?php
/**
 * BackWPup abilities v2 protocol tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Back_WP_Up;

/**
 * Deterministic provider boundary for typed BackWPup tests.
 */
class Test_MainWP_Child_Back_WP_Up_V2_Fixture extends MainWP_Child_Back_WP_Up {

	/** @var array */
	public $fixture_options = array();

	/** @var array */
	public $provider_results = array();

	/** @var array */
	public $provider_calls = array();

	/**
	 * Avoid loading BackWPup.
	 *
	 * @param array $options Options by job ID.
	 * @param array $results Provider results by operation.
	 */
	public function __construct( $options = array(), $results = array() ) {
		$this->fixture_options  = $options;
		$this->provider_results = $results;
	}

	/** @return int[] */
	protected function abilities_v2_get_job_ids() {
		return array_keys( $this->fixture_options );
	}

	/**
	 * @param int    $job_id Job ID.
	 * @param string $key    Option key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	protected function abilities_v2_get_job_option( $job_id, $key, $default = null ) {
		return array_key_exists( $key, $this->fixture_options[ $job_id ] ) ? $this->fixture_options[ $job_id ][ $key ] : $default;
	}

	/**
	 * @param int    $job_id Job ID.
	 * @param string $key    Option key.
	 * @param mixed  $value  Value.
	 */
	protected function abilities_v2_set_job_option( $job_id, $key, $value ) {
		$this->fixture_options[ $job_id ][ $key ] = $value;
	}

	/**
	 * @param int $job_id Job ID.
	 * @return mixed
	 */
	protected function abilities_v2_provider_delete_job( $job_id ) {
		$this->provider_calls[] = array( 'delete_job', $job_id );
		return $this->provider_results['delete_job'];
	}

	/**
	 * @param int $job_id Job ID.
	 * @return mixed
	 */
	protected function abilities_v2_provider_start_backup( $job_id ) {
		$this->provider_calls[] = array( 'start_backup', $job_id );
		return $this->provider_results['start_backup'];
	}

	/**
	 * @param array $target   Internal target.
	 * @param int   $position Log position.
	 * @return mixed
	 */
	protected function abilities_v2_provider_backup_progress( $target, $position ) {
		$this->provider_calls[] = array( 'backup_progress', $target, $position );
		return $this->provider_results['backup_progress'];
	}

	/** @return mixed */
	protected function abilities_v2_provider_abort_backup() {
		$this->provider_calls[] = array( 'abort_backup' );
		return $this->provider_results['abort_backup'];
	}

	/**
	 * @param string $scope Scope.
	 * @return mixed
	 */
	protected function abilities_v2_provider_list_backups( $scope ) {
		$this->provider_calls[] = array( 'list_backups', $scope );
		return $this->provider_results['list_backups'];
	}

	/**
	 * @param array $target Internal target.
	 * @return mixed
	 */
	protected function abilities_v2_provider_delete_backup( $target ) {
		$this->provider_calls[] = array( 'delete_backup', $target );
		return $this->provider_results['delete_backup'];
	}

	/**
	 * @param array $target Internal target.
	 * @return mixed
	 */
	protected function abilities_v2_provider_redeem_backup_download( $target ) {
		$this->provider_calls[] = array( 'redeem_backup_download', $target );
		return $this->provider_results['redeem_backup_download'];
	}

	/**
	 * @param string $scope Scope.
	 * @return mixed
	 */
	protected function abilities_v2_provider_list_logs( $scope ) {
		$this->provider_calls[] = array( 'list_logs', $scope );
		return $this->provider_results['list_logs'];
	}

	/**
	 * @param array $target    Internal target.
	 * @param int   $offset    Offset.
	 * @param int   $max_chars Maximum characters.
	 * @return mixed
	 */
	protected function abilities_v2_provider_read_log( $target, $offset, $max_chars ) {
		$this->provider_calls[] = array( 'read_log_excerpt', $target, $offset, $max_chars );
		return $this->provider_results['read_log_excerpt'];
	}

	/**
	 * @param array $target Internal target.
	 * @return mixed
	 */
	protected function abilities_v2_provider_delete_log( $target ) {
		$this->provider_calls[] = array( 'delete_log', $target );
		return $this->provider_results['delete_log'];
	}

	/** @return mixed */
	protected function abilities_v2_provider_diagnostics() {
		$this->provider_calls[] = array( 'diagnostics' );
		return $this->provider_results['diagnostics'];
	}
}

/**
 * BackWPup abilities v2 contract tests.
 */
class Test_MainWP_Child_Back_WP_Up_Abilities_V2 extends WP_UnitTestCase {

	/**
	 * Reset request globals after each test.
	 */
	public function tearDown(): void {
		delete_site_option( 'mainwp_backwpup_hide_plugin' );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	/**
	 * Invoke the typed protocol handler without writing the HTTP response.
	 *
	 * @param array $request Typed request.
	 * @return array
	 */
	private function invoke_v2( $request, $object = null ) {
		$this->assertTrue( method_exists( MainWP_Child_Back_WP_Up::class, 'abilities_v2' ) );
		$method = new ReflectionMethod( MainWP_Child_Back_WP_Up::class, 'abilities_v2' );
		$method->setAccessible( true );
		if ( null === $object ) {
			$object = ( new ReflectionClass( MainWP_Child_Back_WP_Up::class ) )->newInstanceWithoutConstructor();
		}

		return $method->invoke( $object, $request );
	}

	/**
	 * Invoke one protected provider adapter.
	 *
	 * @param object $object Object.
	 * @param string $method_name Method name.
	 * @param array  $arguments Arguments.
	 * @return mixed
	 */
	private function invoke_provider( $object, $method_name, $arguments = array() ) {
		$this->assertTrue( method_exists( MainWP_Child_Back_WP_Up::class, $method_name ) );
		$method = new ReflectionMethod( MainWP_Child_Back_WP_Up::class, $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( $object, $arguments );
	}

	/**
	 * Return a deterministic BackWPup option boundary.
	 *
	 * @param array $options Stored options keyed by job ID.
	 * @return MainWP_Child_Back_WP_Up
	 */
	private function option_fixture( $options ) {
		return new Test_MainWP_Child_Back_WP_Up_V2_Fixture( $options );
	}

	/**
	 * Unknown operations return the closed redacted protocol envelope.
	 */
	public function test_unknown_operation_fails_closed() {
		$result = $this->invoke_v2(
			array(
				'operation' => 'not_supported',
				'payload'   => array(),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'error' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertSame( 'not_supported', $result['operation'] );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'code', 'message' ), array_keys( $result['error'] ) );
		$this->assertSame( 'unsupported_operation', $result['error']['code'] );
		$this->assertLessThanOrEqual( 1000, strlen( $result['error']['message'] ) );
	}

	/**
	 * Root and paging objects are exact, while JSON object order is irrelevant.
	 */
	public function test_request_and_list_inputs_are_closed_and_order_independent() {
		$reordered = $this->invoke_v2(
			array(
				'payload'   => array(),
				'operation' => 'abort_backup',
			),
			new Test_MainWP_Child_Back_WP_Up_V2_Fixture(
				array(),
				array( 'abort_backup' => array( 'abort_requested' => false, 'state' => 'not_running', 'message' => 'No backup is running.' ) )
			)
		);
		$this->assertTrue( $reordered['ok'] );

		$wire = $this->invoke_v2(
			array(
				'operation'    => 'abort_backup',
				'payload_json' => '{}',
			),
			new Test_MainWP_Child_Back_WP_Up_V2_Fixture(
				array(),
				array( 'abort_backup' => array( 'abort_requested' => false, 'state' => 'not_running', 'message' => 'No backup is running.' ) )
			)
		);
		$this->assertTrue( $wire['ok'] );

		$malformed_wire = $this->invoke_v2( array( 'operation' => 'diagnostics', 'payload_json' => '[]' ) );
		$this->assertFalse( $malformed_wire['ok'] );
		$this->assertSame( 'invalid_request', $malformed_wire['error']['code'] );

		$extra = $this->invoke_v2( array( 'operation' => 'diagnostics', 'payload' => array(), 'extra' => true ) );
		$this->assertFalse( $extra['ok'] );
		$this->assertSame( 'invalid_request', $extra['error']['code'] );

		$fixture = new Test_MainWP_Child_Back_WP_Up_V2_Fixture( array(), array( 'list_backups' => array() ) );
		foreach ( array( 0, 1001 ) as $page ) {
			$result = $this->invoke_v2( array( 'operation' => 'list_backups', 'payload' => array( 'page' => $page, 'per_page' => 25, 'scope' => 'all' ) ), $fixture );
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'invalid_input', $result['error']['code'] );
		}
		$this->assertSame( array(), $fixture->provider_calls );
	}

	/**
	 * Visibility accepts only a boolean and truthfully distinguishes a no-op.
	 */
	public function test_visibility_is_typed_and_idempotent() {
		$hidden = $this->invoke_v2(
			array(
				'operation' => 'set_visibility',
				'payload'   => array( 'hidden' => true ),
			)
		);
		$this->assertSame(
			array(
				'protocol'  => '2',
				'operation' => 'set_visibility',
				'ok'        => true,
				'data'      => array( 'hidden' => true, 'updated' => true ),
			),
			$hidden
		);
		$this->assertSame( 'hide', get_site_option( 'mainwp_backwpup_hide_plugin' ) );

		$replayed = $this->invoke_v2(
			array(
				'operation' => 'set_visibility',
				'payload'   => array( 'hidden' => true ),
			)
		);
		$this->assertSame( array( 'hidden' => true, 'updated' => false ), $replayed['data'] );

		$invalid = $this->invoke_v2(
			array(
				'operation' => 'set_visibility',
				'payload'   => array( 'hidden' => 'true' ),
			)
		);
		$this->assertFalse( $invalid['ok'] );
		$this->assertSame( 'invalid_input', $invalid['error']['code'] );
		$this->assertSame( 'hide', get_site_option( 'mainwp_backwpup_hide_plugin' ) );
	}

	/**
	 * Stored basic schedules are projected into the closed typed shape.
	 */
	public function test_get_job_schedule_projects_basic_schedule() {
		$fixture = $this->option_fixture(
			array(
				7 => array(
					'activetype'      => 'wpcron',
					'cronselect'      => 'basic',
					'cronbtype'       => 'week',
					'weekcronwday'    => '2',
					'weekcronhours'   => '4',
					'weekcronminutes' => '30',
				),
			)
		);

		$result = $this->invoke_v2(
			array(
				'operation' => 'get_job_schedule',
				'payload'   => array( 'job_id' => 7 ),
			),
			$fixture
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame(
			array(
				'job_id'   => 7,
				'schedule' => array( 'mode' => 'weekly', 'weekday' => 2, 'hour' => 4, 'minute' => 30 ),
			),
			$result['data']
		);
	}

	/**
	 * Current BackWPup frequency plus cron storage is projected exactly.
	 */
	public function test_get_job_schedule_projects_current_frequency_storage() {
		$fixture = $this->option_fixture(
			array(
				7 => array(
					'activetype' => 'wpcron',
					'cronselect' => 'basic',
					'frequency'  => 'monthly',
					'cron'       => '0 0 1 * *',
				),
			)
		);

		$result = $this->invoke_v2(
			array(
				'operation' => 'get_job_schedule',
				'payload'   => array( 'job_id' => 7 ),
			),
			$fixture
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame(
			array(
				'job_id'   => 7,
				'schedule' => array( 'mode' => 'monthly', 'day' => 1, 'hour' => 0, 'minute' => 0 ),
			),
			$result['data']
		);
	}

	/**
	 * Schedule updates preserve unrelated provider settings and verify a no-op.
	 */
	public function test_update_job_schedule_preserves_unrelated_settings() {
		$fixture = $this->option_fixture(
			array(
				7 => array(
					'activetype' => '',
					'ftppass'    => 'encrypted-secret',
				),
			)
		);
		$request = array(
			'operation' => 'update_job_schedule',
			'payload'   => array(
				'job_id'   => 7,
				'schedule' => array( 'mode' => 'daily', 'hour' => 3, 'minute' => 15 ),
			),
		);

		$result = $this->invoke_v2( $request, $fixture );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'job_id' => 7, 'updated' => true, 'schedule' => $request['payload']['schedule'] ), $result['data'] );
		$this->assertSame( 'encrypted-secret', $fixture->fixture_options[7]['ftppass'] );
		$this->assertSame( 'wpcron', $fixture->fixture_options[7]['activetype'] );
		$this->assertSame( 'basic', $fixture->fixture_options[7]['cronselect'] );
		$this->assertSame( 'day', $fixture->fixture_options[7]['cronbtype'] );
		$this->assertSame( 'daily', $fixture->fixture_options[7]['frequency'] );
		$this->assertSame( '15 3 * * *', $fixture->fixture_options[7]['cron'] );

		$replayed = $this->invoke_v2( $request, $fixture );
		$this->assertSame( false, $replayed['data']['updated'] );
	}

	/**
	 * Invalid schedule values are rejected before any option changes.
	 */
	public function test_update_job_schedule_rejects_invalid_values() {
		$fixture = $this->option_fixture( array( 7 => array( 'activetype' => '', 'ftppass' => 'encrypted-secret' ) ) );
		$before  = $fixture->fixture_options;

		$result = $this->invoke_v2(
			array(
				'operation' => 'update_job_schedule',
				'payload'   => array(
					'job_id'   => 7,
					'schedule' => array( 'mode' => 'daily', 'hour' => 24, 'minute' => 0 ),
				),
			),
			$fixture
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_input', $result['error']['code'] );
		$this->assertSame( $before, $fixture->fixture_options );
	}

	/**
	 * Every accepted schedule variant round-trips through the typed projection.
	 */
	public function test_all_schedule_modes_round_trip() {
		$schedules = array(
			array( 'mode' => 'manual' ),
			array( 'mode' => 'hourly', 'minute' => 17 ),
			array( 'mode' => 'daily', 'hour' => 3, 'minute' => 18 ),
			array( 'mode' => 'weekly', 'weekday' => 2, 'hour' => 4, 'minute' => 19 ),
			array( 'mode' => 'monthly', 'day' => 20, 'hour' => 5, 'minute' => 21 ),
			array( 'mode' => 'advanced', 'expression' => '7 6 5 4 3' ),
		);
		foreach ( $schedules as $schedule ) {
			$fixture = $this->option_fixture( array( 7 => array( 'activetype' => '', 'ftppass' => 'encrypted-secret' ) ) );
			$updated = $this->invoke_v2( array( 'operation' => 'update_job_schedule', 'payload' => array( 'job_id' => 7, 'schedule' => $schedule ) ), $fixture );
			$this->assertTrue( $updated['ok'], wp_json_encode( $schedule ) );
			$this->assertSame( $schedule, $updated['data']['schedule'] );
			$this->assertSame( 'encrypted-secret', $fixture->fixture_options[7]['ftppass'] );
			$read = $this->invoke_v2( array( 'operation' => 'get_job_schedule', 'payload' => array( 'job_id' => 7 ) ), $fixture );
			$this->assertSame( $schedule, $read['data']['schedule'] );
		}
	}

	/**
	 * Job deletion returns a stable terminal result without exposing provider data.
	 */
	public function test_delete_job_is_structured_and_idempotent() {
		$fixture = new Test_MainWP_Child_Back_WP_Up_V2_Fixture(
			array( 7 => array( 'activetype' => '' ) ),
			array( 'delete_job' => 'deleted' )
		);

		$result = $this->invoke_v2(
			array( 'operation' => 'delete_job', 'payload' => array( 'job_id' => 7 ) ),
			$fixture
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'job_id' => 7, 'deleted' => true, 'already_absent' => false ), $result['data'] );
		$this->assertSame( array( array( 'delete_job', 7 ) ), $fixture->provider_calls );

		$fixture->provider_results['delete_job'] = 'absent';
		$absent = $this->invoke_v2( array( 'operation' => 'delete_job', 'payload' => array( 'job_id' => 7 ) ), $fixture );
		$this->assertSame( array( 'job_id' => 7, 'deleted' => false, 'already_absent' => true ), $absent['data'] );
	}

	/**
	 * Start returns an opaque token and progress resolves it without exposing a logfile.
	 */
	public function test_start_and_progress_use_an_opaque_run_token() {
		$fixture = new Test_MainWP_Child_Back_WP_Up_V2_Fixture(
			array( 7 => array( 'activetype' => '' ) ),
			array(
				'start_backup'    => array(
					'accepted'      => true,
					'job_id'        => 7,
					'logfile'       => 'backwpup_log_fixture.html',
					'last_backup_at' => 123,
					'message'       => 'Started.',
				),
				'backup_progress' => array(
					'state'            => 'running',
					'progress_percent' => 25,
					'log_position'     => 80,
					'last_backup_at'   => 123,
					'message'          => 'Running.',
				),
			)
		);

		$started = $this->invoke_v2( array( 'operation' => 'start_backup', 'payload' => array( 'job_id' => 7 ) ), $fixture );

		$this->assertTrue( $started['ok'] );
		$this->assertTrue( $started['data']['accepted'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/D', $started['data']['run_token'] );
		$this->assertArrayNotHasKey( 'logfile', $started['data'] );
		$run_key = 'mainwp_backwpup_v2_' . hash( 'sha256', $started['data']['run_token'] );
		$this->assertGreaterThanOrEqual( time() + DAY_IN_SECONDS - 5, (int) get_option( '_transient_timeout_' . $run_key ) );

		$progress = $this->invoke_v2(
			array(
				'operation' => 'backup_progress',
				'payload'   => array( 'run_token' => $started['data']['run_token'], 'log_position' => 0 ),
			),
			$fixture
		);

		$this->assertTrue( $progress['ok'] );
		$this->assertSame( $fixture->provider_results['backup_progress'], $progress['data'] );
		$this->assertSame( 'backwpup_log_fixture.html', $fixture->provider_calls[1][1]['logfile'] );
	}

	/**
	 * Abort reports the provider's exact current-work state.
	 */
	public function test_abort_backup_returns_closed_state() {
		$fixture = new Test_MainWP_Child_Back_WP_Up_V2_Fixture(
			array(),
			array( 'abort_backup' => array( 'abort_requested' => false, 'state' => 'not_running', 'message' => 'No backup is running.' ) )
		);

		$result = $this->invoke_v2( array( 'operation' => 'abort_backup', 'payload' => array() ), $fixture );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $fixture->provider_results['abort_backup'], $result['data'] );
		$this->assertSame( array( array( 'abort_backup' ) ), $fixture->provider_calls );

	}

	/**
	 * Backup lists paginate deterministically and replace targets with action tokens.
	 */
	public function test_list_and_delete_backup_keep_provider_targets_private() {
		$internal = array( 'destination_key' => '7_FOLDER', 'file' => '/private/provider/archive.zip' );
		$fixture  = new Test_MainWP_Child_Back_WP_Up_V2_Fixture(
			array(),
			array(
				'list_backups'  => array(
					array( 'job_id' => 7, 'destination' => 'FOLDER', 'file_name' => 'older.zip', 'created_at' => 100, 'size_bytes' => 12, 'target' => array( 'destination_key' => '7_FOLDER', 'file' => '/private/provider/older.zip' ) ),
					array( 'job_id' => 7, 'destination' => 'FOLDER', 'file_name' => 'archive.zip', 'created_at' => 200, 'size_bytes' => 24, 'target' => $internal ),
				),
				'delete_backup' => 'deleted',
			)
		);

		$list = $this->invoke_v2(
			array( 'operation' => 'list_backups', 'payload' => array( 'page' => 1, 'per_page' => 1, 'scope' => 'all' ) ),
			$fixture
		);

		$this->assertTrue( $list['ok'] );
		$this->assertSame( 2, $list['data']['total'] );
		$this->assertCount( 1, $list['data']['backups'] );
		$row = $list['data']['backups'][0];
		$this->assertSame( 'archive.zip', $row['file_name'] );
		$this->assertArrayNotHasKey( 'target', $row );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/D', $row['download_token'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/D', $row['delete_token'] );
		$this->assertNotSame( $row['download_token'], $row['delete_token'] );
		$this->assertStringNotContainsString( '/private/', wp_json_encode( $list ) );

		$deleted = $this->invoke_v2( array( 'operation' => 'delete_backup', 'payload' => array( 'delete_token' => $row['delete_token'] ) ), $fixture );
		$this->assertSame( array( 'deleted' => true, 'already_absent' => false ), $deleted['data'] );
		$this->assertSame( array( 'delete_backup', $internal ), $fixture->provider_calls[1] );

		$wrong_action = $this->invoke_v2( array( 'operation' => 'delete_backup', 'payload' => array( 'delete_token' => $row['download_token'] ) ), $fixture );
		$this->assertFalse( $wrong_action['ok'] );
		$this->assertSame( 'target_not_found', $wrong_action['error']['code'] );
	}

	/**
	 * Download redemption keeps the provider target private and action-bound.
	 */
	public function test_redeem_backup_download_returns_only_a_bounded_folder_target() {
		$internal = array( 'destination_key' => '7_FOLDER', 'file' => '/private/provider/archive.zip' );
		$target   = array( 'folder' => '/var/www/html/wp-content/uploads/backwpup', 'file_name' => 'archive.zip', 'size_bytes' => 24 );
		$fixture  = new Test_MainWP_Child_Back_WP_Up_V2_Fixture(
			array(),
			array(
				'list_backups'           => array(
					array( 'job_id' => 7, 'destination' => 'FOLDER', 'file_name' => 'archive.zip', 'created_at' => 200, 'size_bytes' => 24, 'target' => $internal ),
				),
				'redeem_backup_download' => $target,
			)
		);

		$list  = $this->invoke_v2( array( 'operation' => 'list_backups', 'payload' => array( 'page' => 1, 'per_page' => 25, 'scope' => 'all' ) ), $fixture );
		$token = $list['data']['backups'][0]['download_token'];
		$result = $this->invoke_v2( array( 'operation' => 'redeem_backup_download', 'payload' => array( 'download_token' => $token ) ), $fixture );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $target, $result['data'] );
		$this->assertSame( array( 'redeem_backup_download', $internal ), $fixture->provider_calls[1] );
		$this->assertStringNotContainsString( '/private/', wp_json_encode( $result ) );

		$wrong_action = $this->invoke_v2( array( 'operation' => 'redeem_backup_download', 'payload' => array( 'download_token' => str_repeat( 'x', 43 ) ) ), $fixture );
		$this->assertFalse( $wrong_action['ok'] );
		$this->assertSame( 'target_not_found', $wrong_action['error']['code'] );

		foreach (
			array(
				array( 'folder' => '', 'file_name' => 'archive.zip', 'size_bytes' => 24 ),
				array( 'folder' => '/var/www/html/wp-content/uploads/backwpup', 'file_name' => '../archive.zip', 'size_bytes' => 24 ),
				array( 'download_url' => 'https://child.example/archive.zip', 'size_bytes' => 24 ),
			) as $index => $invalid
		) {
			$fixture->provider_results['redeem_backup_download'] = $invalid;
			$malformed = $this->invoke_v2( array( 'operation' => 'redeem_backup_download', 'payload' => array( 'download_token' => $token ) ), $fixture );
			$this->assertFalse( $malformed['ok'], (string) $index );
			$this->assertSame( 'operation_failed', $malformed['error']['code'], (string) $index );
		}
	}

	/**
	 * Log tokens preserve the exact hidden target for bounded reads and deletion.
	 */
	public function test_list_read_and_delete_log_use_action_bound_tokens() {
		$internal = array( 'file' => '/private/logs/backwpup_log_fixture.html.gz' );
		$fixture  = new Test_MainWP_Child_Back_WP_Up_V2_Fixture(
			array(),
			array(
				'list_logs'       => array(
					array( 'job_name' => 'Nightly', 'started_at' => 200, 'size_bytes' => 100, 'runtime_seconds' => 5, 'errors' => 0, 'warnings' => 1, 'job_types' => array( 'DBDUMP' ), 'target' => $internal ),
				),
				'read_log_excerpt' => array( 'content' => 'Safe excerpt.', 'offset' => 0, 'next_offset' => 13, 'truncated' => false ),
				'delete_log'       => 'absent',
			)
		);

		$list = $this->invoke_v2( array( 'operation' => 'list_logs', 'payload' => array( 'page' => 1, 'per_page' => 25, 'scope' => 'all' ) ), $fixture );
		$this->assertTrue( $list['ok'] );
		$row = $list['data']['logs'][0];
		$this->assertArrayNotHasKey( 'target', $row );
		$this->assertNotSame( $row['view_token'], $row['delete_token'] );
		$this->assertStringNotContainsString( '/private/', wp_json_encode( $list ) );

		$read = $this->invoke_v2( array( 'operation' => 'read_log_excerpt', 'payload' => array( 'view_token' => $row['view_token'], 'offset' => 0, 'max_chars' => 100 ) ), $fixture );
		$this->assertSame( $fixture->provider_results['read_log_excerpt'], $read['data'] );
		$this->assertSame( array( 'read_log_excerpt', $internal, 0, 100 ), $fixture->provider_calls[1] );

		$deleted = $this->invoke_v2( array( 'operation' => 'delete_log', 'payload' => array( 'delete_token' => $row['delete_token'] ) ), $fixture );
		$this->assertSame( array( 'deleted' => false, 'already_absent' => true ), $deleted['data'] );
	}

	/**
	 * Diagnostics accept only the closed safe projection.
	 */
	public function test_diagnostics_are_closed_and_bounded() {
		$data = array(
			'backwpup_edition' => 'free',
			'backwpup_version' => '5.1.0',
			'wordpress_version' => '6.8.1',
			'php_version'      => '8.3.0',
			'cron_status'      => 'enabled',
			'temp_status'      => 'writable',
			'logs_status'      => 'writable',
			'self_connect'     => 'ok',
			'issues'           => array(),
		);
		$fixture = new Test_MainWP_Child_Back_WP_Up_V2_Fixture( array(), array( 'diagnostics' => $data ) );

		$result = $this->invoke_v2( array( 'operation' => 'diagnostics', 'payload' => array() ), $fixture );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $data, $result['data'] );
		$this->assertSame( array( array( 'diagnostics' ) ), $fixture->provider_calls );

		$fixture->provider_results['diagnostics']['path'] = '/private/secret';
		$invalid = $this->invoke_v2( array( 'operation' => 'diagnostics', 'payload' => array() ), $fixture );
		$this->assertFalse( $invalid['ok'] );
		$this->assertSame( 'operation_failed', $invalid['error']['code'] );
	}

	/**
	 * The real backup source adapter normalizes provider rows without list-table writes.
	 */
	public function test_provider_backup_source_normalizes_rows() {
		$destination = new class() {
			/** @return array */
			public function file_get_list() {
				return array(
					array( 'file' => '/provider/private/archive.zip', 'filename' => 'archive.zip', 'filesize' => 24, 'time' => 200 ),
				);
			}
		};
		$fixture = new class( $destination ) extends MainWP_Child_Back_WP_Up {
			/** @var object */
			private $destination;

			/** @param object $destination Destination. */
			public function __construct( $destination ) {
				$this->destination = $destination;
			}

			/** @return int[] */
			protected function abilities_v2_get_job_ids() {
				return array( 7 );
			}

			/**
			 * @param int    $job_id Job ID.
			 * @param string $key Option key.
			 * @param mixed  $default Default.
			 * @return mixed
			 */
			protected function abilities_v2_get_job_option( $job_id, $key, $default = null ) {
				if ( 'backuptype' === $key ) {
					return 'archive';
				}
				if ( 'destinations' === $key ) {
					return array( 'FOLDER' );
				}
				return $default;
			}

			/**
			 * @param string $destination Destination name.
			 * @return object
			 */
			protected function abilities_v2_get_destination( $destination ) {
				return $this->destination;
			}
		};

		$result = $this->invoke_provider( $fixture, 'abilities_v2_provider_list_backups', array( 'all' ) );

		$this->assertSame(
			array(
				array(
					'job_id'     => 7,
					'destination' => 'FOLDER',
					'file_name'   => 'archive.zip',
					'created_at'  => 200,
					'size_bytes'  => 24,
					'target'      => array( 'destination_key' => '7_FOLDER', 'file' => '/provider/private/archive.zip' ),
				),
			),
			$result
		);
	}

	/**
	 * The real log adapter streams, sanitizes, redacts and deletes an exact file.
	 */
	public function test_provider_log_source_is_bounded_and_redacted() {
		$temp_file = wp_tempnam( 'mainwp-backwpup-v2-log' );
		$this->assertIsString( $temp_file );
		wp_delete_file( $temp_file );
		$this->assertTrue( wp_mkdir_p( $temp_file ) );
		$log_file = trailingslashit( $temp_file ) . 'backwpup_log_fixture.html';
		$content  = '<meta name="backwpup_jobname" content="Nightly" />' .
			'<meta name="backwpup_jobtime" content="200" />' .
			'<meta name="backwpup_jobruntime" content="5" />' .
			'<meta name="backwpup_errors" content="0" />' .
			'<meta name="backwpup_warnings" content="1" />' .
			'<meta name="backwpup_jobtype" content="DBDUMP" />' .
			'<body><b>Password: top-secret</b> /private/provider/path https://provider.example.test/archive</body>';
		file_put_contents( $log_file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture.

		$fixture = new class( $temp_file ) extends MainWP_Child_Back_WP_Up {
			/** @var string */
			private $directory;

			/** @param string $directory Directory. */
			public function __construct( $directory ) {
				$this->directory = $directory;
			}

			/** @return string */
			protected function abilities_v2_log_directory() {
				return $this->directory;
			}
		};

		$rows = $this->invoke_provider( $fixture, 'abilities_v2_provider_list_logs', array( 'all' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Nightly', $rows[0]['job_name'] );
		$this->assertSame( array( 'DBDUMP' ), $rows[0]['job_types'] );

		$excerpt = $this->invoke_provider( $fixture, 'abilities_v2_provider_read_log', array( $rows[0]['target'], 0, 20000 ) );
		$this->assertStringNotContainsString( 'top-secret', $excerpt['content'] );
		$this->assertStringNotContainsString( '/private/', $excerpt['content'] );
		$this->assertStringNotContainsString( 'https://', $excerpt['content'] );
		$this->assertStringNotContainsString( '<b>', $excerpt['content'] );

		$this->assertSame( 'deleted', $this->invoke_provider( $fixture, 'abilities_v2_provider_delete_log', array( $rows[0]['target'] ) ) );
		$this->assertFileDoesNotExist( $log_file );
		$this->assertTrue( rmdir( $temp_file ) );
	}

	/**
	 * Plain, gzip and bzip2 logs are streamed without whole-file allocation.
	 */
	public function test_provider_log_streams_plain_gzip_and_bzip2() {
		$temp_dir = wp_tempnam( 'mainwp-backwpup-v2-compressed-log' );
		$this->assertIsString( $temp_dir );
		wp_delete_file( $temp_dir );
		$this->assertTrue( wp_mkdir_p( $temp_dir ) );
		$header = '<meta name="backwpup_jobname" content="Nightly" />' .
			'<meta name="backwpup_jobtime" content="200" />' .
			'<meta name="backwpup_jobruntime" content="5" />' .
			'<meta name="backwpup_errors" content="0" />' .
			'<meta name="backwpup_warnings" content="0" />' .
			'<meta name="backwpup_jobtype" content="DBDUMP" />';
		$content = $header . '<body>' . str_repeat( 'safe ', 300 ) . ' password: hidden-value https://provider.example.test/private</body>';
		$files   = array(
			'backwpup_log_plain.html'    => $content,
			'backwpup_log_gzip.html.gz'  => gzencode( $content ),
			'backwpup_log_bzip.html.bz2' => bzcompress( $content ),
		);
		foreach ( $files as $name => $bytes ) {
			$this->assertIsString( $bytes );
			file_put_contents( trailingslashit( $temp_dir ) . $name, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture.
		}

		$fixture = new class( $temp_dir ) extends MainWP_Child_Back_WP_Up {
			/** @var string */
			private $directory;

			/** @param string $directory Directory. */
			public function __construct( $directory ) {
				$this->directory = $directory;
			}

			/** @return string */
			protected function abilities_v2_log_directory() {
				return $this->directory;
			}
		};

		$rows = $this->invoke_provider( $fixture, 'abilities_v2_provider_list_logs', array( 'all' ) );
		$this->assertCount( 3, $rows );
		foreach ( $rows as $row ) {
			$excerpt = $this->invoke_provider( $fixture, 'abilities_v2_provider_read_log', array( $row['target'], 0, 100 ) );
			$this->assertIsArray( $excerpt );
			$this->assertLessThanOrEqual( 100, strlen( $excerpt['content'] ) );
			$this->assertTrue( $excerpt['truncated'] );
			$this->assertStringNotContainsString( '<meta', $excerpt['content'] );
		}

		foreach ( array_keys( $files ) as $name ) {
			wp_delete_file( trailingslashit( $temp_dir ) . $name );
		}
		$this->assertTrue( rmdir( $temp_dir ) );
	}

	/**
	 * The original visibility primitive remains callable and behavior-compatible.
	 */
	public function test_legacy_visibility_primitive_is_unchanged() {
		$fixture            = ( new ReflectionClass( MainWP_Child_Back_WP_Up::class ) )->newInstanceWithoutConstructor();
		$_POST['show_hide'] = 'hide';
		$result             = $this->invoke_provider( $fixture, 'show_hide' );
		$this->assertSame( array( 'success' => 1 ), $result );
		$this->assertSame( 'hide', get_site_option( 'mainwp_backwpup_hide_plugin' ) );
	}
}
