<?php
/**
 * Tests for the activation routines: tables, roles and capabilities.
 *
 * @package Ticketoo
 */

use Ticketoo\Activator;
use Ticketoo\Admin\Capabilities;

class Test_Activator extends WP_UnitTestCase {

	/**
	 * The framework rewrites CREATE/DROP TABLE into temporary-table
	 * statements so DDL stays inside the test transaction, but MySQL
	 * cannot create FULLTEXT indexes on temporary InnoDB tables. Run the
	 * Activator's DDL against real tables instead; every test activates
	 * first, so the class stays self-contained.
	 */
	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function test_tables_created(): void {
		global $wpdb;

		Activator::activate();

		$tables = Activator::table_names();

		foreach ( $tables as $table ) {
			$this->assertNotNull(
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ),
				"Expected table {$table} to exist."
			);
		}

		$this->assertTrue(
			$this->index_exists( $tables['tickets'], 'subject' ),
			'Expected a FULLTEXT index on ticketoo_tickets.subject.'
		);
		$this->assertTrue(
			$this->index_exists( $tables['messages'], 'content' ),
			'Expected a FULLTEXT index on ticketoo_messages.content.'
		);
	}

	public function test_activation_is_idempotent(): void {
		global $wpdb;

		Activator::activate();
		Activator::activate();

		$all_tables = $wpdb->get_col( 'SHOW TABLES' );
		$tables     = Activator::table_names();

		foreach ( $tables as $table ) {
			$matches = array_filter(
				$all_tables,
				static fn ( string $name ): bool => $name === $table
			);
			$this->assertCount( 1, $matches, "Expected exactly one {$table} table after re-activation." );
		}

		$this->assertTrue(
			$this->index_exists( $tables['tickets'], 'subject' ),
			'FULLTEXT index must survive re-activation.'
		);
	}

	public function test_agent_role_created(): void {
		Activator::activate();

		$role = get_role( Capabilities::ROLE );
		$this->assertNotNull( $role, 'The ticketoo_agent role must exist after activation.' );
		$this->assertTrue( $role->has_cap( Capabilities::CAP ), 'The agent role must hold the capability.' );

		$user_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE ) );
		$this->assertTrue( user_can( $user_id, Capabilities::CAP ) );
		$this->assertTrue( user_can( $user_id, 'read' ) );
	}

	public function test_administrator_has_cap(): void {
		Activator::activate();

		$admin = get_user_by( 'login', 'admin' );
		$this->assertNotFalse( $admin, 'Expected an admin user in the test install.' );
		$this->assertTrue( user_can( $admin, Capabilities::CAP ) );
	}

	public function test_uninstall_drops_everything(): void {
		global $wpdb;

		Activator::activate();
		update_option( 'ticketoo_auto_close_days', 14 );

		$tables = Activator::table_names();
		Activator::uninstall();

		$remaining = $wpdb->get_col( 'SHOW TABLES' );
		foreach ( $tables as $table ) {
			$this->assertNotContains( $table, $remaining, "Expected table {$table} to be dropped." );
		}

		$this->assertNull( get_role( Capabilities::ROLE ), 'The ticketoo_agent role must be removed.' );
		$this->assertFalse( get_option( 'ticketoo_auto_close_days' ), 'ticketoo_* options must be deleted.' );

		$administrator = get_role( 'administrator' );
		$this->assertNotNull( $administrator );
		$this->assertFalse( $administrator->has_cap( Capabilities::CAP ), 'Administrators must lose the capability.' );
	}

	private function index_exists( string $table, string $index_name ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM {$table} WHERE Key_name = %s",
				$index_name
			)
		);

		return null !== $found;
	}
}
