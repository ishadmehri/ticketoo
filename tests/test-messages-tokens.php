<?php
/**
 * Tests for the Message model, MessageRepository and TokenAccess.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Activator;
use Ticketoo\Database\MessageRepository;
use Ticketoo\Database\TicketRepository;
use Ticketoo\Guest\TokenAccess;
use Ticketoo\Model\Message;

class Test_Messages_Tokens extends Ticketoo_Database_TestCase {

	/**
	 * Recreates the plugin's tables: tear_down() drops them after every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Activator::activate();
	}

	public function test_add_message_persists_and_touches_ticket(): void {
		global $wpdb;

		$ticket_id = TicketRepository::create( $this->ticket_data() );

		// Rewind both timestamps so the assertions prove add() writes them.
		$wpdb->update(
			Activator::table_names()['tickets'],
			array(
				'last_activity_at' => '2000-01-01 00:00:00',
				'updated_at'       => '2000-01-01 00:00:00',
			),
			array( 'id' => $ticket_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$message_id = MessageRepository::add( $ticket_id, 42, 'member@example.com', 0, 'The login page is broken.' );

		$this->assertGreaterThan( 0, $message_id );

		$result = MessageRepository::for_ticket( $ticket_id );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['items'] );

		$message = $result['items'][0];
		$this->assertInstanceOf( Message::class, $message );
		$this->assertSame( $message_id, $message->id );
		$this->assertSame( $ticket_id, $message->ticket_id );
		$this->assertSame( 42, $message->user_id );
		$this->assertSame( 'member@example.com', $message->email );
		$this->assertSame( 0, $message->is_agent );
		$this->assertSame( 'The login page is broken.', $message->content );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$message->created_at,
			'created_at must hold a MySQL datetime.'
		);

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertGreaterThan(
			'2000-01-01 00:00:00',
			$ticket->last_activity_at,
			'add() must update the ticket last_activity_at.'
		);
		$this->assertGreaterThan(
			'2000-01-01 00:00:00',
			$ticket->updated_at,
			'add() must update the ticket updated_at.'
		);
	}

	public function test_for_ticket_orders_asc_and_paginates(): void {
		global $wpdb;

		$ticket_id = TicketRepository::create( $this->ticket_data() );
		$other_id  = TicketRepository::create( $this->ticket_data( array( 'subject' => 'Other ticket' ) ) );

		MessageRepository::add( $other_id, 7, 'other@example.com', 0, 'Belongs to another ticket.' );

		$ids = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$ids[ $i ] = MessageRepository::add( $ticket_id, 42, 'member@example.com', 0, sprintf( 'Message %02d', $i ) );
		}

		$messages = Activator::table_names()['messages'];

		// Send the first-inserted message to the end and the last-inserted to
		// the start, so the assertions prove created_at ordering (not id order).
		$wpdb->update(
			$messages,
			array( 'created_at' => '2030-01-01 00:00:00' ),
			array( 'id' => $ids[1] ),
			array( '%s' ),
			array( '%d' )
		);
		$wpdb->update(
			$messages,
			array( 'created_at' => '2000-01-01 00:00:00' ),
			array( 'id' => $ids[25] ),
			array( '%s' ),
			array( '%d' )
		);

		$first = MessageRepository::for_ticket( $ticket_id, 1, 20 );
		$this->assertSame( 25, $first['total'], 'total must count every message of the ticket, unpaginated.' );
		$this->assertCount( 20, $first['items'] );
		$this->assertSame(
			$ids[25],
			$first['items'][0]->id,
			'The oldest created_at must come first regardless of insertion order.'
		);
		$this->assertSame( $ids[20], $first['items'][19]->id );

		$second = MessageRepository::for_ticket( $ticket_id, 2, 20 );
		$this->assertSame( 25, $second['total'] );
		$this->assertCount( 5, $second['items'] );
		$this->assertSame(
			$ids[1],
			$second['items'][4]->id,
			'The newest created_at must come last.'
		);

		$ids_in_order = array_merge(
			array_map( static fn ( Message $message ): int => $message->id, $first['items'] ),
			array_map( static fn ( Message $message ): int => $message->id, $second['items'] )
		);
		$this->assertCount( 25, array_unique( $ids_in_order ), 'Pages must cover all 25 messages exactly once.' );

		$foreign = MessageRepository::for_ticket( $other_id );
		$this->assertSame( 1, $foreign['total'], 'Messages must never leak across tickets.' );
		$this->assertCount( 1, $foreign['items'] );
	}

	public function test_issue_returns_64_hex(): void {
		$token = TokenAccess::issue();

		$this->assertSame( 64, strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );
		$this->assertNotSame( $token, TokenAccess::issue(), 'Two issued tokens must differ.' );
	}

	public function test_verify_rejects_wrong_token(): void {
		$stored = TokenAccess::issue();
		$wrong  = ( '0' === $stored[0] ? '1' : '0' ) . substr( $stored, 1 );

		$this->assertTrue( TokenAccess::verify( $stored, $stored ), 'The exact token must verify.' );
		$this->assertFalse( TokenAccess::verify( $wrong, $stored ), 'A single flipped character must fail.' );
		$this->assertFalse( TokenAccess::verify( 'nope', $stored ), 'A short token must fail.' );
	}

	public function test_verify_rejects_null_stored(): void {
		$this->assertFalse( TokenAccess::verify( TokenAccess::issue(), null ) );
		$this->assertFalse( TokenAccess::verify( '', '' ), 'An empty stored value must never verify.' );
	}

	/**
	 * Create payload with the keys TicketRepository::create documents.
	 *
	 * @param array $overrides Replacement values for a single ticket.
	 * @return array
	 */
	private function ticket_data( array $overrides = array() ): array {
		return array_merge(
			array(
				'subject'     => 'Sample ticket',
				'user_id'     => 42,
				'email'       => 'member@example.com',
				'guest_token' => null,
			),
			$overrides
		);
	}
}
