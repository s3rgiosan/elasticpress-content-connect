<?php
/**
 * Tests covering the multi-target relationship indexing overwrite bug.
 *
 * @package EPContentConnect
 */

namespace EPContentConnect\Tests\Unit;

use ReflectionProperty;
use WP_UnitTestCase;
use EPContentConnect\PostToPost\Helper;
use EPContentConnect\PostToPost\Indexing;
use function TenUp\ContentConnect\Helpers\get_registry;

/**
 * A relationship whose target is MULTIPLE post types should keep every
 * related post from every target type when a post is indexed.
 *
 * Today it doesn't: Helper::get_field_name() ignores its $post_type
 * argument (so every target post type resolves to the same ES field name),
 * and Indexing::index_relationships() assigns
 * `$post_args[ $field_name ] = $related_posts` for each target type in turn
 * (src/PostToPost/Indexing.php ~line 72), so the last target type processed
 * silently overwrites the related posts gathered for every earlier type.
 */
class IndexingRelationshipsTest extends WP_UnitTestCase {

	/**
	 * The post-to-post relationship registered for this test.
	 *
	 * @var \TenUp\ContentConnect\Relationships\PostToPost
	 */
	private $relationship;

	/**
	 * Unique relationship name for this test run.
	 *
	 * @var string
	 */
	private $relationship_name;

	/**
	 * @inheritDoc
	 */
	/**
	 * A second, non-`post` target post type used alongside `page` so the
	 * relationship targets two DISTINCT types, neither the same as the source
	 * type. Using `post` as both source and a target would make Content
	 * Connect's same-type query return the source itself, which is a separate
	 * concern from the multi-target overwrite this test isolates.
	 *
	 * @var string
	 */
	private $target_cpt = 'ep_rel_cpt';

	/**
	 * @inheritDoc
	 */
	public function set_up(): void {
		if ( ! function_exists( 'TenUp\ContentConnect\Helpers\get_registry' ) ) {
			$this->markTestSkipped( 'Content Connect is not available.' );
		}

		parent::set_up();

		register_post_type(
			$this->target_cpt,
			[
				'public'      => true,
				'label'       => 'EP Rel CPT',
				'show_in_rest' => true,
			]
		);

		// The Content Connect registry is a process-wide singleton
		// (TenUp\ContentConnect\Plugin::instance()), so relationships
		// registered by other tests in the same PHPUnit run persist across
		// test methods. Using a unique relationship name per test keeps this
		// test isolated: no other test can be looking at the same field name.
		$this->relationship_name = 'related_content_' . wp_generate_password( 12, false );

		// Register a single relationship FROM `post` TO two DISTINCT target
		// post types (`page` and a custom type) via the real Content Connect
		// registry API. Both targets resolve to the same ES field name, which
		// is exactly the multi-target case that gets overwritten.
		$this->relationship = get_registry()->define_post_to_post(
			'post',
			[ 'page', $this->target_cpt ],
			$this->relationship_name
		);
	}

	public function test_index_relationships_includes_related_posts_from_every_target_post_type(): void {

		$source_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$related_page_ids = self::factory()->post->create_many( 3, [ 'post_type' => 'page' ] );
		$related_cpt_ids  = self::factory()->post->create_many( 2, [ 'post_type' => $this->target_cpt ] );

		// Persist both relationships via Content Connect's real API: the
		// relationship object's add_relationship( $pid1, $pid2 ) method,
		// which also fires the `tenup-content-connect-add-relationship`
		// action that EPContentConnect\PostToPost\Indexing listens to.
		foreach ( $related_page_ids as $related_id ) {
			$this->relationship->add_relationship( $source_id, $related_id );
		}

		foreach ( $related_cpt_ids as $related_id ) {
			$this->relationship->add_relationship( $source_id, $related_id );
		}

		$helper = new Helper();

		$indexing = new Indexing();

		// Indexing::index_relationships() reads a Helper instance from its
		// private $helper property, which is normally populated by
		// Indexing::setup() only when the ElasticPress feature is active.
		// We bypass that activation dance and call index_relationships()
		// directly, wiring the private property via reflection so the unit
		// under test runs in isolation.
		$helper_property = new ReflectionProperty( Indexing::class, 'helper' );
		$helper_property->setAccessible( true );
		$helper_property->setValue( $indexing, $helper );

		$post_args = [
			'post_type' => 'post',
		];

		$result = $indexing->index_relationships( $post_args, $source_id );

		$field_name = $helper->get_field_name( $this->relationship_name, 'post' );

		$this->assertArrayHasKey( $field_name, $result, 'Expected the relationship field to be present in the ES post args.' );

		$indexed_post_ids = wp_list_pluck( $result[ $field_name ], 'post_id' );
		sort( $indexed_post_ids );

		$expected_post_ids = array_merge( $related_page_ids, $related_cpt_ids );
		sort( $expected_post_ids );

		// FAILS until fix: index_relationships overwrites instead of merging multi-target relationships.
		$this->assertSame(
			$expected_post_ids,
			$indexed_post_ids,
			'Expected related posts from BOTH target post types (post and page), matched by post_id.'
		);
	}
}
