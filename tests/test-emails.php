<?php
/**
 * Tests for the ticket email notifications (spec 7).
 *
 * Every send is captured through the pre_wp_mail short-circuit, so nothing
 * ever leaves the container and each assert can inspect the exact
 * wp_mail() arguments: to, subject, message and headers.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Activator;
use Ticketoo\Admin\Capabilities;
use Ticketoo\Database\TicketRepository;
use Ticketoo\Email\Notifier;
use Ticketoo\Guest\TokenAccess;

class Test_Emails extends Ticketoo_Database_TestCase {

	/**
	 * Emails captured through pre_wp_mail, in send order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = array();

	/**
	 * Recreates the plugin tables and pins the recipient set.
	 *
	 * The suite shares the dev database, whose pre-existing administrator
	 * would otherwise hold ticketoo_manage_tickets and leak into every
	 * "all agents" assertion; after the strip below the agents this test
	 * creates are the only capability holders.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Activator::activate();

		$administrator = get_role( 'administrator' );

		if ( null !== $administrator ) {
			$administrator->remove_cap( Capabilities::CAP );
		}

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
	 * @param mixed                  $short_circuit Value wp_mail() would return.
	 * @param array<string, mixed>   $atts          wp_mail() arguments.
	 * @return bool True so nothing is actually sent.
	 */
	public function record_mail( $short_circuit, $atts ): bool {
		$this->mails[] = $atts;

		return true;
	}

	public function test_new_ticket_notifies_agents(): void {
		update_option( 'ticketoo_allow_guests', 1 );
		update_option( 'ticketoo_from_name', 'Support Desk' );
		update_option( 'ticketoo_from_email', 'help@example.org' );

		$one = $this->create_agent( 'one@example.org' );
		$two = $this->create_agent( 'two@example.org' );

		wp_set_current_user( 0 );

		$response = $this->dispatch(
			'POST',
			'/ticketoo/v1/tickets',
			array(),
			array(
				'subject' => 'Printer on fire',
				'content' => 'It is making noises.',
				'name'    => 'Guesty',
				'email'   => 'guest@example.org',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$ticket_id = (int) $response->get_data()['id'];
		$ticket    = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );

		$mail = $this->mail_to( 'one@example.org' );

		$this->assertEqualsCanonicalizing(
			array( 'one@example.org', 'two@example.org' ),
			(array) $mail['to'],
			'The new-ticket email must reach every ticketoo_manage_tickets holder.'
		);
		$this->assertNotContains( 'guest@example.org', (array) $mail['to'] );
		$this->assertStringContainsString( '#' . $ticket_id, $mail['subject'] );
		$this->assertStringContainsString( 'Printer on fire', $mail['subject'] );

		// Spec 7: number, subject, excerpt and a direct link.
		$this->assertStringContainsString( '#' . $ticket_id, $mail['message'] );
		$this->assertStringContainsString( 'Printer on fire', $mail['message'] );
		$this->assertStringContainsString( 'noises', $mail['message'] );
		$this->assertStringContainsString( '?ticketoo_ticket=' . $ticket_id, $mail['message'] );

		// Agents must never receive the guest bearer token.
		$this->assertStringNotContainsString(
			(string) $ticket->guest_token,
			(string) $mail['message'],
			'The agent notification must not carry the guest token.'
		);

		$headers = implode( "\n", (array) $mail['headers'] );
		$this->assertStringContainsString( 'text/html', $headers, 'Emails must be sent as HTML.' );
		$this->assertStringContainsString(
			'From: Support Desk <help@example.org>',
			$headers,
			'The From header must come from the settings accessors.'
		);
	}

	public function test_guest_creation_email_contains_token_link(): void {
		update_option( 'ticketoo_allow_guests', 1 );

		$agent = $this->create_agent( 'agent@example.org' );

		wp_set_current_user( 0 );

		$response = $this->dispatch(
			'POST',
			'/ticketoo/v1/tickets',
			array(),
			array(
				'subject' => 'Guest issue',
				'content' => 'Please help me.',
				'name'    => 'Guesty',
				'email'   => 'guest@example.org',
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$ticket_id = (int) $response->get_data()['id'];
		$ticket    = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$token = (string) $ticket->guest_token;
		$this->assertNotSame( '', $token );

		// Spec 5: creation sends exactly two emails - agents, then the guest link.
		$this->assertCount( 2, $this->mails );

		$guest_mail = $this->mail_to( 'guest@example.org' );
		$this->assertStringContainsString(
			'ticketoo_ticket=' . $ticket_id . '&token=' . $token,
			(string) $guest_mail['message'],
			'The guest must receive the raw token link (their only way back to the ticket).'
		);

		$agent_mail = $this->mail_to( 'agent@example.org' );
		$this->assertStringNotContainsString(
			$token,
			(string) $agent_mail['message'],
			'The agent notification must not contain the guest token.'
		);
	}

	public function test_agent_reply_notifies_owner_with_token_link(): void {
		$this->create_agent( 'agent@example.org' );

		$token     = TokenAccess::issue();
		$ticket_id = TicketRepository::create(
			array(
				'subject'     => 'Guest ticket',
				'user_id'     => 0,
				'email'       => 'guest@example.org',
				'guest_token' => $token,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE ) ) );

		$response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/messages', $ticket_id ),
			array(),
			array( 'content' => 'Working on it.' )
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertCount( 1, $this->mails, 'Only the owner is notified of an agent reply.' );

		$mail = $this->mails[0];
		$this->assertSame( array( 'guest@example.org' ), (array) $mail['to'] );
		$this->assertStringContainsString( '#' . $ticket_id, $mail['subject'] );
		$this->assertStringContainsString(
			'ticketoo_ticket=' . $ticket_id . '&token=' . $token,
			(string) $mail['message'],
			'A guest owner can only come back through the token link in this email.'
		);
	}

	public function test_no_email_to_replier(): void {
		$agent_email = 'agent@example.org';
		$agent       = $this->create_agent( $agent_email );
		$owner       = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'owner@example.org',
			)
		);

		$owner_ticket = TicketRepository::create(
			array(
				'subject' => 'Someone else ticket',
				'user_id' => $owner,
				'email'   => 'owner@example.org',
			)
		);

		wp_set_current_user( $agent );

		$response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/messages', $owner_ticket ),
			array(),
			array( 'content' => 'Looking into it.' )
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertCount( 1, $this->mails, 'Sanity check: an agent reply does notify the owner.' );

		$own_ticket = TicketRepository::create(
			array(
				'subject' => 'Agent owned ticket',
				'user_id' => $agent,
				'email'   => $agent_email,
			)
		);

		$response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/messages', $own_ticket ),
			array(),
			array( 'content' => 'Answering myself.' )
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertCount(
			1,
			$this->mails,
			'Nobody may be emailed at the address that triggered the event.'
		);
	}

	public function test_unassigned_reply_notifies_all_agents(): void {
		$this->create_agent( 'one@example.org' );
		$this->create_agent( 'two@example.org' );

		$owner = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'owner@example.org',
			)
		);

		$ticket_id = TicketRepository::create(
			array(
				'subject' => 'Unassigned ticket',
				'user_id' => $owner,
				'email'   => 'owner@example.org',
			)
		);

		wp_set_current_user( $owner );

		$response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/messages', $ticket_id ),
			array(),
			array( 'content' => 'Still waiting.' )
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertCount( 1, $this->mails );

		$mail = $this->mails[0];
		$this->assertEqualsCanonicalizing(
			array( 'one@example.org', 'two@example.org' ),
			(array) $mail['to'],
			'With no assignee, every agent must be notified.'
		);
		$this->assertStringContainsString( '#' . $ticket_id, $mail['subject'] );
		$this->assertStringContainsString( '?ticketoo_ticket=' . $ticket_id, (string) $mail['message'] );
	}

	public function test_auto_close_notifies_owner(): void {
		$token     = TokenAccess::issue();
		$ticket_id = TicketRepository::create(
			array(
				'subject'     => 'Idle ticket',
				'user_id'     => 0,
				'email'       => 'guest@example.org',
				'guest_token' => $token,
			)
		);

		wp_set_current_user( 0 );

		// Nothing fires this yet: Task 13's sweep calls it directly on closure.
		Notifier::on_auto_closed( $ticket_id );

		$this->assertCount( 1, $this->mails );

		$mail = $this->mails[0];
		$this->assertSame( array( 'guest@example.org' ), (array) $mail['to'] );
		$this->assertStringContainsString( '#' . $ticket_id, $mail['subject'] );
		$this->assertStringContainsString(
			'ticketoo_ticket=' . $ticket_id . '&token=' . $token,
			(string) $mail['message'],
			'A guest owner needs the token link to reply and reopen.'
		);
	}

	/**
	 * First captured email addressed to one recipient, or a failure.
	 *
	 * @param string $email Recipient address to look for.
	 * @return array<string, mixed> Captured wp_mail() arguments.
	 */
	private function mail_to( string $email ): array {
		foreach ( $this->mails as $mail ) {
			if ( in_array( $email, (array) $mail['to'], true ) ) {
				return $mail;
			}
		}

		$this->fail( sprintf( 'No email was sent to %s.', $email ) );
	}

	/**
	 * Creates a support agent with a known address.
	 *
	 * @param string $email Agent address.
	 * @return int User id.
	 */
	private function create_agent( string $email ): int {
		return self::factory()->user->create(
			array(
				'role'       => Capabilities::ROLE,
				'user_email' => $email,
			)
		);
	}

	/**
	 * Dispatches a request through the REST server.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $route  Route path.
	 * @param array      $query  Query-string parameters.
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
}
