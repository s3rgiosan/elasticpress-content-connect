<?php
/**
 * Base test case for ElasticPress integration tests.
 *
 * Unlike the mocked unit suite, these tests talk to a real Elasticsearch
 * cluster. Each test runs against a throwaway, test-only index so it never
 * touches a real site index.
 *
 * @package EPContentConnect
 */

namespace EPContentConnect\Tests\Integration;

use ElasticPress\Elasticsearch;
use ElasticPress\Features;
use ElasticPress\Indexables;
use WP_UnitTestCase;

/**
 * Abstract base for Elasticsearch-backed integration tests.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Test-only index prefix, kept distinct from any real site index.
	 *
	 * @var string
	 */
	protected $index_prefix = 'epcctest_';

	/**
	 * The Elasticsearch host under test.
	 *
	 * @var string
	 */
	protected $ep_host = '';

	/**
	 * @inheritDoc
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'TenUp\ContentConnect\Helpers\get_registry' ) ) {
			$this->markTestSkipped( 'Content Connect is not available.' );
		}

		if ( ! class_exists( '\ElasticPress\Elasticsearch' ) ) {
			$this->markTestSkipped( 'ElasticPress is not available.' );
		}

		$this->ep_host = getenv( 'EP_HOST' ) ?: 'http://127.0.0.1:8890';

		$host   = $this->ep_host;
		$prefix = $this->index_prefix;

		add_filter(
			'ep_host',
			static function () use ( $host ) {
				return $host;
			}
		);

		add_filter(
			'ep_index_prefix',
			static function () use ( $prefix ) {
				return $prefix;
			}
		);

		if ( ! $this->is_es_reachable( $host ) ) {
			$this->markTestSkipped( 'Elasticsearch cluster did not answer at ' . $host );
		}

		// Activate the ElasticPress search feature (so the post indexable and
		// its WP_Query integration are wired up) plus this plugin's own
		// post-to-post feature (so the relationship mapping and sync-args hooks
		// register). is_active() reads this option.
		update_option(
			'ep_feature_settings',
			[
				'search'                          => [ 'active' => true ],
				'ep_content_connect_post_to_post' => [ 'active' => true ],
			]
		);

		// The WordPress PHPUnit suite boots WP before the test runs, so the
		// `init`-time setup_features() call has already fired without our
		// features active. Run it again now that they are activated to wire
		// every hook the query integration and this plugin depend on.
		Features::factory()->setup_features();

		// Start from a clean index carrying the relationship mapping. Tests
		// that register relationships in their own set_up must call
		// create_index() again afterwards so the nested relationship fields make
		// it into the mapping (the fields are derived from registered
		// relationships, which do not exist yet at this point).
		$this->create_index();
	}

	/**
	 * @inheritDoc
	 */
	public function tear_down(): void {

		$post_indexable = Indexables::factory()->get( 'post' );

		if ( $post_indexable && $post_indexable->index_exists() ) {
			$post_indexable->delete_index();
		}

		parent::tear_down();
	}

	/**
	 * Delete and recreate the post index with the current mapping.
	 *
	 * @return void
	 */
	protected function create_index(): void {

		$post_indexable = Indexables::factory()->get( 'post' );

		if ( $post_indexable->index_exists() ) {
			$post_indexable->delete_index();
		}

		$post_indexable->put_mapping();
	}

	/**
	 * Sync a single post into Elasticsearch (blocking).
	 *
	 * @param  int $id Post ID.
	 * @return void
	 */
	protected function index_post( int $id ): void {

		$post_indexable = Indexables::factory()->get( 'post' );
		$document       = $post_indexable->prepare_document( $id );

		Elasticsearch::factory()->index_document(
			$post_indexable->get_index_name(),
			'post',
			$document,
			true
		);
	}

	/**
	 * Force a refresh so freshly indexed documents are immediately searchable.
	 *
	 * @return void
	 */
	protected function refresh_index(): void {
		Elasticsearch::factory()->refresh_indices();
	}

	/**
	 * Probe the Elasticsearch host, retrying a few times.
	 *
	 * @param  string $host Elasticsearch host URL.
	 * @return bool Whether the host answered with HTTP 200.
	 */
	private function is_es_reachable( $host ): bool {

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {

			$response = wp_remote_get( $host, [ 'timeout' => 5 ] );

			$status_code = (int) wp_remote_retrieve_response_code( $response );

			if ( ! is_wp_error( $response ) && 200 === $status_code ) {
				return true;
			}

			usleep( 200000 );
		}

		return false;
	}
}
