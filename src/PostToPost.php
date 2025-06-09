<?php

namespace EPContentConnect;

use function TenUp\ContentConnect\Helpers\get_registry;

/**
 * Helper class for post-to-post relationships operations.
 *
 * @package EPContentConnect
 */
class PostToPost {

	/**
	 * Cached relationships.
	 *
	 * @var array
	 */
	private $relationships = [];

	/**
	 * Cached related post types by source post type.
	 *
	 * @var array
	 */
	private $related_post_types = [];

	/**
	 * Get all registered post-to-post relationships.
	 *
	 * @return array Relationship objects.
	 */
	public function get_relationships() {

		if ( ! empty( $this->relationships ) ) {
			return $this->relationships;
		}

		$relationships = get_registry()->get_post_to_post_relationships();

		/**
		 * Filter the post-to-post relationships.
		 *
		 * @param array $relationships Relationship objects.
		 */
		$relationships = apply_filters( 'ep_content_connect_post_to_post_relationships', $relationships );

		$this->relationships = $relationships;

		return $relationships;
	}

	/**
	 * Get related post types for a given post type.
	 *
	 * @param  string $post_type Source post type.
	 * @return array Related post types by relationship name.
	 */
	public function get_related_post_types( $post_type ) {

		if ( isset( $this->related_post_types[ $post_type ] ) ) {
			return $this->related_post_types[ $post_type ];
		}

		$relationships = $this->get_relationships();

		if ( empty( $relationships ) ) {
			return [];
		}

		$related_post_types = [];

		foreach ( $relationships as $relationship ) {

			$from_post_types = is_array( $relationship->from ) ? $relationship->from : [ $relationship->from ];
			$to_post_types   = is_array( $relationship->to ) ? $relationship->to : [ $relationship->to ];

			if ( ! in_array( $post_type, $from_post_types, true ) && ! in_array( $post_type, $to_post_types, true ) ) {
				continue;
			}

			$relationship_post_types = $from_post_types;
			if ( in_array( $post_type, $from_post_types, true ) ) {
				$relationship_post_types = $to_post_types;
			}

			foreach ( $relationship_post_types as $relationship_post_type ) {
				$related_post_types[ $relationship->name ][] = $relationship_post_type;
			}
		}

		/**
		 * Filter the related post types for a given post type.
		 *
		 * @param array  $related_post_types Related post types.
		 * @param string $post_type          Source post type.
		 */
		$related_post_types = apply_filters( 'ep_content_connect_related_post_types', $related_post_types, $post_type );

		$this->related_post_types[ $post_type ] = $related_post_types;

		return $related_post_types;
	}

	/**
	 * Retrieve the field name for a post type relationship.
	 *
	 * @param  string $relationship_name Relationship name.
	 * @param  string $post_type         Post type.
	 * @return string Field name.
	 */
	public function get_field_name( $relationship_name, $post_type ) {

		$field_name = str_replace( '-', '_', $relationship_name );

		/**
		 * Filter the field name for a post type.
		 *
		 * @param string $field_name        The field name.
		 * @param string $relationship_name Relationship name.
		 * @param string $post_type         Post type.
		 */
		$field_name = apply_filters( 'ep_content_connect_field_name', $field_name, $relationship_name, $post_type );

		return $field_name;
	}

	/**
	 * Retrieve the field value for a post.
	 *
	 * @param  \WP_Post $post Post object.
	 * @return array Field value.
	 */
	public function get_field_value( $post ) {

		if ( ! $post instanceof \WP_Post ) {
			return [];
		}

		$field_value = [
			'post_id'    => (int) $post->ID,
			'post_title' => $post->post_title,
			'post_type'  => $post->post_type,
			'post_name'  => $post->post_name,
		];

		/**
		 * Filter the field value for a post.
		 *
		 * @param array    $field_value The field value.
		 * @param \WP_Post $post        Post object.
		 */
		$field_value = apply_filters( 'ep_content_connect_field_value', $field_value, $post );

		return $field_value;
	}
}
