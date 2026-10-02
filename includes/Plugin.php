<?php
/**
 * Plugin bootstrapping: the single composition root for all hooks.
 *
 * @package Ticketoo
 */

namespace Ticketoo;

use Ticketoo\Admin\Capabilities;
use Ticketoo\Rest\FrontendController;
use Ticketoo\Shortcode\TicketooShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every hook the plugin registers.
 */
class Plugin {

	/**
	 * Runs on plugins_loaded. Re-asserts the capability and agent role so
	 * they survive wiped options, queues the public REST routes for
	 * rest_api_init, and registers the [ticketoo] shortcode together with
	 * its classic (no-JS) form handler.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Capabilities::register();

		add_action( 'rest_api_init', array( FrontendController::class, 'register_routes' ) );

		// Server-rendered views (ADR 0003): the shortcode prints markup that
		// works without JavaScript, and template_redirect turns the classic
		// form posts into post/redirect/get cycles before anything renders.
		add_shortcode( 'ticketoo', array( TicketooShortcode::class, 'shortcode' ) );
		add_action( 'template_redirect', array( TicketooShortcode::class, 'handle_post' ) );
	}
}
