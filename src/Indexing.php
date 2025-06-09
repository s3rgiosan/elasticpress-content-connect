<?php

namespace ElasticPressContentConnect;

use ElasticPress\Elasticsearch;
use ElasticPress\Indexables;

/**
 * Handles all Elasticsearch indexing operations for relationships.
 *
 * @package ElasticPressContentConnect
 */
class Indexing {

	/**
	 * Post to post relationships helper instance.
	 *
	 * @var PostToPost
	 */
	private $post_to_post;

	/**
	 * Initialize hooks and filters.
	 *
	 * @return void
	 */
	public function setup() {
		$this->post_to_post = new PostToPost();

		add_filter( 'ep_post_sync_args', [ $this, 'index_post_to_post_relationships' ], 10, 2 );
		add_action( 'tenup-content-connect-add-relationship', [ $this, 'index_post_to_post_relationship' ], 10, 4 );
		add_action( 'tenup-content-connect-delete-relationship', [ $this, 'deindex_post_to_post_relationship' ], 10, 4 );
	}

	/**
	 * Index post-to-post relationships in Elasticsearch.
	 *
	 * @param  array $post_args Post arguments for Elasticsearch.
	 * @param  int   $post_id   Post ID being indexed.
	 * @return array Modified post arguments.
	 */
	public function index_post_to_post_relationships( $post_args, $post_id ) {

		if ( ! isset( $post_args['post_type'] ) ) {
			return $post_args;
		}

		$post_type          = $post_args['post_type'];
		$related_post_types = $this->post_to_post->get_related_post_types( $post_type );

		if ( empty( $related_post_types ) ) {
			return $post_args;
		}

		foreach ( $related_post_types as $relationship_name => $relationship_post_types ) {
			foreach ( $relationship_post_types as $relationship_post_type ) {

				$related_posts = $this->get_related_posts( $post_id, $relationship_post_type, $relationship_name );

				if ( empty( $related_posts ) ) {
					continue;
				}

				$field_name = $this->post_to_post->get_field_name( $relationship_post_type, $relationship_name );

				$post_args[ $field_name ] = $related_posts;
			}
		}

		return $post_args;
	}

	/**
	 * Index a single post-to-post relationship in Elasticsearch.
	 *
	 * @param  int    $pid1 First post ID.
	 * @param  int    $pid2 Second post ID.
	 * @param  string $name Relationship name.
	 * @param  string $type Relationship type (post-to-post or post-to-user).
	 * @return void
	 */
	public function index_post_to_post_relationship( $pid1, $pid2, $name, $type ) {

		if ( 'post-to-user' === $type ) {
			return;
		}

		$relationship_data = $this->prepare_post_to_post_relationship( $pid1, $pid2, $name );

		if ( empty( $relationship_data ) ) {
			return;
		}

		$this->execute_bulk_update( $relationship_data, 'add' );
	}

	/**
	 * Deindex a single post-to-post relationship in Elasticsearch.
	 *
	 * @param int    $pid1 First post ID.
	 * @param int    $pid2 Second post ID.
	 * @param string $name Relationship name.
	 * @param string $type Relationship type (post-to-post or post-to-user).
	 * @return void
	 */
	public function deindex_post_to_post_relationship( $pid1, $pid2, $name, $type ) {

		if ( 'post-to-user' === $type ) {
			return;
		}

		$relationship_data = $this->prepare_post_to_post_relationship( $pid1, $pid2, $name );

		if ( empty( $relationship_data ) ) {
			return;
		}

		$this->execute_bulk_update( $relationship_data, 'remove' );
	}

	/**
	 * Get the related posts to a specific post.
	 *
	 * @param  int    $post_id           Source post ID.
	 * @param  string $related_post_type Related post type.
	 * @param  string $relationship_name Relationship name.
	 * @return array Related posts.
	 */
	private function get_related_posts( $post_id, $related_post_type, $relationship_name ) {

		$query_args = [
			'post_type'              => $related_post_type,
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
		 * @param array  $query_args        Query arguments.
		 * @param int    $post_id           Post ID.
		 * @param string $relationship_name Relationship name.
		 */
		$query_args = apply_filters( 'ep_content_connect_related_posts_query_args', $query_args, $post_id, $relationship_name );

		$query = new \WP_Query( $query_args );
		$posts = $query->get_posts();

		$related_posts = [];

		foreach ( $posts as $post ) {

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$related_posts[] = $this->post_to_post->get_field_value( $post );
		}

		/**
		 * Filter the related posts retrieved for a specific post.
		 *
		 * @param array $related_posts Related posts.
		 * @param array $posts         Original post objects.
		 */
		$related_posts = apply_filters( 'ep_content_connect_related_posts', $related_posts, $posts );

		return $related_posts;
	}

	/**
	 * Prepares a post-to-post relationship for Elasticsearch operations.
	 *
	 * @param  int    $pid1 First post ID.
	 * @param  int    $pid2 Second post ID.
	 * @param  string $name Relationship name.
	 * @return array Relationship data.
	 */
	private function prepare_post_to_post_relationship( $pid1, $pid2, $name ) {

		$first_post  = get_post( $pid1 );
		$second_post = get_post( $pid2 );

		if ( ! $first_post instanceof \WP_Post || ! $second_post instanceof \WP_Post ) {
			return [];
		}

		$relationship_data = [
			$pid1 => [
				'field' => $this->post_to_post->get_field_name( $second_post->post_type, $name ),
				'value' => $this->post_to_post->get_field_value( $second_post ),
			],
			$pid2 => [
				'field' => $this->post_to_post->get_field_name( $first_post->post_type, $name ),
				'value' => $this->post_to_post->get_field_value( $first_post ),
			],
		];

		/**
		 * Filter the post-to-post relationship before indexing.
		 *
		 * @param array    $relationship_data Relationship data.
		 * @param \WP_Post $first_post        First post object.
		 * @param \WP_Post $second_post       Second post object.
		 */
		$relationship_data = apply_filters( 'ep_content_connect_post_to_post_relationship_data', $relationship_data, $first_post, $second_post );

		return $relationship_data;
	}

	/**
	 * Executes a bulk update operation in Elasticsearch.
	 *
	 * @param  array  $relationship_data The relationship data to update.
	 * @param  string $operation         The operation type ('add' or 'remove').
	 * @return \WP_Error|array The response or WP_Error on failure.
	 */
	private function execute_bulk_update( $relationship_data, $operation ) {

		if ( empty( $relationship_data ) || ! in_array( $operation, [ 'add', 'remove' ], true ) ) {
			return new \WP_Error( 'ep_content_connect_invalid_data', 'Invalid relationship data or operation' );
		}

		$index_name = Indexables::factory()->get( 'post' )->get_index_name();
		$script     = $this->get_bulk_script( $operation );

		$bulk_body = '';
		foreach ( $relationship_data as $post_id => $params ) {

			$bulk_body .= wp_json_encode(
				[
					'update' => [ '_id' => $post_id ],
				]
			) . "\n";

			$bulk_body .= wp_json_encode(
				[
					'script' => [
						'source' => $script,
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

		$response = Elasticsearch::factory()->remote_request( $path, $args );

		return $response;
	}

	/**
	 * Get the script for bulk operations.
	 *
	 * @param  string $operation The operation type ('add' or 'remove').
	 * @return string The script source code.
	 */
	private function get_bulk_script( $operation ) {

		if ( 'add' === $operation ) {
			return 'if (!ctx._source.containsKey(params.field)) { ctx._source[params.field] = [params.value]; } else { boolean exists = false; for (item in ctx._source[params.field]) { if (item.post_id == params.value.post_id) { exists = true; break; } } if (!exists) { ctx._source[params.field].add(params.value); } }';
		}

		return 'if (ctx._source.containsKey(params.field)) { ctx._source[params.field].removeIf(item -> item.post_id == params.value.post_id); if (ctx._source[params.field].isEmpty()) { ctx._source.remove(params.field); } }';
	}
}
