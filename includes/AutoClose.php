<?php
/**
 * Daily auto-close sweep: closes stale tickets on WP-Cron (spec 7).
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo;

use Ticketoo\Admin\SettingsPage;
use Ticketoo\Database\MessageRepository;
use Ticketoo\Database\TicketRepository;
use Ticketoo\Email\Notifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Closes open/pending tickets whose last activity has aged past the
 * configured window. The event is scheduled on activation and re-checked
 * on init with wp_next_scheduled(); the sweep is a no-op while
 * ticketoo_auto_close_days is 0.
 */
class AutoClose {

	/**
	 * Cron event name of the daily sweep.
	 *
	 * @var string
	 */
	public const HOOK = 'ticketoo_auto_close_sweep';

	/**
	 * Registers the sweep callback and the re-schedule guard.
	 *
	 * Called from Plugin::boot(): sweep() runs on the daily cron event,
	 * maybe_schedule() re-registers a missing event on init (spec 7:
	 * "registered on activation and re-checked with wp_next_scheduled").
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( self::HOOK, array( self::class, 'sweep' ) );
		add_action( 'init', array( self::class, 'maybe_schedule' ) );
	}

	/**
	 * Schedules the daily sweep when no event is pending.
	 *
	 * Idempotent: wp_next_scheduled() short-circuits the call, so neither
	 * repeated init fires nor re-activations stack duplicate events.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function maybe_schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), 'daily', self::HOOK );
	}

	/**
	 * Closes every open/pending ticket idle for longer than the configured
	 * window and returns the number of tickets closed.
	 *
	 * Per closure (spec 7): the ticketoo_auto_close_ticket veto filter, a
	 * system message (is_agent = 2, content filtered through
	 * ticketoo_auto_close_message), the status change to closed with the
	 * matching ticketoo_ticket_status_changed action, then the owner email
	 * via Notifier::on_auto_closed(). The whole sweep is skipped while
	 * ticketoo_auto_close_days is 0.
	 *
	 * @since 0.1.0
	 * @return int Number of tickets closed.
	 */
	public static function sweep(): int {
		$days = SettingsPage::get_auto_close_days();

		if ( $days <= 0 ) {
			return 0;
		}

		$closed = 0;

		foreach ( self::stale_rows( $days ) as $row ) {
			$ticket_id = (int) $row->id;

			/**
			 * Filters whether the sweep may close a ticket.
			 *
			 * @since 0.1.0
			 * @param int $ticket_id Ticket id under consideration.
			 * @return bool False vetoes the closure.
			 */
			$close = apply_filters( 'ticketoo_auto_close_ticket', $ticket_id );

			if ( ! $close ) {
				continue;
			}

			/**
			 * Filters the system message stored when a ticket auto-closes.
			 *
			 * @since 0.1.0
			 * @param string $content   Message body.
			 * @param int    $ticket_id Ticket id being closed.
			 */
			$content = (string) apply_filters(
				'ticketoo_auto_close_message',
				self::default_message( $days ),
				$ticket_id
			);

			$message_id = MessageRepository::add( $ticket_id, 0, '', 2, $content );

			if ( 0 === $message_id || ! TicketRepository::update_status( $ticket_id, 'closed' ) ) {
				continue;
			}

			/**
			 * Fires after a ticket's status changed.
			 *
			 * @since 0.1.0
			 * @param int    $ticket_id Ticket id.
			 * @param string $old_status Status slug before the change.
			 * @param string $new_status Status slug after the change.
			 */
			do_action( 'ticketoo_ticket_status_changed', $ticket_id, (string) $row->status, 'closed' );

			Notifier::on_auto_closed( $ticket_id );

			++$closed;
		}

		return $closed;
	}

	/**
	 * Rows (id and current status) of every open/pending ticket idle for
	 * more than $days days.
	 *
	 * The cutoff is computed from current_time( 'mysql' ) — the same clock
	 * the repositories stamp last_activity_at with — so it stays correct
	 * on sites whose timezone differs from the database server's.
	 *
	 * @param int $days Idle threshold in days.
	 * @return object[] Rows exposing ->id and ->status.
	 */
	private static function stale_rows( int $days ): array {
		global $wpdb;

		$table = Activator::table_names()['tickets'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The sweep is an uncached maintenance query; the tickets table has no object-cache layer.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status FROM {$table} WHERE status IN ('open', 'pending') AND last_activity_at < DATE_SUB(%s, INTERVAL %d DAY) ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from Activator::table_names(); the cutoff and the day count travel as prepared placeholders.
				current_time( 'mysql' ),
				$days
			)
		);

		return (array) $rows;
	}

	/**
	 * Default content of the auto-close system message.
	 *
	 * @param int $days Configured idle window in days.
	 * @return string Translatable message body.
	 */
	private static function default_message( int $days ): string {
		return sprintf(
			/* translators: %d: number of days of inactivity before a ticket is auto-closed. */
			__( 'This ticket was automatically closed after %d days of inactivity.', 'ticketoo' ),
			$days
		);
	}
}
