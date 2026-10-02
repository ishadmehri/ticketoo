<?php
/**
 * Plugin bootstrapping: the single composition root for all hooks.
 *
 * @package Ticketoo
 */

namespace Ticketoo;

use Ticketoo\Admin\Capabilities;
use Ticketoo\Rest\FrontendController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every hook the plugin registers.
 */
class Plugin {

	/**
	 * Runs on plugins_loaded. Re-asserts the capability and agent role so
	 * they survive wiped options, and queues the public REST routes for
	 * rest_api_init; later tasks wire their remaining hooks here.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Capabilities::register();

		add_action( 'rest_api_init', array( FrontendController::class, 'register_routes' ) );
	}
}
