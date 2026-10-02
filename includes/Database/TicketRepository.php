<?php
/**
 * Data access for the ticketoo_tickets table.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Database;

use Ticketoo\Activator;
use Ticketoo\Model\Ticket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes ticket rows. Every user-supplied value reaches SQL as a
 * $wpdb->prepare() placeholder; interpolated fragments (table name, WHERE
 * clauses) are assembled from trusted literals only.
 */
class TicketRepository {

	/**
	 * Inserts a ticket and returns its id.
	 *
	 * Defaults: status 'open', user_id 0, guest_token null. created_at,
	 * updated_at and last_activity_at are all stamped with
	 * current_time( 'mysql' ).
	 *
	 * @param array $data Keys: subject (string), user_id (int), email (string),
	 *                    guest_token (64-hex string|null) and optional status (string, 'open').
	 * @return int Inserted row id, or 0 when the insert fails.
	 */
	public static function create( array $data ): int {
		global $wpdb;

		$now         = current_time( 'mysql' );
		$guest_token = $data['guest_token'] ?? null;

		$row = array(
			'subject'          => (string) ( $data['subject'] ?? '' ),
			'status'           => (string) ( $data['status'] ?? 'open' ),
			'user_id'          => (int) ( $data['user_id'] ?? 0 ),
			'email'            => (string) ( $data['email'] ?? '' ),
			'guest_token'      => null !== $guest_token ? (string) $guest_token : null,
			'last_activity_at' => $now,
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Repository writes are intentionally uncached; the tickets table has no object-cache layer.
		$inserted = $wpdb->insert( Activator::table_names()['tickets'], $row );

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Fetches one ticket by id.
	 *
	 * @param int $id Row id.
	 * @return Ticket|null Null when no ticket has that id.
	 */
	public static function find( int $id ): ?Ticket {
		global $wpdb;

		$table = Activator::table_names()['tickets'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from Activator::table_names(); the id travels as a prepared placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return null !== $row ? Ticket::from_row( $row ) : null;
	}

	/**
	 * Returns a filtered, paginated slice of tickets plus the unpaginated
	 * matching count.
	 *
	 * Ordered by last_activity_at DESC (newest activity first), id DESC as a
	 * tie-breaker so pagination is stable.
	 *
	 * @param array $args Optional keys: status (string|null), q (string|null,
	 *                    matches subject via LIKE), page (int, default 1), per_page (int, default 20),
	 *                    user_id (int|null), assigned_to (int|null). Null or empty string means "no filter".
	 * @return array{items: Ticket[], total: int}
	 */
	public static function list( array $args ): array {
		global $wpdb;

		$table = Activator::table_names()['tickets'];

		$status      = $args['status'] ?? null;
		$q           = $args['q'] ?? null;
		$page        = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page    = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$user_id     = isset( $args['user_id'] ) ? (int) $args['user_id'] : null;
		$assigned_to = isset( $args['assigned_to'] ) ? (int) $args['assigned_to'] : null;

		$where  = array();
		$values = array();

		if ( null !== $status && '' !== $status ) {
			$where[]  = 'status = %s';
			$values[] = (string) $status;
		}

		if ( null !== $q && '' !== $q ) {
			$where[]  = 'subject LIKE %s';
			$values[] = '%' . $wpdb->esc_like( (string) $q ) . '%';
		}

		if ( null !== $user_id ) {
			$where[]  = 'user_id = %d';
			$values[] = $user_id;
		}

		if ( null !== $assigned_to ) {
			$where[]  = 'assigned_to = %d';
			$values[] = $assigned_to;
		}

		$where_sql = array() === $where ? '1 = 1' : implode( ' AND ', $where );

		if ( array() === $values ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Unfiltered count: table name comes from Activator::table_names() and there is no user input to bind.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders live inside the trusted {$where_sql} fragment the sniff cannot analyse; every filter value is bound by prepare().
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $values ) );
		}

		$page_values = array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders live inside the trusted {$where_sql} fragment the sniff cannot analyse; $page_values carries one value per placeholder (filters, limit, offset).
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY last_activity_at DESC, id DESC LIMIT %d OFFSET %d", $page_values ) );

		return array(
			'items' => array_map( array( Ticket::class, 'from_row' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Sets a ticket's status and stamps updated_at.
	 *
	 * @param int    $id     Row id.
	 * @param string $status New status slug (open, pending, answered, closed).
	 * @return bool True when the statement ran against an existing row — a
	 *              no-op status change still reports true; false when the row is missing
	 *              or the update failed.
	 */
	public static function update_status( int $id, string $status ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Status changes must reach the database immediately; the tickets table is not object-cached.
		$updated = $wpdb->update(
			Activator::table_names()['tickets'],
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		// 0 rows affected means either "no such ticket" or "status already set"; only the latter is a success.
		return $updated > 0 || null !== self::find( $id );
	}
}
