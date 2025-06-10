<?php

namespace EPContentConnect\PostToPost;

/**
 * Post to Post relationships feature for ElasticPress.
 *
 * @package EPContentConnect
 */
class Feature extends \ElasticPress\Feature {

	/**
	 * Post to Post relationships helper instance.
	 *
	 * @var Helper
	 */
	private $helper;

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		$this->slug    = 'ep_content_connect_post_to_post';
		$this->title   = esc_html__( 'Post to Post Relationships', 'ep-content-connect' );
		$this->summary = esc_html__( 'Filter by Content Connect post-to-post relationships.', 'ep-content-connect' );

		$this->requires_install_reindex = true;

		parent::__construct();
	}

	/**
	 * Initialize hooks and filters.
	 *
	 * @return void
	 */
	public function setup() {
		$this->helper = new Helper();

		$indexing = new Indexing();
		$indexing->setup();

		$mapping = new Mapping();
		$mapping->setup();

		add_filter( 'ep_post_formatted_args', [ $this, 'set_relationship_filters' ], 20, 3 );
	}

	/**
	 * Set relationship filters on Elasticsearch queries.
	 *
	 * @param  array     $formatted_args Formatted Elasticsearch arguments.
	 * @param  array     $args           Original query arguments.
	 * @param  \WP_Query $wp_query       WordPress query object.
	 * @return array Modified formatted arguments.
	 */
	public function set_relationship_filters( $formatted_args, $args, $wp_query ) {

		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $formatted_args;
		}

		if ( ! $this->is_filterable_page( $wp_query ) ) {
			return $formatted_args;
		}

		if ( empty( $args['post_type'] ) ) {
			return $formatted_args;
		}

		$post_type      = is_array( $args['post_type'] ) ? $args['post_type'][0] : $args['post_type'];
		$active_filters = $this->get_active_filters( $post_type, $wp_query );

		if ( empty( $active_filters ) ) {
			return $formatted_args;
		}

		$filter_queries = $this->build_filter_queries( $active_filters );
		$formatted_args = $this->add_filters_to_query( $formatted_args, $filter_queries );

		return $formatted_args;
	}

	/**
	 * Check if the current page supports relationship filtering.
	 *
	 * @param  \WP_Query $query WordPress query object.
	 * @return bool Whether the page is filterable.
	 */
	private function is_filterable_page( $query ) {

		$is_filterable = $query->is_home() || $query->is_search() || $query->is_tax() || $query->is_tag() || $query->is_category() || $query->is_post_type_archive();

		/**
		 * Filter whether the current page is filterable for post-to-post relationships.
		 *
		 * @param  bool      $is_filterable Whether the page is filterable.
		 * @param  \WP_Query $query         WordPress query object.
		 * @return bool Modified filterable status.
		 */
		$is_filterable = apply_filters( 'ep_content_connect_is_filterable_page', $is_filterable, $query );

		return $is_filterable;
	}

	/**
	 * Get active relationship filters from URL parameters.
	 *
	 * @param  string    $post_type Post type to get filters for.
	 * @param  \WP_Query $wp_query  WordPress query object.
	 * @return array Active filters array.
	 */
	private function get_active_filters( $post_type, $wp_query ) {

		$supported_filters = $this->get_supported_filters( $post_type, $wp_query );

		if ( empty( $supported_filters ) ) {
			return [];
		}

		$active_filters = [];

		foreach ( $supported_filters as $relationship_name => $filters ) {
			foreach ( $filters as $relationship_post_type => $filter_name ) {

				if ( empty( $_GET[ $filter_name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					continue;
				}

				$raw_filter_value = wp_unslash( $_GET[ $filter_name ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

				$sanitized_filter_value = '';
				if ( is_array( $raw_filter_value ) ) {
					$sanitized_filter_value = array_map( 'sanitize_text_field', $raw_filter_value );
				} else {
					$sanitized_filter_value = sanitize_text_field( $raw_filter_value );
				}

				/**
				 * Filter the filter value for post-to-post relationships.
				 *
				 * @param  string|array $sanitized_filter_value The sanitized filter value.
				 * @param  string|array $raw_filter_value       The raw filter value from the URL.
				 * @return string|array Modified filter value.
				 */
				$sanitized_filter_value = apply_filters( 'ep_content_connect_post_to_post_relationship_filter_value', $sanitized_filter_value, $raw_filter_value );

				$active_filters[ $relationship_name ][ $relationship_post_type ] = $sanitized_filter_value;
			}
		}

		/**
		 * Filter the active filters for post-to-post relationships.
		 *
		 * @param  array     $active_filters Array of active filters.
		 * @param  string    $post_type      Post type for which filters are active.
		 * @param  \WP_Query $wp_query       WordPress query object.
		 * @return array Modified active filters.
		 */
		$active_filters = apply_filters( 'ep_content_connect_post_to_post_relationship_active_filters', $active_filters, $post_type, $wp_query );

		return $active_filters;
	}

	/**
	 * Get supported relationship filters for a post type.
	 *
	 * @param  string    $post_type Post type to get supported filters for.
	 * @param  \WP_Query $wp_query  WordPress query object.
	 * @return array Supported filters array.
	 */
	private function get_supported_filters( $post_type, $wp_query ) {

		$related_post_types = $this->helper->get_related_post_types( $post_type );

		if ( empty( $related_post_types ) ) {
			return [];
		}

		$supported_filters = [];

		foreach ( $related_post_types as $relationship_name => $relationship_post_types ) {
			foreach ( $relationship_post_types as $relationship_post_type ) {

				/**
				 * Filter the filter name for post-to-post relationships.
				 *
				 * @param  string $relationship_post_type The relationship post type.
				 * @param  string $relationship_name      The relationship name.
				 * @param  string $post_type              The source post type.
				 * @return string Modified filter name.
				 */
				$filter_name = apply_filters( 'ep_content_connect_post_to_post_relationship_filter_name', $relationship_post_type, $relationship_name, $post_type );

				$supported_filters[ $relationship_name ][ $relationship_post_type ] = $filter_name;
			}
		}

		/**
		 * Filter the supported filters for post-to-post relationships.
		 *
		 * @param  array     $supported_filters Array of supported filters.
		 * @param  string    $post_type         The source post type.
		 * @param  \WP_Query $wp_query          WordPress query object.
		 * @return array Modified supported filters.
		 */
		$supported_filters = apply_filters( 'ep_content_connect_post_to_post_relationship_supported_filters', $supported_filters, $post_type, $wp_query );

		return $supported_filters;
	}

	/**
	 * Build Elasticsearch filter queries from active filters.
	 *
	 * @param  array $active_filters Active relationship filters.
	 * @return array Elasticsearch filter queries.
	 */
	private function build_filter_queries( $active_filters ) {

		$grouped_filters = [];

		foreach ( $active_filters as $relationship_name => $filters ) {
			foreach ( $filters as $relationship_post_type => $filter_value ) {
				$field_name = $this->helper->get_field_name( $relationship_name, $relationship_post_type );

				$grouped_filters[ $field_name ][] = $filter_value;
			}
		}

		$filter_queries = [];

		foreach ( $grouped_filters as $field_name => $filter_values ) {

			$should_queries = [];
			foreach ( $filter_values as $filter_value ) {

				if ( is_numeric( $filter_value ) ) {
					$should_queries[] = [
						'term' => [
							$field_name . '.post_id' => (int) $filter_value,
						],
					];
				} else {
					$should_queries[] = [
						'term' => [
							$field_name . '.post_name' => $filter_value,
						],
					];

					$should_queries[] = [
						'term' => [
							$field_name . '.post_title.raw' => $filter_value,
						],
					];

					$should_queries[] = [
						'match' => [
							$field_name . '.post_title' => $filter_value,
						],
					];
				}
			}

			$filter_queries[] = [
				'nested' => [
					'path'  => $field_name,
					'query' => [
						'bool' => [
							'should'               => $should_queries,
							'minimum_should_match' => 1,
						],
					],
				],
			];
		}

		/**
		 * Filter the filter queries for post-to-post relationships.
		 *
		 * @param  array $filter_queries Array of filter queries.
		 * @param  array $active_filters Active relationship filters.
		 * @return array Modified filter queries.
		 */
		$filter_queries = apply_filters( 'ep_content_connect_post_to_post_relationship_filter_queries', $filter_queries, $active_filters );

		return $filter_queries;
	}

	/**
	 * Add filter queries to formatted Elasticsearch arguments.
	 *
	 * @param  array $formatted_args Current formatted arguments.
	 * @param  array $filter_queries Filter queries to add.
	 * @return array Modified formatted arguments.
	 */
	private function add_filters_to_query( $formatted_args, $filter_queries ) {

		if ( ! isset( $formatted_args['query']['bool'] ) ) {
			$formatted_args['query'] = [
				'bool' => [
					'must' => $filter_queries,
				],
			];
		} else {
			if ( ! isset( $formatted_args['query']['bool']['must'] ) ) {
				$formatted_args['query']['bool']['must'] = [];
			}

			$formatted_args['query']['bool']['must'] = array_merge(
				$formatted_args['query']['bool']['must'],
				$filter_queries
			);
		}

		return $formatted_args;
	}
}
