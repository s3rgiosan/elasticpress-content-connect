<?php
/**
 * End-to-end relationship filtering against a real Elasticsearch cluster.
 *
 * @package EPContentConnect
 */

namespace EPContentConnect\Tests\Integration;

use function TenUp\ContentConnect\Helpers\get_registry;

require_once __DIR__ . '/TestCase.php';

/**
 * Exercises the full path: index two related posts into Elasticsearch, then
 * filter a post-type archive by a related post's slug and assert the source
 * post is (and, in the negative case, is not) returned.
 */
class RelationshipFilterTest extends TestCase {

	/**
	 * The registered person post type slug.
	 *
	 * @var string
	 */
	private $person_type = 'ep_person';

	/**
	 * The registered service post type slug.
	 *
	 * @var string
	 */
	private $service_type = 'ep_service';

	/**
	 * Unique relationship name for this test run.
	 *
	 * @var string
	 */
	private $relationship_name = '';

	/**
	 * The registered post-to-post relationship.
	 *
	 * @var \TenUp\ContentConnect\Relationships\PostToPost
	 */
	private $relationship;

	/**
	 * @inheritDoc
	 */
	public function set_up(): void {
		parent::set_up();

		register_post_type(
			$this->person_type,
			[
				'public'       => true,
				'label'        => 'EP Person',
				'show_in_rest' => true,
			]
		);

		register_post_type(
			$this->service_type,
			[
				'public'       => true,
				'label'        => 'EP Service',
				'show_in_rest' => true,
			]
		);

		// A dash in the name also exercises the dash-to-underscore field name
		// conversion the plugin performs. Unique per run because the Content
		// Connect registry is a process-wide singleton.
		$this->relationship_name = 'person-service-' . wp_generate_password( 8, false );

		$this->relationship = get_registry()->define_post_to_post(
			$this->person_type,
			$this->service_type,
			$this->relationship_name
		);

		// The base set_up created the index before this relationship existed,
		// so its nested field is missing from the mapping. Rebuild the index now
		// that the relationship is registered.
		$this->create_index();
	}

	/**
	 * @inheritDoc
	 */
	public function tear_down(): void {
		unset( $_GET['ep_service'] );

		parent::tear_down();
	}

	public function test_archive_is_filtered_by_related_post_slug(): void {

		$relationship = $this->relationship;

		$service_id = self::factory()->post->create(
			[
				'post_type'   => $this->service_type,
				'post_title'  => 'Acme Service',
				'post_name'   => 'acme',
				'post_status' => 'publish',
			]
		);

		$person_id = self::factory()->post->create(
			[
				'post_type'   => $this->person_type,
				'post_title'  => 'Jane Doe',
				'post_status' => 'publish',
			]
		);

		$relationship->add_relationship( $person_id, $service_id );

		// Index both partners so the person document carries the relationship
		// field populated with the service (post_name = 'acme').
		$this->index_post( $service_id );
		$this->index_post( $person_id );
		$this->refresh_index();

		// Sanity check: the relationship field is actually present in the
		// indexed person document. The ES field name is the relationship name
		// with dashes converted to underscores.
		$field_name       = str_replace( '-', '_', $this->relationship_name );
		$person_document  = $this->get_es_document( $person_id );
		$this->assertArrayHasKey(
			$field_name,
			$person_document,
			'Expected the relationship field to be present in the indexed person document.'
		);
		$indexed_slugs = wp_list_pluck( $person_document[ $field_name ], 'post_name' );
		$this->assertContains( 'acme', $indexed_slugs, 'Expected the related service slug in the person document.' );

		add_filter( 'ep_content_connect_is_filterable_page', '__return_true' );

		// Positive case: filtering by the real related service slug returns the
		// person.
		$_GET['ep_service'] = 'acme';

		$query = new \WP_Query(
			[
				'post_type'    => $this->person_type,
				'ep_integrate' => true,
			]
		);

		$this->assertTrue( $query->elasticsearch_success, 'Expected the query to be served by Elasticsearch.' );

		$returned_ids = wp_list_pluck( $query->posts, 'ID' );
		$this->assertContains(
			$person_id,
			$returned_ids,
			'Expected the person related to the "acme" service to be returned.'
		);

		// Negative case: filtering by a slug no service has excludes the person.
		$_GET['ep_service'] = 'nonexistent';

		$negative_query = new \WP_Query(
			[
				'post_type'    => $this->person_type,
				'ep_integrate' => true,
			]
		);

		$this->assertTrue( $negative_query->elasticsearch_success, 'Expected the negative query to be served by Elasticsearch.' );

		$negative_ids = wp_list_pluck( $negative_query->posts, 'ID' );
		$this->assertNotContains(
			$person_id,
			$negative_ids,
			'Expected the person to be excluded when filtering by an unrelated service slug.'
		);
	}

	/**
	 * Fetch the raw _source of a document from the test index.
	 *
	 * @param  int $id Post ID.
	 * @return array Decoded _source, or an empty array.
	 */
	private function get_es_document( int $id ): array {

		$post_indexable = \ElasticPress\Indexables::factory()->get( 'post' );
		$path           = trailingslashit( $post_indexable->get_index_name() ) . '_doc/' . $id;

		$response = \ElasticPress\Elasticsearch::factory()->remote_request( $path, [ 'method' => 'GET' ] );

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return $body['_source'] ?? [];
	}
}
