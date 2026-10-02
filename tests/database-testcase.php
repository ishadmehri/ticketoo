<?php
/**
 * Shared base test case for tests that execute the plugin's DDL.
 *
 * The WordPress test framework rewrites CREATE/DROP TABLE into
 * temporary-table statements so DDL stays inside the per-test transaction,
 * but MySQL cannot create FULLTEXT indexes on temporary InnoDB tables.
 * Tests extending this case run the Activator's DDL against real tables
 * instead; because real DDL implicitly commits and defeats the framework's
 * rollback, tear_down() then wipes every trace of the plugin so no tables,
 * role, capability or ticketoo_* option leaks into later tests or runs.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Activator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Ticketoo_Database_TestCase extends WP_UnitTestCase {

	/**
	 * Drops the framework's temporary-table rewrites for this test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Rolls back, then explicitly removes plugin state committed by real DDL.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		parent::tear_down();

		global $wpdb;

		// The framework's ROLLBACK does not roll back WordPress's in-memory
		// option cache or the roles registry; refresh both so the cleanup
		// below sees the same state as the database (remove_role() and
		// remove_cap() silently no-op against a stale registry).
		wp_cache_flush();
		$GLOBALS['wp_roles'] = null;
		wp_roles();

		Activator::uninstall();

		// The session runs with autocommit disabled; commit the cleanup so it
		// survives the end of the run instead of being rolled back on disconnect.
		$wpdb->query( 'COMMIT' );
	}
}
