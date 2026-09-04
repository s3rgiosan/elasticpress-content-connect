<?php
/**
 * Test covering Indexing::deindex_post_relationships() batching the partner
 * cleanup into one bulk request per relationship and excluding the deleted
 * post's own document.
 *
 * @package EPContentConnect
 */

namespace EPContentConnect\Tests\Unit;

use ReflectionProperty;
use WP_UnitTestCase;
use EPContentConnect\PostToPost\Helper;
use EPContentConnect\PostToPost\Indexing;
use function TenUp\ContentConnect\Helpers\get_registry;

class IndexingDeindexCleanupTest extends WP_UnitTestCase {

	/**
	 * Indexing instance under test, with its private Helper wired via reflection.
	 *
	 * @var Indexing
	 */
	private $indexing;

	/**
	 * Helper instance used both by the test and by the Indexing instance under test.
	 *
	 * @var Helper
	 */
	private $helper;

	/**
	 * Raw ndjson bodies of the ES bulk requests captured during the test.
	 *
	 * @var array
	 */
	private $captured_bulk_requests = [];

	/**
	 * @inheritDoc
	 */
	public function set_up(): void {
		if ( ! function_exists( 'TenUp\ContentConnect\Helpers\get_registry' ) ) {
			$this->markTestSkipped( 'Content Connect is not available.' );
		}

		parent::set_up();

		$this->helper   = new Helper();
		$this->indexing = new Indexing();

		$helper_property = new ReflectionProperty( Indexing::class, 'helper' );
		$helper_property->setAccessible( true );
		$helper_property->setValue( $this->indexing, $this->helper );

		$this->captured_bulk_requests = [];
	}

	public function test_deindex_removes_the_deleted_post_from_partners_in_one_request_per_relationship(): void {

		// Capture every ES bulk request and short-circuit the HTTP call with a
		// fake 200 so execute_bulk_update() succeeds without a live cluster.
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) {
				if ( false !== strpos( (string) $url, '_bulk' ) ) {
					$this->captured_bulk_requests[] = $parsed_args['body'];

					return [
						'response' => [
							'code'    => 200,
							'message' => 'OK',
						],
						'body'     => wp_json_encode(
							[
								'errors' => false,
								'items'  => [],
							]
						),
						'headers'  => [],
					];
				}

				return $preempt;
			},
			10,
			3
		);

		$rel_a = 'cleanup_a_' . wp_generate_password( 12, false );
		$rel_b = 'cleanup_b_' . wp_generate_password( 12, false );

		$relationship_a = get_registry()->define_post_to_post( 'post', [ 'post' ], $rel_a );
		$relationship_b = get_registry()->define_post_to_post( 'post', [ 'page' ], $rel_b );

		$source_id     = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$partner_posts = self::factory()->post->create_many( 2, [ 'post_type' => 'post' ] );
		$partner_page  = self::factory()->post->create( [ 'post_type' => 'page' ] );

		foreach ( $partner_posts as $partner_id ) {
			$relationship_a->add_relationship( $source_id, $partner_id );
		}
		$relationship_b->add_relationship( $source_id, $partner_page );

		// Discard the indexing bulk requests fired by add_relationship() during
		// setup; measure only the requests made by the cleanup under test.
		$this->captured_bulk_requests = [];

		$this->indexing->deindex_post_relationships( $source_id );

		// One bulk request per relationship that has related posts (2), NOT one
		// per related post (which the pre-fix per-pair code would have made: 3).
		$this->assertCount(
			2,
			$this->captured_bulk_requests,
			'Expected exactly one bulk request per relationship, not one per related post.'
		);

		$targeted_ids = [];
		foreach ( $this->captured_bulk_requests as $body ) {
			foreach ( explode( "\n", trim( (string) $body ) ) as $line ) {
				$decoded = json_decode( $line, true );
				if ( isset( $decoded['update']['_id'] ) ) {
					$targeted_ids[] = (int) $decoded['update']['_id'];
				}
			}
		}
		sort( $targeted_ids );

		$expected_ids = array_merge( $partner_posts, [ $partner_page ] );
		sort( $expected_ids );

		$this->assertSame(
			$expected_ids,
			$targeted_ids,
			'Expected each partner document to be targeted exactly once.'
		);

		$this->assertNotContains(
			$source_id,
			$targeted_ids,
			'Expected the deleted post to be excluded from the cleanup.'
		);
	}
}
