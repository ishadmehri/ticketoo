<?php
/**
 * Data access for the ticketoo_messages table.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Database;

use Ticketoo\Activator;
use Ticketoo\Model\Message;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes message rows. Every user-supplied value reaches SQL as a
 * $wpdb->prepare() placeholder or a typed $wpdb->insert() column; interpolated
 * fragments (table names) come from Activator::table_names() only.
 */
class MessageRepository {

	/**
	 * Inserts a message, touches its ticket and returns the message id.
	 *
	 * The created_at column is stamped with current_time( 'mysql' ). On
	 * success the owning ticket's last_activity_at and updated_at are
	 * refreshed so the auto-close window restarts on every new activity.
	 *
	 * @param int    $ticket_id Owning ticket id.
	 * @param int    $user_id   Authoring user id; 0 for guests.
	 * @param string $email     Author email address.
	 * @param int    $is_agent  0 user/guest, 1 agent, 2 system message.
	 * @param string $content   Message body (caller sanitizes).
	 * @return int Inserted row id, or 0 when the insert fails.
	 */
	public static function add( int $ticket_id, int $user_id, string $email, int $is_agent, string $content ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Repository writes are intentionally uncached; the messages table has no object-cache layer.
		$inserted = $wpdb->insert(
			Activator::table_names()['messages'],
			array(
				'ticket_id'  => $ticket_id,
				'user_id'    => $user_id,
				'email'      => $email,
				'is_agent'   => $is_agent,
				'content'    => $content,
				'created_at' => current_time( 'mysql' ),
			)
		);

		if ( false === $inserted ) {
			return 0;
		}

		self::touch( $ticket_id );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Returns a paginated slice of a ticket's conversation plus the
	 * unpaginated message count.
	 *
	 * Ordered by created_at ASC (oldest first), id ASC as a tie-breaker so
	 * pagination is stable when messages share a timestamp.
	 *
	 * @param int $ticket_id Owning ticket id.
	 * @param int $page      1-based page number, clamped to >= 1.
	 * @param int $per_page  Page size, clamped to >= 1.
	 * @return array{items: Message[], total: int}
	 */
	public static function for_ticket( int $ticket_id, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$table    = Activator::table_names()['messages'];
		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from Activator::table_names(); the ticket id travels as a prepared placeholder.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ticket_id = %d", $ticket_id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from Activator::table_names(); every bound value travels as a prepared placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY created_at ASC, id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from Activator::table_names(); every value is a prepared placeholder below.
				$ticket_id,
				$per_page,
				( $page - 1 ) * $per_page
			)
		);

		return array(
			'items' => array_map( array( Message::class, 'from_row' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Refreshes a ticket's activity timestamps after a new message.
	 *
	 * A missing ticket is a no-op (0 rows affected); the reply still stands
	 * on its own.
	 *
	 * @param int $ticket_id Owning ticket id.
	 * @return void
	 */
	private static function touch( int $ticket_id ): void {
		global $wpdb;

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Repository writes are intentionally uncached; the tickets table has no object-cache layer.
		$wpdb->update(
			Activator::table_names()['tickets'],
			array(
				'last_activity_at' => $now,
				'updated_at'       => $now,
			),
			array( 'id' => $ticket_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
