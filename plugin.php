<?php
/**
 * Plugin Name:       ElasticPress Content Connect
 * Description:       Integrates Content Connect with ElasticPress queries.
 * Plugin URI:        https://github.com/s3rgiosan/elasticpress-content-connect
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Version:           0.1.0
 * Author:            10up
 * Author URI:        https://10up.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/s3rgiosan/elasticpress-content-connect
 * Text Domain:       ep-content-connect
 * Domain Path:       /languages
 * Requires Plugins:  elasticpress
 *
 * @package           EPContentConnect
 */

namespace EPContentConnect;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'EP_CONTENT_CONNECT_VERSION', '0.1.0' );
define( 'EP_CONTENT_CONNECT_PATH', plugin_dir_path( __FILE__ ) );
define( 'EP_CONTENT_CONNECT_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( EP_CONTENT_CONNECT_PATH . 'vendor/autoload.php' ) ) {
	require_once EP_CONTENT_CONNECT_PATH . 'vendor/autoload.php';
}

$plugin_core = new PluginCore();
$plugin_core->setup();
