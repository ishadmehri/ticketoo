<?php
/**
 * Email notifications for every ticket event (spec 7).
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Email;

use Ticketoo\Admin\Capabilities;
use Ticketoo\Admin\SettingsPage;
use Ticketoo\Database\MessageRepository;
use Ticketoo\Database\TicketRepository;
use Ticketoo\Model\Ticket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and sends the plugin's notification emails.
 *
 * Every send runs through deliver(), which applies the
 * ticketoo_email_subject / ticketoo_email_body / ticketoo_email_headers
 * filters, fires ticketoo_before_send_email, honours the
 * ticketoo_defer_email short-circuit (return true to queue instead of
 * sending) and only then calls wp_mail(). No email ever goes to the
 * address that triggered the event, and the guest bearer token is put
 * into a link only in emails addressed to that ticket's own guest owner -
 * agents never receive it.
 */
class Notifier {

	/**
	 * Notifies every capability holder that a ticket was created, then
	 * sends a guest owner their creation link.
	 *
	 * The guest link email is mandated by spec 5: the guest create REST
	 * response carries no token, so this email is the only way the guest
	 * ever receives their bearer link. It goes to the guest's address
	 * alone - by design that is the triggerer's own address here.
	 *
	 * Hooked to ticketoo_ticket_created (accepted args: 1).
	 *
	 * @since 0.1.0
	 * @param int $ticket_id New ticket id.
	 * @return void
	 */
	public static function on_ticket_created( int $ticket_id ): void {
		$ticket = TicketRepository::find( $ticket_id );

		if ( null === $ticket ) {
			return;
		}

		$body = sprintf(
			/* translators: 1: ticket number, 2: ticket subject, 3: excerpt of the opening message. */
			__( 'Ticket #%1$d "%2$s" has been created: %3$s', 'ticketoo' ),
			$ticket->id,
			$ticket->subject,
			self::excerpt( $ticket->id )
		);

		self::deliver(
			self::without( self::agent_emails(), self::actor_email( $ticket ) ),
			'ticket_created',
			$ticket,
			$body,
			self::ticket_url( $ticket, false )
		);

		if ( ! self::is_guest_ticket( $ticket ) || '' === $ticket->email ) {
			return;
		}

		$body = sprintf(
			/* translators: %d: ticket number. */
			__( 'We received your ticket #%d. Use the link below to view the conversation and reply at any time.', 'ticketoo' ),
			$ticket->id
		);

		self::deliver(
			array( $ticket->email ),
			'guest_created',
			$ticket,
			$body,
			self::ticket_url( $ticket, true )
		);
	}

	/**
	 * Routes a stored reply to the agent or the user notifier.
	 *
	 * Hooked to ticketoo_ticket_replied (accepted args: 2).
	 *
	 * @since 0.1.0
	 * @param int  $ticket_id Ticket id.
	 * @param bool $is_agent  True when an agent wrote the reply.
	 * @return void
	 */
	public static function on_ticket_replied( int $ticket_id, bool $is_agent ): void {
		if ( $is_agent ) {
			self::on_agent_reply( $ticket_id );

			return;
		}

		self::on_user_reply( $ticket_id );
	}

	/**
	 * Notifies the ticket owner that support replied.
	 *
	 * The owner's email column is the recipient; a guest owner receives
	 * the token link, a logged-in owner the plain conversation URL.
	 *
	 * @since 0.1.0
	 * @param int $ticket_id Ticket id.
	 * @return void
	 */
	public static function on_agent_reply( int $ticket_id ): void {
		$ticket = TicketRepository::find( $ticket_id );

		if ( null === $ticket || '' === $ticket->email ) {
			return;
		}

		$body = sprintf(
			/* translators: 1: ticket number, 2: ticket subject. */
			__( 'Support replied to your ticket #%1$d: %2$s. Follow the link below to read the answer and respond.', 'ticketoo' ),
			$ticket->id,
			$ticket->subject
		);

		self::deliver(
			self::without( array( $ticket->email ), self::actor_email( $ticket ) ),
			'agent_reply',
			$ticket,
			$body,
			self::ticket_url( $ticket, self::is_guest_ticket( $ticket ) )
		);
	}

	/**
	 * Notifies the assigned agent - or every agent when the ticket is
	 * unassigned - that the customer replied.
	 *
	 * The sender's own address is dropped from the recipients, and the
	 * link never carries a guest token because the recipients are agents.
	 *
	 * @since 0.1.0
	 * @param int $ticket_id Ticket id.
	 * @return void
	 */
	public static function on_user_reply( int $ticket_id ): void {
		$ticket = TicketRepository::find( $ticket_id );

		if ( null === $ticket ) {
			return;
		}

		$recipients = array();

		if ( null !== $ticket->assigned_to && 0 < $ticket->assigned_to ) {
			$assignee = get_userdata( $ticket->assigned_to );

			if ( false !== $assignee ) {
				$recipients[] = (string) $assignee->user_email;
			}
		} else {
			$recipients = self::agent_emails();
		}

		$body = sprintf(
			/* translators: 1: ticket number, 2: ticket subject. */
			__( 'A new reply was posted on ticket #%1$d: %2$s. Follow the link below to view the conversation.', 'ticketoo' ),
			$ticket->id,
			$ticket->subject
		);

		self::deliver(
			self::without( $recipients, self::actor_email( $ticket ) ),
			'user_reply',
			$ticket,
			$body,
			self::ticket_url( $ticket, false )
		);
	}

	/**
	 * Notifies the owner that the ticket was auto-closed and how to
	 * reopen it.
	 *
	 * Not wired to a hook: AutoClose's sweep calls it directly after it
	 * closes a stale ticket.
	 *
	 * @since 0.1.0
	 * @param int $ticket_id Ticket id.
	 * @return void
	 */
	public static function on_auto_closed( int $ticket_id ): void {
		$ticket = TicketRepository::find( $ticket_id );

		if ( null === $ticket || '' === $ticket->email ) {
			return;
		}

		$body = sprintf(
			/* translators: %d: ticket number. */
			__( 'Your ticket #%d has been closed due to inactivity. Reply to reopen it.', 'ticketoo' ),
			$ticket->id
		);

		self::deliver(
			array( $ticket->email ),
			'auto_closed',
			$ticket,
			$body,
			self::ticket_url( $ticket, self::is_guest_ticket( $ticket ) )
		);
	}

	/**
	 * Renders and sends one notification email.
	 *
	 * @param string[] $to      Recipient addresses (already minus the triggerer).
	 * @param string   $event   Event slug pinned into the filter/action context.
	 * @param Ticket   $ticket  Ticket the email is about.
	 * @param string   $body    Translated plain-text body sentence(s).
	 * @param string   $url     Link shown in the email; token-bearing only
	 *                          for the guest owner's own address.
	 * @return void
	 */
	private static function deliver( array $to, string $event, Ticket $ticket, string $body, string $url ): void {
		$to = self::clean_recipients( $to );

		if ( array() === $to ) {
			return;
		}

		$context = array(
			'event'     => $event,
			'ticket_id' => $ticket->id,
		);

		$subject = (string) apply_filters( 'ticketoo_email_subject', self::subject( $event, $ticket ), $context );
		$message = self::render(
			array(
				'subject'   => $subject,
				'body'      => $body,
				'url'       => $url,
				'site_name' => get_bloginfo( 'name' ),
				'lang'      => get_bloginfo( 'language' ),
			)
		);
		$message = (string) apply_filters( 'ticketoo_email_body', $message, $context );
		$headers = (array) apply_filters( 'ticketoo_email_headers', self::headers(), $context );

		/**
		 * Fires once an email is fully built, immediately before wp_mail().
		 *
		 * @since 0.1.0
		 * @param string[]                          $to      Recipient addresses.
		 * @param string                            $subject Email subject.
		 * @param string                            $message Rendered HTML body.
		 * @param string[]                          $headers Email headers.
		 * @param array{event: string, ticket_id: int} $context Event context.
		 */
		do_action( 'ticketoo_before_send_email', $to, $subject, $message, $headers, $context );

		/**
		 * Filters whether wp_mail() should be skipped so a listener can
		 * queue the send instead (spec 7: ticketoo_defer_email hook for
		 * future queueing).
		 *
		 * @since 0.1.0
		 * @param bool                             $defer    True to skip wp_mail().
		 * @param string[]                         $to       Recipient addresses.
		 * @param string                           $subject  Email subject.
		 * @param array{event: string, ticket_id: int} $context Event context.
		 */
		if ( apply_filters( 'ticketoo_defer_email', false, $to, $subject, $context ) ) {
			return;
		}

		wp_mail( $to, $subject, $message, $headers );
	}

	/**
	 * The subject line for an event.
	 *
	 * @param string $event Event slug.
	 * @param Ticket $ticket Ticket the email is about.
	 * @return string Translated subject.
	 */
	private static function subject( string $event, Ticket $ticket ): string {
		switch ( $event ) {
			case 'ticket_created':
				return sprintf(
					/* translators: 1: ticket number, 2: ticket subject. */
					__( 'New ticket #%1$d: %2$s', 'ticketoo' ),
					$ticket->id,
					$ticket->subject
				);

			case 'guest_created':
				return sprintf(
					/* translators: %d: ticket number. */
					__( 'Your ticket #%d has been received', 'ticketoo' ),
					$ticket->id
				);

			case 'agent_reply':
				return sprintf(
					/* translators: 1: ticket number, 2: ticket subject. */
					__( 'Support replied to ticket #%1$d: %2$s', 'ticketoo' ),
					$ticket->id,
					$ticket->subject
				);

			case 'user_reply':
				return sprintf(
					/* translators: 1: ticket number, 2: ticket subject. */
					__( 'New reply on ticket #%1$d: %2$s', 'ticketoo' ),
					$ticket->id,
					$ticket->subject
				);

			case 'auto_closed':
			default:
				return sprintf(
					/* translators: %d: ticket number. */
					__( 'Ticket #%d has been closed', 'ticketoo' ),
					$ticket->id
				);
		}
	}

	/**
	 * Renders templates/email/default.php with every {{placeholder}}
	 * replaced by its escaped value.
	 *
	 * Text placeholders run through esc_html(). The url placeholder runs
	 * through esc_url_raw() because esc_url() would rewrite the '&' of the
	 * guest token link to &#038;, breaking the exact link spec 5 mandates;
	 * esc_url_raw() still strips everything unhrefable and the URL itself
	 * is assembled by ticket_url() from home_url(), an integer id and a
	 * 64-hex token, so no user-controlled bytes reach the HTML unscreened.
	 *
	 * @param array<string, string> $vars Raw placeholder values.
	 * @return string Rendered HTML.
	 */
	private static function render( array $vars ): string {
		$file = TICKETOO_DIR . 'templates/email/default.php';

		if ( ! is_readable( $file ) ) {
			return '';
		}

		ob_start();
		include $file;
		$html = (string) ob_get_clean();

		$replacements = array();

		foreach ( $vars as $key => $value ) {
			$replacements[ '{{' . $key . '}}' ] = 'url' === $key
				? esc_url_raw( $value )
				: esc_html( $value );
		}

		return strtr( $html, $replacements );
	}

	/**
	 * The HTML email headers: content type plus the configured sender.
	 *
	 * The From pair comes from the settings accessors (spec 10) with the
	 * site name and admin address as fallbacks, as those accessors
	 * document.
	 *
	 * @return string[] Header lines.
	 */
	private static function headers(): array {
		$from_name = SettingsPage::get_from_name();
		$from_name = '' !== $from_name ? $from_name : get_bloginfo( 'name' );

		$from_email = SettingsPage::get_from_email();
		$from_email = '' !== $from_email ? $from_email : get_bloginfo( 'admin_email' );

		$headers = array(
			sprintf( 'Content-Type: text/html; charset=%s', get_bloginfo( 'charset' ) ),
		);

		if ( '' !== $from_name && is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}

		return $headers;
	}

	/**
	 * A trimmed, tag-free excerpt of a ticket's opening message.
	 *
	 * @param int $ticket_id Ticket id.
	 * @return string Excerpt, or '' when the ticket has no message yet.
	 */
	private static function excerpt( int $ticket_id ): string {
		$messages = MessageRepository::for_ticket( $ticket_id, 1, 1 );

		if ( array() === $messages['items'] ) {
			return '';
		}

		return wp_trim_words( wp_strip_all_tags( (string) $messages['items'][0]->content ), 20 );
	}

	/**
	 * Email addresses of every ticketoo_manage_tickets holder.
	 *
	 * @return string[] Addresses (may contain empty strings; cleaned later).
	 */
	private static function agent_emails(): array {
		$emails = array();

		foreach ( get_users( array( 'capability' => Capabilities::CAP ) ) as $user ) {
			$emails[] = (string) $user->user_email;
		}

		return $emails;
	}

	/**
	 * The address that triggered the current event: the logged-in user's
	 * email, or the ticket's own email for guest requests.
	 *
	 * @param Ticket $ticket Ticket being acted on.
	 * @return string Address, or '' when none applies.
	 */
	private static function actor_email( Ticket $ticket ): string {
		$user = wp_get_current_user();

		if ( $user->exists() && '' !== $user->user_email ) {
			return (string) $user->user_email;
		}

		return (string) $ticket->email;
	}

	/**
	 * Removes one address from a recipient list (case-insensitive).
	 *
	 * @param string[] $emails  Candidate addresses.
	 * @param string   $exclude Address that must not be emailed.
	 * @return string[] Remaining addresses.
	 */
	private static function without( array $emails, string $exclude ): array {
		if ( '' === $exclude ) {
			return $emails;
		}

		return array_values(
			array_filter(
				$emails,
				static fn ( string $email ): bool => strtolower( $email ) !== strtolower( $exclude )
			)
		);
	}

	/**
	 * Sanitizes, validates and de-duplicates recipient addresses.
	 *
	 * @param string[] $emails Candidate addresses.
	 * @return string[] Deliverable addresses.
	 */
	private static function clean_recipients( array $emails ): array {
		$clean = array();

		foreach ( $emails as $email ) {
			$email = sanitize_email( (string) $email );

			if ( '' !== $email && is_email( $email ) ) {
				$clean[] = $email;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * The conversation URL for a ticket.
	 *
	 * @param Ticket $ticket     Ticket to link to.
	 * @param bool   $with_token Append the guest bearer token; callers only
	 *                           pass true for emails addressed to that
	 *                           ticket's own guest owner.
	 * @return string Absolute URL.
	 */
	private static function ticket_url( Ticket $ticket, bool $with_token ): string {
		$args = array( 'ticketoo_ticket' => $ticket->id );

		if ( $with_token && self::is_guest_ticket( $ticket ) ) {
			$args['token'] = (string) $ticket->guest_token;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Whether the ticket is a guest ticket carrying a bearer token.
	 *
	 * @param Ticket $ticket Ticket to inspect.
	 * @return bool True for guests with a token.
	 */
	private static function is_guest_ticket( Ticket $ticket ): bool {
		return 0 === $ticket->user_id
			&& null !== $ticket->guest_token
			&& '' !== $ticket->guest_token;
	}
}
