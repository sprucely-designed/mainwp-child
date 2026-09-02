<?php
/**
 * Tests for Pressable Cache Management purges.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Cache_Purge;

if ( ! class_exists( 'Edge_Cache_Plugin' ) ) {
	/**
	 * Minimal Pressable Edge Cache test fixture.
	 */
	class Edge_Cache_Plugin {

		const EC_ENABLED  = 'enabled';
		const EC_DISABLED = 'disabled';

		/**
		 * Live Edge Cache status returned to MainWP Child.
		 *
		 * @var string
		 */
		public static $status = self::EC_ENABLED;

		/**
		 * Return the fixture instance.
		 *
		 * @return self Fixture instance.
		 */
		public static function get_instance() {
			return new self();
		}

		/**
		 * Return the configured live Edge Cache status.
		 *
		 * @return string Edge Cache status.
		 */
		public function get_ec_status() {
			return self::$status;
		}
	}
}

/**
 * Test double for controlling Pressable cache-layer results.
 */
class MainWP_Child_Cache_Purge_Pressable_Test_Double extends MainWP_Child_Cache_Purge {

	/**
	 * Object cache flush result.
	 *
	 * @var bool
	 */
	public $object_cache_result = true;

	/**
	 * Batcache flush result.
	 *
	 * @var bool
	 */
	public $batcache_result = true;

	/**
	 * Whether Edge Cache is enabled.
	 *
	 * @var bool
	 */
	public $edge_cache_enabled = true;

	/**
	 * Edge Cache purge result.
	 *
	 * @var bool
	 */
	public $edge_cache_result = true;

	/**
	 * Number of Edge Cache purge attempts.
	 *
	 * @var int
	 */
	public $edge_cache_purge_calls = 0;

	/**
	 * Return the configured object cache result.
	 *
	 * @return bool Object cache result.
	 */
	protected function pressable_flush_object_cache() {
		return $this->object_cache_result;
	}

	/**
	 * Return the configured Batcache result.
	 *
	 * @return bool Batcache result.
	 */
	protected function pressable_flush_batcache() {
		return $this->batcache_result;
	}

	/**
	 * Return the configured Edge Cache status.
	 *
	 * @return bool Edge Cache status.
	 */
	protected function pressable_edge_cache_is_enabled() {
		return $this->edge_cache_enabled;
	}

	/**
	 * Return the configured Edge Cache purge result.
	 *
	 * @return bool Edge Cache purge result.
	 */
	protected function pressable_purge_edge_cache() {
		++$this->edge_cache_purge_calls;
		return $this->edge_cache_result;
	}
}

/**
 * Test double that exposes Pressable Edge Cache status detection.
 */
class MainWP_Child_Cache_Purge_Pressable_Status_Test_Double extends MainWP_Child_Cache_Purge {

	/**
	 * Return the detected Edge Cache status.
	 *
	 * @return bool Whether Edge Cache is enabled.
	 */
	public function edge_cache_is_enabled() {
		return $this->pressable_edge_cache_is_enabled();
	}
}

/**
 * Pressable Cache Management purge test case.
 */
class Pressable_Cache_Purge_Test extends WP_UnitTestCase {

	/**
	 * Reset cache-control timestamps after each test.
	 */
	public function tear_down() {
		delete_option( 'mainwp_cache_control_last_purged' );
		delete_option( 'flush-obj-cache-time-stamp' );
		delete_option( 'edge-cache-purge-time-stamp' );
		delete_option( 'edge-cache-enabled' );
		Edge_Cache_Plugin::$status = Edge_Cache_Plugin::EC_ENABLED;

		parent::tear_down();
	}

	/**
	 * Test that all active layers must succeed before MainWP reports success.
	 */
	public function test_reports_success_after_all_active_layers_are_purged() {
		$purger = new MainWP_Child_Cache_Purge_Pressable_Test_Double();
		$result = $purger->pressable_cache_management_auto_purge_cache();

		$this->assertSame( 'SUCCESS', $result['action'] );
		$this->assertSame( 1, $purger->edge_cache_purge_calls );
		$this->assertNotFalse( get_option( 'mainwp_cache_control_last_purged', false ) );
		$this->assertNotFalse( get_option( 'flush-obj-cache-time-stamp', false ) );
	}

	/**
	 * Test that an object cache failure is reported after other layers are attempted.
	 */
	public function test_reports_object_cache_failure_without_advancing_mainwp_timestamp() {
		update_option( 'mainwp_cache_control_last_purged', 12345 );

		$purger                      = new MainWP_Child_Cache_Purge_Pressable_Test_Double();
		$purger->object_cache_result = false;
		$result                      = $purger->pressable_cache_management_auto_purge_cache();

		$this->assertSame( 'ERROR', $result['action'] );
		$this->assertNotFalse( strpos( $result['result'], 'Object Cache' ) );
		$this->assertSame( 1, $purger->edge_cache_purge_calls );
		$this->assertSame( 12345, get_option( 'mainwp_cache_control_last_purged' ) );
		$this->assertFalse( get_option( 'flush-obj-cache-time-stamp', false ) );
	}

	/**
	 * Test that Batcache and Edge Cache failures are both reported.
	 */
	public function test_reports_each_failed_cache_layer() {
		update_option( 'mainwp_cache_control_last_purged', 12345 );

		$purger                    = new MainWP_Child_Cache_Purge_Pressable_Test_Double();
		$purger->batcache_result   = false;
		$purger->edge_cache_result = false;
		$result                    = $purger->pressable_cache_management_auto_purge_cache();

		$this->assertSame( 'ERROR', $result['action'] );
		$this->assertNotFalse( strpos( $result['result'], 'Batcache' ) );
		$this->assertNotFalse( strpos( $result['result'], 'Edge Cache' ) );
		$this->assertSame( 12345, get_option( 'mainwp_cache_control_last_purged' ) );
		$this->assertFalse( get_option( 'flush-obj-cache-time-stamp', false ) );
	}

	/**
	 * Test that disabled Edge Cache is skipped.
	 */
	public function test_skips_edge_cache_when_disabled() {
		$purger                     = new MainWP_Child_Cache_Purge_Pressable_Test_Double();
		$purger->edge_cache_enabled = false;
		$purger->edge_cache_result  = false;
		$result                     = $purger->pressable_cache_management_auto_purge_cache();

		$this->assertSame( 'SUCCESS', $result['action'] );
		$this->assertSame( 0, $purger->edge_cache_purge_calls );
		$this->assertNotFalse( get_option( 'mainwp_cache_control_last_purged', false ) );
	}

	/**
	 * Test that an authoritative disabled status overrides a stale enabled option.
	 */
	public function test_live_disabled_edge_cache_overrides_stale_enabled_option() {
		update_option( 'edge-cache-enabled', 'enabled' );
		Edge_Cache_Plugin::$status = Edge_Cache_Plugin::EC_DISABLED;

		$purger = new MainWP_Child_Cache_Purge_Pressable_Status_Test_Double();

		$this->assertFalse( $purger->edge_cache_is_enabled() );
	}
}
