<?php
/**
 * Tests for the Ticket model and TicketRepository.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Activator;
use Ticketoo\Database\TicketRepository;

class Test_Ticket_Repository extends Ticketoo_Database_TestCase {

	/**
	 * Recreates the plugin's tables: tear_down() drops them after every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Activator::activate();
	}

	public function test_create_returns_id_with_defaults(): void {
		$id = TicketRepository::create(
			$this->ticket_data(
				array(
					'subject' => 'Login broken',
					'user_id' => 42,
					'email'   => 'member@example.com',
				)
			)
		);

		$this->assertGreaterThan( 0, $id );

		$ticket = TicketRepository::find( $id );
		$this->assertNotNull( $ticket, 'create() must store a row that find() can read back.' );
		$this->assertSame( $id, $ticket->id );
		$this->assertSame( 'Login broken', $ticket->subject );
		$this->assertSame( 'open', $ticket->status );
		$this->assertSame( 42, $ticket->user_id );
		$this->assertSame( 'member@example.com', $ticket->email );
		$this->assertNull( $ticket->guest_token );
		$this->assertNull( $ticket->assigned_to );

		foreach ( array( 'created_at', 'updated_at', 'last_activity_at' ) as $column ) {
			$this->assertMatchesRegularExpression(
				'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
				$ticket->$column,
				"{$column} must hold a MySQL datetime."
			);
		}

		$this->assertSame( $ticket->created_at, $ticket->updated_at );
		$this->assertSame( $ticket->created_at, $ticket->last_activity_at );
	}

	public function test_create_persists_guest_token(): void {
		$token = str_repeat( 'a1', 32 );

		$id = TicketRepository::create( $this->ticket_data( array( 'guest_token' => $token ) ) );

		$ticket = TicketRepository::find( $id );
		$this->assertNotNull( $ticket );
		$this->assertSame( $token, $ticket->guest_token );
	}

	public function test_find_unknown_returns_null(): void {
		$this->assertNull( TicketRepository::find( 99999 ) );
	}

	public function test_list_filters_by_status(): void {
		$open_id   = TicketRepository::create( $this->ticket_data( array( 'subject' => 'Open ticket' ) ) );
		$closed_id = TicketRepository::create(
			$this->ticket_data(
				array(
					'subject' => 'Closed ticket',
					'status'  => 'closed',
				)
			)
		);

		$closed = TicketRepository::list( array( 'status' => 'closed' ) );
		$this->assertSame( 1, $closed['total'] );
		$this->assertCount( 1, $closed['items'] );
		$this->assertSame( $closed_id, $closed['items'][0]->id );
		$this->assertSame( 'closed', $closed['items'][0]->status );

		$open = TicketRepository::list( array( 'status' => 'open' ) );
		$this->assertSame( 1, $open['total'] );
		$this->assertCount( 1, $open['items'] );
		$this->assertSame( $open_id, $open['items'][0]->id );
	}

	public function test_list_search_matches_subject(): void {
		TicketRepository::create( $this->ticket_data( array( 'subject' => 'Payment issue' ) ) );
		TicketRepository::create( $this->ticket_data( array( 'subject' => 'Login broken' ) ) );

		$result = TicketRepository::list( array( 'q' => 'pay' ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'Payment issue', $result['items'][0]->subject );
	}

	public function test_list_search_escapes_like_wildcards(): void {
		TicketRepository::create( $this->ticket_data( array( 'subject' => 'Progress 100% done' ) ) );
		TicketRepository::create( $this->ticket_data( array( 'subject' => 'No wildcard here' ) ) );

		$result = TicketRepository::list( array( 'q' => '%' ) );

		$this->assertSame( 1, $result['total'], 'A % in q must match literally, not as a wildcard.' );
		$this->assertSame( 'Progress 100% done', $result['items'][0]->subject );
	}

	public function test_list_paginates_and_reports_total(): void {
		for ( $i = 1; $i <= 25; $i++ ) {
			TicketRepository::create(
				$this->ticket_data( array( 'subject' => sprintf( 'Bulk ticket %02d', $i ) ) )
			);
		}

		$first = TicketRepository::list( array( 'page' => 1, 'per_page' => 20 ) );
		$this->assertSame( 25, $first['total'] );
		$this->assertCount( 20, $first['items'] );

		$second = TicketRepository::list( array( 'page' => 2, 'per_page' => 20 ) );
		$this->assertSame( 25, $second['total'] );
		$this->assertCount( 5, $second['items'] );

		$ids = array_merge(
			array_map( static fn ( $ticket ): int => $ticket->id, $first['items'] ),
			array_map( static fn ( $ticket ): int => $ticket->id, $second['items'] )
		);
		$this->assertCount( 25, array_unique( $ids ), 'Pages must cover all 25 tickets exactly once.' );
	}

	public function test_list_filters_by_user_id(): void {
		$mine   = TicketRepository::create( $this->ticket_data( array( 'user_id' => 7 ) ) );
		$theirs = TicketRepository::create( $this->ticket_data( array( 'user_id' => 8 ) ) );

		$result = TicketRepository::list( array( 'user_id' => 7 ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $mine, $result['items'][0]->id );
		$this->assertSame( 7, $result['items'][0]->user_id );
		$this->assertNotSame( $theirs, $result['items'][0]->id );
	}

	public function test_list_filters_by_assigned_to(): void {
		global $wpdb;

		$assigned = TicketRepository::create( $this->ticket_data( array( 'subject' => 'Assigned ticket' ) ) );
		TicketRepository::create( $this->ticket_data( array( 'subject' => 'Unassigned ticket' ) ) );

		$wpdb->update(
			Activator::table_names()['tickets'],
			array( 'assigned_to' => 9 ),
			array( 'id' => $assigned ),
			array( '%d' ),
			array( '%d' )
		);

		$result = TicketRepository::list( array( 'assigned_to' => 9 ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $assigned, $result['items'][0]->id );

		$none = TicketRepository::list( array( 'assigned_to' => 10 ) );
		$this->assertSame( 0, $none['total'] );
		$this->assertSame( array(), $none['items'] );

		$unfiltered = TicketRepository::list( array() );
		$this->assertSame( 2, $unfiltered['total'], 'No filters must return every ticket.' );
	}

	public function test_update_status_persists(): void {
		global $wpdb;

		$id = TicketRepository::create( $this->ticket_data() );

		$created = TicketRepository::find( $id );
		$this->assertNotNull( $created );
		$this->assertSame( 'open', $created->status );

		// Rewind updated_at so the assertion proves update_status() writes it.
		$wpdb->update(
			Activator::table_names()['tickets'],
			array( 'updated_at' => '2000-01-01 00:00:00' ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertTrue( TicketRepository::update_status( $id, 'closed' ) );

		$ticket = TicketRepository::find( $id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'closed', $ticket->status );
		$this->assertGreaterThan( '2000-01-01 00:00:00', $ticket->updated_at );

		$this->assertFalse( TicketRepository::update_status( 99999, 'closed' ), 'Unknown ids must report false.' );
	}

	/**
	 * Create payload with the keys the repository documents.
	 *
	 * @param array $overrides Replacement values for a single ticket.
	 * @return array
	 */
	private function ticket_data( array $overrides = array() ): array {
		return array_merge(
			array(
				'subject'     => 'Sample ticket',
				'user_id'     => 0,
				'email'       => 'guest@example.com',
				'guest_token' => null,
			),
			$overrides
		);
	}
}
