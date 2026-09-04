<?php

namespace EPContentConnect\PostToPost;

use ElasticPress\Elasticsearch;
use ElasticPress\Features;
use ElasticPress\Indexables;

/**
 * Handles all Elasticsearch indexing operations for post-to-post relationships.
 *
 * @package EPContentConnect
 */
class Indexing {

	/**
	 * Post to Post relationships helper instance.
	 *
	 * @var Helper
	 */
	private $helper;

	/**
	 * Initialize hooks and filters.
	 *
	 * @return void
	 */
	public function setup() {

		if ( ! Features::factory()->get_registered_feature( 'ep_content_connect_post_to_post' )->is_active() ) {
			return;
		}

		$this->helper = new Helper();

		add_filter( 'ep_post_sync_args', [ $this, 'index_relationships' ], 10, 2 );
		add_action( 'tenup-content-connect-add-relationship', [ $this, 'index_relationship' ], 10, 4 );
		add_action( 'tenup-content-connect-delete-relationship', [ $this, 'deindex_relationship' ], 10, 4 );
		add_action( 'before_delete_post', [ $this, 'deindex_post_relationships' ] );
	}

	/**
	 * Index post-to-post relationships in Elasticsearch.
	 *
	 * @param  array $post_args Post arguments for Elasticsearch.
	 * @param  int   $post_id   Post ID being indexed.
	 * @return array Modified post arguments.
	 */
	public function index_relationships( $post_args, $post_id ) {

		if ( ! isset( $post_args['post_type'] ) ) {
			return $post_args;
		}

		$post_type          = $post_args['post_type'];
		$related_post_types = $this->helper->get_related_post_types( $post_type );

		if ( empty( $related_post_types ) ) {
			return $post_args;
		}

		foreach ( $related_post_types as $relationship_name => $relationship_post_types ) {
			foreach ( $relationship_post_types as $relationship_post_type ) {

				$related_posts = $this->get_related_posts( $post_id, $relationship_post_type, $relationship_name );

				if ( empty( $related_posts ) ) {
					continue;
				}

				$field_name = $this->helper->get_field_name( $relationship_name, $relationship_post_type );

				$post_args[ $field_name ] = array_merge( $post_args[ $field_name ] ?? [], $related_posts );
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
	public function index_relationship( $pid1, $pid2, $name, $type ) {

		if ( 'post-to-user' === $type ) {
			return;
		}

		$relationship_data = $this->prepare_relationship( $pid1, $pid2, $name );

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
	public function deindex_relationship( $pid1, $pid2, $name, $type ) {

		if ( 'post-to-user' === $type ) {
			return;
		}

		$relationship_data = $this->prepare_relationship( $pid1, $pid2, $name );

		if ( empty( $relationship_data ) ) {
			return;
		}

		$this->execute_bulk_update( $relationship_data, 'remove' );
	}

	/**
	 * Removes a permanently deleted post from every partner's Elasticsearch document.
	 *
	 * Content Connect deletes the junction rows for a post on permanent deletion
	 * without firing its delete-relationship action, which would otherwise leave
	 * stale entries in the ES documents of related posts. This runs while the
	 * junction rows still exist, so every related post can be found and cleaned up.
	 *
	 * @param  int $post_id ID of the post being permanently deleted.
	 * @return void
	 */
	public function deindex_post_relationships( $post_id ) {

		$relationships = $this->helper->get_relationships();

		if ( empty( $relationships ) ) {
			return;
		}

		foreach ( $relationships as $relationship ) {

			$related_ids = $relationship->get_related_object_ids( $post_id );

			if ( empty( $related_ids ) ) {
				continue;
			}

			$relationship_data = [];

			foreach ( $related_ids as $related_id ) {
				$related_id = (int) $related_id;

				$pair_data = $this->prepare_relationship( $post_id, $related_id, $relationship->name );

				if ( empty( $pair_data[ $related_id ] ) ) {
					continue;
				}

				$relationship_data[ $related_id ] = $pair_data[ $related_id ];
			}

			if ( empty( $relationship_data ) ) {
				continue;
			}

			$this->execute_bulk_update( $relationship_data, 'remove' );
		}
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
			'post__not_in'           => [ $post_id ],
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

		if ( ! isset( $query_args['posts_per_page'] ) ) {
			$query_args['posts_per_page'] = 100;
		}

		$page_size = (int) $query_args['posts_per_page'];

		$all_posts = [];
		$paged     = 1;

		do {
			$page_args          = $query_args;
			$page_args['paged'] = $paged;

			$query = new \WP_Query( $page_args );
			$posts = $query->get_posts();

			$all_posts = array_merge( $all_posts, $posts );

			$posts_count = count( $posts );

			++$paged;

		} while ( -1 !== $page_size && $posts_count === $page_size );

		$related_posts = [];

		foreach ( $all_posts as $post ) {

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$related_posts[] = $this->helper->get_field_value( $post );
		}

		/**
		 * Filter the related posts retrieved for a specific post.
		 *
		 * @param array $related_posts Related posts.
		 * @param array $posts         Original post objects.
		 */
		$related_posts = apply_filters( 'ep_content_connect_related_posts', $related_posts, $all_posts );

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
	private function prepare_relationship( $pid1, $pid2, $name ) {

		$first_post  = get_post( $pid1 );
		$second_post = get_post( $pid2 );

		if ( ! $first_post instanceof \WP_Post || ! $second_post instanceof \WP_Post ) {
			return [];
		}

		$relationship_data = [
			$pid1 => [
				'field' => $this->helper->get_field_name( $name, $second_post->post_type ),
				'value' => $this->helper->get_field_value( $second_post ),
			],
			$pid2 => [
				'field' => $this->helper->get_field_name( $name, $first_post->post_type ),
				'value' => $this->helper->get_field_value( $first_post ),
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
	 * @return \WP_Error|array|false The response, WP_Error on invalid input, or false on request failure.
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
					'update' => [
						'_id'               => $post_id,
						'retry_on_conflict' => 3,
					],
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

		if ( is_wp_error( $response ) ) {
			error_log( '[EPContentConnect] Bulk relationship update request failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return false;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			error_log( '[EPContentConnect] Bulk relationship update failed with unexpected HTTP status ' . $status_code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return false;
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $response_body['errors'] ) ) {
			error_log( '[EPContentConnect] Bulk relationship update completed with item errors: ' . wp_json_encode( $response_body['items'] ?? $response_body ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return false;
		}

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
