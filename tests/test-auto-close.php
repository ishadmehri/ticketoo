<?php
/**
 * Tests for the daily auto-close sweep and its cron wiring (spec 7).
 *
 * Cron assertions use WordPress's own scheduling functions
 * (wp_next_scheduled / wp_get_scheduled_event / wp_clear_scheduled_hook);
 * sweep behaviour is exercised against real, backdated ticket rows, with
 * every wp_mail() call captured through the pre_wp_mail short-circuit so
 * Notifier::on_auto_closed() can be observed without sending.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Activator;
use Ticketoo\Admin\SettingsPage;
use Ticketoo\AutoClose;
use Ticketoo\Database\MessageRepository;
use Ticketoo\Database\TicketRepository;

class Test_AutoClose extends Ticketoo_Database_TestCase {

	/**
	 * Emails captured through pre_wp_mail, in send order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = array();

	/**
	 * Recreates the plugin tables and starts capturing outgoing mail.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Activator::activate();

		add_filter( 'pre_wp_mail', array( $this, 'record_mail' ), 10, 2 );
	}

	/**
	 * Stops capturing before the shared fixtures are wiped.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'record_mail' ), 10 );

		parent::tear_down();
	}

	/**
	 * pre_wp_mail listener: records one wp_mail() call and short-circuits it.
	 *
	 * @param mixed                $short_circuit Value wp_mail() would return.
	 * @param array<string, mixed> $atts          wp_mail() arguments.
	 * @return bool True so nothing is actually sent.
	 */
	public function record_mail( $short_circuit, $atts ): bool {
		$this->mails[] = $atts;

		return true;
	}

	public function test_activation_schedules_daily_event(): void {
		wp_clear_scheduled_hook( AutoClose::HOOK );
		$this->assertFalse(
			wp_next_scheduled( AutoClose::HOOK ),
			'Precondition: no sweep event is scheduled.'
		);

		Activator::activate();

		$event = wp_get_scheduled_event( AutoClose::HOOK );

		$this->assertNotNull( $event, 'Activation must schedule the sweep event.' );
		$this->assertSame( 'daily', $event->schedule, 'The sweep must recur daily (spec 7).' );

		$this->assertNotFalse(
			has_action( AutoClose::HOOK, array( AutoClose::class, 'sweep' ) ),
			'register_hooks() must hook sweep() to the cron event.'
		);
		$this->assertNotFalse(
			has_action( 'init', array( AutoClose::class, 'maybe_schedule' ) ),
			'register_hooks() must hook the wp_next_scheduled() re-schedule guard onto init.'
		);

		wp_clear_scheduled_hook( AutoClose::HOOK );

		call_user_func( array( AutoClose::class, 'maybe_schedule' ) );

		$this->assertNotFalse(
			wp_next_scheduled( AutoClose::HOOK ),
			'The init guard must re-schedule a missing sweep event.'
		);
	}

	public function test_sweep_closes_stale_open_ticket(): void {
		update_option( SettingsPage::OPTION_AUTO_CLOSE_DAYS, 14 );

		$ticket_id = TicketRepository::create(
			array(
				'subject' => 'Stale printer thread',
				'user_id' => 0,
				'email'   => 'owner@example.org',
			)
		);

		$this->backdate( $ticket_id, 20 );

		$status_changes = array();
		$capture        = static function ( int $id, string $old, string $new ) use ( &$status_changes ): void {
			$status_changes[] = array( $id, $old, $new );
		};

		add_action( 'ticketoo_ticket_status_changed', $capture, 10, 3 );

		$closed = AutoClose::sweep();

		remove_action( 'ticketoo_ticket_status_changed', $capture, 10 );

		$this->assertSame( 1, $closed, 'A 20-day-stale open ticket must be closed at a 14-day threshold.' );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'closed', $ticket->status );

		$messages = MessageRepository::for_ticket( $ticket_id );
		$this->assertCount( 1, $messages['items'], 'The closure must leave a system message in the thread.' );

		$system = $messages['items'][0];
		$this->assertSame( 2, $system->is_agent, 'The system message must carry is_agent = 2 (spec 7).' );
		$this->assertNotSame( '', trim( $system->content ), 'The system message must have content.' );

		$this->assertCount( 1, $this->mails, 'The owner must be emailed about the auto-close (spec 7).' );
		$this->assertSame( array( 'owner@example.org' ), (array) $this->mails[0]['to'] );

		$this->assertSame(
			array( array( $ticket_id, 'open', 'closed' ) ),
			$status_changes,
			'ticketoo_ticket_status_changed must fire after every status change (spec 7).'
		);
	}

	public function test_sweep_closes_stale_pending_ticket(): void {
		update_option( SettingsPage::OPTION_AUTO_CLOSE_DAYS, 14 );

		$ticket_id = TicketRepository::create(
			array(
				'subject' => 'Stale pending thread',
				'user_id' => 0,
				'email'   => 'owner@example.org',
				'status'  => 'pending',
			)
		);

		$this->backdate( $ticket_id, 20 );

		$this->assertSame( 1, AutoClose::sweep(), 'Pending tickets are swept alongside open ones (spec 7).' );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'closed', $ticket->status );
	}

	public function test_sweep_ignores_answered(): void {
		update_option( SettingsPage::OPTION_AUTO_CLOSE_DAYS, 14 );

		$answered = TicketRepository::create(
			array(
				'subject' => 'Answered thread',
				'user_id' => 0,
				'email'   => 'owner@example.org',
				'status'  => 'answered',
			)
		);

		$already_closed = TicketRepository::create(
			array(
				'subject' => 'Closed thread',
				'user_id' => 0,
				'email'   => 'owner@example.org',
				'status'  => 'closed',
			)
		);

		$this->backdate( $answered, 20 );
		$this->backdate( $already_closed, 20 );

		$this->assertSame( 0, AutoClose::sweep(), 'Only open/pending tickets are swept (spec 7).' );

		$answered_ticket = TicketRepository::find( $answered );
		$this->assertNotNull( $answered_ticket );
		$this->assertSame( 'answered', $answered_ticket->status, 'Answered tickets must stay untouched.' );

		$closed_ticket = TicketRepository::find( $already_closed );
		$this->assertNotNull( $closed_ticket );
		$this->assertSame( 'closed', $closed_ticket->status, 'Closed tickets must stay untouched.' );

		$this->assertCount( 0, MessageRepository::for_ticket( $answered )['items'], 'No system message on untouched tickets.' );
		$this->assertCount( 0, MessageRepository::for_ticket( $already_closed )['items'], 'No system message on untouched tickets.' );
		$this->assertCount( 0, $this->mails, 'No auto-close email for tickets that were not swept.' );
	}

	public function test_sweep_skipped_when_disabled(): void {
		update_option( SettingsPage::OPTION_AUTO_CLOSE_DAYS, 0 );

		$ticket_id = TicketRepository::create(
			array(
				'subject' => 'Stale but feature off',
				'user_id' => 0,
				'email'   => 'owner@example.org',
			)
		);

		$this->backdate( $ticket_id, 20 );

		$this->assertSame( 0, AutoClose::sweep(), 'ticketoo_auto_close_days = 0 disables the sweep (spec 7/10).' );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'open', $ticket->status );

		$this->assertCount( 0, MessageRepository::for_ticket( $ticket_id )['items'] );
		$this->assertCount( 0, $this->mails );
	}

	public function test_veto_filter_prevents_close(): void {
		update_option( SettingsPage::OPTION_AUTO_CLOSE_DAYS, 14 );

		$ticket_id = TicketRepository::create(
			array(
				'subject' => 'Vetoed thread',
				'user_id' => 0,
				'email'   => 'owner@example.org',
			)
		);

		$this->backdate( $ticket_id, 20 );

		add_filter( 'ticketoo_auto_close_ticket', 'ticketoo_test_veto_auto_close' );

		$closed = AutoClose::sweep();

		remove_filter( 'ticketoo_auto_close_ticket', 'ticketoo_test_veto_auto_close' );

		$this->assertSame( 0, $closed, 'Returning false from ticketoo_auto_close_ticket must veto the closure.' );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'open', $ticket->status );

		$this->assertCount( 0, MessageRepository::for_ticket( $ticket_id )['items'] );
		$this->assertCount( 0, $this->mails );
	}

	public function test_recent_activity_spares_ticket(): void {
		update_option( SettingsPage::OPTION_AUTO_CLOSE_DAYS, 14 );

		$ticket_id = TicketRepository::create(
			array(
				'subject' => 'Recently active thread',
				'user_id' => 0,
				'email'   => 'owner@example.org',
			)
		);

		$this->backdate( $ticket_id, 5 );

		$this->assertSame( 0, AutoClose::sweep(), 'Activity inside the window must restart it (spec 7).' );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'open', $ticket->status );

		$this->assertCount( 0, MessageRepository::for_ticket( $ticket_id )['items'] );
		$this->assertCount( 0, $this->mails );
	}

	/**
	 * Rewrites a ticket's last_activity_at to $days days ago.
	 *
	 * @param int $ticket_id Ticket id.
	 * @param int $days      Age in days.
	 * @return void
	 */
	private function backdate( int $ticket_id, int $days ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture: rewind the activity clock the same way current_time() stamps it.
		$wpdb->update(
			Activator::table_names()['tickets'],
			array(
				'last_activity_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ),
			),
			array( 'id' => $ticket_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}

/**
 * ticketoo_auto_close_ticket veto callback for test_veto_filter_prevents_close.
 *
 * A named function keeps the filter removable by identity and documents the
 * brief's callback contract: int $ticket_id in, bool out, false vetoes.
 *
 * @param int $ticket_id Ticket id being considered.
 * @return bool False vetoes the closure.
 */
function ticketoo_test_veto_auto_close( int $ticket_id ): bool {
	return false;
}
