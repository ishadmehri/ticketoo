<?php
/**
 * The [ticketoo] shortcode: view dispatch, template resolution and the
 * classic (no-JS) form fallback.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Shortcode;

use Ticketoo\Admin\Capabilities;
use Ticketoo\Admin\SettingsPage;
use Ticketoo\Database\MessageRepository;
use Ticketoo\Database\TicketRepository;
use Ticketoo\Guest\TokenAccess;
use Ticketoo\Model\Ticket;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the list, form and conversation views as server-side HTML
 * (ADR 0003: vanilla JS enhances markup that already works without it) and
 * processes the classic form posts that keep the flow usable when JS is
 * unavailable or broken.
 */
class TicketooShortcode {

	/**
	 * Nonce action shared by every classic form the plugin renders.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'ticketoo_submit';

	/**
	 * Nonce field name of the new-ticket form.
	 *
	 * @var string
	 */
	public const NONCE_FIELD = 'ticketoo_nonce';

	/**
	 * Nonce field name of the reply form.
	 *
	 * @var string
	 */
	public const REPLY_NONCE_FIELD = 'ticketoo_reply_nonce';

	/**
	 * Nonce field name of the close-ticket form.
	 *
	 * @var string
	 */
	public const CLOSE_NONCE_FIELD = 'ticketoo_close_nonce';

	/**
	 * Tickets per page in the list view.
	 *
	 * @var int
	 */
	private const LIST_PER_PAGE = 20;

	/**
	 * Messages per conversation page in the ticket view.
	 *
	 * @var int
	 */
	private const MESSAGES_PER_PAGE = 50;

	/**
	 * Shortcode callback registered for [ticketoo].
	 *
	 * WordPress hands over an empty string instead of an array when the tag
	 * carries no attributes, so the payload is normalized before dispatch.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public static function shortcode( array|string $atts ): string {
		return self::render( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * Dispatches the shortcode to a view and renders it.
	 *
	 * Views: list (default), form, ticket; an unknown view falls back to the
	 * list. A ?ticketoo_ticket=ID deep link (spec §5 email link) opens the
	 * ticket view for every view, access-checked through can_access().
	 *
	 * @param array $atts Attributes: view (list|form|ticket), id (ticket id).
	 * @return string Rendered HTML.
	 */
	public static function render( array $atts ): string {
		self::enqueue_assets();

		$atts = shortcode_atts(
			array(
				'view' => 'list',
				'id'   => 0,
			),
			$atts,
			'ticketoo'
		);

		$view      = sanitize_key( (string) $atts['view'] );
		$ticket_id = (int) $atts['id'];
		$query     = self::query_args();

		if ( ! in_array( $view, array( 'list', 'form', 'ticket' ), true ) ) {
			$view = 'list';
		}

		$deep_link = self::query_int( $query, 'ticketoo_ticket' );

		if ( 0 < $deep_link ) {
			$body = self::render_ticket_view( $deep_link, $query );
		} elseif ( 'form' === $view ) {
			$body = self::render_form_view();
		} elseif ( 'ticket' === $view && 0 < $ticket_id ) {
			$body = self::render_ticket_view( $ticket_id, $query );
		} else {
			$body = self::render_list_view( $query );
		}

		return self::notice_html( self::notice_code( $query ) ) . $body;
	}

	/**
	 * Resolves a template file: filter override, theme override, plugin default.
	 *
	 * Sources are checked in that order: the ticketoo_template_path filter may
	 * return an absolute path to a readable file; otherwise a
	 * yourtheme/ticketoo/{name}.php file wins over the plugin's own
	 * templates/{name}.php (spec §3: standard WP template-hierarchy style).
	 *
	 * @param string $name Template name without extension ('list', 'form', ...).
	 * @return string Absolute path, or '' when no source provides the template.
	 */
	public static function locate_template( string $name ): string {
		$file = sanitize_file_name( $name . '.php' );

		/**
		 * Filters the absolute path of a Ticketoo template file.
		 *
		 * A returned path that is not readable falls through to the next
		 * source, so themes may probe without breaking rendering.
		 *
		 * @since 0.1.0
		 * @param string $override Absolute path to a template file, or '' for none.
		 * @param string $name     Template name without extension.
		 */
		$override = (string) apply_filters( 'ticketoo_template_path', '', $name );

		if ( '' !== $override && is_readable( $override ) ) {
			return $override;
		}

		$theme = \locate_template( array( 'ticketoo/' . $file ) );

		if ( '' !== $theme ) {
			return $theme;
		}

		$plugin = TICKETOO_DIR . 'templates/' . $file;

		return is_readable( $plugin ) ? $plugin : '';
	}

	/**
	 * Builds the public URL of one ticket.
	 *
	 * The guest bearer token is appended only for logged-out requesters —
	 * guests need it in the link (spec §5), while owners and agents pass the
	 * access check without it and the token stays out of their URLs.
	 *
	 * @param Ticket      $ticket Ticket to link to.
	 * @param string|null $base   Base URL; defaults to the current request URL.
	 * @return string Escaped-ready absolute URL (escape with esc_url() on output).
	 */
	public static function ticket_url( Ticket $ticket, ?string $base = null ): string {
		$base = null !== $base ? $base : self::current_url();
		$args = array( 'ticketoo_ticket' => $ticket->id );

		if ( 0 === get_current_user_id() && null !== $ticket->guest_token && '' !== $ticket->guest_token ) {
			$args['token'] = $ticket->guest_token;
		}

		return add_query_arg( $args, $base );
	}

	/**
	 * Callback for template_redirect: turns a classic form POST into a
	 * redirect (post/redirect/get), keeping the no-JS flow free of resubmits.
	 *
	 * @return void
	 */
	public static function handle_post(): void {
		$result = self::process_post();

		if ( null === $result ) {
			return;
		}

		wp_safe_redirect( $result['url'] );
		exit;
	}

	/**
	 * Handles the plugin's classic form posts (create, reply, close).
	 *
	 * Verifies the nonce, then replays the payload through the matching REST
	 * handler with rest_do_request(), so validation, permissions, attachment
	 * uploads and the ticketoo_* actions behave exactly like the AJAX path.
	 *
	 * @return array{url: string}|null Null when the request is not one of the
	 *                                 plugin's forms (the request continues as a normal page
	 *                                 load); otherwise the redirect URL carrying a
	 *                                 ticketoo_notice code (created, replied, closed, error,
	 *                                 expired).
	 */
	public static function process_post(): ?array {
		if ( 'POST' !== self::request_method() ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read once; the nonce carried by this payload is verified below before anything is processed.
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$action = sanitize_key( self::post_str( $post, 'ticketoo_action' ) );

		if ( ! in_array( $action, array( 'create', 'reply', 'close' ), true ) ) {
			return null;
		}

		$return = self::return_url( $post );

		if ( ! self::verified_nonce( $post ) ) {
			return array( 'url' => add_query_arg( 'ticketoo_notice', 'expired', $return ) );
		}

		$request = self::rest_request( $action, $post );

		if ( null === $request ) {
			return array( 'url' => add_query_arg( 'ticketoo_notice', 'error', $return ) );
		}

		$response = rest_do_request( $request );

		if ( is_wp_error( $response ) ) {
			return array( 'url' => add_query_arg( 'ticketoo_notice', 'error', $return ) );
		}

		return array( 'url' => self::success_url( $action, $response->get_data(), $return ) );
	}

	/**
	 * Renders the ticket list for the logged-in user (spec §5: listing
	 * requires an authenticated user; guests get the guest notice).
	 *
	 * @param array $query Unslashed request query args.
	 * @return string Rendered HTML.
	 */
	private static function render_list_view( array $query ): string {
		if ( ! is_user_logged_in() ) {
			return self::render_guest_view( __( 'Please log in to view your tickets.', 'ticketoo' ) );
		}

		$statuses = self::statuses();
		$filtered = self::query_str( $query, 'ticketoo_status' );
		$status   = isset( $statuses[ $filtered ] ) ? $filtered : '';
		$page     = max( 1, self::query_int( $query, 'ticketoo_page' ) );

		$args = array(
			'user_id'  => get_current_user_id(),
			'page'     => $page,
			'per_page' => self::LIST_PER_PAGE,
		);

		if ( '' !== $status ) {
			$args['status'] = $status;
		}

		$result      = TicketRepository::list( $args );
		$total       = (int) $result['total'];
		$total_pages = max( 1, (int) ceil( $total / self::LIST_PER_PAGE ) );

		// A stale page link shows an empty page; clamp back to the last real one.
		if ( $page > $total_pages ) {
			$page   = $total_pages;
			$result = TicketRepository::list( array_merge( $args, array( 'page' => $page ) ) );
		}

		$context                  = self::base_context();
		$context['tickets']       = $result['items'];
		$context['filter_status'] = $status;
		$context['pagination']    = array(
			'page'        => $page,
			'total_pages' => $total_pages,
			'total'       => $total,
		);

		return self::load_template( 'list', $context );
	}

	/**
	 * Renders the new-ticket form (spec §5 guest flow starts here).
	 *
	 * @return string Rendered HTML.
	 */
	private static function render_form_view(): string {
		if ( ! is_user_logged_in() && ! self::guests_allowed() ) {
			return self::render_guest_view( __( 'Guest ticket creation is disabled. Please log in to open a ticket.', 'ticketoo' ) );
		}

		return self::load_template( 'form', self::base_context() );
	}

	/**
	 * Renders one ticket's conversation after an access check.
	 *
	 * The conversation window lands on the newest messages (the last page of
	 * the message list) so a reply always sits next to the latest activity;
	 * earlier pages stay reachable through plain links.
	 *
	 * @param int   $ticket_id Ticket id from the id attribute or deep link.
	 * @param array $query     Unslashed request query args (carries the guest token).
	 * @return string Rendered HTML.
	 */
	private static function render_ticket_view( int $ticket_id, array $query ): string {
		$ticket = TicketRepository::find( $ticket_id );

		if ( null === $ticket ) {
			return self::render_guest_view( __( 'This ticket link is invalid or has expired.', 'ticketoo' ) );
		}

		if ( ! self::can_access( $ticket, $query ) ) {
			return self::render_guest_view( __( 'You are not allowed to view this ticket. Use the link from your email or log in.', 'ticketoo' ) );
		}

		// A single fetch serves both items and total: for_ticket() always
		// returns the unpaginated count, so only a stale page link needs a
		// second, clamped fetch (the first call's rows are never discarded).
		$requested_page = max( 1, self::query_int( $query, 'ticketoo_msg_page' ) );
		$messages       = MessageRepository::for_ticket( $ticket->id, $requested_page, self::MESSAGES_PER_PAGE );
		$total          = (int) $messages['total'];
		$total_pages    = max( 1, (int) ceil( $total / self::MESSAGES_PER_PAGE ) );
		$page           = min( $requested_page, $total_pages );

		if ( $page !== $requested_page ) {
			$messages = MessageRepository::for_ticket( $ticket->id, $page, self::MESSAGES_PER_PAGE );
		}

		$context                           = self::base_context();
		$context['ticket']                 = $ticket;
		$context['messages']               = $messages['items'];
		$context['current_user_can_reply'] = true;
		$context['can_close']              = 'closed' !== $ticket->status;

		// A guest carries the token they already presented; the reply form
		// hands it back so the classic POST passes the REST permission check.
		$context['guest_token'] = 0 === get_current_user_id()
			? self::query_str( $query, 'token' )
			: '';
		$context['pagination']  = array(
			'page'        => $page,
			'total_pages' => $total_pages,
			'total'       => $total,
		);

		return self::load_template( 'conversation', $context );
	}

	/**
	 * Renders the guest notice: the no-access page for logged-out visitors,
	 * broken token links and disabled guest creation.
	 *
	 * @param string $message Translated notice text.
	 * @return string Rendered HTML.
	 */
	private static function render_guest_view( string $message ): string {
		$context = self::base_context();

		$context['notice_message'] = $message;
		$context['login_url']      = wp_login_url( self::current_url() );

		return self::load_template( 'guest-notice', $context );
	}

	/**
	 * Template context shared by every view.
	 *
	 * Keys: ticket, messages, current_user_can_reply, can_close, statuses,
	 * pagination, tickets, filter_status, page_url, notice_message, login_url,
	 * guest_token.
	 *
	 * @return array Context passed to the template files.
	 */
	private static function base_context(): array {
		return array(
			'ticket'                 => null,
			'messages'               => array(),
			'current_user_can_reply' => false,
			'can_close'              => false,
			'statuses'               => self::statuses(),
			'pagination'             => array(
				'page'        => 1,
				'total_pages' => 1,
				'total'       => 0,
			),
			'tickets'                => array(),
			'filter_status'          => '',
			'page_url'               => self::current_url(),
			'notice_message'         => '',
			'login_url'              => '',
			'guest_token'            => '',
		);
	}

	/**
	 * Includes one template with every context key made a local variable.
	 *
	 * The keys are the fixed set built by base_context() and are assigned
	 * explicitly (WPCS forbids extract()), so templates and theme overrides
	 * receive $ticket, $messages, $current_user_can_reply, $statuses,
	 * $pagination and the remaining context under the same names.
	 *
	 * @param string $name    Template name (list, form, conversation, guest-notice).
	 * @param array  $context Variables made available to the template.
	 * @return string Captured template output.
	 */
	private static function load_template( string $name, array $context ): string {
		$file = self::locate_template( $name );

		if ( '' === $file ) {
			return '';
		}

		$ticket                 = $context['ticket'];
		$messages               = $context['messages'];
		$current_user_can_reply = $context['current_user_can_reply'];
		$can_close              = $context['can_close'];
		$statuses               = $context['statuses'];
		$pagination             = $context['pagination'];
		$tickets                = $context['tickets'];
		$filter_status          = $context['filter_status'];
		$page_url               = $context['page_url'];
		$notice_message         = $context['notice_message'];
		$login_url              = $context['login_url'];
		$guest_token            = $context['guest_token'];

		ob_start();
		include $file;

		return (string) ob_get_clean();
	}

	/**
	 * The status slugs shown in filters and badges (slug => label),
	 * extensible through the ticketoo_statuses filter (spec §10).
	 *
	 * @return array Status slug => human-readable label.
	 */
	private static function statuses(): array {
		/**
		 * Filters the ticket statuses shown in the front-end views.
		 *
		 * @since 0.1.0
		 * @param array $statuses Status slug => human-readable label.
		 */
		$statuses = apply_filters(
			'ticketoo_statuses',
			array(
				'open'     => __( 'Open', 'ticketoo' ),
				'pending'  => __( 'Pending', 'ticketoo' ),
				'answered' => __( 'Answered', 'ticketoo' ),
				'closed'   => __( 'Closed', 'ticketoo' ),
			)
		);

		return is_array( $statuses ) ? $statuses : array();
	}

	/**
	 * Whether the requester may see a specific ticket.
	 *
	 * Mirrors FrontendController::current_user_can_access() (spec §8): agent
	 * capability, ownership, or the guest token presented in the query string
	 * — compared with hash_equals by TokenAccess::verify().
	 *
	 * @param Ticket $ticket Ticket being viewed.
	 * @param array  $query  Unslashed request query args.
	 * @return bool True when the requester may access the ticket.
	 */
	private static function can_access( Ticket $ticket, array $query ): bool {
		if ( current_user_can( Capabilities::CAP ) ) {
			return true;
		}

		$user_id = get_current_user_id();

		if ( 0 < $user_id && $ticket->user_id === $user_id ) {
			return true;
		}

		return TokenAccess::verify( self::query_str( $query, 'token' ), $ticket->guest_token );
	}

	/**
	 * Whether guests may open tickets, read through the SettingsPage
	 * accessor (option ticketoo_allow_guests, default on).
	 *
	 * Same decision as FrontendController::permission_create_ticket().
	 *
	 * @return bool True when guest ticket creation is enabled.
	 */
	private static function guests_allowed(): bool {
		return SettingsPage::get_allow_guests();
	}

	/**
	 * The current request URL as an absolute URL.
	 *
	 * REQUEST_URI is sanitized as a URL (esc_url_raw), never as text:
	 * sanitize_text_field() strips every percent-encoded octet, which would
	 * corrupt non-ASCII permalinks (this project targets Persian/RTL paths)
	 * and break form actions, filter/pagination hrefs, ticketoo_return
	 * redirect targets, wp_login_url() and ticket_url() bases.
	 *
	 * @return string Base URL for building filter, pagination and form links.
	 */
	private static function current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';

		return home_url( '' === $uri ? '/' : $uri );
	}

	/**
	 * The unslashed request query args; every value is sanitized at use.
	 *
	 * @return array Query key => raw value.
	 */
	private static function query_args(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only view parameters (deep link, filter, page, token); each value is sanitized where it is used and no state changes.
		$args = isset( $_GET ) ? wp_unslash( $_GET ) : array();

		return is_array( $args ) ? $args : array();
	}

	/**
	 * Reads one query value as a sanitized string.
	 *
	 * @param array  $query Unslashed query args.
	 * @param string $key   Query key.
	 * @return string Sanitized value, or '' when missing or not scalar.
	 */
	private static function query_str( array $query, string $key ): string {
		$value = $query[ $key ] ?? '';

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Reads one query value as a non-negative integer.
	 *
	 * @param array  $query Unslashed query args.
	 * @param string $key   Query key.
	 * @return int Parsed value, or 0 when missing or not numeric.
	 */
	private static function query_int( array $query, string $key ): int {
		return max( 0, (int) self::query_str( $query, $key ) );
	}

	/**
	 * The ticketoo_notice query code of the current request, sanitized.
	 *
	 * @param array $query Unslashed query args.
	 * @return string Notice code, or '' when there is none.
	 */
	private static function notice_code( array $query ): string {
		return sanitize_key( self::query_str( $query, 'ticketoo_notice' ) );
	}

	/**
	 * Renders the post/redirect/get notice block for a notice code.
	 *
	 * @param string $code Notice code (created, replied, closed, error, expired).
	 * @return string Escaped notice HTML, or '' for an unknown code.
	 */
	private static function notice_html( string $code ): string {
		$messages = array(
			'created' => __( 'Your ticket has been created.', 'ticketoo' ),
			'replied' => __( 'Your reply has been sent.', 'ticketoo' ),
			'closed'  => __( 'The ticket has now been closed.', 'ticketoo' ),
			'error'   => __( 'Your submission could not be saved. Please try again.', 'ticketoo' ),
			'expired' => __( 'The form has expired. Please try again.', 'ticketoo' ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return '';
		}

		$type = in_array( $code, array( 'error', 'expired' ), true ) ? 'error' : 'success';

		return sprintf(
			'<div class="ticketoo-notice ticketoo-notice--%1$s" role="status">%2$s</div>',
			esc_attr( $type ),
			esc_html( $messages[ $code ] )
		);
	}

	/**
	 * Registers the front-end stylesheet and enhancement script (only printed
	 * when a shortcode ran).
	 *
	 * The script consumes the localized `ticketooFrontend` payload — REST
	 * base URL, `wp_rest` nonce and translated UI strings (ADR 0003: without
	 * the script the server-rendered markup keeps working on its own).
	 *
	 * @return void
	 */
	private static function enqueue_assets(): void {
		wp_enqueue_style(
			'ticketoo-frontend',
			plugins_url( 'assets/css/frontend.css', TICKETOO_FILE ),
			array(),
			TICKETOO_VERSION
		);

		if ( wp_script_is( 'ticketoo-frontend', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'ticketoo-frontend',
			plugins_url( 'assets/js/frontend.js', TICKETOO_FILE ),
			array(),
			TICKETOO_VERSION,
			true
		);

		wp_localize_script(
			'ticketoo-frontend',
			'ticketooFrontend',
			array(
				'restUrl' => rest_url( 'ticketoo/v1' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'statuses' => self::statuses(),
					'empty'    => __( 'You have not opened a ticket yet.', 'ticketoo' ),
					'created'  => __( 'Your ticket has been created.', 'ticketoo' ),
					'replied'  => __( 'Your reply has been sent.', 'ticketoo' ),
					'closed'   => __( 'The ticket has now been closed.', 'ticketoo' ),
					'error'    => __( 'Your submission could not be saved. Please try again.', 'ticketoo' ),
					'you'      => __( 'You', 'ticketoo' ),
					'prev'     => __( 'Previous', 'ticketoo' ),
					'next'     => __( 'Next', 'ticketoo' ),
					'view'     => __( 'View ticket', 'ticketoo' ),
				),
			)
		);
	}

	/**
	 * The HTTP method of the current request.
	 *
	 * @return string Uppercased method name, or '' when unavailable.
	 */
	private static function request_method(): string {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';

		return strtoupper( $method );
	}

	/**
	 * Reads one classic POST value as a sanitized string.
	 *
	 * @param array  $post Unslashed POST payload.
	 * @param string $key  Field name.
	 * @return string Sanitized value, or '' when missing or not scalar.
	 */
	private static function post_str( array $post, string $key ): string {
		$value = $post[ $key ] ?? '';

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Reads one classic POST value as sanitized HTML (message bodies).
	 *
	 * @param array  $post Unslashed POST payload.
	 * @param string $key  Field name.
	 * @return string wp_kses_post()-sanitized value, or '' when missing.
	 */
	private static function post_html( array $post, string $key ): string {
		$value = $post[ $key ] ?? '';

		return is_scalar( $value ) ? wp_kses_post( (string) $value ) : '';
	}

	/**
	 * Verifies whichever nonce field the posting form carried.
	 *
	 * Each form renders its own field name so a page with several Ticketoo
	 * forms never repeats an element id; all share NONCE_ACTION.
	 *
	 * @param array $post Unslashed POST payload.
	 * @return bool True when one of the plugin's nonces verifies.
	 */
	private static function verified_nonce( array $post ): bool {
		$fields = array( self::NONCE_FIELD, self::REPLY_NONCE_FIELD, self::CLOSE_NONCE_FIELD );

		foreach ( $fields as $field ) {
			$nonce = self::post_str( $post, $field );

			if ( '' !== $nonce && wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The safe URL to fall back to after a classic post.
	 *
	 * @param array $post Unslashed POST payload (carries ticketoo_return).
	 * @return string Validated URL; defaults to the current request URL.
	 */
	private static function return_url( array $post ): string {
		$url = self::post_str( $post, 'ticketoo_return' );

		if ( '' === $url ) {
			return self::current_url();
		}

		return (string) wp_validate_redirect( $url, self::current_url() );
	}

	/**
	 * Builds the REST request that mirrors one classic form post.
	 *
	 * File inputs travel through $_FILES exactly as they arrive, so the REST
	 * attachment validation (type allow-list, size cap) applies unchanged.
	 *
	 * @param string $action One of create, reply, close.
	 * @param array  $post   Unslashed POST payload.
	 * @return WP_REST_Request|null Null when the payload names no such ticket.
	 */
	private static function rest_request( string $action, array $post ): ?WP_REST_Request {
		if ( 'create' === $action ) {
			$request = new WP_REST_Request( 'POST', '/ticketoo/v1/tickets' );
			$request->set_body_params(
				array(
					'subject' => self::post_str( $post, 'ticketoo_subject' ),
					'content' => self::post_html( $post, 'ticketoo_content' ),
					'email'   => self::post_str( $post, 'ticketoo_email' ),
				)
			);

			return $request;
		}

		$ticket_id = (int) self::post_str( $post, 'ticketoo_id' );

		if ( 1 > $ticket_id || null === TicketRepository::find( $ticket_id ) ) {
			return null;
		}

		if ( 'reply' === $action ) {
			$request = new WP_REST_Request( 'POST', sprintf( '/ticketoo/v1/tickets/%d/messages', $ticket_id ) );
			$request->set_body_params(
				array(
					'content' => self::post_html( $post, 'ticketoo_content' ),
					'token'   => self::post_str( $post, 'ticketoo_token' ),
				)
			);

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce of this same payload was verified in process_post() before this runs; file inputs travel with it.
			$request->set_file_params( isset( $_FILES ) && is_array( $_FILES ) ? $_FILES : array() );

			return $request;
		}

		$request = new WP_REST_Request( 'POST', sprintf( '/ticketoo/v1/tickets/%d/status', $ticket_id ) );
		$request->set_body_params( array( 'status' => 'closed' ) );

		return $request;
	}

	/**
	 * Builds the redirect target after a successful classic post: the ticket
	 * deep link plus its notice code.
	 *
	 * @param string $action One of create, reply, close.
	 * @param mixed  $data   REST response payload.
	 * @param string $return_url Fallback URL when the ticket cannot be re-read.
	 * @return string Redirect URL.
	 */
	private static function success_url( string $action, mixed $data, string $return_url ): string {
		$notices = array(
			'create' => 'created',
			'reply'  => 'replied',
			'close'  => 'closed',
		);

		$id = 0;

		if ( is_array( $data ) ) {
			$id = 'reply' === $action ? (int) ( $data['ticket_id'] ?? 0 ) : (int) ( $data['id'] ?? 0 );
		}

		$ticket = 0 < $id ? TicketRepository::find( $id ) : null;

		if ( null === $ticket ) {
			return add_query_arg( 'ticketoo_notice', 'error', $return_url );
		}

		return add_query_arg(
			'ticketoo_notice',
			$notices[ $action ],
			self::ticket_url( $ticket, $return_url )
		);
	}
}
