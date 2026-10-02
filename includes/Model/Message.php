<?php
/**
 * Message entity mapped to the ticketoo_messages table.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single conversation message. Instances come from
 * Ticketoo\Database\MessageRepository; the properties are immutable
 * snapshots of one row.
 */
class Message {

	/**
	 * Row id.
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * Owning ticket id.
	 *
	 * @var int
	 */
	public int $ticket_id;

	/**
	 * Authoring user id; 0 for guests.
	 *
	 * @var int
	 */
	public int $user_id;

	/**
	 * Author email address.
	 *
	 * @var string
	 */
	public string $email;

	/**
	 * Author role: 0 user/guest, 1 agent, 2 system message.
	 *
	 * @var int
	 */
	public int $is_agent;

	/**
	 * Message body (untrusted HTML; sanitize on output).
	 *
	 * @var string
	 */
	public string $content;

	/**
	 * Creation timestamp.
	 *
	 * @var string
	 */
	public string $created_at;

	/**
	 * Hydrates a Message from a database row.
	 *
	 * @param object $row Associative row for the ticketoo_messages table.
	 * @return Message Populated entity with PHP-native types.
	 */
	public static function from_row( object $row ): Message {
		$message = new self();

		$message->id         = (int) $row->id;
		$message->ticket_id  = (int) $row->ticket_id;
		$message->user_id    = (int) $row->user_id;
		$message->email      = (string) $row->email;
		$message->is_agent   = (int) $row->is_agent;
		$message->content    = (string) $row->content;
		$message->created_at = (string) $row->created_at;

		return $message;
	}
}
