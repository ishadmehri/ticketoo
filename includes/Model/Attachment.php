<?php
/**
 * Attachment entity mapped to the ticketoo_attachments table.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single uploaded file attached to a conversation message. Instances come
 * from Ticketoo\Rest\FrontendController; the properties are immutable
 * snapshots of one row. file_path points at the stored random filesystem
 * name and is never serialized into a REST response.
 */
class Attachment {

	/**
	 * Row id.
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * Owning message id.
	 *
	 * @var int
	 */
	public int $message_id;

	/**
	 * Absolute path of the stored file (random name under uploads/ticketoo).
	 *
	 * @var string
	 */
	public string $file_path;

	/**
	 * Sanitized filename as originally uploaded, for display only.
	 *
	 * @var string
	 */
	public string $original_name;

	/**
	 * Detected MIME type (server-side, from wp_check_filetype_and_ext).
	 *
	 * @var string
	 */
	public string $mime;

	/**
	 * File size in bytes.
	 *
	 * @var int
	 */
	public int $size;

	/**
	 * Creation timestamp.
	 *
	 * @var string
	 */
	public string $created_at;

	/**
	 * Hydrates an Attachment from a database row.
	 *
	 * @param object $row Associative row for the ticketoo_attachments table.
	 * @return Attachment Populated entity with PHP-native types.
	 */
	public static function from_row( object $row ): Attachment {
		$attachment = new self();

		$attachment->id            = (int) $row->id;
		$attachment->message_id    = (int) $row->message_id;
		$attachment->file_path     = (string) $row->file_path;
		$attachment->original_name = (string) $row->original_name;
		$attachment->mime          = (string) $row->mime;
		$attachment->size          = (int) $row->size;
		$attachment->created_at    = (string) $row->created_at;

		return $attachment;
	}
}
