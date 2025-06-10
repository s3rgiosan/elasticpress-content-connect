<?php

namespace EPContentConnect;

/**
 * Core plugin functionality for ElasticPress Content Connect integration.
 *
 * @package EPContentConnect
 */
class PluginCore {

	/**
	 * Default setup routine.
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'plugins_loaded', [ $this, 'register_features' ], 11 );
	}

	/**
	 * Register ElasticPress features.
	 *
	 * @return void
	 */
	public function register_features() {

		if ( class_exists( '\ElasticPress\Features' ) ) {
			\ElasticPress\Features::factory()->register_feature(
				new PostToPost\Feature()
			);
		}
	}
}
