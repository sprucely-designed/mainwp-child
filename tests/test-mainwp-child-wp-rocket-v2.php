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
		$this->provider_calls[] = $categories;
		return $this->provider_result;
	}
}

/** WP Rocket protocol-v2 contract tests. */
class Test_MainWP_Child_WP_Rocket_V2 extends WP_UnitTestCase {

	/** @var Test_MainWP_Child_WP_Rocket_V2_Fixture */
	private $rocket;

	/** Set up a provider-free protocol handler. */
	public function setUp(): void {
		parent::setUp();
		$this->rocket = new Test_MainWP_Child_WP_Rocket_V2_Fixture();
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

	/** The original settings-driven action remains present for legacy callers. */
	public function test_legacy_optimize_database_contract_remains_present() {
		$source = file_get_contents( dirname( __DIR__ ) . '/class/class-mainwp-child-wp-rocket.php' );
		$this->assertStringContainsString( "case 'optimize_database':", $source );
		$this->assertStringContainsString( '$this->optimize_database();', $source );
		$this->assertStringContainsString( 'array_filter( array_keys( $optimization->get_options() ), array( $options, \'get\' ) )', $source );
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
