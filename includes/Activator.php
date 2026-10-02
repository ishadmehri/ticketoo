<?php
/**
 * Activation routines: database tables, roles and capabilities.
 *
 * @package Ticketoo
 */

namespace Ticketoo;

use Ticketoo\Admin\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs when the plugin is activated.
 */
class Activator {

	/**
	 * Activation hook callback: creates the custom tables with dbDelta()
	 * and registers roles/capabilities.
	 *
	 * Idempotent — safe to run on every activation or upgrade.
	 *
	 * @return void
	 */
	public static function activate(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;

		$tables          = self::table_names();
		$charset_collate = $wpdb->get_charset_collate();

		$queries = array(
			"CREATE TABLE {$tables['tickets']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject VARCHAR(190) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  email VARCHAR(160) NOT NULL DEFAULT '',
  assigned_to BIGINT UNSIGNED NULL DEFAULT NULL,
  guest_token CHAR(64) NULL DEFAULT NULL,
  last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY status_last_activity (status,last_activity_at),
  KEY user_id (user_id),
  KEY email (email),
  KEY assigned_to (assigned_to),
  FULLTEXT KEY subject (subject)
) {$charset_collate};",

			"CREATE TABLE {$tables['messages']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  email VARCHAR(160) NOT NULL DEFAULT '',
  is_agent TINYINT(1) NOT NULL DEFAULT 0,
  content LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY ticket_id (ticket_id),
  FULLTEXT KEY content (content)
) {$charset_collate};",

			"CREATE TABLE {$tables['attachments']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  file_path VARCHAR(255) NOT NULL DEFAULT '',
  original_name VARCHAR(255) NOT NULL DEFAULT '',
  mime VARCHAR(100) NOT NULL DEFAULT '',
  size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY message_id (message_id)
) {$charset_collate};",
		);

		dbDelta( $queries );

		Capabilities::register();
	}

	/**
	 * Uninstall hook callback: drops the custom tables, removes the agent
	 * role and capability, and deletes every ticketoo_* option.
	 *
	 * Idempotent — safe to run when only part of the plugin was installed.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;

		foreach ( self::table_names() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table name comes from a trusted prefix; dropping tables on uninstall is an intentional, uncacheable schema change.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		remove_role( Capabilities::ROLE );

		$administrator = get_role( 'administrator' );

		if ( null !== $administrator ) {
			$administrator->remove_cap( Capabilities::CAP );
		}

		$like = $wpdb->esc_like( 'ticketoo_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-shot option cleanup on uninstall; caching would be counterproductive.
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from wpdb.
				$like
			)
		);

		foreach ( (array) $option_names as $option_name ) {
			delete_option( $option_name );
		}
	}

	/**
	 * Full names of the plugin's custom tables.
	 *
	 * @return array<string, string> Table key (tickets, messages, attachments)
	 *                               => full table name including wpdb prefix.
	 */
	public static function table_names(): array {
		global $wpdb;

		$prefix = $wpdb->prefix . 'ticketoo_';

		return array(
			'tickets'     => $prefix . 'tickets',
			'messages'    => $prefix . 'messages',
			'attachments' => $prefix . 'attachments',
		);
	}
}
