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

	/** @var mixed Receipt store as it stood on disk while the provider ran. */
	public $receipts_during_dispatch = null;

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
		wp_cache_delete( 'mainwp_wp_rocket_abilities_v2_receipts', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$this->receipts_during_dispatch = get_option( 'mainwp_wp_rocket_abilities_v2_receipts', array() );
		return $this->provider_result;
	}

	/** @return string */
	public function fixture_lock_name() {
		return $this->abilities_v2_lock_name();
	}
}

/** A lock backend that cannot answer GET_LOCK(). */
class Test_MainWP_Child_WP_Rocket_V2_Broken_Wpdb {

	/** @var string */
	public $last_error = '';

	/** @var string */
	private $error;

	/** @var string|null */
	private $result;

	/**
	 * @param string      $error  Driver error reported after the query, empty when the driver itself is fine.
	 * @param string|null $result GET_LOCK() result.
	 */
	public function __construct( $error, $result ) {
		$this->error  = $error;
		$this->result = $result;
	}

	/**
	 * @param string $query Query.
	 * @param mixed  ...$args Placeholder values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		return $query;
	}

	/**
	 * @param string $query Query.
	 * @return string|null
	 */
	public function get_var( $query ) {
		$this->last_error = $this->error;
		return $this->result;
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
			$receipts[ sprintf( '123e4567-e89b-42d3-a456-4266141750%02d', $index ) ] = $this->settled_receipt( time() );
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

	/**
	 * A store full of entries the Child cannot read must not lock the ability out.
	 *
	 * A WordPress option is untrusted input: truncated or hand-edited receipts answer nothing on
	 * replay, so treating them as receipts worth keeping would refuse every future optimization
	 * until someone repaired the option by hand.
	 */
	public function test_unreadable_receipts_never_hold_the_optimization_store_shut() {
		$receipts = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$receipts[ sprintf( '123e4567-e89b-42d3-a456-4266141751%02d', $index ) ] = array(
				'effect_hash' => 'not-a-hash',
				'response'    => 'truncated',
			);
		}
		update_option( 'mainwp_wp_rocket_abilities_v2_receipts', $receipts, false );

		$request = array(
			'protocol'    => '2',
			'operation'   => 'optimize_database',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174922',
			'payload'     => array( 'categories' => array( 'revisions' ) ),
		);
		$result  = $this->invoke_v2( $request );
		$stored  = get_option( 'mainwp_wp_rocket_abilities_v2_receipts' );

		$this->assertTrue( $result['ok'], wp_json_encode( $result ) );
		$this->assertCount( 1, $this->rocket->provider_calls );
		$this->assertArrayHasKey( $request['request_ref'], $stored );
		$this->assertLessThanOrEqual( 100, count( $stored ) );
	}

	/**
	 * The reservation that stops a second dispatch has to be on disk before the queue is touched.
	 *
	 * A request that dies after WP Rocket accepted the work leaves nothing else behind, and its
	 * retry would queue the same destructive optimization again.
	 */
	public function test_the_reservation_is_durable_before_the_optimization_is_queued() {
		$request = array(
			'protocol'    => '2',
			'operation'   => 'optimize_database',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174923',
			'payload'     => array( 'categories' => array( 'revisions' ) ),
		);

		$result = $this->invoke_v2( $request );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $this->rocket->provider_calls );
		$this->assertIsArray( $this->rocket->receipts_during_dispatch );
		$this->assertArrayHasKey(
			$request['request_ref'],
			$this->rocket->receipts_during_dispatch,
			'The receipt has to survive a crash during dispatch, so it must already be stored when the provider runs.'
		);
		$this->assertSame( 'dispatching', $this->rocket->receipts_during_dispatch[ $request['request_ref'] ]['state'] );
		$this->assertNull( $this->rocket->receipts_during_dispatch[ $request['request_ref'] ]['response'] );
		$this->assertSame( 'settled', get_option( 'mainwp_wp_rocket_abilities_v2_receipts' )[ $request['request_ref'] ]['state'] );
	}

	/** A retry that lands on an unsettled reservation reports the outcome as unknown instead of optimizing again. */
	public function test_an_interrupted_request_is_answered_unknown_and_never_dispatched_twice() {
		$request = array(
			'protocol'    => '2',
			'operation'   => 'optimize_database',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174924',
			'payload'     => array( 'categories' => array( 'revisions' ) ),
		);
		update_option(
			'mainwp_wp_rocket_abilities_v2_receipts',
			array(
				$request['request_ref'] => array(
					'effect_hash' => hash( 'sha256', wp_json_encode( array( 'optimize_database', array( 'revisions' ) ) ) ),
					'state'       => 'dispatching',
					'response'    => null,
					'accepted_at' => time(),
				),
			),
			false
		);

		$result = $this->invoke_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'outcome_unknown', $result['error_code'] );
		$this->assertSame( array(), $this->rocket->provider_calls, 'A request that may already be queued must never be queued again.' );
	}

	/**
	 * A reservation older than any live retry is retired instead of orphaning its reference forever.
	 *
	 * The reservation is stored before WP Rocket is called, so a process that died in between leaves
	 * one behind for work that never ran. Past the retry horizon that queue entry has drained or been
	 * abandoned, and answering the reference outcome_unknown for good is the worse harm.
	 */
	public function test_a_reservation_past_the_retry_horizon_is_retired_and_dispatched_again() {
		$request = array(
			'protocol'    => '2',
			'operation'   => 'optimize_database',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174925',
			'payload'     => array( 'categories' => array( 'revisions' ) ),
		);
		update_option(
			'mainwp_wp_rocket_abilities_v2_receipts',
			array(
				$request['request_ref'] => array(
					'effect_hash' => $this->effect_hash( array( 'revisions' ) ),
					'state'       => 'dispatching',
					'response'    => null,
					'accepted_at' => time() - ( DAY_IN_SECONDS + 3600 ),
				),
			),
			false
		);

		$result = $this->invoke_v2( $request );
		$stored = get_option( 'mainwp_wp_rocket_abilities_v2_receipts' );

		$this->assertTrue( $result['ok'], wp_json_encode( $result ) );
		$this->assertSame( 'requested', $result['status'] );
		$this->assertCount( 1, $this->rocket->provider_calls, 'Past the retry horizon the request has to reach WP Rocket instead of answering unknown for good.' );
		$this->assertSame( 'settled', $stored[ $request['request_ref'] ]['state'] );
	}

	/**
	 * A store full of receipts stamped in the future must not lock the ability out.
	 *
	 * Option data is untrusted input, so an impossible timestamp is not a young receipt: nothing may
	 * read age from it, and eviction has to treat it like any other entry it cannot use.
	 */
	public function test_future_dated_receipts_never_hold_the_optimization_store_shut() {
		$receipts = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$receipts[ sprintf( '123e4567-e89b-42d3-a456-4266141752%02d', $index ) ] = $this->settled_receipt( time() + ( 10 * YEAR_IN_SECONDS ) );
		}
		update_option( 'mainwp_wp_rocket_abilities_v2_receipts', $receipts, false );

		$request = array(
			'protocol'    => '2',
			'operation'   => 'optimize_database',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174926',
			'payload'     => array( 'categories' => array( 'revisions' ) ),
		);
		$result  = $this->invoke_v2( $request );
		$stored  = get_option( 'mainwp_wp_rocket_abilities_v2_receipts' );

		$this->assertTrue( $result['ok'], wp_json_encode( $result ) );
		$this->assertCount( 1, $this->rocket->provider_calls );
		$this->assertArrayHasKey( $request['request_ref'], $stored );
		$this->assertLessThanOrEqual( 100, count( $stored ) );
	}

	/** A receipt stamped in the future is not an outcome the Child may hand back as this request's answer. */
	public function test_a_future_dated_receipt_is_refused_instead_of_replayed() {
		$request = array(
			'protocol'    => '2',
			'operation'   => 'optimize_database',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174927',
			'payload'     => array( 'categories' => array( 'revisions' ) ),
		);
		$receipt                = $this->settled_receipt( time() + YEAR_IN_SECONDS );
		$receipt['effect_hash'] = $this->effect_hash( array( 'revisions' ) );
		update_option( 'mainwp_wp_rocket_abilities_v2_receipts', array( $request['request_ref'] => $receipt ), false );

		$result = $this->invoke_v2( $request );

		$this->assertFalse( $result['ok'], wp_json_encode( $result ) );
		$this->assertSame( 'storage_unavailable', $result['error_code'] );
		$this->assertSame( array(), $this->rocket->provider_calls );
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

	/**
	 * A replayed receipt may only assert the categories the request it answers actually binds.
	 *
	 * The effect hash covers the request's category list, not the stored answer's, and the receipt
	 * store is a WordPress option. Without a binding check, an altered stored response lets an
	 * authenticated retry be told ok/requested for categories nothing ever asked for or queued.
	 */
	public function test_a_receipt_never_replays_categories_the_request_does_not_bind() {
		$request_ref = '123e4567-e89b-42d3-a456-426614174928';
		$request     = array(
			'protocol'    => '2',
			'operation'   => 'optimize_database',
			'request_ref' => $request_ref,
			'payload'     => array( 'categories' => array( 'revisions' ) ),
		);

		foreach ( array( array( 'fabricated' ), array(), array( 'revisions', 'all_transients' ) ) as $tampered ) {
			$this->store_receipt( $request_ref, $this->bound_receipt( array( 'revisions' ), $tampered ) );

			$result = $this->invoke_transport( $request );

			$this->assertFalse( $result['ok'], wp_json_encode( $result ) );
			$this->assertSame( 'storage_unavailable', $result['error_code'] );
			$this->assertArrayNotHasKey( 'categories', $result );
			$this->assertSame( array(), $this->rocket->provider_calls, 'A receipt that may already have queued the work must not queue it again.' );
		}

		// The untouched receipt still replays, so the check binds the claim rather than refusing every retry.
		$this->store_receipt( $request_ref, $this->bound_receipt( array( 'revisions' ), array( 'revisions' ) ) );
		$replayed = $this->invoke_transport( $request );

		$this->assertTrue( $replayed['ok'], wp_json_encode( $replayed ) );
		$this->assertSame( 'requested', $replayed['status'] );
		$this->assertSame( array( 'revisions' ), $replayed['categories'] );
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

	/** A lock backend that cannot answer is refused as a store failure, not as someone else's lock. */
	public function test_an_unusable_lock_backend_is_refused_apart_from_a_held_lock() {
		// The lock name reads home_url(), so it is resolved while the real connection is still in place.
		$name    = $this->rocket->fixture_lock_name();
		$request = array(
			'protocol'    => '2',
			'operation'   => 'optimize_database',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174922',
			'payload'     => array( 'categories' => array( 'revisions' ) ),
		);

		$backends = array(
			'driver error'     => new Test_MainWP_Child_WP_Rocket_V2_Broken_Wpdb( 'MySQL server has gone away', null ),
			'GET_LOCK is NULL' => new Test_MainWP_Child_WP_Rocket_V2_Broken_Wpdb( '', null ),
		);
		foreach ( $backends as $label => $backend ) {
			$real            = $GLOBALS['wpdb'];
			$GLOBALS['wpdb'] = $backend;
			try {
				$result = $this->invoke_v2( $request );
			} finally {
				$GLOBALS['wpdb'] = $real;
			}

			$this->assertFalse( $result['ok'], $label );
			$this->assertSame( 'storage_unavailable', $result['error_code'], $label );
		}

		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->suppress_errors( true );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) );
		$held = $this->invoke_v2( $request );
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		$other->close();

		$this->assertFalse( $held['ok'] );
		$this->assertSame( 'lock_busy', $held['error_code'] );
		$this->assertSame( array(), $this->rocket->provider_calls );
		$this->assertSame( array(), get_option( 'mainwp_wp_rocket_abilities_v2_receipts', array() ) );
	}

	/**
	 * Build one settled receipt in the exact stored shape.
	 *
	 * @param int $accepted_at Receipt age.
	 * @return array
	 */
	private function settled_receipt( $accepted_at ) {
		return array(
			'effect_hash' => str_repeat( 'a', 64 ),
			'state'       => 'settled',
			'response'    => array(
				'protocol'   => '2',
				'operation'  => 'optimize_database',
				'ok'         => true,
				'status'     => 'requested',
				'categories' => array( 'revisions' ),
			),
			'accepted_at' => $accepted_at,
		);
	}

	/**
	 * Build a settled receipt bound to one request whose stored answer names another category list.
	 *
	 * @param array $requested Categories the request carries, and so the ones the effect hash binds.
	 * @param array $answered  Categories the stored response asserts.
	 * @return array
	 */
	private function bound_receipt( $requested, $answered ) {
		$receipt                           = $this->settled_receipt( time() );
		$receipt['effect_hash']            = $this->effect_hash( $requested );
		$receipt['response']['categories'] = $answered;

		return $receipt;
	}

	/**
	 * Put one receipt in the store under the given reference.
	 *
	 * @param string $request_ref Request reference.
	 * @param array  $receipt     Receipt to store.
	 * @return void
	 */
	private function store_receipt( $request_ref, $receipt ) {
		update_option( 'mainwp_wp_rocket_abilities_v2_receipts', array( $request_ref => $receipt ), false );
	}

	/**
	 * Invoke the protocol the way an authenticated Child request reaches it.
	 *
	 * @param array $request Request.
	 * @return array
	 */
	private function invoke_transport( $request ) {
		$respond = $this->action_response_method();
		try {
			$_POST['request'] = wp_json_encode( $request );
			return $respond->invoke( $this->rocket, 'abilities_v2' );
		} finally {
			unset( $_POST['request'] );
		}
	}

	/**
	 * The effect hash the handler computes for one category set.
	 *
	 * @param array $categories Public category names.
	 * @return string
	 */
	private function effect_hash( $categories ) {
		return hash( 'sha256', wp_json_encode( array( 'optimize_database', $categories ) ) );
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
