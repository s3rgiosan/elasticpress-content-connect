<?php

namespace ElasticPressContentConnect;

/**
 * Core plugin functionality for ElasticPress Content Connect integration.
 *
 * @package ElasticPressContentConnect
 */
class PluginCore {

	/**
	 * Default setup routine.
	 *
	 * @return void
	 */
	public function setup() {
		$indexing = new Indexing();
		$indexing->setup();

		$mapping = new Mapping();
		$mapping->setup();

		$query = new Query();
		$query->setup();
	}
}
