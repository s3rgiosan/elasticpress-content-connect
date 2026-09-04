<?php
/**
 * Tests covering the multi-value filter param -> Elasticsearch "term" bug.
 *
 * @package EPContentConnect
 */

namespace EPContentConnect\Tests\Unit;

use ReflectionMethod;
use ReflectionProperty;
use WP_Query;
use WP_UnitTestCase;
use EPContentConnect\PostToPost\Feature;
use EPContentConnect\PostToPost\Helper;

/**
 * Feature::get_active_filters() supports array values
 * (`?filter[]=a&filter[]=b`, sanitized via array_map( 'sanitize_text_field', ... )),
 * but Feature::build_filter_queries() (src/PostToPost/Feature.php ~244)
 * treats every filter value as a scalar. When the value is actually an
 * array, it gets embedded directly into an Elasticsearch `term` clause,
 * e.g. `[ 'term' => [ $field_name . '.post_name' => [ 'alpha', 'beta' ] ] ]`,
 * which Elasticsearch rejects with
 * "[term] query does not support array of values".
 */
class FeatureFilterQueriesTest extends WP_UnitTestCase {

	/**
	 * Invokes the private Feature::build_filter_queries() method.
	 *
	 * build_filter_queries() is private and has no public seam: it is only
	 * ever reached internally from set_relationship_filters(), which in
	 * turn requires the full ElasticPress `ep_post_formatted_args` pipeline
	 * (a live query, admin/AJAX checks, is_filterable_page(), etc.) just to
	 * get to it. Reflection is used here because there is no other way to
	 * exercise this query-building logic directly.
	 *
	 * @param  Feature   $feature        Feature instance.
	 * @param  array     $active_filters Active filters, keyed the same way
	 *                                   Feature::get_active_filters() produces them:
	 *                                   [ $relationship_name => [ $relationship_post_type => $filter_value ] ].
	 * @param  WP_Query  $wp_query       WordPress query object.
	 * @return array Built Elasticsearch filter queries.
	 */
	private function build_filter_queries( Feature $feature, array $active_filters, WP_Query $wp_query ): array {

		$method = new ReflectionMethod( Feature::class, 'build_filter_queries' );
		$method->setAccessible( true );

		return $method->invoke( $feature, $active_filters, $wp_query );
	}

	/**
	 * Invokes the private Feature::add_filters_to_query() method.
	 *
	 * add_filters_to_query() is private and, like build_filter_queries(), is
	 * only ever reached from set_relationship_filters() behind the full
	 * ElasticPress `ep_post_formatted_args` pipeline. Reflection is the only way
	 * to exercise the per-post-type grouping / OR-of-groups assembly directly.
	 *
	 * @param  Feature  $feature             Feature instance.
	 * @param  array    $formatted_args      Current formatted arguments.
	 * @param  array    $filter_query_groups List of per-post-type filter query groups.
	 * @param  WP_Query $wp_query            WordPress query object.
	 * @return array Modified formatted arguments.
	 */
	private function add_filters_to_query( Feature $feature, array $formatted_args, array $filter_query_groups, WP_Query $wp_query ): array {

		$method = new ReflectionMethod( Feature::class, 'add_filters_to_query' );
		$method->setAccessible( true );

		return $method->invoke( $feature, $formatted_args, $filter_query_groups, $wp_query );
	}

	/**
	 * Creates a Feature instance with its private Helper dependency wired up,
	 * without running Feature::setup() (which requires the ElasticPress
	 * feature registration/activation machinery we don't need for this test).
	 *
	 * @return Feature
	 */
	private function make_feature(): Feature {

		$feature = new Feature();

		$helper_property = new ReflectionProperty( Feature::class, 'helper' );
		$helper_property->setAccessible( true );
		$helper_property->setValue( $feature, new Helper() );

		return $feature;
	}

	/**
	 * Recursively walks a built query array and collects the field names of
	 * every `term` clause whose value is itself an array.
	 *
	 * @param  mixed $node       Current node being inspected.
	 * @param  array $violations Collected offending field names (by reference).
	 * @return void
	 */
	private function collect_array_valued_term_clauses( $node, array &$violations ): void {

		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['term'] ) && is_array( $node['term'] ) ) {
			foreach ( $node['term'] as $field => $value ) {
				if ( is_array( $value ) ) {
					$violations[] = $field;
				}
			}
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$this->collect_array_valued_term_clauses( $value, $violations );
			}
		}
	}

	public function test_array_filter_value_does_not_produce_a_term_clause_with_an_array_value(): void {

		$feature  = $this->make_feature();
		$wp_query = new WP_Query();

		// Shape matches what Feature::get_active_filters() builds when a
		// `?filter[]=alpha&filter[]=beta` param is present: an array of
		// non-numeric strings (slugs) for one relationship post type.
		$active_filters = [
			'related_content' => [
				'page' => [ 'alpha', 'beta' ],
			],
		];

		$filter_queries = $this->build_filter_queries( $feature, $active_filters, $wp_query );

		$violations = [];
		$this->collect_array_valued_term_clauses( $filter_queries, $violations );

		// FAILS until fix: array filter values must expand to terms / per-value clauses, not a term with an array value.
		$this->assertSame(
			[],
			$violations,
			'No "term" clause should ever carry an array as its value; Elasticsearch rejects that with "[term] query does not support array of values".'
		);
	}

	public function test_scalar_filter_value_still_builds_a_valid_single_value_term_clause(): void {

		$feature  = $this->make_feature();
		$wp_query = new WP_Query();

		$active_filters = [
			'related_content' => [
				'page' => 'alpha',
			],
		];

		$filter_queries = $this->build_filter_queries( $feature, $active_filters, $wp_query );

		$violations = [];
		$this->collect_array_valued_term_clauses( $filter_queries, $violations );

		$this->assertSame( [], $violations, 'A scalar filter value should never produce a "term" clause with an array value.' );

		$this->assertNotEmpty( $filter_queries );

		$should_clauses = $filter_queries[0]['nested']['query']['bool']['should'];

		$term_field_names = [];
		foreach ( $should_clauses as $clause ) {
			if ( isset( $clause['term'] ) ) {
				$term_field_names = array_merge( $term_field_names, array_keys( $clause['term'] ) );
			}
		}

		$this->assertContains( 'related_content.post_name', $term_field_names );
		$this->assertContains( 'related_content.post_title.raw', $term_field_names );
	}

	public function test_numeric_array_filter_value_builds_post_id_term_clauses(): void {

		$feature  = $this->make_feature();
		$wp_query = new WP_Query();

		// Shape matches Feature::get_active_filters() for `?filter[]=1&filter[]=2`:
		// an array of numeric strings for one relationship post type.
		$active_filters = [
			'related_content' => [
				'page' => [ '1', '2' ],
			],
		];

		$filter_queries = $this->build_filter_queries( $feature, $active_filters, $wp_query );

		$violations = [];
		$this->collect_array_valued_term_clauses( $filter_queries, $violations );
		$this->assertSame( [], $violations, 'A numeric array filter value must not produce a term clause with an array value.' );

		$should_clauses = $filter_queries[0]['nested']['query']['bool']['should'];

		// Each numeric value expands to exactly one post_id term with an int value.
		$post_id_terms = [];
		foreach ( $should_clauses as $clause ) {
			if ( isset( $clause['term']['related_content.post_id'] ) ) {
				$post_id_terms[] = $clause['term']['related_content.post_id'];
			}
		}
		sort( $post_id_terms );
		$this->assertSame( [ 1, 2 ], $post_id_terms, 'Numeric array values must expand to one int post_id term each.' );

		// Numeric values must NOT produce post_name / post_title clauses.
		$field_names = [];
		foreach ( $should_clauses as $clause ) {
			foreach ( [ 'term', 'match' ] as $type ) {
				if ( isset( $clause[ $type ] ) ) {
					$field_names = array_merge( $field_names, array_keys( $clause[ $type ] ) );
				}
			}
		}
		$this->assertNotContains( 'related_content.post_name', $field_names );
		$this->assertNotContains( 'related_content.post_title.raw', $field_names );
	}

	public function test_multiple_post_type_groups_are_ored_under_should_with_minimum_should_match(): void {

		$feature  = $this->make_feature();
		$wp_query = new WP_Query();

		// Two queried post types, each carrying its own relationship filter.
		// Each group is a flat list of nested queries, exactly as
		// set_relationship_filters() collects it per queried post type.
		$group_a = $this->build_filter_queries( $feature, [ 'related_content' => [ 'page' => 'alpha' ] ], $wp_query );
		$group_b = $this->build_filter_queries( $feature, [ 'related_content' => [ 'post' => 'beta' ] ], $wp_query );

		$formatted_args = $this->add_filters_to_query( $feature, [], [ $group_a, $group_b ], $wp_query );

		$bool = $formatted_args['post_filter']['bool'];

		// Groups must be OR'd, not AND'd into a single `must`.
		$this->assertArrayHasKey( 'should', $bool );
		$this->assertArrayNotHasKey( 'must', $bool );
		$this->assertSame( 1, $bool['minimum_should_match'] );

		// One should clause per post-type group, each an inner bool wrapping
		// that group's own nested filter(s) under the within-group operator.
		$this->assertCount( 2, $bool['should'] );

		foreach ( $bool['should'] as $group_clause ) {
			$this->assertArrayHasKey( 'bool', $group_clause );
			$this->assertArrayHasKey( 'must', $group_clause['bool'] );

			foreach ( $group_clause['bool']['must'] as $nested ) {
				$this->assertArrayHasKey( 'nested', $nested );
			}
		}

		// Each group keeps its own nested queries; they are not merged together.
		$this->assertSame( $group_a, $formatted_args['post_filter']['bool']['should'][0]['bool']['must'] );
		$this->assertSame( $group_b, $formatted_args['post_filter']['bool']['should'][1]['bool']['must'] );
	}

	public function test_single_post_type_group_keeps_the_previous_structure(): void {

		$feature  = $this->make_feature();
		$wp_query = new WP_Query();

		$group = $this->build_filter_queries( $feature, [ 'related_content' => [ 'page' => 'alpha' ] ], $wp_query );

		$formatted_args = $this->add_filters_to_query( $feature, [], [ $group ], $wp_query );

		$bool = $formatted_args['post_filter']['bool'];

		// A lone group is placed directly under the within-group operator, with
		// no extra should-of-groups wrapper (structurally equivalent to before).
		$this->assertArrayHasKey( 'must', $bool );
		$this->assertArrayNotHasKey( 'should', $bool );
		$this->assertArrayNotHasKey( 'minimum_should_match', $bool );

		$this->assertSame( $group, $bool['must'] );
	}

	public function test_multiple_relationship_filters_on_one_post_type_are_anded_under_must(): void {

		$feature  = $this->make_feature();
		$wp_query = new WP_Query();

		// The production use case: a single post type (e.g. a person archive)
		// filtered by several distinct relationships at once (service, office,
		// role). get_active_filters() produces one entry per active relationship.
		$active_filters = [
			'person_to_service' => [ 'service' => 'alpha' ],
			'person_to_office'  => [ 'office' => 'beta' ],
			'person_to_role'    => [ 'role' => 'gamma' ],
		];

		$group = $this->build_filter_queries( $feature, $active_filters, $wp_query );

		// One nested query per relationship field, each on its own path.
		$this->assertCount( 3, $group, 'Each active relationship should build its own nested query.' );

		$paths = [];
		foreach ( $group as $query ) {
			$this->assertArrayHasKey( 'nested', $query );
			$paths[] = $query['nested']['path'];
		}
		sort( $paths );
		$this->assertSame( [ 'person_to_office', 'person_to_role', 'person_to_service' ], $paths );

		$formatted_args = $this->add_filters_to_query( $feature, [], [ $group ], $wp_query );

		$bool = $formatted_args['post_filter']['bool'];

		// Different relationships must be AND-combined: a document has to satisfy
		// every relationship filter, so they sit under `must`, never `should`.
		$this->assertArrayHasKey( 'must', $bool );
		$this->assertArrayNotHasKey( 'should', $bool );
		$this->assertArrayNotHasKey( 'minimum_should_match', $bool );

		$this->assertCount( 3, $bool['must'] );
		$this->assertSame( $group, $bool['must'] );
	}
}
