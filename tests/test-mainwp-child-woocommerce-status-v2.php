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

	protected function abilities_v2_runtime() {
		return $this->runtime;
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
	}

	public function test_capabilities_are_closed_and_claim_only_prepare() {
		$result = $this->request( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array( 'status_v2_prepare' ), $result['operations'] );
		$this->assertFalse( $result['mutation_supported'] );
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
