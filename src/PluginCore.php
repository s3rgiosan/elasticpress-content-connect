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
		$indexing = new Indexing();
		$indexing->setup();

		$mapping = new Mapping();
		$mapping->setup();

		$query = new Query();
		$query->setup();
	}
}
