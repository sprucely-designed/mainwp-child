<?php
/**
 * WooCommerce Status abilities-v2 protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class WooCommerce_Status_V2_Fixture extends MainWP_Child_WooCommerce_Status {

	/** @var mixed */
	public $runtime;

	/** @var mixed */
	public $page;

	/** @var mixed */
	public $readiness;

	/** @var int */
	public $started = 0;

	/** @var array */
	public $receipts = array();

	/** @var string|null */
	public $lease = null;

	/** @var array|null */
	public $observation = null;

	protected function abilities_v2_runtime() {
		return $this->runtime;
	}

	protected function abilities_v2_order_page( $payload, $runtime ) {
		return $this->page;
	}

	protected function abilities_v2_db_readiness( $runtime ) {
		return $this->readiness;
	}

	protected function abilities_v2_start_db_update() {
		++$this->started;
		return 2;
	}

	protected function abilities_v2_acquire_db_lease( $request_ref ) {
		if ( null !== $this->lease && $this->lease !== $request_ref ) {
			return false;
		}
		$this->lease = $request_ref;
		return true;
	}

	protected function abilities_v2_release_db_lease( $request_ref ) {
		if ( $this->lease === $request_ref ) {
			$this->lease = null;
		}
		return true;
	}

	protected function abilities_v2_db_receipt( $request_ref ) {
		return isset( $this->receipts[ $request_ref ] ) ? $this->receipts[ $request_ref ] : null;
	}

	protected function abilities_v2_store_db_receipt( $request_ref, $payload, $response ) {
		$this->receipts[ $request_ref ] = array(
			'request_hash' => hash( 'sha256', wp_json_encode( $payload ) ),
			'response'     => $response,
			'requested_at' => 100,
		);
		return true;
	}

	protected function abilities_v2_record_observation( $generation, $observed_at ) {
		$this->observation = array( 'generation' => $generation, 'observed_at' => 101 );
		return true;
	}

	protected function abilities_v2_observation() {
		return $this->observation;
	}
}

class Test_MainWP_Child_WooCommerce_Status_V2 extends WP_UnitTestCase {

	/** @var WooCommerce_Status_V2_Fixture */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		$reflection    = new ReflectionClass( WooCommerce_Status_V2_Fixture::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
		$this->subject->runtime = array(
			'wc_version'               => '10.9.4',
			'storage_mode'             => 'hpos',
			'store_timezone'           => 'America/New_York',
			'current_db_version'       => '10.8.0',
			'target_db_version'        => '10.9.4',
			'database_update_needed'   => true,
		);
		$this->subject->page = array(
			'next_cursor'           => null,
			'complete'              => true,
			'page_size'             => 100,
			'order_count'           => 2,
			'total_orders'          => 2,
			'currency_totals'       => array( array( 'currency' => 'USD', 'minor_unit' => 2, 'net_sales_minor' => '125050' ) ),
			'top_sellers'           => array( array( 'product_id' => 41, 'name' => 'Widget', 'net_quantity' => '9' ) ),
			'processing_orders'     => 2,
			'on_hold_orders'        => 1,
			'low_stock'             => 3,
			'out_of_stock'          => 0,
			'inventory_observed_at' => '2026-08-10T12:00:00Z',
			'generated_at'          => '2026-08-10T12:01:00Z',
			'source'                => 'woocommerce_crud',
			'storage_mode'          => 'hpos',
			'accounting_profile'    => 'net-order-total-v1',
		);
		$this->subject->readiness = array(
			'callback_hashes' => array( str_repeat( 'b', 64 ), str_repeat( 'c', 64 ) ),
			'conflict_hashes' => array(),
			'state'           => 'ready',
		);
		$this->subject->observation = array( 'generation' => str_repeat( 'd', 64 ), 'observed_at' => 101 );
	}

	public function test_capabilities_are_closed_and_claim_the_complete_protocol() {
		$result = $this->request( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array( 'status_v2_prepare', 'status_v2_page', 'db_update_v2_prepare', 'db_update_v2_start', 'db_update_v2_status' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_prepare_returns_bounded_runtime_identity_without_commerce_data() {
		$result = $this->prepare();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '123e4567-e89b-42d3-a456-426614174001', $result['request_ref'] );
		$this->assertSame( str_repeat( 'a', 64 ), $result['site_fingerprint'] );
		$this->assertSame( 'hpos', $result['storage_mode'] );
		$this->assertSame( 'America/New_York', $result['store_timezone'] );
		$this->assertTrue( $result['database_update_needed'] );
		$this->assertSame( 'net-order-total-v1', $result['accounting_profile'] );
		$this->assertSame( 250, $result['page_size_max'] );
		$this->assertSame( 100000, $result['order_limit'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $result['preparation_generation'] );
		$this->assertSame( array(), array_intersect( array( 'currency_totals', 'top_sellers', 'orders', 'customers' ), array_keys( $result ) ) );
	}

	public function test_prepare_rejects_invalid_period_identity_and_uuid_alias() {
		$payload                         = $this->payload();
		$payload['end_at']               = $payload['start_at'];
		$this->assertSame( 'invalid_request', $this->request( 'status_v2_prepare', $payload )['code'] );

		$payload                         = $this->payload();
		$payload['site_fingerprint']      = 'not-a-fingerprint';
		$this->assertSame( 'invalid_request', $this->request( 'status_v2_prepare', $payload )['code'] );

		$payload                         = $this->payload();
		$payload['request_id']           = $payload['request_ref'];
		unset( $payload['request_ref'] );
		$this->assertSame( 'invalid_request', $this->request( 'status_v2_prepare', $payload )['code'] );
	}

	public function test_missing_or_malformed_runtime_fails_closed() {
		$this->subject->runtime = false;
		$this->assertSame( 'woocommerce_unavailable', $this->prepare()['code'] );

		$this->subject->runtime = array(
			'wc_version'             => '10.9.4',
			'storage_mode'           => 'maybe',
			'store_timezone'         => 'UTC',
			'current_db_version'     => '10.8.0',
			'target_db_version'      => '10.9.4',
			'database_update_needed' => true,
		);
		$this->assertSame( 'runtime_invalid', $this->prepare()['code'] );
	}

	public function test_status_page_binds_prepare_cursor_completeness_and_hash() {
		$prepare = $this->prepare();
		$payload = array_merge(
			$this->payload(),
			array(
				'preparation_generation' => $prepare['preparation_generation'],
				'cursor'                 => null,
				'page_size'              => 100,
			)
		);
		$result = $this->request( 'status_v2_page', $payload );

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['complete'] );
		$this->assertNull( $result['next_cursor'] );
		$this->assertSame( '125050', $result['currency_totals'][0]['net_sales_minor'] );
		$hash = $result['response_hash'];
		unset( $result['response_hash'] );
		$this->assertSame( hash( 'sha256', wp_json_encode( $result ) ), $hash );

		$payload['preparation_generation'] = str_repeat( 'f', 64 );
		$this->assertSame( 'preparation_drift', $this->request( 'status_v2_page', $payload )['code'] );
	}

	public function test_malformed_or_oversized_status_page_is_partial_not_zero() {
		$prepare = $this->prepare();
		$payload = array_merge( $this->payload(), array( 'preparation_generation' => $prepare['preparation_generation'], 'cursor' => null, 'page_size' => 100 ) );
		$this->subject->page['currency_totals'][0]['net_sales_minor'] = '1.5';

		$this->assertSame( 'partial_result', $this->request( 'status_v2_page', $payload )['code'] );
	}

	public function test_database_prepare_start_replay_and_terminal_status_are_truthful() {
		$identity = array( 'request_ref' => '123e4567-e89b-42d3-a456-426614174002', 'site_fingerprint' => str_repeat( 'a', 64 ) );
		$prepare  = $this->request( 'db_update_v2_prepare', $identity );
		$this->assertTrue( $prepare['ok'] );
		$this->assertSame( 2, $prepare['pending_callback_count'] );
		$this->assertSame( array( str_repeat( 'b', 64 ), str_repeat( 'c', 64 ) ), $prepare['callback_hashes'] );

		$start_payload = array_merge(
			$identity,
			array(
				'current_version'      => $prepare['current_version'],
				'target_version'       => $prepare['target_version'],
				'readiness_generation' => $prepare['readiness_generation'],
			)
		);
		$started = $this->request( 'db_update_v2_start', $start_payload );
		$this->assertSame( 'requested', $started['state'] );
		$this->assertSame( 2, $started['queued_callbacks'] );
		$this->assertSame( $started, $this->request( 'db_update_v2_start', $start_payload ) );
		$this->assertSame( 1, $this->subject->started );

		$this->subject->runtime['current_db_version']       = '10.9.4';
		$this->subject->runtime['database_update_needed']   = false;
		$this->subject->readiness['callback_hashes']        = array();
		$this->subject->readiness['state']                  = 'current';
		$status = $this->request( 'db_update_v2_status', $identity );
		$this->assertSame( 'completed', $status['state'] );
		$this->assertSame( 0, $status['pending_callback_count'] );
		$this->assertNull( $this->subject->lease );
	}

	public function test_database_start_rejects_uuid_id_alias_and_readiness_drift() {
		$identity = array( 'request_ref' => '123e4567-e89b-42d3-a456-426614174003', 'site_fingerprint' => str_repeat( 'a', 64 ) );
		$prepare  = $this->request( 'db_update_v2_prepare', $identity );
		$payload  = array_merge( $identity, array( 'current_version' => $prepare['current_version'], 'target_version' => $prepare['target_version'], 'readiness_generation' => str_repeat( 'f', 64 ) ) );
		$this->assertSame( 'readiness_drift', $this->request( 'db_update_v2_start', $payload )['code'] );

		$identity['request_id'] = $identity['request_ref'];
		unset( $identity['request_ref'] );
		$this->assertSame( 'invalid_request', $this->request( 'db_update_v2_prepare', $identity )['code'] );
	}

	public function test_decimal_and_signed_integer_arithmetic_is_exact_without_floats() {
		$reflection = new ReflectionClass( MainWP_Child_WooCommerce_Status::class );
		$decimal    = $reflection->getMethod( 'abilities_v2_decimal_to_minor' );
		$add        = $reflection->getMethod( 'abilities_v2_integer_add' );
		$subtract   = $reflection->getMethod( 'abilities_v2_integer_subtract' );

		$this->assertSame( '125050', $decimal->invoke( $this->subject, '1250.50', 2 ) );
		$this->assertSame( '-5', $decimal->invoke( $this->subject, '-0.05', 2 ) );
		$this->assertSame( '1000000000000000000000000000000', $add->invoke( $this->subject, '999999999999999999999999999999', '1' ) );
		$this->assertSame( '-25', $subtract->invoke( $this->subject, '75', '100' ) );
		$this->assertFalse( $decimal->invoke( $this->subject, '1.005', 2 ) );
	}

	private function prepare() {
		return $this->request( 'status_v2_prepare', $this->payload() );
	}

	private function payload() {
		return array(
			'request_ref'      => '123e4567-e89b-42d3-a456-426614174001',
			'site_fingerprint' => str_repeat( 'a', 64 ),
			'start_at'         => '2026-08-01T04:00:00Z',
			'end_at'           => '2026-08-10T12:00:00Z',
			'top_limit'        => 10,
		);
	}

	private function request( $operation, $payload ) {
		return $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => $operation,
				'payload'   => $payload,
			)
		);
	}
}
