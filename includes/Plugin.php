<?php
/**
 * Plugin bootstrapping: the single composition root for all hooks.
 *
 * @package Ticketoo
 */

namespace Ticketoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every hook the plugin registers.
 */
class Plugin {

	/**
	 * Runs on plugins_loaded. Empty in the skeleton; later tasks wire their
	 * hooks, services and REST routes here.
	 *
	 * @return void
	 */
	public static function boot(): void {
	}
}
