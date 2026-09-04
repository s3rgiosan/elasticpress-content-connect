# ElasticPress Content Connect

> ElasticPress Content Connect is a WordPress plugin that integrates [Content Connect](https://github.com/10up/wp-content-connect/) relationships with [ElasticPress](https://elasticpress.io/).

## Features

### Post to Post Relationships

Filter by Content Connect post-to-post relationships.

* Advanced Filtering: Filter search results by Content Connect post-to-post relationships
* Flexible Query Types: Support for ID-based, slug-based, and title-based filtering
* Real-time Indexing: Automatically indexes relationships when they're created or modified
* Performance Optimized: Uses Elasticsearch nested queries for efficient relationship lookups

## Requirements

* PHP 7.4+
* WordPress 6.7
* [ElasticPress](https://elasticpress.io/)
* [Content Connect](https://github.com/10up/wp-content-connect/)
* Elasticsearch per [ElasticPress requirements](https://github.com/10up/ElasticPress#requirements)

## Installation

### Manual Installation

1. Download the plugin ZIP file from the GitHub repository.
2. Go to Plugins > Add New > Upload Plugin in your WordPress admin area.
3. Upload the ZIP file and click Install Now.
4. Activate the plugin.

### Install with Composer

To include this plugin as a dependency in your Composer-managed WordPress project:

1. Add the plugin to your project using the following command:

```bash
composer require s3rgiosan/elasticpress-content-connect
```

2. Run `composer install` to install the plugin.
3. Activate the plugin from your WordPress admin area or using WP-CLI.

## Setup

1. Navigate to "ElasticPress" > "Features".
2. Enable "Post to Post Relationships" (see the [Features](#features) section for more).
3. Click "Save and sync now" or "Save and sync later" (sync is required for the feature to work).

## Advanced Setup

### Filter Names

Change the URL parameter names for filters:

```php
add_filter( 'ep_content_connect_post_to_post_relationship_filter_name', function( $filter_name, $relationship_name, $post_type ) {
    if ( $relationship_name === 'post-to-product' ) {
        return 'related-product'; // Use ?related-product=123 instead of ?product=123
    }
    return $filter_name;
}, 10, 3 );
```

### Field Names

Customize Elasticsearch field names:

```php
add_filter( 'ep_content_connect_field_name', function( $field_name, $relationship_name, $post_type ) {
    return $relationship_name . '_' . $post_type . '_relationships';
}, 10, 3 );
```

### Field Values

Add extra data to indexed relationships:

```php
add_filter( 'ep_content_connect_field_value', function( $field_value, $post ) {
    $field_value['custom_field'] = get_post_meta( $post->ID, 'custom_field', true );
    return $field_value;
}, 10, 2 );
```

### Related Posts Query Args

```php
// Adjust maximum related posts per relationship
add_filter( 'ep_content_connect_related_posts_query_args', function( $args, $post_id, $relationship_name ) {
    $args['posts_per_page'] = 150; // Default is 100
    return $args;
}, 10, 3 );
```

### Filterable Pages

Enable filtering on custom pages:

```php
add_filter( 'ep_content_connect_is_filterable_page', function( $is_filterable, $query ) {
    if ( $query->is_page() && is_page( 'special-archive' ) ) {
        return true;
    }
    return $is_filterable;
}, 10, 2 );
```

### Elasticsearch Mapping

The plugin automatically creates nested field mappings:

```json
{
  "post_to_product": {
    "type": "nested",
    "properties": {
      "post_id": { "type": "integer" },
      "post_title": {
        "type": "text",
        "fields": {
          "raw": { "type": "keyword" }
        }
      },
      "post_type": { "type": "keyword" },
      "post_name": { "type": "keyword" }
    }
  }
}
```

```php
add_filter( 'ep_content_connect_post_to_post_relationships_field_mapping', function( $mapping ) {
    $mapping['properties']['custom_field'] = [ 'type' => 'keyword' ];
    return $mapping;
} );
```

## Hooks Reference

### Filters

**Relationship Data:**

* `ep_content_connect_post_to_post_relationships` - Modify the available relationships
* `ep_content_connect_related_post_types` - Modify the related post types for a post type
* `ep_content_connect_field_name` - Customize a relationship's Elasticsearch field name
* `ep_content_connect_field_value` - Customize the indexed value for a related post

**Indexing:**

* `ep_content_connect_post_to_post_relationships_field_mapping` - Customize the nested Elasticsearch field mapping
* `ep_content_connect_post_to_post_relationship_fields` - Modify the set of relationship field names that get mapped
* `ep_content_connect_post_to_post_relationship_data` - Modify a relationship's data before indexing
* `ep_content_connect_related_posts` - Modify the related posts collected during indexing

**Query Filtering:**

* `ep_content_connect_is_filterable_page` - Control which pages support filtering
* `ep_content_connect_post_to_post_relationship_supported_filters` - Modify the supported filters for a post type
* `ep_content_connect_post_to_post_relationship_active_filters` - Modify the active filters read from the request
* `ep_content_connect_post_to_post_relationship_filter_name` - Customize URL parameter names
* `ep_content_connect_post_to_post_relationship_filter_value` - Sanitize filter values
* `ep_content_connect_post_to_post_relationship_filter_queries` - Modify the Elasticsearch filter queries
* `ep_content_connect_post_to_post_relationship_filter_operator` - Set how one post type's filters combine (default `must`)
* `ep_content_connect_post_to_post_relationship_minimum_should_match` - Set the minimum number of post-type groups that must match when combining multiple post types (default `1`)

**Performance:**

* `ep_content_connect_related_posts_query_args` - Optimize relationship queries

## Development

### Requirements

* [Node.js](https://nodejs.org/) and [Docker](https://www.docker.com/) (for the `wp-env` test environment)
* [Composer](https://getcomposer.org/)

### Running Tests

The test suite is split into two parts.

**Unit tests** run against a mocked Elasticsearch transport and need no cluster:

```bash
npm run test:setup   # start the wp-env environment (first run only)
npm run test:unit
```

**Integration tests** run against a real Elasticsearch cluster and are opt-in. Point `EP_HOST` at a reachable cluster and run:

```bash
EP_HOST=http://host.docker.internal:9200 npm run test:integration
```

Without a reachable `EP_HOST`, the integration suite skips itself, so the unit suite always runs on its own.

## Changelog

A complete listing of all notable changes to this project are documented in [CHANGELOG.md](https://github.com/s3rgiosan/elasticpress-content-connect/blob/main/CHANGELOG.md).
