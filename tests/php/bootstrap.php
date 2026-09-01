<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package EPContentConnect
 */

// Load Composer autoloader.
require_once dirname( dirname( __DIR__ ) ) . '/vendor/autoload.php';

// Load the WordPress test library.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested, plus its runtime dependencies.
 *
 * The WordPress PHPUnit suite boots its own minimal site and does not activate
 * the plugins installed in the wp-env instance, so ElasticPress (provides
 * ElasticPress\Feature) and Content Connect (provides get_registry()) must be
 * loaded explicitly, in dependency order, before this plugin.
 */
function _manually_load_plugin() {
	$plugins_dir = dirname( dirname( dirname( __DIR__ ) ) );

	$elasticpress = $plugins_dir . '/elasticpress/elasticpress.php';
	if ( file_exists( $elasticpress ) ) {
		require $elasticpress;
	}

	$content_connect = $plugins_dir . '/wp-content-connect/content-connect.php';
	if ( file_exists( $content_connect ) ) {
		require $content_connect;
	}

	require dirname( dirname( __DIR__ ) ) . '/plugin.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// Content Connect creates its custom tables on activation, but the PHPUnit
// suite never runs activation hooks. Create them now so relationship inserts
// (add_relationship -> BaseTable::replace) have their junction table.
if ( class_exists( 'TenUp\ContentConnect\Tables\PostToPost' ) ) {
	( new \TenUp\ContentConnect\Tables\PostToPost() )->upgrade( true );
}
if ( class_exists( 'TenUp\ContentConnect\Tables\PostToUser' ) ) {
	( new \TenUp\ContentConnect\Tables\PostToUser() )->upgrade( true );
}
