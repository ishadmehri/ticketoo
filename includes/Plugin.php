<?php
/**
 * Plugin bootstrapping: the single composition root for all hooks.
 *
 * @package Ticketoo
 */

namespace Ticketoo;

use Ticketoo\Admin\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every hook the plugin registers.
 */
class Plugin {

	/**
	 * Runs on plugins_loaded. Currently re-asserts the capability and agent
	 * role so they survive wiped options; later tasks wire their hooks,
	 * services and REST routes here.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Capabilities::register();
	}
}
