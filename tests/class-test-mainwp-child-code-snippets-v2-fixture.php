<?php

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused protocol fixture and test case intentionally share one file.
/**
 * Code Snippets Child protocol-v2 tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Misc;

/** Deterministic Code Snippets v2 fixture. */
class Test_MainWP_Child_Code_Snippets_V2_Fixture extends MainWP_Child_Misc {

	/** @var array<string,mixed> */
	public $options = array();

	/** @var bool */
	public $lock_busy = false;

	/** @var bool */
	public $write_fails = false;

	/** @var string|false */
	public $config_path = false;

	/** @return mixed */
	protected function snippet_v2_get_option( $name, $fallback = false ) {
		return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
	}

	/** @return bool */
	protected function snippet_v2_update_option( $name, $value ) {
		if ( $this->write_fails ) {
			return false;
		}
		$this->options[ $name ] = $value;
		return true;
	}

	/** @return string|false */
	protected function snippet_v2_acquire_lock( $slug ) {
		return $this->lock_busy ? false : 'fixture-owner';
	}

	/** @return bool */
	protected function snippet_v2_release_lock( $slug, $owner ) {
		return 'fixture-owner' === $owner;
	}

	/** @return string|false */
	protected function snippet_v2_config_path() {
		return $this->config_path;
	}

	/** @return bool */
	protected function snippet_v2_write_file_atomic( $path, $expected, $next ) {
		return ! $this->write_fails && parent::snippet_v2_write_file_atomic( $path, $expected, $next );
	}
}

/** Code Snippets protocol-v2 contract tests. */
class Test_MainWP_Child_Code_Snippets_V2 extends WP_UnitTestCase {

	/** @var string */
	private $request_ref = '123e4567-e89b-42d3-a456-426614174000';

	/** @var Test_MainWP_Child_Code_Snippets_V2_Fixture */
	private $fixture;

	/** @var string */
	private $config_path;

	/** Set up one isolated fixture. */
	public function setUp(): void {
		parent::setUp();
		$this->fixture     = new Test_MainWP_Child_Code_Snippets_V2_Fixture();
		$this->config_path = tempnam( sys_get_temp_dir(), 'mainwp-cs-v2-' );
		file_put_contents( $this->config_path, "<?php\n\$table_prefix = 'wp_';\n/* collateral */\n" );
		$this->fixture->config_path = $this->config_path;
	}

	/** Remove the temporary configuration fixture. */
	public function tearDown(): void {
		if ( is_string( $this->config_path ) && file_exists( $this->config_path ) ) {
			unlink( $this->config_path );
		}
		parent::tearDown();
	}

	/** @return array<string,mixed> */
	private function request( $type = 'S', $code = "echo 'ok';" ) {
		return array(
			'protocol_version' => 2,
			'request_ref'      => $this->request_ref,
			'slug'             => 'FixtureSlug1',
			'type'             => $type,
			'code'             => $code,
		);
	}

	/** Closed requests reject legacy UUID names and unknown fields. */
	public function test_rejects_nonclosed_and_legacy_request_id_inputs() {
		$request               = $this->request();
		$request['request_id'] = $request['request_ref'];
		unset( $request['request_ref'] );
		$this->assertSame( 'invalid_request', $this->fixture->snippet_v2( 'apply_snippet_v2', $request )['error_code'] );

		$request          = $this->request();
		$request['extra'] = true;
		$this->assertSame( 'invalid_request', $this->fixture->snippet_v2( 'apply_snippet_v2', $request )['error_code'] );
	}

	/** Run-once output is bounded and failures expose no throwable detail. */
	public function test_run_once_is_bounded_and_redacted_on_failure() {
		$result = $this->fixture->snippet_v2( 'run_snippet_v2', $this->request( 'R', "echo 'safe-output';" ) );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( 'safe-output', $result['output'] );
		$this->assertFalse( $result['output_truncated'] );
		$this->assertSame( $this->request_ref, $result['request_ref'] );

		$result = $this->fixture->snippet_v2( 'run_snippet_v2', $this->request( 'R', "throw new Exception('PRIVATE-DETAIL');" ) );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( '', $result['output'] );
		$this->assertStringNotContainsString( 'PRIVATE-DETAIL', wp_json_encode( $result ) );

		$result = $this->fixture->snippet_v2( 'run_snippet_v2', $this->request( 'R', "echo str_repeat('x', 70000);" ) );
		$this->assertTrue( $result['output_truncated'] );
		$this->assertSame( 65535, strlen( $result['output'] ) );
	}

	/** Stored-option application and removal converge with exact readback. */
	public function test_saved_snippet_apply_and_remove_converge() {
		$result = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request() );
		$this->assertSame( 'changed', $result['result'] );
		$this->assertSame( "echo 'ok';", $this->fixture->options['mainwp_ext_code_snippets']['FixtureSlug1'] );
		$this->assertTrue( $this->fixture->options['mainwp_ext_snippets_enabled'] );

		$result = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request() );
		$this->assertSame( 'unchanged', $result['result'] );

		$remove = $this->request();
		unset( $remove['code'] );
		$result = $this->fixture->snippet_v2( 'remove_snippet_v2', $remove );
		$this->assertSame( 'removed', $result['result'] );
		$this->assertArrayNotHasKey( 'FixtureSlug1', $this->fixture->options['mainwp_ext_code_snippets'] );
		$this->assertSame( 'already_absent', $this->fixture->snippet_v2( 'remove_snippet_v2', $remove )['result'] );
	}

	/** Lock and storage failures are stable and do not expose code. */
	public function test_lock_and_storage_failures_are_stable() {
		$this->fixture->lock_busy = true;
		$this->assertSame( 'lock_busy', $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request() )['error_code'] );

		$this->fixture->lock_busy   = false;
		$this->fixture->write_fails = true;
		$result                     = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request( 'S', "echo 'PRIVATE-CODE';" ) );
		$this->assertSame( 'storage_failed', $result['error_code'] );
		$this->assertStringNotContainsString( 'PRIVATE-CODE', wp_json_encode( $result ) );
	}

	/** wp-config application/removal is exact and preserves collateral bytes. */
	public function test_config_apply_remove_and_duplicate_marker_failure() {
		$original = file_get_contents( $this->config_path );
		$result   = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request( 'C', "define( 'FIXTURE_VALUE', true );" ) );
		$this->assertSame( 'changed', $result['result'] );
		$applied = file_get_contents( $this->config_path );
		$this->assertStringContainsString( '/***snippet_FixtureSlug1***/', $applied );
		$this->assertStringContainsString( '/* collateral */', $applied );

		$this->assertSame( 'unchanged', $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request( 'C', "define( 'FIXTURE_VALUE', true );" ) )['result'] );

		$remove = $this->request( 'C' );
		unset( $remove['code'] );
		$this->assertSame( 'removed', $this->fixture->snippet_v2( 'remove_snippet_v2', $remove )['result'] );
		$this->assertStringNotContainsString( 'FIXTURE_VALUE', file_get_contents( $this->config_path ) );
		$this->assertStringContainsString( '/* collateral */', file_get_contents( $this->config_path ) );
		$this->assertSame( 'already_absent', $this->fixture->snippet_v2( 'remove_snippet_v2', $remove )['result'] );

		file_put_contents( $this->config_path, $original . "/***snippet_FixtureSlug1***/x/***end_FixtureSlug1***/\n/***snippet_FixtureSlug1***/y/***end_FixtureSlug1***/\n" );
		$this->assertSame( 'storage_failed', $this->fixture->snippet_v2( 'remove_snippet_v2', $remove )['error_code'] );
	}
}
