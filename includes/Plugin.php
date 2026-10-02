<?php
/**
 * Plugin bootstrapping: the single composition root for all hooks.
 *
 * @package Ticketoo
 */

namespace Ticketoo;

use Ticketoo\Admin\Capabilities;
use Ticketoo\Admin\MenuPage;
use Ticketoo\Admin\SettingsPage;
use Ticketoo\Integrations\Elementor;
use Ticketoo\Integrations\Gutenberg;
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
	 * rest_api_init, registers the [ticketoo] shortcode together with
	 * its classic (no-JS) form handler, and wires the capability-gated
	 * admin menu, settings screen and panel assets.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Capabilities::register();

		// Admin menu and settings (spec §6): both pages are gated by the
		// plugin capability. The menu registers first so the Settings
		// submenu finds its parent; assets load only on the panel screen.
		add_action( 'admin_menu', array( MenuPage::class, 'register' ) );
		add_action( 'admin_menu', array( SettingsPage::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( MenuPage::class, 'enqueue_assets' ) );

		// options.php requires manage_options by default; saving these
		// settings only needs the plugin capability.
		add_filter(
			'option_page_capability_' . SettingsPage::OPTION_GROUP,
			array( SettingsPage::class, 'option_page_capability' )
		);

		add_action( 'rest_api_init', array( FrontendController::class, 'register_routes' ) );

		// Server-rendered views (ADR 0003): the shortcode prints markup that
		// works without JavaScript, and template_redirect turns the classic
		// form posts into post/redirect/get cycles before anything renders.
		add_shortcode( 'ticketoo', array( TicketooShortcode::class, 'shortcode' ) );
		add_action( 'template_redirect', array( TicketooShortcode::class, 'handle_post' ) );

		// The Gutenberg blocks are wrappers over that same shortcode. The
		// integrations/ directory sits outside the includes/-rooted
		// autoloader, so its entry file is loaded here explicitly.
		require_once TICKETOO_DIR . 'integrations/Gutenberg.php';
		add_action( 'init', array( Gutenberg::class, 'register' ) );

		// Elementor is a soft dependency: integrations/Elementor.php extends
		// \Elementor\Widget_Base and must stay unloaded until Elementor has
		// actually loaded. Elementor fires `elementor/loaded` while its own
		// plugin file is included — before `plugins_loaded`, so usually
		// before boot() runs — hence the did_action() fast path; the hook
		// covers load orders in which Elementor arrives afterwards.
		add_action( 'elementor/loaded', array( __CLASS__, 'boot_elementor' ) );

		if ( did_action( 'elementor/loaded' ) ) {
			self::boot_elementor();
		}
	}

	/**
	 * Loads the Elementor integration and hands over to its maybe_register().
	 *
	 * Reached only from the `elementor/loaded` hook (or the did_action()
	 * fast path in boot()), so integrations/Elementor.php — and with it the
	 * Widget_Base subclass — never loads on a site without Elementor.
	 *
	 * @return void
	 */
	public static function boot_elementor(): void {
		require_once TICKETOO_DIR . 'integrations/Elementor.php';

		Elementor::maybe_register();
	}
}
