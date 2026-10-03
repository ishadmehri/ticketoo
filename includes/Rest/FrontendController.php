<?php
/**
 * Public REST controller: ticket create, list, read and reply, status and
 * assignment changes, and secure attachment upload/download.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Ticketoo\Activator;
use Ticketoo\Admin\Capabilities;
use Ticketoo\Admin\SettingsPage;
use Ticketoo\Database\MessageRepository;
use Ticketoo\Database\TicketRepository;
use Ticketoo\Guest\TokenAccess;
use Ticketoo\Model\Attachment;
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
	 * Registers the public routes on rest_api_init and the raw-download
	 * serve hook.
	 *
	 * Every route carries an explicit permission_callback.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		// Attachment downloads must reach the client as raw bytes instead of
		// JSON-encoded data; see serve_raw_response().
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_raw_response' ), 10, 2 );

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

		register_rest_route(
			'ticketoo/v1',
			'/tickets/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'set_ticket_status' ),
				'permission_callback' => array( __CLASS__, 'permission_change_status' ),
				'args'                => array(
					'id'     => array(
						'type' => 'integer',
					),
					'status' => array(
						'type'        => 'string',
						'description' => __( 'New status slug; must exist in the ticketoo_statuses list.', 'ticketoo' ),
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			'ticketoo/v1',
			'/tickets/(?P<id>\d+)/assign',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'assign_ticket' ),
				'permission_callback' => array( __CLASS__, 'permission_assign_ticket' ),
				'args'                => array(
					'id'      => array(
						'type' => 'integer',
					),
					'user_id' => array(
						'type'        => 'integer',
						'description' => __( 'Agent user id to assign the ticket to, or 0 to unassign.', 'ticketoo' ),
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			'ticketoo/v1',
			'/attachments/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'download_attachment' ),
				'permission_callback' => array( __CLASS__, 'permission_access_attachment' ),
				'args'                => array(
					'id'    => array(
						'type' => 'integer',
					),
					'token' => array(
						'type'        => 'string',
						'description' => __( 'Guest bearer token for the ticket this attachment belongs to.', 'ticketoo' ),
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
	 * Permission callback for POST /tickets/{id}/status: agents
	 * (ticketoo_manage_tickets) may pick any allowed status, the logged-in
	 * owner of the ticket may only close it, and guests are refused outright
	 * (spec §5 lists read, reply and download as the only guest routes).
	 *
	 * A missing ticket answers 404; a logged-in user without a claim on the
	 * ticket also answers 404 so ticket ids cannot be probed.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return bool|WP_Error True when allowed, otherwise a 403/404 error.
	 */
	public static function permission_change_status( WP_REST_Request $request ): bool|WP_Error {
		$ticket = TicketRepository::find( (int) $request->get_param( 'id' ) );

		if ( null === $ticket ) {
			return self::ticket_not_found();
		}

		if ( current_user_can( Capabilities::CAP ) ) {
			return true;
		}

		$current_user_id = get_current_user_id();

		if ( 0 === $current_user_id ) {
			return new WP_Error(
				'ticketoo_rest_forbidden',
				__( 'Guests cannot change the ticket status.', 'ticketoo' ),
				array( 'status' => 403 )
			);
		}

		if ( $ticket->user_id === $current_user_id ) {
			return true;
		}

		return self::ticket_not_found();
	}

	/**
	 * Permission callback for POST /tickets/{id}/assign: only holders of
	 * ticketoo_manage_tickets may assign a ticket; every other requester —
	 * including guests — answers 403 regardless of the ticket.
	 *
	 * @return bool|WP_Error True when allowed, otherwise a 403 error.
	 */
	public static function permission_assign_ticket(): bool|WP_Error {
		if ( current_user_can( Capabilities::CAP ) ) {
			return true;
		}

		return new WP_Error(
			'ticketoo_rest_forbidden',
			__( 'Sorry, you are not allowed to assign tickets.', 'ticketoo' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Permission callback for GET /attachments/{id}: the same access rule as
	 * reading the owning ticket — agent capability, ownership, or a verified
	 * guest token (spec §5).
	 *
	 * A missing attachment (or one whose ticket the requester may not see)
	 * answers 404 so attachment ids cannot be probed; a guest holding a
	 * wrong or missing token answers 403.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return bool|WP_Error True when allowed, otherwise a 403/404 error.
	 */
	public static function permission_access_attachment( WP_REST_Request $request ): bool|WP_Error {
		$ticket = self::attachment_ticket( (int) $request->get_param( 'id' ) );

		if ( null === $ticket ) {
			return self::attachment_not_found();
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

		return self::attachment_not_found();
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
	 * Handles GET /tickets/{id}: one ticket, its paged conversation and the
	 * ticket's attachment list.
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

		$data['attachments'] = self::ticket_attachments( $ticket->id );

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
	 * Handles POST /tickets/{id}/messages: stores a reply, stores any
	 * attached files (`files[]`) and touches the ticket's activity
	 * timestamps through MessageRepository::add().
	 *
	 * A non-agent reply to a closed ticket reopens it to `open` and fires
	 * ticketoo_ticket_status_changed (the auto-close email's "Reply to
	 * reopen" promise); agent replies leave the status untouched because
	 * agents reopen through POST /tickets/{id}/status (spec § Close by user).
	 *
	 * Every file is validated (type, option allow-list, size cap) before the
	 * message is written, so one rejected file rejects the whole request
	 * with nothing stored. Stored files get a random filesystem name under
	 * uploads/ticketoo/YYYY/MM/; only the sanitized original name reaches
	 * the database.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error 201 with the stored message, its
	 *                                   attachments and the post-reply
	 *                                   ticket status, or a 400/404/500 error.
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

		$uploads = self::validated_uploads( $request );

		if ( is_wp_error( $uploads ) ) {
			return $uploads;
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

		$ticket_status = $ticket->status;

		if ( 'closed' === $ticket_status && 0 === $is_agent ) {
			if ( ! TicketRepository::update_status( $ticket->id, 'open' ) ) {
				return new WP_Error(
					'ticketoo_rest_reopen_failed',
					__( 'The ticket could not be reopened.', 'ticketoo' ),
					array( 'status' => 500 )
				);
			}

			$ticket_status = 'open';

			/**
			 * Fires after a reply reopened a closed ticket (spec § Close by
			 * user: the auto-close email promises "Reply to reopen").
			 *
			 * @since 0.1.0
			 * @param int    $ticket_id  Ticket id.
			 * @param string $old_status Status slug before the change.
			 * @param string $new_status Status slug after the change.
			 */
			do_action( 'ticketoo_ticket_status_changed', (int) $ticket->id, 'closed', 'open' );
		}

		/**
		 * Fires after a reply has been stored. The classic (no-JS) form
		 * replays through this same handler, so one fire site covers both
		 * request paths.
		 *
		 * @since 0.1.0
		 * @param int  $ticket_id Ticket id.
		 * @param bool $is_agent  True when an agent wrote the reply.
		 */
		do_action( 'ticketoo_ticket_replied', (int) $ticket->id, (bool) $is_agent );

		$attachments = array();

		foreach ( $uploads as $upload ) {
			$attachment = self::store_upload( $message_id, $upload );

			if ( null === $attachment ) {
				return new WP_Error(
					'ticketoo_rest_upload_failed',
					__( 'The attachment could not be stored.', 'ticketoo' ),
					array( 'status' => 500 )
				);
			}

			$attachments[] = self::attachment_payload( $attachment );
		}

		return new WP_REST_Response(
			array(
				'id'            => $message_id,
				'ticket_id'     => $ticket->id,
				'is_agent'      => $is_agent,
				'content'       => $content,
				'ticket_status' => $ticket_status,
				'attachments'   => $attachments,
			),
			201
		);
	}

	/**
	 * Handles POST /tickets/{id}/status: validates the new status against
	 * the ticketoo_statuses list, restricts non-agents to 'closed', persists
	 * the change and fires ticketoo_ticket_status_changed.
	 *
	 * The permission callback has already authorized the requester.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error 200 with id and status, or a
	 *                                   400/403/404/500 error.
	 */
	public static function set_ticket_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ticket = TicketRepository::find( (int) $request->get_param( 'id' ) );

		if ( null === $ticket ) {
			return self::ticket_not_found();
		}

		$status   = self::string_param( $request, 'status' );
		$statuses = self::allowed_statuses();

		if ( ! isset( $statuses[ $status ] ) && ! in_array( $status, $statuses, true ) ) {
			return new WP_Error(
				'ticketoo_rest_invalid_status',
				__( 'Unknown ticket status.', 'ticketoo' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( Capabilities::CAP ) && 'closed' !== $status ) {
			return new WP_Error(
				'ticketoo_rest_forbidden',
				__( 'You may only close your own ticket; agents may set any status.', 'ticketoo' ),
				array( 'status' => 403 )
			);
		}

		$old_status = $ticket->status;

		if ( $old_status !== $status ) {
			if ( ! TicketRepository::update_status( $ticket->id, $status ) ) {
				return new WP_Error(
					'ticketoo_rest_status_failed',
					__( 'The ticket status could not be updated.', 'ticketoo' ),
					array( 'status' => 500 )
				);
			}

			/**
			 * Fires after a ticket's status changed.
			 *
			 * @since 0.1.0
			 * @param int    $ticket_id  Ticket id.
			 * @param string $old_status Status slug before the change.
			 * @param string $new_status Status slug after the change.
			 */
			do_action( 'ticketoo_ticket_status_changed', $ticket->id, $old_status, $status );
		}

		return new WP_REST_Response(
			array(
				'id'     => $ticket->id,
				'status' => $status,
			),
			200
		);
	}

	/**
	 * Handles POST /tickets/{id}/assign: assigns the ticket to a
	 * ticketoo_agent or administrator, unassigns it when user_id is 0, and
	 * fires ticketoo_ticket_assigned when the assignee actually changes.
	 *
	 * The permission callback has already required ticketoo_manage_tickets.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error 200 with id and assigned_to, or a
	 *                                   400/404/500 error.
	 */
	public static function assign_ticket( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$ticket = TicketRepository::find( (int) $request->get_param( 'id' ) );

		if ( null === $ticket ) {
			return self::ticket_not_found();
		}

		$user_id = (int) $request->get_param( 'user_id' );

		if ( 0 > $user_id ) {
			return new WP_Error(
				'ticketoo_rest_invalid_assignee',
				__( 'A positive user id, or 0 to unassign, is required.', 'ticketoo' ),
				array( 'status' => 400 )
			);
		}

		if ( 0 < $user_id ) {
			$user = get_userdata( $user_id );

			if ( false === $user ) {
				return new WP_Error(
					'ticketoo_rest_invalid_assignee',
					__( 'The requested user does not exist.', 'ticketoo' ),
					array( 'status' => 400 )
				);
			}

			$is_agent = array_intersect(
				array( Capabilities::ROLE, 'administrator' ),
				(array) $user->roles
			);

			if ( array() === $is_agent ) {
				return new WP_Error(
					'ticketoo_rest_invalid_assignee',
					__( 'Tickets can only be assigned to support agents or administrators.', 'ticketoo' ),
					array( 'status' => 400 )
				);
			}
		}

		$assignee = 0 < $user_id ? $user_id : null;
		$previous = $ticket->assigned_to;

		if ( $previous !== $assignee ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Assignment must reach the database immediately; the tickets table is not object-cached.
			$updated = $wpdb->update(
				Activator::table_names()['tickets'],
				array(
					'assigned_to' => $assignee,
					'updated_at'  => current_time( 'mysql' ),
				),
				array( 'id' => $ticket->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				return new WP_Error(
					'ticketoo_rest_assign_failed',
					__( 'The ticket could not be assigned.', 'ticketoo' ),
					array( 'status' => 500 )
				);
			}

			/**
			 * Fires after a ticket's assignee changed.
			 *
			 * @since 0.1.0
			 * @param int $ticket_id        Ticket id.
			 * @param int $assigned_user_id New agent id; 0 when unassigned.
			 * @param int $previous_user_id Previous agent id; 0 when there was none.
			 */
			do_action( 'ticketoo_ticket_assigned', $ticket->id, $user_id, null !== $previous ? $previous : 0 );
		}

		return new WP_REST_Response(
			array(
				'id'          => $ticket->id,
				'assigned_to' => $assignee,
			),
			200
		);
	}

	/**
	 * Handles GET /attachments/{id}: serves the stored file bytes with an
	 * attachment disposition.
	 *
	 * The permission callback has already authorized the request. The
	 * stored path must realpath() into a real file underneath the ticketoo
	 * upload directory — the prefix check defeats path-traversal payloads
	 * smuggled into file_path, and the raw bytes are echoed by
	 * serve_raw_response() instead of being JSON-encoded.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error File bytes with download headers,
	 *                                   or a 404 error.
	 */
	public static function download_attachment( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$attachment = self::find_attachment( (int) $request->get_param( 'id' ) );

		if ( null === $attachment ) {
			return self::attachment_not_found();
		}

		$base = realpath( wp_upload_dir()['basedir'] . '/ticketoo' );
		$file = realpath( $attachment->file_path );

		if ( false === $base || false === $file || ! is_file( $file ) ) {
			return self::attachment_not_found();
		}

		if ( ! str_starts_with( $file, $base . DIRECTORY_SEPARATOR ) ) {
			return self::attachment_not_found();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the authorized attachment from disk (a local path, not a URL); WP_Filesystem would add boot cost for a single read.
		$contents = file_get_contents( $file );

		if ( false === $contents ) {
			return self::attachment_not_found();
		}

		$mime = 1 === preg_match( '#^[A-Za-z0-9.+-]+/[A-Za-z0-9.+-]+$#', $attachment->mime )
			? $attachment->mime
			: 'application/octet-stream';

		$filename = str_replace( array( '"', "\r", "\n" ), '', $attachment->original_name );

		$response = new WP_REST_Response( $contents, 200 );
		$response->header( 'Content-Type', $mime );
		$response->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
		$response->header( 'Content-Length', (string) strlen( $contents ) );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		return $response;
	}

	/**
	 * Serves attachment downloads byte for byte.
	 *
	 * REST responses are JSON-encoded by default, which would corrupt a
	 * binary file; when the response carries an attachment disposition this
	 * callback echoes the raw bytes itself and skips the JSON step. Hooked
	 * to rest_pre_serve_request from register_routes().
	 *
	 * @param bool             $served Whether the request has already been served.
	 * @param WP_REST_Response $result Response being served.
	 * @return bool True when this callback served the response itself.
	 */
	public static function serve_raw_response( $served, $result ) {
		if ( true === $served || ! ( $result instanceof WP_REST_Response ) ) {
			return $served;
		}

		$headers     = $result->get_headers();
		$disposition = isset( $headers['Content-Disposition'] ) ? (string) $headers['Content-Disposition'] : '';
		$data        = $result->get_data();

		if ( ! str_starts_with( $disposition, 'attachment' ) || ! is_string( $data ) ) {
			return $served;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw bytes of the already-authorized attachment file.
		echo $data;

		return true;
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
	 * The status slugs the plugin accepts, extensible through the
	 * ticketoo_statuses filter (spec §10).
	 *
	 * @return array Status slug => human-readable label.
	 */
	private static function allowed_statuses(): array {
		/**
		 * Filters the ticket statuses accepted for status changes.
		 *
		 * @since 0.1.0
		 * @param array $statuses Status slug => human-readable label.
		 */
		return apply_filters(
			'ticketoo_statuses',
			array(
				'open'     => __( 'Open', 'ticketoo' ),
				'pending'  => __( 'Pending', 'ticketoo' ),
				'answered' => __( 'Answered', 'ticketoo' ),
				'closed'   => __( 'Closed', 'ticketoo' ),
			)
		);
	}

	/**
	 * Allowed attachment extensions, read through the SettingsPage
	 * accessor so the default lives in one place only.
	 *
	 * An empty option fails closed: nothing is accepted.
	 *
	 * @return string[] Lowercased extension list.
	 */
	private static function allowed_attachment_types(): array {
		return SettingsPage::get_attachment_types();
	}

	/**
	 * Validates every uploaded file before anything is stored.
	 *
	 * Per file: a successful upload error code, a readable temporary file,
	 * wp_check_filetype_and_ext() agreement, an extension listed in
	 * ticketoo_attachment_types and a size within ticketoo_attachment_max_mb.
	 * The client-declared MIME type and size are never trusted; both are
	 * re-derived from the file on disk (spec §8).
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return array|WP_Error Cleaned upload descriptors, or a 400 error for
	 *                         the first rejected file.
	 */
	private static function validated_uploads( WP_REST_Request $request ): array|WP_Error {
		$files = self::request_files( $request );

		if ( array() === $files ) {
			return array();
		}

		$max_mb    = max( 0, SettingsPage::get_attachment_max_mb() );
		$max_bytes = $max_mb * 1024 * 1024;
		$allowed   = self::allowed_attachment_types();
		$uploads   = array();

		foreach ( $files as $file ) {
			if ( UPLOAD_ERR_OK !== $file['error'] ) {
				return self::upload_failed_error();
			}

			$tmp = $file['tmp_name'];

			if ( '' === $tmp || ! is_readable( $tmp ) ) {
				return self::upload_failed_error();
			}

			$check = wp_check_filetype_and_ext( $tmp, $file['name'] );
			$ext   = is_string( $check['ext'] ) ? strtolower( $check['ext'] ) : '';
			$mime  = is_string( $check['type'] ) ? (string) $check['type'] : '';

			if ( '' === $ext || '' === $mime || ! in_array( $ext, $allowed, true ) ) {
				return new WP_Error(
					'ticketoo_rest_disallowed_file',
					__( 'This file type is not allowed.', 'ticketoo' ),
					array( 'status' => 400 )
				);
			}

			$size = filesize( $tmp );

			if ( false === $size ) {
				return self::upload_failed_error();
			}

			if ( $size > $max_bytes ) {
				return new WP_Error(
					'ticketoo_rest_file_too_large',
					sprintf(
						/* translators: %d: Maximum attachment size in megabytes. */
						__( 'Attachments must not exceed %d MB.', 'ticketoo' ),
						$max_mb
					),
					array( 'status' => 400 )
				);
			}

			$uploads[] = array(
				'tmp_name' => $tmp,
				'name'     => self::store_original_name( $file['name'], $ext ),
				'ext'      => $ext,
				'mime'     => $mime,
				'size'     => (int) $size,
			);
		}

		return $uploads;
	}

	/**
	 * Reads the `files[]` upload tree from the request in the same shape PHP
	 * builds for a multipart field named files[].
	 *
	 * UPLOAD_ERR_NO_FILE entries (empty file inputs) are dropped silently;
	 * the client-declared MIME type and size are deliberately discarded.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return array<int, array{name: string, tmp_name: string, error: int}>
	 */
	private static function request_files( WP_REST_Request $request ): array {
		$params = $request->get_file_params();

		if ( ! isset( $params['files'] ) || ! is_array( $params['files'] ) ) {
			return array();
		}

		$files = $params['files'];
		$names = isset( $files['name'] ) && is_array( $files['name'] ) ? $files['name'] : array( $files['name'] ?? '' );

		$slots = array(
			'tmp_name' => isset( $files['tmp_name'] ) && is_array( $files['tmp_name'] ) ? $files['tmp_name'] : array( $files['tmp_name'] ?? '' ),
			'error'    => isset( $files['error'] ) && is_array( $files['error'] ) ? $files['error'] : array( $files['error'] ?? UPLOAD_ERR_NO_FILE ),
		);

		$uploads = array();

		foreach ( $names as $index => $name ) {
			$error = (int) ( $slots['error'][ $index ] ?? UPLOAD_ERR_NO_FILE );

			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue;
			}

			$uploads[] = array(
				'name'     => is_scalar( $name ) ? (string) $name : '',
				'tmp_name' => is_scalar( $slots['tmp_name'][ $index ] ?? null ) ? (string) $slots['tmp_name'][ $index ] : '',
				'error'    => $error,
			);
		}

		return $uploads;
	}

	/**
	 * Prepares the original filename for the original_name column: the
	 * client name sanitized, then truncated at 100 characters so long names
	 * always fit the field.
	 *
	 * @param string $name Client-side filename.
	 * @param string $ext  Validated extension, used when nothing survives sanitizing.
	 * @return string Display name stored in the database (never on disk).
	 */
	private static function store_original_name( string $name, string $ext ): string {
		$original = sanitize_file_name( $name );

		if ( '' === $original ) {
			$original = 'file.' . $ext;
		}

		return function_exists( 'mb_substr' )
			? mb_substr( $original, 0, 100, 'UTF-8' )
			: substr( $original, 0, 100 );
	}

	/**
	 * Moves one validated upload into uploads/ticketoo/YYYY/MM/ under a
	 * random name and inserts its ticketoo_attachments row.
	 *
	 * The directory root carries an .htaccess deny rule (spec §4/§8), so
	 * stored files are only reachable through the permission-checked
	 * download route. The filesystem name is 24 random hex characters; only
	 * the sanitized original name reaches the database.
	 *
	 * @param int   $message_id Owning message id.
	 * @param array $upload     Validated upload (tmp_name, name, ext, mime, size).
	 * @return Attachment|null Stored entity, or null when the file or the row failed.
	 */
	private static function store_upload( int $message_id, array $upload ): ?Attachment {
		global $wpdb;

		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return null;
		}

		$now  = current_time( 'mysql' );
		$base = $upload_dir['basedir'] . '/ticketoo';
		$dir  = $base . '/' . substr( $now, 0, 4 ) . '/' . substr( $now, 5, 2 );

		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		self::write_denied_htaccess( $base );

		$dest = $dir . '/' . bin2hex( random_bytes( 12 ) ) . '.' . $upload['ext'];

		$moved = is_uploaded_file( $upload['tmp_name'] )
			? move_uploaded_file( $upload['tmp_name'], $dest )
			: copy( $upload['tmp_name'], $dest );

		if ( ! $moved ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Attachment rows are written once at upload time; the table has no object-cache layer.
		$inserted = $wpdb->insert(
			Activator::table_names()['attachments'],
			array(
				'message_id'    => $message_id,
				'file_path'     => $dest,
				'original_name' => $upload['name'],
				'mime'          => $upload['mime'],
				'size'          => $upload['size'],
				'created_at'    => $now,
			)
		);

		if ( false === $inserted ) {
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}

			return null;
		}

		return self::find_attachment( (int) $wpdb->insert_id );
	}

	/**
	 * Writes the .htaccess deny rule into the ticketoo upload root once
	 * (spec §4: stored files are never served directly by the web server).
	 *
	 * @param string $base Absolute path of the ticketoo upload directory.
	 * @return void
	 */
	private static function write_denied_htaccess( string $base ): void {
		$htaccess = $base . '/.htaccess';

		if ( file_exists( $htaccess ) ) {
			return;
		}

		$rules =
			"# Ticketoo: attachments are served only through the REST download route.\n" .
			"<IfModule mod_authz_core.c>\n" .
			"\tRequire all denied\n" .
			"</IfModule>\n" .
			"<IfModule !mod_authz_core.c>\n" .
			"\tOrder deny,allow\n" .
			"\tDeny from all\n" .
			"</IfModule>\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Writes a static deny rule next to the stored files; WP_Filesystem would require credentials on some hosts for a one-time local write.
		file_put_contents( $htaccess, $rules );
	}

	/**
	 * Builds the public payload for one attachment.
	 *
	 * The server-side file_path is deliberately absent: no REST response may
	 * expose where a file lives on disk.
	 *
	 * @param Attachment $attachment Attachment entity.
	 * @return array Public attachment fields.
	 */
	private static function attachment_payload( Attachment $attachment ): array {
		return array(
			'id'         => $attachment->id,
			'message_id' => $attachment->message_id,
			'name'       => $attachment->original_name,
			'mime'       => $attachment->mime,
			'size'       => $attachment->size,
			'url'        => rest_url( 'ticketoo/v1/attachments/' . $attachment->id ),
		);
	}

	/**
	 * Lists a ticket's attachments across all of its messages, oldest first.
	 *
	 * @param int $ticket_id Owning ticket id.
	 * @return array Attachment payloads (see attachment_payload()).
	 */
	private static function ticket_attachments( int $ticket_id ): array {
		global $wpdb;

		$attachments_table = Activator::table_names()['attachments'];
		$messages_table    = Activator::table_names()['messages'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Ticket attachment listing must reach the database immediately; table names come from Activator::table_names() and the ticket id travels as a prepared placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.* FROM {$attachments_table} a INNER JOIN {$messages_table} m ON m.id = a.message_id WHERE m.ticket_id = %d ORDER BY a.id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from Activator::table_names(); the ticket id is a prepared placeholder.
				$ticket_id
			)
		);

		$items = array();

		foreach ( (array) $rows as $row ) {
			$items[] = self::attachment_payload( Attachment::from_row( $row ) );
		}

		return $items;
	}

	/**
	 * Fetches one attachment by id.
	 *
	 * @param int $id Attachment id.
	 * @return Attachment|null Null when no attachment has that id.
	 */
	private static function find_attachment( int $id ): ?Attachment {
		global $wpdb;

		if ( 1 > $id ) {
			return null;
		}

		$table = Activator::table_names()['attachments'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single-row lookup by id; table name comes from Activator::table_names() and the id travels as a prepared placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return null !== $row ? Attachment::from_row( $row ) : null;
	}

	/**
	 * Resolves the ticket an attachment belongs to through its message.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return Ticket|null Null when neither the attachment nor its message exists.
	 */
	private static function attachment_ticket( int $attachment_id ): ?Ticket {
		global $wpdb;

		if ( 1 > $attachment_id ) {
			return null;
		}

		$messages    = Activator::table_names()['messages'];
		$attachments = Activator::table_names()['attachments'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Permission resolution needs the owning ticket immediately; table names come from Activator::table_names() and the id travels as a prepared placeholder.
		$ticket_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT m.ticket_id FROM {$messages} m INNER JOIN {$attachments} a ON a.message_id = m.id WHERE a.id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from Activator::table_names(); the attachment id is a prepared placeholder.
				$attachment_id
			)
		);

		if ( null === $ticket_id ) {
			return null;
		}

		return TicketRepository::find( (int) $ticket_id );
	}

	/**
	 * Builds the uniform "upload failed" error (400).
	 *
	 * @return WP_Error Error with a 400 status.
	 */
	private static function upload_failed_error(): WP_Error {
		return new WP_Error(
			'ticketoo_rest_invalid_upload',
			__( 'The upload could not be processed.', 'ticketoo' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Whether guest ticket creation is enabled, read through the
	 * SettingsPage accessor (option default: enabled).
	 *
	 * @return bool True when guests may open tickets.
	 */
	private static function guests_allowed(): bool {
		return SettingsPage::get_allow_guests();
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

	/**
	 * Builds the uniform "attachment missing" error (404).
	 *
	 * Also returned when the requester may not access the owning ticket, so
	 * attachment ids cannot be probed.
	 *
	 * @return WP_Error Error with a 404 status.
	 */
	private static function attachment_not_found(): WP_Error {
		return new WP_Error(
			'ticketoo_rest_attachment_not_found',
			__( 'Attachment not found.', 'ticketoo' ),
			array( 'status' => 404 )
		);
	}
}
