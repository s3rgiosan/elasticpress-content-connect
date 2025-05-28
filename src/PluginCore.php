<?php
/**
 * PluginCore module.
 *
 * @package ElasticPressContentConnect
 */

namespace ElasticPressContentConnect;

use ElasticPress\Elasticsearch;
use ElasticPress\Indexables;

use function TenUp\ContentConnect\Helpers\get_registry;

/**
 * PluginCore module.
 *
 * @package ElasticPressContentConnect
 */
class PluginCore {

	/**
	 * Default setup routine.
	 *
	 * @return void
	 */
	public function setup() {
		add_filter( 'ep_post_sync_args', [ $this, 'index_post_to_post_relationships' ], 10, 2 );
		add_action( 'tenup-content-connect-add-relationship', [ $this, 'index_post_to_post_relationship' ], 10, 4 );
		add_action( 'tenup-content-connect-delete-relationship', [ $this, 'deindex_post_to_post_relationship' ], 10, 4 );
		add_filter( 'ep_config_mapping', [ $this, 'post_to_post_relationships_mapping' ], 10, 2 );
	}

	/**
	 * Indexes a post's post-to-post relationships in Elasticsearch.
	 *
	 * Modifies the post arguments to include related posts based on registered
	 * post-to-post relationships before indexing.
	 *
	 * @param  array $post_args The post arguments for Elasticsearch indexing.
	 * @param  int   $post_id   The ID of the post being indexed.
	 * @return array Modified post arguments with related posts included.
	 */
	public function index_post_to_post_relationships( $post_args, $post_id ) {

		$relationships = $this->get_post_to_post_relationships();

		if ( empty( $relationships ) ) {
			return $post_args;
		}

		$post_type = $post_args['post_type'];

		foreach ( $relationships as $relationship ) {

			$relationship_from = is_array( $relationship->from ) ? $relationship->from : [ $relationship->from ];
			$relationship_to   = is_array( $relationship->to ) ? $relationship->to : [ $relationship->to ];

			if ( ! in_array( $post_type, $relationship_from, true ) && ! in_array( $post_type, $relationship_to, true ) ) {
				continue;
			}

			$related_post_types = [];

			if ( in_array( $post_type, $relationship_from, true ) ) {
				$related_post_types = $relationship_to;
			} else {
				$related_post_types = $relationship_from;
			}

			foreach ( $related_post_types as $related_post_type ) {

				$related_posts = $this->get_related_posts( $post_id, $related_post_type, $relationship->name );
				if ( empty( $related_posts ) ) {
					continue;
				}

				$key               = $this->get_field_name( $related_post_type );
				$post_args[ $key ] = $related_posts;
			}
		}

		return $post_args;
	}

	/**
	 * Indexes a single post-to-post relationship in Elasticsearch.
	 *
	 * Updates both posts in the relationship to reference each other.
	 *
	 * @param  int    $pid1 The ID of the first post in the relationship.
	 * @param  int    $pid2 The ID of the second post in the relationship.
	 * @param  string $name Relationship name.
	 * @param  string $type Relationship type (post-to-post or post-to-user).
	 * @return void
	 */
	public function index_post_to_post_relationship( $pid1, $pid2, $name, $type ) {

		if ( 'post-to-user' === $type ) {
			return;
		}

		$relationship = $this->prepare_post_to_post_relationship( $pid1, $pid2 );

		if ( empty( $relationship ) ) {
			return;
		}

		$index_name = Indexables::factory()->get( 'post' )->get_index_name();

		$bulk_body = '';
		foreach ( $relationship as $source_id => $params ) {

			$bulk_body .= wp_json_encode(
				[
					'update' => [
						'_id' => $source_id,
					],
				]
			) . "\n";

			$bulk_body .= wp_json_encode(
				[
					'script' => [
						'source' => 'if (!ctx._source.containsKey(params.field)) { ctx._source[params.field] = [params.value]; } else { boolean exists = false; for (item in ctx._source[params.field]) { if (item.post_id == params.value.post_id) { exists = true; break; } } if (!exists) { ctx._source[params.field].add(params.value); } }',
						'params' => $params,
					],
				]
			) . "\n";
		}

		$path = trailingslashit( $index_name ) . '_bulk';

		$args = [
			'method'  => 'POST',
			'body'    => $bulk_body,
			'headers' => [
				'Content-Type' => 'application/x-ndjson',
			],
		];

		Elasticsearch::factory()->remote_request( $path, $args, [], 'post' );
	}

	/**
	 * Removes a post-to-post relationship from Elasticsearch.
	 *
	 * Updates both posts to remove references to each other.
	 *
	 * @param  int    $pid1 The ID of the first post in the relationship.
	 * @param  int    $pid2 The ID of the second post in the relationship.
	 * @param  string $name Relationship name.
	 * @param  string $type Relationship type (post-to-post or post-to-user).
	 * @return void
	 */
	public function deindex_post_to_post_relationship( $pid1, $pid2, $name, $type ) {

		if ( 'post-to-user' === $type ) {
			return;
		}

		$relationship = $this->prepare_post_to_post_relationship( $pid1, $pid2 );

		if ( empty( $relationship ) ) {
			return;
		}

		$index_name = Indexables::factory()->get( 'post' )->get_index_name();

		$bulk_body = '';
		foreach ( $relationship as $source_id => $params ) {

			$bulk_body .= wp_json_encode(
				[
					'update' => [
						'_id' => $source_id,
					],
				]
			) . "\n";

			$bulk_body .= wp_json_encode(
				[
					'script' => [
						'source' => 'if (ctx._source.containsKey(params.field)) { ctx._source[params.field].removeIf(item -> item.post_id == params.value.post_id); if (ctx._source[params.field].isEmpty()) { ctx._source.remove(params.field); } }',
						'params' => $params,
					],
				]
			) . "\n";
		}

		$path = trailingslashit( $index_name ) . '_bulk';

		$args = [
			'method'  => 'POST',
			'body'    => $bulk_body,
			'headers' => [
				'Content-Type' => 'application/x-ndjson',
			],
		];

		Elasticsearch::factory()->remote_request( $path, $args, [], 'post' );
	}

	/**
	 * Prepares a post-to-post relationship for Elasticsearch operations.
	 *
	 * Creates a data structure representing both sides of the relationship
	 * with the appropriate field names and values.
	 *
	 * @param  int $pid1 The ID of the first post in the relationship.
	 * @param  int $pid2 The ID of the second post in the relationship.
	 * @return array An associative array representing the relationship between the two posts.
	 */
	public function prepare_post_to_post_relationship( $pid1, $pid2 ) {

		$first_post  = get_post( $pid1 );
		$second_post = get_post( $pid2 );

		if ( ! $first_post instanceof \WP_Post || ! $second_post instanceof \WP_Post ) {
			return [];
		}

		$relationship = [
			$pid1 => [
				'field' => $this->get_field_name( $second_post->post_type ),
				'value' => $this->get_field_value( $second_post ),
			],
			$pid2 => [
				'field' => $this->get_field_name( $first_post->post_type ),
				'value' => $this->get_field_value( $first_post ),
			],
		];

		/**
		 * Filter the post-to-post relationship data.
		 *
		 * Allows modification of the relationship structure before indexing.
		 *
		 * @param  array    $relationship The relationship data between the two posts.
		 * @param  \WP_Post $first_post   The first post in the relationship.
		 * @param  \WP_Post $second_post  The second post in the relationship.
		 * @return array The modified relationship data.
		 */
		$relationship = apply_filters( 'ep_content_connect_post_to_post_relationship', $relationship, $first_post, $second_post );

		return $relationship;
	}

	/**
	 * Adds post-to-post relationship fields to the Elasticsearch mapping.
	 *
	 * Ensures that related post fields are properly defined in the index
	 * with appropriate data types and field analysis options.
	 *
	 * @param  array  $mapping    Current Elasticsearch mapping.
	 * @param  string $index_name Name of the index being mapped.
	 * @return array Updated mapping with relationship fields included.
	 */
	public function post_to_post_relationships_mapping( $mapping, $index_name ) {

		if ( Indexables::factory()->get( 'post' )->get_index_name() !== $index_name ) {
			return $mapping;
		}

		$relationship_keys = $this->get_post_to_post_relationship_keys();

		if ( empty( $relationship_keys ) ) {
			return $mapping;
		}

		$map = [
			'type'       => 'nested',
			'properties' => [
				'post_id'    => [ 'type' => 'integer' ],
				'post_title' => [
					'type'   => 'text',
					'fields' => [
						'raw' => [ 'type' => 'keyword' ],
					],
				],
				'post_name'  => [ 'type' => 'keyword' ],
			],
		];

		/**
		 * Filter the mapping for post-to-post relationships.
		 *
		 * Allows customization of the mapping structure for related posts.
		 *
		 * @param  array  $map        The mapping structure for post-to-post relationships.
		 * @param  string $index_name The name of the index being mapped.
		 * @return array The modified mapping structure.
		 */
		$map = apply_filters( 'ep_content_connect_post_to_post_relationship_mapping', $map, $index_name );

		foreach ( $relationship_keys as $key ) {

			/**
			 * Filter the mapping for a specific post-to-post relationship.
			 *
			 * @param  array  $map        The mapping structure for the specific relationship key.
			 * @param  string $index_name The name of the index being mapped.
			 * @return array The modified mapping structure for the specific key.
			 */
			$map = apply_filters( 'ep_content_connect_post_to_post_relationship_mapping_' . $key, $map, $index_name );

			$mapping['mappings']['properties'][ $key ] = $map;
		}

		return $mapping;
	}

	/**
	 * Retrieves all field keys used for post-to-post relationships.
	 *
	 * Generates a unique list of field names based on registered relationships
	 * to be used in the Elasticsearch index.
	 *
	 * @return array Array of unique field keys for post-to-post relationships.
	 */
	public function get_post_to_post_relationship_keys() {

		$relationships = $this->get_post_to_post_relationships();

		if ( empty( $relationships ) ) {
			return [];
		}

		$keys = [];

		foreach ( $relationships as $relationship ) {

			if ( empty( $relationship->to ) ) {
				continue;
			}

			$post_types = is_array( $relationship->to ) ? $relationship->to : [ $relationship->to ];

			foreach ( $post_types as $post_type ) {
				$keys[] = $this->get_field_name( $post_type );
			}
		}

		$keys = array_unique( $keys );

		/**
		 * Filter the post-to-post relationship keys.
		 *
		 * @param  array $keys The array of keys for post-to-post relationships.
		 * @return array The modified array of keys.
		 */
		$keys = apply_filters( 'ep_content_connect_post_to_post_relationship_keys', $keys );

		return $keys;
	}

	/**
	 * Gets the prefix for relationship field names.
	 *
	 * @return string The prefix string used for relationship field names.
	 */
	public function get_field_prefix() {

		/**
		 * Filter the prefix used for relationship field names.
		 *
		 * @param  string $prefix The prefix used for relationship field names.
		 * @return string The modified prefix.
		 */
		$prefix = apply_filters( 'ep_content_connect_field_prefix', 'related_' );

		return $prefix;
	}

	/**
	 * Generates the Elasticsearch field name for a given post type.
	 *
	 * @param  string $post_type The post type to generate a field name for.
	 * @return string Elasticsearch field name for the post type.
	 */
	public function get_field_name( $post_type ) {

		$field_name = sprintf( '%s%s', $this->get_field_prefix(), str_replace( '-', '_', $post_type ) );

		/**
		 * Filter the field name used for relationships.
		 *
		 * @param  string $field_name The field name used for relationships.
		 * @param  string $post_type  The post type for which the field name is being generated.
		 * @return string The modified field name.
		 */
		$field_name = apply_filters( 'ep_content_connect_field_name', $field_name, $post_type );

		return $field_name;
	}

	/**
	 * Retrieves the Elasticsearch field value for a given post.
	 *
	 * @param  \WP_Post $post The post object to retrieve the field value for.
	 * @return array Elasticsearch field value for the post.
	 */
	public function get_field_value( $post ) {

		if ( ! $post instanceof \WP_Post ) {
			return [];
		}

		$field_value = [
			'post_id'    => (int) $post->ID,
			'post_title' => $post->post_title,
			'post_name'  => $post->post_name,
		];

		/**
		 * Filter the field value for a post.
		 *
		 * @param  array    $field_value The field value for the post.
		 * @param  \WP_Post $post        The post object being processed.
		 * @return array The modified field value.
		 */
		$field_value = apply_filters( 'ep_content_connect_field_value', $field_value, $post );

		return $field_value;
	}

	/**
	 * Retrieves all registered post-to-post relationships.
	 *
	 * @return array Array of post-to-post relationship objects.
	 */
	public function get_post_to_post_relationships() {

		$relationships = get_registry()->get_post_to_post_relationships();

		/**
		 * Filter the post-to-post relationships.
		 *
		 * @param  array $relationships The array of post-to-post relationships.
		 * @return array The modified array of post-to-post relationships.
		 */
		$relationships = apply_filters( 'ep_content_connect_post_to_post_relationships', $relationships );

		return $relationships;
	}

	/**
	 * Retrieves IDs of posts related to a specific post.
	 *
	 * Performs a WP_Query with relationship parameters to find all posts
	 * of a specific type that are related to the given post.
	 *
	 * @param  int    $post_id           The ID of the post to find related posts for.
	 * @param  string $post_type         The post type of the related posts to retrieve.
	 * @param  string $relationship_name The name of the relationship to query.
	 * @return array Array of related post IDs.
	 */
	public function get_related_posts( $post_id, $post_type, $relationship_name ) {

		$query_args = [
			'post_type'              => $post_type,
			'posts_per_page'         => 100,
			'relationship_query'     => [
				'name'            => $relationship_name,
				'related_to_post' => $post_id,
			],
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		/**
		 * Filter the query arguments for retrieving related posts.
		 *
		 * @param  array  $query_args        The query arguments for retrieving related posts.
		 * @param  int    $post_id           The ID of the post being queried.
		 * @param  string $relationship_name The name of the relationship being queried.
		 * @return array The modified query arguments.
		 */
		$query_args = apply_filters( 'ep_content_connect_related_posts_query_args', $query_args, $post_id, $relationship_name );

		$query = new \WP_Query( $query_args );

		$queried_posts = $query->get_posts();

		$related_posts = [];

		foreach ( $queried_posts as $related_post ) {
			$related_posts[] = $this->get_field_value( $related_post );
		}

		/**
		 * Filter the related posts retrieved from the query.
		 *
		 * Allows modification of the related posts array before returning.
		 *
		 * @param  array $related_posts The array of related posts.
		 * @param  array $queried_posts The original queried posts.
		 * @return array The modified array of related posts.
		 */
		$related_posts = apply_filters( 'ep_content_connect_related_posts', $related_posts, $queried_posts );

		return $related_posts;
	}
}
