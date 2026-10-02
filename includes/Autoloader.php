<?php
/**
 * PSR-4 style autoloader for the Ticketoo namespace.
 *
 * Maps Ticketoo\... to files under includes/, so the plugin ships with
 * no runtime Composer dependency.
 *
 * @package Ticketoo
 */

namespace Ticketoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers an SPL autoloader for the Ticketoo\ namespace.
 */
class Autoloader {

	/**
	 * Prefix mapped by this autoloader.
	 *
	 * @var string
	 */
	const PREFIX = 'Ticketoo\\';

	/**
	 * Registers the autoloader with SPL.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Loads the file that defines a Ticketoo class.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function autoload( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$file     = dirname( __DIR__ ) . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
