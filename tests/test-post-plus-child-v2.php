<?php
/**
 * Post Plus Child protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_Post_Plus_Child_V2 extends WP_UnitTestCase {

	private $actor;
	private $server_option;

	public function set_up(): void {
		parent::set_up();
		$this->server_option = get_option( 'mainwp_child_server', null );
		update_option( 'mainwp_child_server', 'https://dashboard.example.test/' );
		$this->actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->actor );
		wp_insert_term( 'Post Plus Fixture', 'category', array( 'slug' => 'post-plus-fixture' ) );
		delete_option( 'mainwp_child_post_plus_operations_v2' );
	}

	public function tear_down(): void {
		delete_option( 'mainwp_child_post_plus_operations_v2' );
		if ( null === $this->server_option ) {
			delete_option( 'mainwp_child_server' );
		} else {
			update_option( 'mainwp_child_server', $this->server_option );
		}
		parent::tear_down();
	}

	public function test_capability_callable_advertises_create_status_and_readback() {
		$this->assertTrue( MainWP_Child_Callable::get_instance()->is_callable_function( 'post_plus_capabilities_v2' ) );
		$result = $this->request( 'capabilities', array() );
		$this->assertSame( array( 'post_plus_newpost_v2', 'post_plus_status_v2', 'post_plus_readback_v2' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_create_freezes_random_choices_and_replays_one_post() {
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174611', 'Post Plus create' );
		$result  = $this->request( 'post_plus_newpost_v2', $payload );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'applied', $result['state'] );
		$this->assertSame( $result, $this->request( 'post_plus_newpost_v2', $payload ) );

		$posts = $this->operation_posts( $payload['operation_ref'] );
		$this->assertCount( 1, $posts );
		$this->assertSame( 'author', get_userdata( $posts[0]->post_author )->roles[0] );
		$this->assertNotEmpty( wp_get_post_categories( $posts[0]->ID ) );
	}

	public function test_status_and_readback_are_closed_and_revision_bound() {
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174612', 'Post Plus status' );
		$created = $this->request( 'post_plus_newpost_v2', $payload );
		$status  = $this->request( 'post_plus_status_v2', $this->identity( $payload ) );
		$this->assertSame( 'post_plus_status_v2', $status['operation'] );
		unset( $created['operation'], $status['operation'] );
		$this->assertSame( $created, $status );

		$readback = $this->request( 'post_plus_readback_v2', $this->identity( $payload ) );
		$this->assertSame( array( 'protocol', 'operation', 'ok', 'state', 'operation_ref', 'content_digest', 'post_revision', 'remote_post_ref', 'retryable', 'updated_at', 'readback' ), array_keys( $readback ) );
		$this->assertSame( array( 'post_type', 'status', 'revision' ), array_keys( $readback['readback'] ) );
		$this->assertSame( 'post', $readback['readback']['post_type'] );
		$this->assertSame( $created['post_revision'], $readback['readback']['revision'] );
	}

	public function test_readback_source_mode_is_bounded_and_does_not_take_an_edit_lock() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'post_title'     => 'Clone source',
				'post_content'   => 'Bounded source content.',
				'post_excerpt'   => 'Source excerpt.',
				'post_name'      => 'clone-source',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);
		wp_set_post_terms( $post_id, array( 'post-plus-fixture' ), 'category' );
		$payload = array(
			'dashboard_ref'  => hash( 'sha256', 'https://dashboard.example.test' ),
			'source_post_id' => $post_id,
			'source_type'    => 'post',
		);
		$result = $this->request( 'post_plus_readback_v2', $payload );

		$this->assertSame( array( 'protocol', 'operation', 'complete', 'source_post_id', 'source_type', 'source_ref', 'title', 'content_digest', 'compatibility', 'private_context', 'source_revision' ), array_keys( $result ) );
		$this->assertTrue( $result['complete'] );
		$this->assertSame( 'supported', $result['compatibility'] );
		$this->assertSame( '', get_post_meta( $post_id, '_edit_lock', true ) );
		$private = json_decode( $result['private_context'], true );
		$this->assertSame( 'Bounded source content.', $private['post']['content'] );
		$this->assertSame( $result['content_digest'], hash( 'sha256', wp_json_encode( array( $private['post'], $private['randomization'] ) ) ) );
		$binding = $result;
		unset( $binding['source_revision'] );
		$this->assertSame( hash( 'sha256', wp_json_encode( $binding ) ), $result['source_revision'] );

		$payload['source_post_id'] = (string) $post_id;
		$this->assertSame( 'invalid_request', $this->request( 'post_plus_readback_v2', $payload )['code'] );
	}

	public function test_update_and_conflict_paths_do_not_duplicate_content() {
		$create = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174613', 'Before Plus update' );
		$first  = $this->request( 'post_plus_newpost_v2', $create );
		$posts  = $this->operation_posts( $create['operation_ref'] );

		$update                      = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174614', 'After Plus update' );
		$update['mode']              = 'update';
		$update['target_post_id']    = $posts[0]->ID;
		$update['expected_revision'] = $first['post_revision'];
		$result                      = $this->request( 'post_plus_newpost_v2', $update );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'After Plus update', get_post( $posts[0]->ID )->post_title );

		$update['post']['title']  = 'Conflicting replay';
		$update['content_digest'] = hash( 'sha256', wp_json_encode( array( $update['post'], $update['randomization'] ) ) );
		$this->assertSame( 'request_conflict', $this->request( 'post_plus_newpost_v2', $update )['code'] );
		$this->assertCount( 1, $this->operation_posts( $update['operation_ref'] ) );
	}

	public function test_random_category_appends_to_explicit_categories() {
		$explicit = wp_insert_term( 'Post Plus Explicit', 'category', array( 'slug' => 'post-plus-explicit' ) );
		$this->assertIsArray( $explicit );
		$payload = null;
		$chosen  = null;
		for ( $suffix = 618; $suffix < 638; $suffix++ ) {
			$candidate                        = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174' . $suffix, 'Append categories' );
			$candidate['post']['categories']  = array( 'post-plus-explicit' );
			$candidate['content_digest']      = hash( 'sha256', wp_json_encode( array( $candidate['post'], $candidate['randomization'] ) ) );
			$category_ids                     = get_categories( array( 'hide_empty' => false, 'number' => 1001, 'fields' => 'ids' ) );
			$category_ids                     = array_values( array_unique( array_map( 'intval', $category_ids ) ) );
			sort( $category_ids, SORT_NUMERIC );
			$seed                             = hash( 'sha256', $candidate['dashboard_ref'] . '|' . $candidate['operation_ref'] . '|' . $candidate['content_digest'] );
			$candidate_choice                 = $category_ids[ hexdec( substr( $seed, 8, 8 ) ) % count( $category_ids ) ];
			if ( (int) $explicit['term_id'] !== $candidate_choice ) {
				$payload = $candidate;
				$chosen  = $candidate_choice;
				break;
			}
		}
		$this->assertIsArray( $payload );
		$this->assertTrue( $this->request( 'post_plus_newpost_v2', $payload )['ok'] );
		$records = get_option( 'mainwp_child_post_plus_operations_v2' );
		$this->assertSame( $chosen, $records[ $payload['operation_ref'] ]['choices']['category_id'] );
		$posts      = $this->operation_posts( $payload['operation_ref'] );
		$categories = wp_get_post_categories( $posts[0]->ID );
		$this->assertContains( (int) $explicit['term_id'], $categories );
		$this->assertContains( $chosen, $categories );
	}

	public function test_malformed_randomization_and_uuid_id_alias_fail_closed() {
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174615', 'Malformed' );
		$payload['randomization']['roles'][] = 'subscriber';
		$this->assertSame( 'invalid_request', $this->request( 'post_plus_newpost_v2', $payload )['code'] );

		$payload                 = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174616', 'Alias' );
		$payload['operation_id'] = $payload['operation_ref'];
		$this->assertSame( 'invalid_request', $this->request( 'post_plus_newpost_v2', $payload )['code'] );

		$page                                      = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174617', 'Page category' );
		$page['post']['post_type']                  = 'page';
		$page['randomization']['random_category']   = true;
		$page['content_digest']                     = hash( 'sha256', wp_json_encode( array( $page['post'], $page['randomization'] ) ) );
		$this->assertSame( 'invalid_request', $this->request( 'post_plus_newpost_v2', $page )['code'] );
	}

	private function delivery_payload( $operation_ref, $title ) {
		$post = array(
			'post_type'      => 'post',
			'status'         => 'publish',
			'title'          => $title,
			'content'        => 'Typed Post Plus content.',
			'excerpt'        => 'Typed excerpt.',
			'slug'           => sanitize_title( $title ),
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'categories'     => array(),
			'tags'           => array( 'fixture' ),
		);
		$randomization = array(
			'roles'           => array( 'author' ),
			'random_category' => true,
			'date_from'       => '2025-08-01',
			'date_to'         => '2025-08-31',
			'timezone'        => 'America/New_York',
		);
		return array(
			'dashboard_ref'     => hash( 'sha256', 'https://dashboard.example.test' ),
			'operation_ref'     => $operation_ref,
			'mode'              => 'create',
			'target_post_id'    => null,
			'expected_revision' => null,
			'content_digest'    => hash( 'sha256', wp_json_encode( array( $post, $randomization ) ) ),
			'expires_at'        => time() + 300,
			'post'              => $post,
			'randomization'     => $randomization,
		);
	}

	private function identity( $payload ) {
		return array(
			'dashboard_ref' => $payload['dashboard_ref'],
			'operation_ref' => $payload['operation_ref'],
		);
	}

	private function operation_posts( $operation_ref ) {
		return get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'meta_key'       => '_mainwp_child_content_operation_v2',
				'meta_value'     => $operation_ref,
				'posts_per_page' => 2,
			)
		);
	}

	private function request( $operation, $payload ) {
		return MainWP_Child_Posts::get_instance()->post_plus_capabilities_v2(
			array(
				'payload'   => $payload,
				'operation' => $operation,
				'protocol'  => '2',
			)
		);
	}
}
