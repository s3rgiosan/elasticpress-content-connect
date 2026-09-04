<?php
/**
 * Tests covering Indexing::execute_bulk_update() result handling: invalid input,
 * transport failure, non-2xx status, item-level errors, and success. The ES HTTP
 * call is short-circuited via the WordPress `pre_http_request` filter so no live
 * cluster is needed.
 *
 * @package EPContentConnect
 */

namespace EPContentConnect\Tests\Unit;

use ReflectionMethod;
use WP_Error;
use WP_UnitTestCase;
use EPContentConnect\PostToPost\Indexing;

class IndexingBulkUpdateResultTest extends WP_UnitTestCase {

	/**
	 * @inheritDoc
	 */
	public function set_up(): void {
		if ( ! class_exists( '\ElasticPress\Indexables' ) ) {
			$this->markTestSkipped( 'ElasticPress is not available.' );
		}

		parent::set_up();
	}

	/**
	 * Invokes the private Indexing::execute_bulk_update().
	 *
	 * @param  mixed  $data      Relationship data map.
	 * @param  string $operation Operation ('add' or 'remove').
	 * @return mixed
	 */
	private function execute_bulk_update( $data, string $operation ) {

		$indexing = new Indexing();

		$method = new ReflectionMethod( Indexing::class, 'execute_bulk_update' );
		$method->setAccessible( true );

		return $method->invoke( $indexing, $data, $operation );
	}

	/**
	 * A valid relationship-data map keyed by post id.
	 *
	 * @return array
	 */
	private function valid_data(): array {
		return [
			123 => [
				'field' => 'related_content',
				'value' => [
					'post_id'    => 123,
					'post_title' => 'Example',
					'post_type'  => 'post',
					'post_name'  => 'example',
				],
			],
		];
	}

	/**
	 * Short-circuit every ES `_bulk` HTTP request with a canned response.
	 *
	 * @param  mixed $response Canned response or WP_Error to return.
	 * @return void
	 */
	private function stub_bulk_response( $response ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( $response ) {
				if ( false !== strpos( (string) $url, '_bulk' ) ) {
					return $response;
				}
				return $preempt;
			},
			10,
			3
		);
	}

	public function test_invalid_data_returns_wp_error(): void {
		$this->assertInstanceOf( WP_Error::class, $this->execute_bulk_update( [], 'add' ) );
	}

	public function test_invalid_operation_returns_wp_error(): void {
		$this->assertInstanceOf( WP_Error::class, $this->execute_bulk_update( $this->valid_data(), 'bogus' ) );
	}

	public function test_transport_error_returns_false(): void {
		$this->stub_bulk_response( new WP_Error( 'http_request_failed', 'Simulated transport failure.' ) );

		$this->assertFalse( $this->execute_bulk_update( $this->valid_data(), 'remove' ) );
	}

	public function test_non_2xx_status_returns_false(): void {
		$this->stub_bulk_response(
			[
				'response' => [
					'code'    => 500,
					'message' => 'Internal Server Error',
				],
				'body'     => wp_json_encode( [ 'errors' => false ] ),
				'headers'  => [],
			]
		);

		$this->assertFalse( $this->execute_bulk_update( $this->valid_data(), 'remove' ) );
	}

	public function test_item_errors_return_false(): void {
		$this->stub_bulk_response(
			[
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => wp_json_encode(
					[
						'errors' => true,
						'items'  => [ [ 'update' => [ 'status' => 404 ] ] ],
					]
				),
				'headers'  => [],
			]
		);

		$this->assertFalse( $this->execute_bulk_update( $this->valid_data(), 'remove' ) );
	}

	public function test_success_returns_the_response(): void {
		$this->stub_bulk_response(
			[
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => wp_json_encode(
					[
						'errors' => false,
						'items'  => [],
					]
				),
				'headers'  => [],
			]
		);

		$result = $this->execute_bulk_update( $this->valid_data(), 'add' );

		$this->assertNotFalse( $result );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 200, (int) wp_remote_retrieve_response_code( $result ) );
	}
}
