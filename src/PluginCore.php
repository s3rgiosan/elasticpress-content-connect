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
		$indexing = new PostToPost\Indexing();
		$indexing->setup();

		$mapping = new PostToPost\Mapping();
		$mapping->setup();

		$query = new Query();
		$query->setup();
	}
}
