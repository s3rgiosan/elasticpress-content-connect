<?php
/**
 * Tests covering Helper::get_relationships() / get_related_post_types() when no
 * relationships are available.
 *
 * Note: the `function_exists( 'TenUp\ContentConnect\Helpers\get_registry' )`
 * guard in Helper::get_relationships() cannot be exercised directly here because
 * Content Connect is loaded in the test environment and a function cannot be
 * undefined at runtime. These tests instead cover the same practical outcome the
 * guard produces -- an empty relationship set -- by forcing it through the
 * `ep_content_connect_post_to_post_relationships` filter, and assert the helper
 * degrades safely.
 *
 * @package EPContentConnect
 */

namespace EPContentConnect\Tests\Unit;

use WP_UnitTestCase;
use EPContentConnect\PostToPost\Helper;
use function TenUp\ContentConnect\Helpers\get_registry;

class HelperRelationshipsTest extends WP_UnitTestCase {

	/**
	 * @inheritDoc
	 */
	public function set_up(): void {
		if ( ! function_exists( 'TenUp\ContentConnect\Helpers\get_registry' ) ) {
			$this->markTestSkipped( 'Content Connect is not available.' );
		}

		parent::set_up();
	}

	public function test_get_related_post_types_is_empty_when_no_relationships_available(): void {

		add_filter( 'ep_content_connect_post_to_post_relationships', '__return_empty_array' );

		$helper = new Helper();

		$this->assertSame( [], $helper->get_relationships() );
		$this->assertSame( [], $helper->get_related_post_types( 'post' ) );
	}

	public function test_get_relationships_returns_registered_relationships(): void {

		$relationship_name = 'helper_rel_' . wp_generate_password( 12, false );

		get_registry()->define_post_to_post( 'post', [ 'page' ], $relationship_name );

		$helper        = new Helper();
		$relationships = $helper->get_relationships();

		$names = [];
		foreach ( $relationships as $relationship ) {
			$names[] = $relationship->name;
		}

		$this->assertContains( $relationship_name, $names, 'Expected the registered relationship to be returned.' );
	}

	public function test_get_related_post_types_maps_both_sides_of_a_relationship(): void {

		$relationship_name = 'helper_rel_' . wp_generate_password( 12, false );

		get_registry()->define_post_to_post( 'post', [ 'page' ], $relationship_name );

		$helper = new Helper();

		// Queried as the "from" post type -> related types are the "to" side.
		$from_side = $helper->get_related_post_types( 'post' );
		$this->assertArrayHasKey( $relationship_name, $from_side );
		$this->assertContains( 'page', $from_side[ $relationship_name ] );

		// Queried as the "to" post type -> related types are the "from" side.
		$to_side = $helper->get_related_post_types( 'page' );
		$this->assertArrayHasKey( $relationship_name, $to_side );
		$this->assertContains( 'post', $to_side[ $relationship_name ] );
	}
}
