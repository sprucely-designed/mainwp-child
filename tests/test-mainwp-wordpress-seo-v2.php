<?php
/**
 * WordPress SEO safe protocol-v2 tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_MainWP_WordPress_SEO_V2 extends WP_UnitTestCase {

	public function test_describe_is_closed_and_advertises_only_the_safe_read() {
		$subject = new Testable_MainWP_WordPress_SEO_V2( '25.5', $this->safe_options() );
		$result  = $subject->abilities_v2( 'describe_v2', array( 'contract_version' => '2' ) );

		$this->assertSame( array( 'contract_version', 'operation', 'ok', 'plugin_state', 'version', 'compatibility', 'schema', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'supported', $result['compatibility'] );
		$this->assertSame( array( 'read_safe_v1' ), $result['operations'] );
		$this->assertFalse( $result['mutation_supported'] );
	}

	public function test_safe_read_normalizes_only_the_allowlisted_subset() {
		$subject = new Testable_MainWP_WordPress_SEO_V2( '25.5', $this->safe_options() );
		$result  = $subject->abilities_v2( 'read_safe_v1', $this->read_request() );

		$this->assertSame( array( 'contract_version', 'operation', 'ok', 'request_ref', 'site_generation', 'version', 'schema', 'settings', 'config_generation' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'post_types', 'taxonomies', 'archives', 'sitemaps' ), array_keys( $result['settings'] ) );
		$this->assertTrue( $result['settings']['post_types'][0]['index'] );
		$this->assertFalse( $result['settings']['archives']['author_index'] );
		$this->assertSame( hash( 'sha256', wp_json_encode( $result['settings'] ) ), $result['config_generation'] );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $result ) );
	}

	public function test_unsupported_versions_and_unsafe_templates_fail_closed() {
		$unsupported = new Testable_MainWP_WordPress_SEO_V2( '19.9', $this->safe_options() );
		$this->assertSame( 'unsupported_version', $unsupported->abilities_v2( 'read_safe_v1', $this->read_request() )['code'] );
		$unverified = new Testable_MainWP_WordPress_SEO_V2( '26.0', $this->safe_options() );
		$this->assertSame( 'unsupported_version', $unverified->abilities_v2( 'read_safe_v1', $this->read_request() )['code'] );

		$options                                = $this->safe_options();
		$options['wpseo_titles']['title-post'] = '%%title%% %%unknown%%';
		$unsafe                                 = new Testable_MainWP_WordPress_SEO_V2( '25.5', $options );
		$this->assertSame( 'unsafe_configuration', $unsafe->abilities_v2( 'read_safe_v1', $this->read_request() )['code'] );
	}

	public function test_inputs_are_closed_and_uuid_uses_request_ref() {
		$subject = new Testable_MainWP_WordPress_SEO_V2( '25.5', $this->safe_options() );
		$request = $this->read_request();
		$request['extra'] = true;
		$this->assertSame( 'invalid_request', $subject->abilities_v2( 'read_safe_v1', $request )['code'] );

		$request = $this->read_request();
		$request['request_id'] = $request['request_ref'];
		unset( $request['request_ref'] );
		$this->assertSame( 'invalid_request', $subject->abilities_v2( 'read_safe_v1', $request )['code'] );

		$source = file_get_contents( dirname( __DIR__ ) . '/class/class-mainwp-wordpress-seo.php' );
		$this->assertStringContainsString( "'import_settings' === \$mwp_action", $source );
		$this->assertStringContainsString( "'file_url'", $source );
	}

	private function read_request() {
		return array(
			'contract_version' => '2',
			'request_ref'      => '123e4567-e89b-42d3-a456-426614173010',
			'site_generation'  => str_repeat( 'a', 64 ),
		);
	}

	private function safe_options() {
		return array(
			'wpseo_titles' => array(
				'noindex-post'              => false,
				'title-post'                => '%%title%% %%sep%% %%sitename%%',
				'metadesc-post'             => '%%excerpt%%',
				'noindex-page'              => false,
				'title-page'                => '%%title%% %%sep%% %%sitename%%',
				'metadesc-page'             => null,
				'noindex-tax-category'      => false,
				'title-tax-category'        => '%%category%% %%sep%% %%sitename%%',
				'metadesc-tax-category'     => null,
				'noindex-tax-post_tag'      => true,
				'title-tax-post_tag'        => '%%tag%% %%sep%% %%sitename%%',
				'metadesc-tax-post_tag'     => null,
				'noindex-author-wpseo'      => true,
				'noindex-archive-wpseo'     => true,
				'unowned_private_setting'   => 'secret',
			),
			'wpseo'        => array(
				'enable_xml_sitemap' => true,
				'verification_token' => 'secret',
			),
		);
	}
}

class Testable_MainWP_WordPress_SEO_V2 extends MainWP_WordPress_SEO {

	private $test_version;

	private $test_options;

	public function __construct( $version, $options ) {
		$this->test_version = $version;
		$this->test_options = $options;
	}

	protected function abilities_v2_runtime() {
		return array(
			'plugin_state' => 'active',
			'version'      => $this->test_version,
			'options'      => $this->test_options,
		);
	}
}
