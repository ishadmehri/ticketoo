<?php
/**
 * Plugin Name:       Ticketoo
 * Plugin URI:        https://github.com/ishadmehri/ticketoo
 * Description:       Lightweight and fast support ticket plugin for WordPress.
 * Author:            Iman Shadmehri
 * Author URI:        https://elinweb.ir
 * Version:           0.1.0
 * Requires at least: 6.4
 * Tested up to:      6.7
 * Requires PHP:      8.0
 * Text Domain:       ticketoo
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * @package Ticketoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TICKETOO_VERSION', '0.1.0' );
define( 'TICKETOO_FILE', __FILE__ );
define( 'TICKETOO_DIR', plugin_dir_path( __FILE__ ) );

// The autoloader cannot autoload itself: load it directly, then register it.
require_once TICKETOO_DIR . 'includes/Autoloader.php';
Ticketoo\Autoloader::register();

register_activation_hook( __FILE__, array( Ticketoo\Activator::class, 'activate' ) );

// Register the plugin's languages directory on init (WP 6.7+ requires
// translations to load at init or later; the actual .mo load still happens
// just in time on the first translated string).
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain(
			'ticketoo',
			false,
			dirname( plugin_basename( TICKETOO_FILE ) ) . '/languages'
		);
	}
);

add_action( 'plugins_loaded', array( Ticketoo\Plugin::class, 'boot' ) );
