# Changelog

All notable changes to this project will be documented in this file, per [the Keep a Changelog standard](http://keepachangelog.com/).

## 1.0.2 - 2026-09-04

### Added

- Expanded unit coverage for bulk update result handling, numeric array filter values, relationship helper fallbacks, batched partner cleanup on deletion, and multiple relationship filters on a single post type.
- A GitHub Actions workflow that runs the unit suite on a PHP 7.4, 8.2, and 8.3 matrix.
- An opt-in Elasticsearch integration test suite with an end-to-end relationship filtering test, kept separate from the unit suite.

## 1.0.1 - 2026-09-04

### Added

- PHPUnit test suite and a wp-env test environment.
- `composer test:install` script to provision the WordPress test library.

### Fixed

- Index every related post instead of silently capping the list at 100.
- Stop indexing a post as its own related item.
- Map relationship fields for both sides of a relationship.
- Remove a permanently deleted post from its related posts' indexed documents.
- Group relationship filters per post type in multi-post-type queries so one type's filter no longer excludes another.
- Accept array filter values in relationship filter queries.
- Guard relationship lookups when Content Connect is unavailable.

### Changed

- Align the Composer platform to the declared PHP 7.4 support floor.

## 1.0.0 - 2025-06-10

- Initial release.
