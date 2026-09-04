<?php
/**
 * Test covering Indexing::get_related_posts() excluding the source post from
 * its own related list.
 *
 * @package EPContentConnect
 */

namespace EPContentConnect\Tests\Unit;

use ReflectionProperty;
use WP_UnitTestCase;
use EPContentConnect\PostToPost\Helper;
use EPContentConnect\PostToPost\Indexing;
use function TenUp\ContentConnect\Helpers\get_registry;

class IndexingExcludeSourceTest extends WP_UnitTestCase {

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
		if ( ! function_exists( 'TenUp\ContentConnect\Helpers\get_registry' ) ) {
			$this->markTestSkipped( 'Content Connect is not available.' );
		}

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

	public function test_index_relationships_excludes_the_source_post_from_its_own_related_list(): void {

		$relationship_name = 'self_related_' . wp_generate_password( 12, false );

		// Register a relationship whose source type ('post') is ALSO one of
		// the target types, via the real Content Connect registry API.
		$relationship = get_registry()->define_post_to_post(
			'post',
			[ 'post', 'page' ],
			$relationship_name
		);

		$source_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$related_post_ids = self::factory()->post->create_many( 2, [ 'post_type' => 'post' ] );
		$related_page_ids = self::factory()->post->create_many( 2, [ 'post_type' => 'page' ] );

		foreach ( array_merge( $related_post_ids, $related_page_ids ) as $related_id ) {
			$relationship->add_relationship( $source_id, $related_id );
		}

		$post_args = [
			'post_type' => 'post',
		];

		$result = $this->indexing->index_relationships( $post_args, $source_id );

		$field_name = $this->helper->get_field_name( $relationship_name, 'post' );

		$this->assertArrayHasKey( $field_name, $result, 'Expected the relationship field to be present in the ES post args.' );

		$indexed_post_ids = wp_list_pluck( $result[ $field_name ], 'post_id' );

		$this->assertNotContains(
			$source_id,
			$indexed_post_ids,
			'Expected the source post to never be indexed as its own related item.'
		);

		// Every genuine relation (posts AND pages) belongs in the merged field;
		// only the source post itself must be excluded.
		$expected_post_ids = array_merge( $related_post_ids, $related_page_ids );
		sort( $expected_post_ids );

		$actual_post_ids = $indexed_post_ids;
		sort( $actual_post_ids );

		$this->assertSame( $expected_post_ids, $actual_post_ids );
	}
}
