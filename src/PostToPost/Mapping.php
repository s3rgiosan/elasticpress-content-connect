<?php

namespace EPContentConnect\PostToPost;

use ElasticPress\Features;
use ElasticPress\Indexables;

/**
 * Handles Elasticsearch mapping configuration for post-to-post relationships.
 *
 * @package EPContentConnect
 */
class Mapping {

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

		add_filter( 'ep_config_mapping', [ $this, 'relationships_mapping' ], 10, 2 );
	}

	/**
	 * Add post-to-post relationship fields to Elasticsearch mapping.
	 *
	 * @param array  $mapping    Current Elasticsearch mapping.
	 * @param string $index_name Index name.
	 * @return array Updated mapping.
	 */
	public function relationships_mapping( $mapping, $index_name ) {

		if ( Indexables::factory()->get( 'post' )->get_index_name() !== $index_name ) {
			return $mapping;
		}

		$fields = $this->get_relationship_fields();

		if ( empty( $fields ) ) {
			return $mapping;
		}

		$default_mapping = $this->get_relationships_field_mapping();

		foreach ( $fields as $field ) {

			/**
			 * Filter the field mapping for each post-to-post relationship field.
			 *
			 * @param array  $default_mapping Default field mapping.
			 * @param string $field           The field name.
			 */
			$field_mapping = apply_filters( "ep_content_connect_post_to_post_relationships_field_{$field}_mapping", $default_mapping, $field );

			$mapping['mappings']['properties'][ $field ] = $field_mapping;
		}

		return $mapping;
	}

	/**
	 * Get the post-to-post relationship fields.
	 *
	 * @return array Array of field names.
	 */
	private function get_relationship_fields() {

		$relationships = $this->helper->get_relationships();

		if ( empty( $relationships ) ) {
			return [];
		}

		$fields = [];

		foreach ( $relationships as $relationship ) {

			if ( empty( $relationship->to ) ) {
				continue;
			}

			$target_types = is_array( $relationship->to ) ? $relationship->to : [ $relationship->to ];

			foreach ( $target_types as $post_type ) {
				$fields[] = $this->helper->get_field_name( $relationship->name, $post_type );
			}
		}

		/**
		 * Filter the post-to-post relationship fields.
		 *
		 * @param array $fields        Array of field names.
		 * @param array $relationships Relationship objects.
		 */
		$fields = apply_filters( 'ep_content_connect_post_to_post_relationship_fields', $fields, $relationships );
		$fields = array_unique( $fields );

		return $fields;
	}

	/**
	 * Get the field mapping for post-to-post relationships.
	 *
	 * @return array Field mapping.
	 */
	private function get_relationships_field_mapping() {

		$field_mapping = [
			'type'       => 'nested',
			'properties' => [
				'post_id'    => [ 'type' => 'integer' ],
				'post_title' => [
					'type'   => 'text',
					'fields' => [
						'raw' => [ 'type' => 'keyword' ],
					],
				],
				'post_type'  => [ 'type' => 'keyword' ],
				'post_name'  => [ 'type' => 'keyword' ],
			],
		];

		/**
		 * Filter the default field mapping for post-to-post relationships.
		 *
		 * @param array $field_mapping Default field mapping.
		 */
		$field_mapping = apply_filters( 'ep_content_connect_post_to_post_relationships_field_mapping', $field_mapping );

		return $field_mapping;
	}
}
