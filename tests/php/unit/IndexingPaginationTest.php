<?php
/**
 * Test covering Indexing::get_related_posts() paginating through all related
 * posts instead of silently capping at one page.
 *
 * @package EPContentConnect
 */

namespace EPContentConnect\Tests\Unit;

use ReflectionProperty;
use WP_UnitTestCase;
use EPContentConnect\PostToPost\Helper;
use EPContentConnect\PostToPost\Indexing;
use function TenUp\ContentConnect\Helpers\get_registry;

class IndexingPaginationTest extends WP_UnitTestCase {

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
	 * @inheritDoc
	 */
	public function set_up(): void {
		parent::set_up();

		$this->helper   = new Helper();
		$this->indexing = new Indexing();

		// Indexing::index_relationships() reads a Helper instance from its
		// private $helper property, which is normally populated by
		// Indexing::setup() only when the ElasticPress feature is active.
		// We bypass that activation dance and wire the private property via
		// reflection so the unit under test runs in isolation.
		$helper_property = new ReflectionProperty( Indexing::class, 'helper' );
		$helper_property->setAccessible( true );
		$helper_property->setValue( $this->indexing, $this->helper );
	}

	public function test_index_relationships_paginates_through_all_related_posts(): void {

		$relationship_name = 'paginated_related_' . wp_generate_password( 12, false );

		$relationship = get_registry()->define_post_to_post(
			'post',
			'page',
			$relationship_name
		);

		$source_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$related_page_ids = self::factory()->post->create_many( 5, [ 'post_type' => 'page' ] );

		foreach ( $related_page_ids as $related_id ) {
			$relationship->add_relationship( $source_id, $related_id );
		}

		// Force a small page size so a single query can never return every
		// related post, proving the pagination loop gathers them all.
		$force_small_page = static function ( $query_args ) {
			$query_args['posts_per_page'] = 2;

			return $query_args;
		};

		add_filter( 'ep_content_connect_related_posts_query_args', $force_small_page );

		$post_args = [
			'post_type' => 'post',
		];

		$result = $this->indexing->index_relationships( $post_args, $source_id );

		remove_filter( 'ep_content_connect_related_posts_query_args', $force_small_page );

		$field_name = $this->helper->get_field_name( $relationship_name, 'page' );

		$this->assertArrayHasKey( $field_name, $result, 'Expected the relationship field to be present in the ES post args.' );

		$indexed_post_ids = wp_list_pluck( $result[ $field_name ], 'post_id' );
		sort( $indexed_post_ids );

		$expected_post_ids = $related_page_ids;
		sort( $expected_post_ids );

		$this->assertSame(
			$expected_post_ids,
			$indexed_post_ids,
			'Expected ALL related posts to be gathered across pages, not just the first page.'
		);
	}

	// Not unit-tested: requires a live Elasticsearch endpoint.
	// - Indexing::execute_bulk_update()'s retry_on_conflict / error handling (Fix #6).
	// - Indexing::deindex_post_relationships() cleanup on permanent post deletion (Fix #4),
	//   which relies on the same execute_bulk_update() bulk request path.
	// Verified via code review instead.
}
