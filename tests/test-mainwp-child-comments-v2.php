<?php
/**
 * Comments Child protocol-v2 tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Comments;

/** Comments protocol-v2 contract tests. */
class Test_MainWP_Child_Comments_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Comments */
	private $comments;

	/** @var int */
	private $post_id;

	/** Set up a fresh post and protocol handler. */
	public function setUp(): void {
		parent::setUp();
		$this->comments = new MainWP_Child_Comments();
		$this->post_id  = self::factory()->post->create( array( 'post_title' => 'Protocol Post' ) );
	}

	/** Exact reads are redacted and return a truthful absence record. */
	public function test_get_comment_is_exact_bounded_and_redacted() {
		$comment_id = $this->create_comment( '0', 'Private body' );
		$result     = $this->comments->comments_v2(
			'get_comment',
			array(
				'operation'  => 'get_comment',
				'comment_id' => $comment_id,
			)
		);

		$this->assertSame( array( 'contract_version', 'operation', 'found', 'comment' ), array_keys( $result ) );
		$this->assertTrue( $result['found'] );
		$this->assertSame( 'pending', $result['comment']['comment_status'] );
		$this->assertSame( 'Private body', $result['comment']['comment_content'] );
		$this->assertArrayNotHasKey( 'author_email', $result['comment'] );
		$this->assertArrayNotHasKey( 'author_ip', $result['comment'] );
		$this->assertArrayNotHasKey( 'author_url', $result['comment'] );

		$missing = $this->comments->comments_v2(
			'get_comment',
			array(
				'operation'  => 'get_comment',
				'comment_id' => PHP_INT_MAX,
			)
		);
		$this->assertFalse( $missing['found'] );
		$this->assertNull( $missing['comment'] );
	}

	/** Closed malformed inputs are rejected before state changes. */
	public function test_malformed_duplicate_and_transition_inputs_do_not_mutate() {
		$comment_id = $this->create_comment( '0' );
		$valid      = array(
			'operation' => 'moderate',
			'action'    => 'approve',
			'items'     => array(
				array(
					'comment_id'      => $comment_id,
					'expected_status' => 'pending',
				),
			),
		);
		$cases      = array(
			array_merge( $valid, array( 'extra' => true ) ),
			array(
				'operation' => 'moderate',
				'action'    => 'approve',
				'items'     => array( $valid['items'][0], $valid['items'][0] ),
			),
			array(
				'operation' => 'moderate',
				'action'    => 'approve',
				'items'     => array(
					array(
						'comment_id'      => '01',
						'expected_status' => 'pending',
					),
				),
			),
			array(
				'operation' => 'moderate',
				'action'    => 'restore',
				'items'     => $valid['items'],
			),
			array(
				'operation' => 'moderate',
				'action'    => 'approve',
				'items'     => array_fill( 0, 101, $valid['items'][0] ),
			),
		);
		foreach ( $cases as $case ) {
			$result = $this->comments->comments_v2( 'moderate', $case );
			$this->assertSame( 'invalid_request', $result['error_code'] );
			$this->assertSame( 'unapproved', wp_get_comment_status( $comment_id ) );
		}
		$mismatched              = $valid;
		$mismatched['operation'] = 'delete_permanently';
		$this->assertSame( 'invalid_request', $this->comments->comments_v2( 'moderate', $mismatched )['error_code'] );
		$source = file_get_contents( dirname( __DIR__ ) . '/class/class-mainwp-child-comments.php' );
		$this->assertStringContainsString( 'if ( $this->has_contract_selector() )', $source );
		$this->assertStringContainsString( "MainWP_Helper::write( \$this->v2_error( 'unknown' ) )", $source );
	}

	/** Every accepted reversible transition is applied and post-read verified. */
	public function test_all_reversible_transitions_are_verified() {
		$cases = array(
			array( 'approve', '0', 'pending', 'approved' ),
			array( 'unapprove', '1', 'approved', 'pending' ),
			array( 'spam', '1', 'approved', 'spam' ),
		);
		foreach ( $cases as $case ) {
			$comment_id = $this->create_comment( $case[1] );
			$result     = $this->moderate( $case[0], $comment_id, $case[2] );
			$this->assertSame( 1, $result['applied'], $case[0] );
			$this->assertSame( $case[3], $result['results'][0]['status_after'], $case[0] );
		}

		$unspam_id = $this->create_comment( '1' );
		wp_spam_comment( $unspam_id );
		$unspam = $this->moderate( 'unspam', $unspam_id, 'spam' );
		$this->assertSame( 'approved', $unspam['results'][0]['status_after'] );

		$trash_id = $this->create_comment( '0' );
		$trash    = $this->moderate( 'trash', $trash_id, 'pending' );
		$this->assertSame( 'trash', $trash['results'][0]['status_after'] );
		$restore = $this->moderate( 'restore', $trash_id, 'trash' );
		$this->assertSame( 'pending', $restore['results'][0]['status_after'] );
	}

	/** Batches sort by ID and preserve truthful mixed outcomes. */
	public function test_moderation_sorts_and_reports_conflict_and_absence() {
		$low    = $this->create_comment( '0' );
		$high   = $this->create_comment( '1' );
		$result = $this->comments->comments_v2(
			'moderate',
			array(
				'operation' => 'moderate',
				'action'    => 'approve',
				'items'     => array(
					array(
						'comment_id'      => PHP_INT_MAX,
						'expected_status' => 'pending',
					),
					array(
						'comment_id'      => $high,
						'expected_status' => 'pending',
					),
					array(
						'comment_id'      => $low,
						'expected_status' => 'pending',
					),
				),
			)
		);
		$this->assertSame( array( $low, $high, PHP_INT_MAX ), wp_list_pluck( $result['results'], 'comment_id' ) );
		$this->assertSame( array( 'applied', 'conflict', 'not_found' ), wp_list_pluck( $result['results'], 'outcome' ) );
		$this->assertSame( 1, $result['applied'] );
	}

	/** Preview performs no deletion; execution proves absence and mixed facts. */
	public function test_permanent_delete_preview_and_execution_are_truthful() {
		$trashed = $this->create_comment( '1' );
		wp_trash_comment( $trashed );
		$approved = $this->create_comment( '1' );
		$items    = array(
			array(
				'comment_id'      => $approved,
				'expected_status' => 'trash',
			),
			array(
				'comment_id'      => $trashed,
				'expected_status' => 'trash',
			),
			array(
				'comment_id'      => PHP_INT_MAX,
				'expected_status' => 'trash',
			),
		);

		$preview = $this->comments->comments_v2(
			'delete_permanently',
			array(
				'operation' => 'delete_permanently',
				'dry_run'   => true,
				'items'     => $items,
			)
		);
		$this->assertSame( 1, $preview['eligible'] );
		$this->assertSame( 0, $preview['deleted'] );
		$this->assertNotNull( get_comment( $trashed ) );
		$this->assertSame( array( 'would_delete', 'conflict', 'not_found' ), wp_list_pluck( $preview['results'], 'outcome' ) );

		$execute = $this->comments->comments_v2(
			'delete_permanently',
			array(
				'operation' => 'delete_permanently',
				'dry_run'   => false,
				'items'     => $items,
			)
		);
		$this->assertSame( 1, $execute['eligible'] );
		$this->assertSame( 1, $execute['deleted'] );
		$this->assertNull( get_comment( $trashed ) );
		$this->assertFalse( $execute['results'][0]['exists_after'] );
	}

	/** @return int */
	private function create_comment( $approved, $content = 'Fixture comment' ) {
		return self::factory()->comment->create(
			array(
				'comment_post_ID'      => $this->post_id,
				'comment_approved'     => $approved,
				'comment_author'       => 'Fixture Author',
				'comment_author_email' => 'private@example.test',
				'comment_author_IP'    => '192.0.2.10',
				'comment_author_url'   => 'https://private.example.test/',
				'comment_content'      => $content,
			)
		);
	}

	/** @return array */
	private function moderate( $action, $comment_id, $expected_status ) {
		return $this->comments->comments_v2(
			'moderate',
			array(
				'operation' => 'moderate',
				'action'    => $action,
				'items'     => array(
					array(
						'comment_id'      => $comment_id,
						'expected_status' => $expected_status,
					),
				),
			)
		);
	}
}
