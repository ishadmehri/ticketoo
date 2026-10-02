<?php
/**
 * Uninstall routine: runs when the plugin is deleted from the plugins screen.
 *
 * Loads the plugin bootstrap (constants + autoloader) and delegates to
 * Activator::uninstall(), the same idempotent cleanup the test suite calls.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/ticketoo.php';

Ticketoo\Activator::uninstall();
