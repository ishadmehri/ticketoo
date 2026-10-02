<?php
/**
 * Tests for the public REST endpoints: create, list, read and reply.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Activator;
use Ticketoo\Admin\Capabilities;
use Ticketoo\Database\MessageRepository;
use Ticketoo\Database\TicketRepository;
use Ticketoo\Guest\TokenAccess;

class Test_Rest_Tickets extends Ticketoo_Database_TestCase {

	/**
	 * Recreates the plugin's tables: tear_down() drops them after every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Activator::activate();
	}

	public function test_guest_can_create_ticket_when_allowed(): void {
		update_option( 'ticketoo_allow_guests', 1 );
		wp_set_current_user( 0 );

		$response = $this->dispatch(
			'POST',
			'/ticketoo/v1/tickets',
			array(),
			array(
				'subject' => 'Printer on fire',
				'content' => 'It is making noises.',
				'name'    => 'Guesty',
				'email'   => 'guest@example.com',
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'url', $data );

		$ticket = TicketRepository::find( (int) $data['id'] );
		$this->assertNotNull( $ticket );
		$this->assertSame( 0, $ticket->user_id, 'A guest ticket must be owned by user 0.' );
		$this->assertSame( 'guest@example.com', $ticket->email );
		$this->assertNotNull( $ticket->guest_token );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $ticket->guest_token );

		$messages = MessageRepository::for_ticket( $ticket->id );
		$this->assertSame( 1, $messages['total'], 'The first message must be stored with the ticket.' );
		$this->assertSame( 'It is making noises.', $messages['items'][0]->content );
		$this->assertSame( 0, $messages['items'][0]->user_id );

		$this->assertStringNotContainsString(
			(string) $ticket->guest_token,
			(string) wp_json_encode( $data ),
			'The guest token must never appear in a REST response.'
		);
	}

	public function test_guest_create_blocked_when_disabled(): void {
		update_option( 'ticketoo_allow_guests', 0 );
		wp_set_current_user( 0 );

		$response = $this->dispatch(
			'POST',
			'/ticketoo/v1/tickets',
			array(),
			array(
				'subject' => 'Should not land',
				'content' => 'Guests are disabled.',
				'email'   => 'guest@example.com',
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ticketoo_rest_guests_disabled', $response->get_data()['code'] );

		$listed = TicketRepository::list( array() );
		$this->assertSame( 0, $listed['total'], 'A blocked request must not create a ticket.' );
	}

	public function test_list_scope_mine_returns_only_own(): void {
		$user_a = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user_b = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$ticket_a = $this->create_ticket(
			array(
				'subject' => 'Ticket of A',
				'user_id' => $user_a,
				'email'   => 'a@example.com',
			)
		);
		$this->create_ticket(
			array(
				'subject' => 'Ticket of B',
				'user_id' => $user_b,
				'email'   => 'b@example.com',
			)
		);

		wp_set_current_user( $user_a );

		$response = $this->dispatch( 'GET', '/ticketoo/v1/tickets', array( 'scope' => 'mine' ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'page', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'total_pages', $data );

		$this->assertCount( 1, $data['items'], 'scope=mine must only return the current user\'s tickets.' );
		$this->assertSame( $ticket_a, $data['items'][0]['id'] );
		$this->assertSame( $user_a, $data['items'][0]['user']['id'] );
		$this->assertSame( 'Ticket of A', $data['items'][0]['subject'] );
		$this->assertSame( 'open', $data['items'][0]['status'] );
		$this->assertNull( $data['items'][0]['assigned_to'] );
		$this->assertArrayHasKey( 'last_activity_at', $data['items'][0] );
		$this->assertArrayHasKey( 'created_at', $data['items'][0] );
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 1, $data['total_pages'] );
	}

	public function test_list_scope_all_requires_capability(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 's@example.com',
			)
		);

		wp_set_current_user( $user );

		$response = $this->dispatch( 'GET', '/ticketoo/v1/tickets', array( 'scope' => 'all' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ticketoo_rest_forbidden', $response->get_data()['code'] );
	}

	public function test_list_requires_authentication(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch( 'GET', '/ticketoo/v1/tickets' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'ticketoo_rest_authentication_required', $response->get_data()['code'] );
	}

	public function test_agent_list_scope_all_returns_all_tickets(): void {
		$user_a   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user_b   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$agent_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE ) );

		$this->create_ticket(
			array(
				'user_id' => $user_a,
				'email'   => 'a@example.com',
			)
		);
		$this->create_ticket(
			array(
				'user_id' => $user_b,
				'email'   => 'b@example.com',
			)
		);

		wp_set_current_user( $agent_id );

		$response = $this->dispatch( 'GET', '/ticketoo/v1/tickets', array( 'scope' => 'all' ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertCount( 2, $data['items'], 'An agent with scope=all must see every ticket.' );
		$this->assertSame( 2, $data['total'] );
	}

	public function test_list_caps_per_page_at_100(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		for ( $i = 1; $i <= 101; $i++ ) {
			$this->create_ticket(
				array(
					'subject' => sprintf( 'Ticket %03d', $i ),
					'user_id' => $user,
					'email'   => 'p@example.com',
				)
			);
		}

		wp_set_current_user( $user );

		$response = $this->dispatch(
			'GET',
			'/ticketoo/v1/tickets',
			array(
				'per_page' => 500,
				'page'     => 2,
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 101, $data['total'] );
		$this->assertSame(
			2,
			$data['total_pages'],
			'per_page must be capped at 100 before the repository call (101 / 100 = 2 pages).'
		);
		$this->assertCount( 1, $data['items'], 'Page 2 of a 101-item set capped at 100 per page has one item.' );
	}

	public function test_user_cannot_read_foreign_ticket(): void {
		$user_a = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user_b = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $user_a,
				'email'   => 'a@example.com',
			)
		);

		wp_set_current_user( $user_b );

		$response = $this->dispatch( 'GET', sprintf( '/ticketoo/v1/tickets/%d', $ticket_id ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame(
			'ticketoo_rest_ticket_not_found',
			$response->get_data()['code'],
			'A foreign ticket must look missing, not merely forbidden.'
		);
	}

	public function test_guest_wrong_token_is_forbidden(): void {
		$ticket_id = $this->create_ticket(
			array(
				'guest_token' => TokenAccess::issue(),
			)
		);

		wp_set_current_user( 0 );

		$response = $this->dispatch(
			'GET',
			sprintf( '/ticketoo/v1/tickets/%d', $ticket_id ),
			array( 'token' => 'nope' )
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ticketoo_rest_forbidden', $response->get_data()['code'] );
	}

	public function test_guest_valid_token_reads_ticket(): void {
		$token     = TokenAccess::issue();
		$ticket_id = $this->create_ticket(
			array(
				'guest_token' => $token,
				'subject'     => 'Token protected',
			)
		);

		MessageRepository::add(
			$ticket_id,
			0,
			'guest@example.com',
			0,
			'<b>hello</b><script>alert(1)</script>'
		);

		wp_set_current_user( 0 );

		$response = $this->dispatch(
			'GET',
			sprintf( '/ticketoo/v1/tickets/%d', $ticket_id ),
			array( 'token' => $token )
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( $ticket_id, $data['id'] );
		$this->assertSame( 'Token protected', $data['subject'] );
		$this->assertSame( 'open', $data['status'] );
		$this->assertTrue( $data['can_close'] );
		$this->assertArrayHasKey( 'messages', $data );
		$this->assertArrayHasKey( 'attachments', $data );
		$this->assertCount( 1, $data['messages'] );
		$this->assertArrayNotHasKey( 'guest_token', $data );

		$content = $data['messages'][0]['content'];
		$this->assertStringContainsString( '<b>hello</b>', $content );
		$this->assertStringNotContainsString( '<script', $content, 'Message content must pass through wp_kses_post.' );
	}

	public function test_reply_creates_message_and_touches_ticket(): void {
		global $wpdb;

		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 'member@example.com',
			)
		);

		// Rewind the timestamp so the assertion proves the reply touched it.
		$wpdb->update(
			Activator::table_names()['tickets'],
			array( 'last_activity_at' => '2000-01-01 00:00:00' ),
			array( 'id' => $ticket_id ),
			array( '%s' ),
			array( '%d' )
		);

		wp_set_current_user( $user );

		$response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/messages', $ticket_id ),
			array(),
			array( 'content' => 'Any update on this?' )
		);

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertSame( $ticket_id, $data['ticket_id'] );

		$messages = MessageRepository::for_ticket( $ticket_id );
		$this->assertSame( 1, $messages['total'] );
		$message = $messages['items'][0];
		$this->assertSame( 'Any update on this?', $message->content );
		$this->assertSame( $user, $message->user_id );
		$this->assertSame( 0, $message->is_agent );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertGreaterThan(
			'2000-01-01 00:00:00',
			$ticket->last_activity_at,
			'A reply must touch the ticket last_activity_at.'
		);
	}

	public function test_user_cannot_reply_to_foreign_ticket(): void {
		$user_a = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user_b = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $user_a,
				'email'   => 'a@example.com',
			)
		);

		wp_set_current_user( $user_b );

		$response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/messages', $ticket_id ),
			array(),
			array( 'content' => 'Let me in.' )
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'ticketoo_rest_ticket_not_found', $response->get_data()['code'] );

		$messages = MessageRepository::for_ticket( $ticket_id );
		$this->assertSame( 0, $messages['total'], 'A rejected reply must not be stored.' );
	}

	public function test_ticket_fields_filter_applied(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 'f@example.com',
			)
		);

		wp_set_current_user( $user );

		$filter = static fn ( array $fields ): array => array( 'subject' => 'X' );
		add_filter( 'ticketoo_ticket_fields', $filter );

		$response = $this->dispatch( 'GET', '/ticketoo/v1/tickets' );

		remove_filter( 'ticketoo_ticket_fields', $filter );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame(
			array( array( 'subject' => 'X' ) ),
			$data['items'],
			'The ticketoo_ticket_fields filter must fully shape the ticket payload it returns.'
		);
	}

	public function test_create_rejects_empty_subject(): void {
		update_option( 'ticketoo_allow_guests', 1 );
		wp_set_current_user( 0 );

		$response = $this->dispatch(
			'POST',
			'/ticketoo/v1/tickets',
			array(),
			array(
				'subject' => '   ',
				'content' => 'Body without a subject.',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'ticketoo_rest_invalid_subject', $response->get_data()['code'] );
		$this->assertSame( 0, TicketRepository::list( array() )['total'] );
	}

	public function test_create_rejects_invalid_email(): void {
		update_option( 'ticketoo_allow_guests', 1 );
		wp_set_current_user( 0 );

		$response = $this->dispatch(
			'POST',
			'/ticketoo/v1/tickets',
			array(),
			array(
				'subject' => 'Contact details',
				'content' => 'Here is my message.',
				'email'   => 'not-an-email',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'ticketoo_rest_invalid_email', $response->get_data()['code'] );
		$this->assertSame( 0, TicketRepository::list( array() )['total'] );
	}

	public function test_logged_in_user_create_stores_account_email_and_no_token(): void {
		// Logged-in creation must work even while guest creation is blocked.
		update_option( 'ticketoo_allow_guests', 0 );

		$user = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'member@example.com',
			)
		);

		wp_set_current_user( $user );

		$response = $this->dispatch(
			'POST',
			'/ticketoo/v1/tickets',
			array(),
			array(
				'subject' => 'Logged-in ticket',
				'content' => 'Written from an account.',
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$ticket = TicketRepository::find( (int) $response->get_data()['id'] );
		$this->assertNotNull( $ticket );
		$this->assertSame( $user, $ticket->user_id );
		$this->assertSame( 'member@example.com', $ticket->email, 'An empty email must fall back to the account email.' );
		$this->assertNull( $ticket->guest_token, 'A logged-in ticket must never receive a guest token.' );
	}

	/**
	 * Dispatches a request through the REST server.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $route  Route path.
	 * @param array      $query  Query-string parameters (also used for tokens).
	 * @param array|null $json   JSON request body, or null when there is none.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, string $route, array $query = array(), ?array $json = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( array() !== $query ) {
			$request->set_query_params( $query );
		}

		if ( null !== $json ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $json ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Inserts a ticket row through the repository.
	 *
	 * @param array $overrides Replacement values for a single ticket.
	 * @return int Inserted ticket id.
	 */
	private function create_ticket( array $overrides = array() ): int {
		return TicketRepository::create(
			array_merge(
				array(
					'subject'     => 'Sample ticket',
					'user_id'     => 0,
					'email'       => 'guest@example.com',
					'guest_token' => null,
				),
				$overrides
			)
		);
	}
}
