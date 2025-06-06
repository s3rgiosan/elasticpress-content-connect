<?php

namespace ElasticPressContentConnect;

use function TenUp\ContentConnect\Helpers\get_registry;

/**
 * Helper class for relationship operations.
 *
 * @package ElasticPressContentConnect
 */
class RelationshipHelper {

	/**
	 * Array of post-to-post relationships.
	 *
	 * @var array
	 */
	private $post_to_post_relationships = [];

	/**
	 * Get all registered post-to-post relationships.
	 *
	 * @return array Relationship objects.
	 */
	public function get_post_to_post_relationships() {

		if ( empty( $this->post_to_post_relationships ) ) {

			$relationships = get_registry()->get_post_to_post_relationships();

			/**
			 * Filter the post-to-post relationships.
			 *
			 * @param array $relationships Relationship objects.
			 */
			$this->post_to_post_relationships = apply_filters( 'ep_content_connect_post_to_post_relationships', $relationships );
		}

		return $this->post_to_post_relationships;
	}

	/**
	 * Get related post types for a given post type.
	 *
	 * @param  string $post_type Source post type.
	 * @return array Related post types by relationship name.
	 */
	public function get_related_post_types( $post_type ) {

		$relationships = $this->get_post_to_post_relationships();

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

		return $related_post_types;
	}

	/**
	 * Retrieve the field name for a post type.
	 *
	 * @param  string $post_type Post type.
	 * @return string Field name.
	 */
	public function get_field_name( $post_type ) {

		$field_prefix = $this->get_field_prefix();
		$field_name   = sprintf( '%s%s', $field_prefix, str_replace( '-', '_', $post_type ) );

		/**
		 * Filter the field name for a post type.
		 *
		 * @param string $field_name The field name.
		 * @param string $post_type  Post type.
		 */
		$field_name = apply_filters( 'ep_content_connect_field_name', $field_name, $post_type );

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

	/**
	 * Retrieve the field prefix.
	 *
	 * @return string Field prefix.
	 */
	private function get_field_prefix() {

		/**
		 * Filter the field prefix.
		 *
		 * @param string $prefix The field prefix.
		 */
		$field_prefix = apply_filters( 'ep_content_connect_field_prefix', 'related_' );

		return $field_prefix;
	}
}
