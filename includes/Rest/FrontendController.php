<?php
/**
 * Public REST controller: ticket create, list, read and reply.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Ticketoo\Admin\Capabilities;
use Ticketoo\Database\MessageRepository;
use Ticketoo\Database\TicketRepository;
use Ticketoo\Guest\TokenAccess;
use Ticketoo\Model\Message;
use Ticketoo\Model\Ticket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the `ticketoo/v1` front-end routes and owns every permission
 * decision and input validation for them (spec §8: the REST boundary does
 * all validation; guest_token is never serialized into a response).
 */
class FrontendController {

	/**
	 * Registers the four public routes on rest_api_init.
	 *
	 * Every route carries an explicit permission_callback.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'ticketoo/v1',
			'/tickets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_tickets' ),
					'permission_callback' => array( __CLASS__, 'permission_list_tickets' ),
					'args'                => array(
						'status'   => array(
							'type'        => 'string',
							'description' => __( 'Filter by status slug.', 'ticketoo' ),
						),
						'q'        => array(
							'type'        => 'string',
							'description' => __( 'Match this text against the subject.', 'ticketoo' ),
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => __( 'Page number, 1-based.', 'ticketoo' ),
							'default'     => 1,
							'minimum'     => 1,
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Page size; capped at 100.', 'ticketoo' ),
							'default'     => 20,
							'minimum'     => 1,
						),
						'scope'    => array(
							'type'        => 'string',
							'description' => __( 'mine (default) or all (agents only).', 'ticketoo' ),
							'default'     => 'mine',
							'enum'        => array( 'mine', 'all' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_ticket' ),
					'permission_callback' => array( __CLASS__, 'permission_create_ticket' ),
					'args'                => array(
						'subject' => array(
							'type'        => 'string',
							'description' => __( 'Ticket subject.', 'ticketoo' ),
							'required'    => true,
						),
						'content' => array(
							'type'        => 'string',
							'description' => __( 'First message body.', 'ticketoo' ),
							'required'    => true,
						),
						'name'    => array(
							'type'        => 'string',
							'description' => __( 'Guest display name.', 'ticketoo' ),
						),
						'email'   => array(
							'type'        => 'string',
							'description' => __( 'Contact email address.', 'ticketoo' ),
						),
					),
				),
			)
		);

		register_rest_route(
			'ticketoo/v1',
			'/tickets/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'read_ticket' ),
				'permission_callback' => array( __CLASS__, 'permission_access_ticket' ),
				'args'                => array(
					'id'    => array(
						'type' => 'integer',
					),
					'token' => array(
						'type'        => 'string',
						'description' => __( 'Guest bearer token for this ticket.', 'ticketoo' ),
					),
				),
			)
		);

		register_rest_route(
			'ticketoo/v1',
			'/tickets/(?P<id>\d+)/messages',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'reply_to_ticket' ),
				'permission_callback' => array( __CLASS__, 'permission_access_ticket' ),
				'args'                => array(
					'id'      => array(
						'type' => 'integer',
					),
					'content' => array(
						'type'        => 'string',
						'description' => __( 'Reply body.', 'ticketoo' ),
						'required'    => true,
					),
					'token'   => array(
						'type'        => 'string',
						'description' => __( 'Guest bearer token for this ticket.', 'ticketoo' ),
					),
				),
			)
		);
	}

	/**
	 * Permission callback for GET /tickets: authenticated users only, and
	 * scope=all is reserved for holders of ticketoo_manage_tickets.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return bool|WP_Error True when allowed, otherwise a 401/403 error.
	 */
	public static function permission_list_tickets( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'ticketoo_rest_authentication_required',
				__( 'You must be logged in to list tickets.', 'ticketoo' ),
				array( 'status' => 401 )
			);
		}

		if ( 'all' === self::string_param( $request, 'scope' ) && ! current_user_can( Capabilities::CAP ) ) {
			return new WP_Error(
				'ticketoo_rest_forbidden',
				__( 'Sorry, you are not allowed to list every ticket.', 'ticketoo' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for POST /tickets: anyone, while guests are
	 * enabled; logged-in users always.
	 *
	 * @return bool|WP_Error True when allowed, otherwise a 403 error.
	 */
	public static function permission_create_ticket(): bool|WP_Error {
		if ( is_user_logged_in() || self::guests_allowed() ) {
			return true;
		}

		return new WP_Error(
			'ticketoo_rest_guests_disabled',
			__( 'Guest ticket creation is disabled.', 'ticketoo' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Permission callback for reading and replying to a single ticket:
	 * agent capability, ownership, or a verified guest token (spec §8).
	 *
	 * A missing ticket answers 404; a logged-in user without a claim on the
	 * ticket also answers 404 so ticket ids cannot be probed, while a guest
	 * holding a wrong or missing token answers 403.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return bool|WP_Error True when allowed, otherwise a 403/404 error.
	 */
	public static function permission_access_ticket( WP_REST_Request $request ): bool|WP_Error {
		$ticket = TicketRepository::find( (int) $request->get_param( 'id' ) );

		if ( null === $ticket ) {
			return self::ticket_not_found();
		}

		if ( self::current_user_can_access( $ticket, $request ) ) {
			return true;
		}

		if ( 0 === get_current_user_id() ) {
			return new WP_Error(
				'ticketoo_rest_forbidden',
				__( 'A valid ticket token is required.', 'ticketoo' ),
				array( 'status' => 403 )
			);
		}

		return self::ticket_not_found();
	}

	/**
	 * Decides whether the requester may see a specific ticket.
	 *
	 * Grants access to agents (ticketoo_manage_tickets), to the owning
	 * logged-in user, and to anyone presenting the ticket's guest token.
	 * The owner comparison is guarded by a positive user id so a guest can
	 * never match a guest ticket by user_id 0 alone — guests always need
	 * the token.
	 *
	 * @param Ticket          $ticket  Ticket being read or replied to.
	 * @param WP_REST_Request $request Request carrying the optional token.
	 * @return bool True when the requester may access the ticket.
	 */
	public static function current_user_can_access( Ticket $ticket, WP_REST_Request $request ): bool {
		if ( current_user_can( Capabilities::CAP ) ) {
			return true;
		}

		$current_user_id = get_current_user_id();

		if ( 0 < $current_user_id && $ticket->user_id === $current_user_id ) {
			return true;
		}

		return TokenAccess::verify( self::string_param( $request, 'token' ), $ticket->guest_token );
	}

	/**
	 * Handles GET /tickets: filtered, paginated ticket list.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error List payload (items, page, total,
	 *                                   total_pages) or a 403 error.
	 */
	public static function list_tickets( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$scope = self::string_param( $request, 'scope' );
		$scope = '' === $scope ? 'mine' : $scope;

		if ( 'all' === $scope && ! current_user_can( Capabilities::CAP ) ) {
			return new WP_Error(
				'ticketoo_rest_forbidden',
				__( 'Sorry, you are not allowed to list every ticket.', 'ticketoo' ),
				array( 'status' => 403 )
			);
		}

		$page = self::int_param( $request, 'page', 1 );

		// Carry-over rule: per_page is capped at 100 before the repository call.
		$per_page = self::int_param( $request, 'per_page', 20, 100 );

		$status = self::string_param( $request, 'status' );
		$q      = self::string_param( $request, 'q' );

		$args = array(
			'page'     => $page,
			'per_page' => $per_page,
		);

		if ( '' !== $status ) {
			$args['status'] = $status;
		}

		if ( '' !== $q ) {
			$args['q'] = $q;
		}

		if ( 'all' !== $scope ) {
			$args['user_id'] = get_current_user_id();
		}

		$result = TicketRepository::list( $args );

		$items = array();

		foreach ( $result['items'] as $ticket ) {
			$items[] = self::ticket_fields( $ticket );
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'page'        => $page,
				'total'       => (int) $result['total'],
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			),
			200
		);
	}

	/**
	 * Handles POST /tickets: validates input, creates the ticket row and
	 * its first message, then fires ticketoo_ticket_created.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error 201 with id and url, or a 400/500
	 *                                   error (403 comes from the permission callback).
	 */
	public static function create_ticket( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$subject = sanitize_text_field( self::string_param( $request, 'subject' ) );

		if ( '' === trim( $subject ) ) {
			return new WP_Error(
				'ticketoo_rest_invalid_subject',
				__( 'A subject is required.', 'ticketoo' ),
				array( 'status' => 400 )
			);
		}

		$content = wp_kses_post( self::string_param( $request, 'content' ) );

		if ( '' === trim( $content ) ) {
			return new WP_Error(
				'ticketoo_rest_invalid_content',
				__( 'A message is required.', 'ticketoo' ),
				array( 'status' => 400 )
			);
		}

		$raw_email = self::string_param( $request, 'email' );
		$email     = sanitize_email( $raw_email );

		if ( '' !== $raw_email && ! is_email( $email ) ) {
			return new WP_Error(
				'ticketoo_rest_invalid_email',
				__( 'A valid email address is required.', 'ticketoo' ),
				array( 'status' => 400 )
			);
		}

		$user_id = get_current_user_id();

		if ( 0 < $user_id && '' === $email ) {
			$email = (string) wp_get_current_user()->user_email;
		}

		$is_agent = current_user_can( Capabilities::CAP ) ? 1 : 0;

		$ticket_id = TicketRepository::create(
			array(
				'subject'     => $subject,
				'user_id'     => $user_id,
				'email'       => $email,
				'guest_token' => 0 === $user_id ? TokenAccess::issue() : null,
			)
		);

		if ( 0 === $ticket_id ) {
			return new WP_Error(
				'ticketoo_rest_create_failed',
				__( 'The ticket could not be created.', 'ticketoo' ),
				array( 'status' => 500 )
			);
		}

		$message_id = MessageRepository::add( $ticket_id, $user_id, $email, $is_agent, $content );

		if ( 0 === $message_id ) {
			return new WP_Error(
				'ticketoo_rest_create_failed',
				__( 'The ticket could not be created.', 'ticketoo' ),
				array( 'status' => 500 )
			);
		}

		$ticket = TicketRepository::find( $ticket_id );

		if ( null === $ticket ) {
			return new WP_Error(
				'ticketoo_rest_create_failed',
				__( 'The ticket could not be created.', 'ticketoo' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires after a ticket and its first message have been stored.
		 *
		 * @since 0.1.0
		 * @param int    $ticket_id New ticket id.
		 * @param Ticket $ticket    The created ticket.
		 */
		do_action( 'ticketoo_ticket_created', $ticket_id, $ticket );

		return new WP_REST_Response(
			array(
				'id'  => $ticket_id,
				'url' => add_query_arg( 'ticketoo_ticket', $ticket_id, home_url( '/' ) ),
			),
			201
		);
	}

	/**
	 * Handles GET /tickets/{id}: one ticket, its paged conversation and an
	 * empty attachment list (uploads arrive with Task 6).
	 *
	 * The permission callback has already authorized the request.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error Ticket payload or a 404 error.
	 */
	public static function read_ticket( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ticket = TicketRepository::find( (int) $request->get_param( 'id' ) );

		if ( null === $ticket ) {
			return self::ticket_not_found();
		}

		$page     = self::int_param( $request, 'page', 1 );
		$per_page = self::int_param( $request, 'per_page', 20, 100 );

		$messages = MessageRepository::for_ticket( $ticket->id, $page, $per_page );

		$data = array(
			'id'        => $ticket->id,
			'subject'   => $ticket->subject,
			'status'    => $ticket->status,
			'can_close' => 'closed' !== $ticket->status,
		);

		$data = apply_filters( 'ticketoo_ticket_fields', $data, $ticket );

		$data['messages'] = array();

		foreach ( $messages['items'] as $message ) {
			$data['messages'][] = self::message_payload( $message );
		}

		$data['attachments'] = array();

		/**
		 * Filters a single-ticket REST response body before it is sent.
		 *
		 * @since 0.1.0
		 * @param array  $data   Response body: ticket fields plus messages and attachments.
		 * @param Ticket $ticket Ticket the response was built from.
		 */
		$data = apply_filters( 'ticketoo_rest_response_ticket', $data, $ticket );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Handles POST /tickets/{id}/messages: stores a reply and touches the
	 * ticket's activity timestamps through MessageRepository::add().
	 *
	 * File uploads (`files[]`) may accompany the request but are handled in
	 * Task 6; this endpoint stores the text content only.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error 201 with the stored message, or a
	 *                                   400/404/500 error.
	 */
	public static function reply_to_ticket( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ticket = TicketRepository::find( (int) $request->get_param( 'id' ) );

		if ( null === $ticket ) {
			return self::ticket_not_found();
		}

		$content = wp_kses_post( self::string_param( $request, 'content' ) );

		if ( '' === trim( $content ) ) {
			return new WP_Error(
				'ticketoo_rest_invalid_content',
				__( 'A message is required.', 'ticketoo' ),
				array( 'status' => 400 )
			);
		}

		$user_id  = get_current_user_id();
		$is_agent = current_user_can( Capabilities::CAP ) ? 1 : 0;
		$email    = $ticket->email;

		if ( 0 < $user_id ) {
			$user_email = (string) wp_get_current_user()->user_email;
			$email      = '' !== $user_email ? $user_email : $ticket->email;
		}

		$message_id = MessageRepository::add( $ticket->id, $user_id, $email, $is_agent, $content );

		if ( 0 === $message_id ) {
			return new WP_Error(
				'ticketoo_rest_reply_failed',
				__( 'The reply could not be saved.', 'ticketoo' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'id'        => $message_id,
				'ticket_id' => $ticket->id,
				'is_agent'  => $is_agent,
				'content'   => $content,
			),
			201
		);
	}

	/**
	 * Builds the public list payload for one ticket and passes it through
	 * the ticketoo_ticket_fields extension point.
	 *
	 * The guest_token field is deliberately absent: no REST response may
	 * expose it.
	 *
	 * @param Ticket $ticket Ticket entity.
	 * @return array Public ticket fields.
	 */
	private static function ticket_fields( Ticket $ticket ): array {
		$data = array(
			'id'               => $ticket->id,
			'subject'          => $ticket->subject,
			'status'           => $ticket->status,
			'user'             => self::user_payload( $ticket ),
			'assigned_to'      => self::agent_payload( $ticket ),
			'last_activity_at' => $ticket->last_activity_at,
			'created_at'       => $ticket->created_at,
		);

		/**
		 * Filters the public fields of a ticket before it is returned.
		 *
		 * @since 0.1.0
		 * @param array  $data   Key/value ticket payload.
		 * @param Ticket $ticket Ticket the payload was built from.
		 */
		return apply_filters( 'ticketoo_ticket_fields', $data, $ticket );
	}

	/**
	 * Builds the requester-facing user object for a ticket.
	 *
	 * @param Ticket $ticket Ticket entity.
	 * @return array Owner identity (id 0 and the ticket email for guests).
	 */
	private static function user_payload( Ticket $ticket ): array {
		if ( 0 < $ticket->user_id ) {
			$user = get_userdata( $ticket->user_id );

			if ( false !== $user ) {
				return array(
					'id'    => (int) $user->ID,
					'name'  => (string) $user->display_name,
					'email' => (string) $user->user_email,
				);
			}
		}

		return array(
			'id'    => 0,
			'name'  => '',
			'email' => $ticket->email,
		);
	}

	/**
	 * Builds the requester-facing assignee object for a ticket.
	 *
	 * @param Ticket $ticket Ticket entity.
	 * @return array|null Agent identity, or null when unassigned.
	 */
	private static function agent_payload( Ticket $ticket ): ?array {
		if ( null === $ticket->assigned_to ) {
			return null;
		}

		$user = get_userdata( $ticket->assigned_to );

		if ( false === $user ) {
			return null;
		}

		return array(
			'id'   => (int) $user->ID,
			'name' => (string) $user->display_name,
		);
	}

	/**
	 * Builds one conversation message for a response body.
	 *
	 * The stored content is rendered through wp_kses_post on the way out.
	 *
	 * @param Message $message Message entity.
	 * @return array Public message fields.
	 */
	private static function message_payload( Message $message ): array {
		return array(
			'id'         => $message->id,
			'user_id'    => $message->user_id,
			'is_agent'   => $message->is_agent,
			'content'    => wp_kses_post( $message->content ),
			'created_at' => $message->created_at,
		);
	}

	/**
	 * Whether guest ticket creation is enabled (option default: enabled).
	 *
	 * @return bool True when guests may open tickets.
	 */
	private static function guests_allowed(): bool {
		$raw = get_option( 'ticketoo_allow_guests', '1' );

		return in_array( $raw, array( 1, '1', true, 'true', 'yes', 'on' ), true );
	}

	/**
	 * Reads a request parameter as a plain string.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @param string          $key     Parameter name.
	 * @return string Empty string when the parameter is missing or not scalar.
	 */
	private static function string_param( WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Reads a request parameter as a positive integer.
	 *
	 * @param WP_REST_Request $request  Request instance.
	 * @param string          $key      Parameter name.
	 * @param int             $fallback Value used when the parameter is missing.
	 * @param int|null        $max      Optional upper bound applied to the result.
	 * @return int Clamped integer.
	 */
	private static function int_param( WP_REST_Request $request, string $key, int $fallback, ?int $max = null ): int {
		$value = $request->get_param( $key );

		if ( null === $value || ! is_scalar( $value ) ) {
			return $fallback;
		}

		$parsed = max( 1, (int) $value );

		return null !== $max ? min( $max, $parsed ) : $parsed;
	}

	/**
	 * Builds the uniform "ticket missing" error (404).
	 *
	 * @return WP_Error Error with a 404 status.
	 */
	private static function ticket_not_found(): WP_Error {
		return new WP_Error(
			'ticketoo_rest_ticket_not_found',
			__( 'Ticket not found.', 'ticketoo' ),
			array( 'status' => 404 )
		);
	}
}
