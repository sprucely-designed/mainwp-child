<?php
/**
 * WP Rocket abilities-v2 protocol tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_WP_Rocket;

/** Deterministic provider boundary for explicit database optimization. */
class Test_MainWP_Child_WP_Rocket_V2_Fixture extends MainWP_Child_WP_Rocket {

	/** @var array */
	public $provider_calls = array();

	/** @var mixed */
	public $provider_result = true;

	/** @var string|null IS_FREE_LOCK() observed while the provider ran. */
	public $lock_free_during_dispatch = null;

	/** Avoid the installed-plugin lookup. */
	public function __construct() {
		$this->is_plugin_installed = true;
	}

	/**
	 * Capture the exact provider categories.
	 *
	 * @param array $categories WP Rocket category keys.
	 * @return mixed
	 */
	protected function abilities_v2_provider_optimize_database( $categories ) {
		global $wpdb;
		$this->provider_calls[]          = $categories;
		$this->lock_free_during_dispatch = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $this->abilities_v2_lock_name() ) );
		return $this->provider_result;
	}

	/** @return string */
	public function fixture_lock_name() {
		return $this->abilities_v2_lock_name();
	}
}

/** WP Rocket protocol-v2 contract tests. */
class Test_MainWP_Child_WP_Rocket_V2 extends WP_UnitTestCase {

	/** @var Test_MainWP_Child_WP_Rocket_V2_Fixture */
	private $rocket;

	/** Set up a provider-free protocol handler. */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'mainwp_wp_rocket_abilities_v2_receipts' );
		$this->rocket = new Test_MainWP_Child_WP_Rocket_V2_Fixture();
	}

	/** Leave no receipts behind. */
	public function tearDown(): void {
		delete_option( 'mainwp_wp_rocket_abilities_v2_receipts' );
		parent::tearDown();
	}

	/** Capability negotiation publishes the exact additive protocol. */
	public function test_capability_negotiation_is_closed_and_exact() {
		$result = $this->invoke_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'categories' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertSame( 'capabilities', $result['operation'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'optimize_database' ), $result['operations'] );
		$this->assertSame( $this->public_categories(), $result['categories'] );
		$this->assertSame( array(), $this->rocket->provider_calls );
	}

	/** Every public category maps to the exact WP Rocket key once. */
	public function test_explicit_categories_are_mapped_once_without_settings_reads() {
		$result = $this->invoke_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'optimize_database',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174916',
				'payload'     => array( 'categories' => $this->public_categories() ),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'status', 'categories' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'requested', $result['status'] );
		$this->assertSame( $this->public_categories(), $result['categories'] );
		$this->assertSame(
			array(
				array(
					'database_revisions',
					'database_auto_drafts',
					'database_trashed_posts',
					'database_spam_comments',
					'database_trashed_comments',
					'database_expired_transients',
					'database_all_transients',
					'database_optimize_tables',
				),
			),
			$this->rocket->provider_calls
		);
	}

	/** A truthy provider return only proves the queue accepted the work, never that it ran. */
	public function test_accepted_optimization_reports_dispatch_not_completion() {
		$result = $this->invoke_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'optimize_database',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174916',
				'payload'     => array( 'categories' => array( 'revisions' ) ),
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertNotSame( 'completed', $result['status'] );
		$this->assertSame( 'requested', $result['status'] );
	}

	/** Invalid requests fail closed before provider work. */
	public function test_malformed_unknown_and_duplicate_requests_have_zero_effect() {
		$base  = array(
			'protocol'    => '2',
			'operation'   => 'optimize_database',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174916',
			'payload'     => array( 'categories' => array( 'revisions' ) ),
		);
		$cases = array(
			array_merge( $base, array( 'extra' => true ) ),
			array_merge( $base, array( 'protocol' => '1' ) ),
			array_merge( $base, array( 'operation' => 'unknown' ) ),
			array_merge( $base, array( 'request_ref' => 'not-a-uuid' ) ),
			array_merge( $base, array( 'payload' => array( 'categories' => array() ) ) ),
			array_merge( $base, array( 'payload' => array( 'categories' => array( 'revisions', 'revisions' ) ) ) ),
			array_merge( $base, array( 'payload' => array( 'categories' => array( 'everything' ) ) ) ),
			array_merge( $base, array( 'payload' => array( 'categories' => array( 'revisions' ), 'extra' => true ) ) ),
		);

		foreach ( $cases as $case ) {
			$result = $this->invoke_v2( $case );
			$this->assertSame( array( 'protocol', 'operation', 'ok', 'error_code' ), array_keys( $result ) );
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'invalid_request', $result['error_code'] );
		}
		$this->assertSame( array(), $this->rocket->provider_calls );
	}

	/** Provider rejection is redacted and never overclaims completion. */
	public function test_provider_failure_is_redacted() {
		$this->rocket->provider_result = new WP_Error( 'private_provider_error', 'CREDENTIAL_SECRET' );
		$result                        = $this->invoke_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'optimize_database',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174916',
				'payload'     => array( 'categories' => array( 'revisions' ) ),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'error_code' ), array_keys( $result ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'provider_failed', $result['error_code'] );
		$this->assertStringNotContainsString( 'CREDENTIAL_SECRET', wp_json_encode( $result ) );
		$this->assertCount( 1, $this->rocket->provider_calls );
	}

	/** A v2 request on a site without WP Rocket is answered inside the protocol envelope, never as v1 display text. */
	public function test_v2_requests_reach_the_protocol_on_a_site_without_wp_rocket() {
		$subject = ( new ReflectionClass( MainWP_Child_WP_Rocket::class ) )->newInstanceWithoutConstructor();
		$respond = $this->action_response_method();
		$this->assertFalse( $subject->is_plugin_installed, 'WP Rocket must be absent, otherwise this test cannot see the v1 bail.' );

		try {
			$_POST['request'] = wp_json_encode(
				array(
					'protocol'  => '2',
					'operation' => 'capabilities',
					'payload'   => array(),
				)
			);
			$capabilities     = $respond->invoke( $subject, 'abilities_v2' );

			$_POST['request'] = wp_json_encode(
				array(
					'protocol'    => '2',
					'operation'   => 'optimize_database',
					'request_ref' => '123e4567-e89b-42d3-a456-426614174916',
					'payload'     => array( 'categories' => array( 'revisions' ) ),
				)
			);
			$optimize         = $respond->invoke( $subject, 'abilities_v2' );

			$_POST['request'] = wp_json_encode(
				array(
					'protocol'    => '2',
					'operation'   => 'optimize_database',
					'request_ref' => 'not-a-uuid',
					'payload'     => array( 'categories' => array( 'revisions' ) ),
				)
			);
			$malformed        = $respond->invoke( $subject, 'abilities_v2' );
		} finally {
			unset( $_POST['request'] );
		}

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'categories' ), array_keys( $capabilities ) );
		$this->assertTrue( $capabilities['ok'] );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'error_code' ), array_keys( $optimize ) );
		$this->assertFalse( $optimize['ok'] );
		$this->assertSame( 'provider_unavailable', $optimize['error_code'] );

		// A malformed payload is malformed whether or not WP Rocket is there to run it.
		$this->assertSame( 'invalid_request', $malformed['error_code'] );
	}

	/** v1 actions keep the legacy provider-absent path instead of being answered by the protocol. */
	public function test_v1_actions_are_not_diverted_into_the_v2_protocol() {
		$subject = ( new ReflectionClass( MainWP_Child_WP_Rocket::class ) )->newInstanceWithoutConstructor();
		$respond = $this->action_response_method();

		foreach ( array( 'optimize_database', 'purge_cloudflare', 'set_showhide', '' ) as $mwp_action ) {
			$this->assertNull( $respond->invoke( $subject, $mwp_action ), $mwp_action );
		}
	}

	/** A repeated request reference replays the stored outcome instead of queueing the work again. */
	public function test_a_repeated_request_reference_never_optimizes_twice() {
		$request = array(
			'protocol'    => '2',
			'operation'   => 'optimize_database',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174917',
			'payload'     => array( 'categories' => array( 'revisions', 'all_transients' ) ),
		);

		$first  = $this->invoke_v2( $request );
		$second = $this->invoke_v2( $request );

		$this->assertTrue( $first['ok'] );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->rocket->provider_calls );

		// The same reference carrying different work is a Dashboard bug, not a replay.
		$request['payload']['categories'] = array( 'revisions' );
		$conflict                         = $this->invoke_v2( $request );
		$this->assertFalse( $conflict['ok'] );
		$this->assertSame( 'request_conflict', $conflict['error_code'] );
		$this->assertCount( 1, $this->rocket->provider_calls );
	}

	/** A store with no retryable slot to free refuses rather than running work it cannot record. */
	public function test_a_full_receipt_store_refuses_before_queueing_an_unrecordable_optimization() {
		$receipts = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$receipts[ sprintf( '123e4567-e89b-42d3-a456-4266141750%02d', $index ) ] = array(
				'effect_hash' => str_repeat( 'a', 64 ),
				'response'    => array(
					'protocol'   => '2',
					'operation'  => 'optimize_database',
					'ok'         => true,
					'status'     => 'requested',
					'categories' => array( 'revisions' ),
				),
				'accepted_at' => time(),
			);
		}
		update_option( 'mainwp_wp_rocket_abilities_v2_receipts', $receipts, false );

		$request = array(
			'protocol'    => '2',
			'operation'   => 'optimize_database',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174918',
			'payload'     => array( 'categories' => array( 'revisions' ) ),
		);
		$result  = $this->invoke_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'storage_unavailable', $result['error_code'] );
		$this->assertSame( array(), $this->rocket->provider_calls );

		// Receipts older than any live retry are the only ones a new request may drop.
		foreach ( array_keys( $receipts ) as $reference ) {
			$receipts[ $reference ]['accepted_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		}
		update_option( 'mainwp_wp_rocket_abilities_v2_receipts', $receipts, false );

		$this->assertTrue( $this->invoke_v2( $request )['ok'] );
		$this->assertCount( 1, $this->rocket->provider_calls );
		$this->assertArrayHasKey( $request['request_ref'], get_option( 'mainwp_wp_rocket_abilities_v2_receipts' ) );
	}

	/** A corrupted receipt is never replayed as an answer and never re-runs the optimization. */
	public function test_an_unreadable_receipt_refuses_instead_of_replaying_or_rerunning() {
		$request = array(
			'protocol'    => '2',
			'operation'   => 'optimize_database',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174919',
			'payload'     => array( 'categories' => array( 'revisions' ) ),
		);
		update_option(
			'mainwp_wp_rocket_abilities_v2_receipts',
			array(
				$request['request_ref'] => array(
					'effect_hash' => str_repeat( 'a', 64 ),
					'response'    => array( 'ok' => true ),
					'accepted_at' => time(),
				),
			),
			false
		);

		$result = $this->invoke_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'storage_unavailable', $result['error_code'] );
		$this->assertSame( array(), $this->rocket->provider_calls );
	}

	/** The receipt check and the dispatch it guards run under one held lock. */
	public function test_the_optimization_runs_under_the_named_request_lock() {
		$result = $this->invoke_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'optimize_database',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174920',
				'payload'     => array( 'categories' => array( 'revisions' ) ),
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '0', (string) $this->rocket->lock_free_during_dispatch );
		$this->assertSame( '1', (string) $this->lock_state( $this->rocket->fixture_lock_name() ) );
	}

	/** A concurrent holder of the lock is refused without dispatching or storing anything. */
	public function test_a_held_lock_refuses_the_request_without_dispatching() {
		$name  = $this->rocket->fixture_lock_name();
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->suppress_errors( true );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) );

		$result = $this->invoke_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'optimize_database',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174921',
				'payload'     => array( 'categories' => array( 'revisions' ) ),
			)
		);

		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		$other->close();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'lock_busy', $result['error_code'] );
		$this->assertSame( array(), $this->rocket->provider_calls );
		$this->assertSame( array(), get_option( 'mainwp_wp_rocket_abilities_v2_receipts', array() ) );
	}

	/**
	 * @param string $name Lock name.
	 * @return string|null
	 */
	private function lock_state( $name ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) );
	}

	/** @return \ReflectionMethod */
	private function action_response_method() {
		$method = ( new ReflectionClass( MainWP_Child_WP_Rocket::class ) )->getMethod( 'abilities_v2_action_response' );
		$method->setAccessible( true );

		return $method;
	}

	/**
	 * Invoke the additive protocol without transport output.
	 *
	 * @param array $request Request.
	 * @return array
	 */
	private function invoke_v2( $request ) {
		$this->assertTrue( method_exists( MainWP_Child_WP_Rocket::class, 'abilities_v2' ) );
		return $this->rocket->abilities_v2( $request );
	}

	/** @return string[] */
	private function public_categories() {
		return array(
			'revisions',
			'auto_drafts',
			'trashed_posts',
			'spam_comments',
			'trashed_comments',
			'expired_transients',
			'all_transients',
			'optimize_tables',
		);
	}
}
