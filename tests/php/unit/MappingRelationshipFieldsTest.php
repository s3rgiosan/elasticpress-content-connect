<?php
/**
 * Tests covering the from-side relationship mapping bug (#9).
 *
 * @package EPContentConnect
 */

namespace EPContentConnect\Tests\Unit;

use ReflectionMethod;
use ReflectionProperty;
use WP_UnitTestCase;
use EPContentConnect\PostToPost\Helper;
use EPContentConnect\PostToPost\Mapping;
use function TenUp\ContentConnect\Helpers\get_registry;

/**
 * Mapping::get_relationship_fields() must declare an ES field for every
 * post type that Indexing::index_relationships() can generate a field name
 * for. Indexing runs for posts on the relationship's `from` side too, and
 * calls Helper::get_field_name( $relationship_name, $post_type ) with that
 * side's post type. If an integrator hooks `ep_content_connect_field_name`
 * to make the field name depend on $post_type, the from-side field name
 * differs from the to-side one, but get_relationship_fields() previously
 * only iterated the `to` post types, so the from-side field was never
 * declared in the mapping.
 */
class MappingRelationshipFieldsTest extends WP_UnitTestCase {

	/**
	 * Unique relationship name for this test run.
	 *
	 * @var string
	 */
	private $relationship_name;

	/**
	 * @inheritDoc
	 */
	public function set_up(): void {
		if ( ! function_exists( 'TenUp\ContentConnect\Helpers\get_registry' ) ) {
			$this->markTestSkipped( 'Content Connect is not available.' );
		}

		parent::set_up();

		// The Content Connect registry is a process-wide singleton
		// (TenUp\ContentConnect\Plugin::instance()), so relationships
		// registered by other tests in the same PHPUnit run persist across
		// test methods. Using a unique relationship name per test keeps this
		// test isolated: no other test can be looking at the same field name.
		$this->relationship_name = 'related_content_' . wp_generate_password( 12, false );

		// Register a relationship FROM `post` TO `page` via the real Content
		// Connect registry API.
		get_registry()->define_post_to_post( 'post', [ 'page' ], $this->relationship_name );

		// Make the field name depend on the post type, so the from-side
		// ('post') and to-side ('page') field names differ.
		add_filter( 'ep_content_connect_field_name', [ $this, 'filter_field_name' ], 10, 3 );
	}

	/**
	 * @inheritDoc
	 */
	public function tear_down(): void {

		remove_filter( 'ep_content_connect_field_name', [ $this, 'filter_field_name' ], 10 );

		parent::tear_down();
	}

	/**
	 * Filter callback that makes the field name depend on the post type.
	 *
	 * @param  string $field_name        The field name.
	 * @param  string $relationship_name Relationship name.
	 * @param  string $post_type         Post type.
	 * @return string The post-type-dependent field name.
	 */
	public function filter_field_name( $field_name, $relationship_name, $post_type ) {

		return "{$field_name}_{$post_type}";
	}

	public function test_get_relationship_fields_includes_both_from_and_to_side_field_names(): void {

		$mapping = new Mapping();
		$helper  = new Helper();

		// Mapping::get_relationship_fields() reads a Helper instance from its
		// private $helper property, which is normally populated by
		// Mapping::setup() only when the ElasticPress feature is active. We
		// bypass that activation dance and wire the private property via
		// reflection so the unit under test runs in isolation.
		$helper_property = new ReflectionProperty( Mapping::class, 'helper' );
		$helper_property->setAccessible( true );
		$helper_property->setValue( $mapping, $helper );

		// get_relationship_fields() is private, so it's invoked via
		// ReflectionMethod to exercise it directly without going through
		// the ElasticPress feature activation dance.
		$get_relationship_fields = new ReflectionMethod( Mapping::class, 'get_relationship_fields' );
		$get_relationship_fields->setAccessible( true );

		$fields = $get_relationship_fields->invoke( $mapping );

		$from_side_field = $helper->get_field_name( $this->relationship_name, 'post' );
		$to_side_field   = $helper->get_field_name( $this->relationship_name, 'page' );

		$this->assertContains( $from_side_field, $fields, 'Expected the mapping to include the FROM-side field name.' );
		$this->assertContains( $to_side_field, $fields, 'Expected the mapping to include the TO-side field name.' );
		$this->assertNotSame( $from_side_field, $to_side_field, 'Test setup sanity check: from and to field names must differ.' );
	}
}
