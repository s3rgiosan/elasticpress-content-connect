<?php
/**
 * Smoke test to prove the WordPress test harness boots correctly.
 *
 * @package EPContentConnect
 */

namespace EPContentConnect\Tests\Unit;

use WP_UnitTestCase;

class SmokeTest extends WP_UnitTestCase {

	public function test_wordpress_is_loaded(): void {

		$this->assertTrue( function_exists( 'add_filter' ) );
	}

	public function test_plugin_feature_class_is_loaded(): void {

		$this->assertTrue( class_exists( '\EPContentConnect\PostToPost\Feature' ) );
	}
}
