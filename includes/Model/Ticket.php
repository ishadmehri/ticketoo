<?php
/**
 * Ticket entity mapped to the ticketoo_tickets table.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single support ticket row. Instances come from
 * Ticketoo\Database\TicketRepository; the properties are immutable
 * snapshots of one row.
 */
class Ticket {

	/**
	 * Row id.
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * Ticket subject line.
	 *
	 * @var string
	 */
	public string $subject;

	/**
	 * Status slug: open, pending, answered or closed.
	 *
	 * @var string
	 */
	public string $status;

	/**
	 * Owning user id; 0 for guests.
	 *
	 * @var int
	 */
	public int $user_id;

	/**
	 * Customer email address.
	 *
	 * @var string
	 */
	public string $email;

	/**
	 * Assigned agent user id, or null when unassigned.
	 *
	 * @var int|null
	 */
	public ?int $assigned_to;

	/**
	 * Raw 64-hex bearer token for guests, or null for logged-in users.
	 *
	 * @var string|null
	 */
	public ?string $guest_token;

	/**
	 * Timestamp of the latest message, used for sorting and auto-close.
	 *
	 * @var string
	 */
	public string $last_activity_at;

	/**
	 * Creation timestamp.
	 *
	 * @var string
	 */
	public string $created_at;

	/**
	 * Last modification timestamp.
	 *
	 * @var string
	 */
	public string $updated_at;

	/**
	 * Hydrates a Ticket from a database row.
	 *
	 * @param object $row Associative row for the ticketoo_tickets table.
	 * @return Ticket Populated entity with PHP-native types.
	 */
	public static function from_row( object $row ): Ticket {
		$ticket = new self();

		$ticket->id               = (int) $row->id;
		$ticket->subject          = (string) $row->subject;
		$ticket->status           = (string) $row->status;
		$ticket->user_id          = (int) $row->user_id;
		$ticket->email            = (string) $row->email;
		$ticket->assigned_to      = isset( $row->assigned_to ) ? (int) $row->assigned_to : null;
		$ticket->guest_token      = isset( $row->guest_token ) ? (string) $row->guest_token : null;
		$ticket->last_activity_at = (string) $row->last_activity_at;
		$ticket->created_at       = (string) $row->created_at;
		$ticket->updated_at       = (string) $row->updated_at;

		return $ticket;
	}
}
