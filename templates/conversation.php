<?php
/**
 * Conversation template: one ticket's messages, the reply form and the
 * close control.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Shortcode\TicketooShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ticketoo ticketoo-view-ticket">
	<header class="ticketoo-ticket__header">
		<h2 class="ticketoo-ticket__subject"><?php echo esc_html( $ticket->subject ); ?></h2>
		<span class="ticketoo-badge ticketoo-badge--<?php echo esc_attr( $ticket->status ); ?>">
			<?php echo esc_html( $statuses[ $ticket->status ] ?? $ticket->status ); ?>
		</span>
		<?php if ( $can_close && is_user_logged_in() ) : ?>
			<form class="ticketoo-close" method="post" action="<?php echo esc_url( $page_url ); ?>">
				<input type="hidden" name="ticketoo_action" value="close" />
				<input type="hidden" name="ticketoo_id" value="<?php echo esc_attr( (string) $ticket->id ); ?>" />
				<input type="hidden" name="ticketoo_return" value="<?php echo esc_url( $page_url ); ?>" />
				<?php wp_nonce_field( TicketooShortcode::NONCE_ACTION, TicketooShortcode::CLOSE_NONCE_FIELD ); ?>
				<button
					type="submit"
					class="ticketoo-button ticketoo-button--close"
					data-close-ticket="<?php echo esc_attr( (string) $ticket->id ); ?>"
				><?php esc_html_e( 'Close ticket', 'ticketoo' ); ?></button>
			</form>
		<?php endif; ?>
	</header>

	<ol class="ticketoo-messages">
		<?php foreach ( $messages as $message ) : ?>
			<?php
			$author_class = 1 === (int) $message->is_agent ? 'agent' : 'user';
			$author_name  = $message->email;

			if ( 0 < $message->user_id ) {
				$author = get_userdata( $message->user_id );

				if ( false !== $author ) {
					$author_name = (string) $author->display_name;
				}
			}
			?>
			<li class="ticketoo-message ticketoo-message--<?php echo esc_attr( $author_class ); ?>">
				<span class="ticketoo-message__author"><?php echo esc_html( $author_name ); ?></span>
				<time class="ticketoo-message__time" datetime="<?php echo esc_attr( $message->created_at ); ?>">
					<?php echo esc_html( $message->created_at ); ?>
				</time>
				<div class="ticketoo-message__body"><?php echo wp_kses_post( $message->content ); ?></div>
			</li>
		<?php endforeach; ?>
	</ol>

	<?php if ( 1 < $pagination['total_pages'] ) : ?>
		<nav class="ticketoo-messages-nav">
			<?php if ( 1 < $pagination['page'] ) : ?>
				<a class="ticketoo-messages-nav__link" href="<?php echo esc_url( add_query_arg( 'ticketoo_msg_page', $pagination['page'] - 1, $page_url ) ); ?>">
					<?php esc_html_e( 'Earlier messages', 'ticketoo' ); ?>
				</a>
			<?php endif; ?>
			<?php if ( $pagination['page'] < $pagination['total_pages'] ) : ?>
				<a class="ticketoo-messages-nav__link" href="<?php echo esc_url( add_query_arg( 'ticketoo_msg_page', $pagination['page'] + 1, $page_url ) ); ?>">
					<?php esc_html_e( 'Later messages', 'ticketoo' ); ?>
				</a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>

	<?php if ( $current_user_can_reply ) : ?>
		<form
			id="ticketoo-reply"
			class="ticketoo-reply"
			method="post"
			action="<?php echo esc_url( $page_url ); ?>"
			enctype="multipart/form-data"
		>
			<input type="hidden" name="ticketoo_action" value="reply" />
			<input type="hidden" name="ticketoo_id" value="<?php echo esc_attr( (string) $ticket->id ); ?>" />
			<input type="hidden" name="ticketoo_return" value="<?php echo esc_url( $page_url ); ?>" />
			<?php if ( '' !== $guest_token ) : ?>
				<input type="hidden" name="ticketoo_token" value="<?php echo esc_attr( $guest_token ); ?>" />
			<?php endif; ?>
			<?php wp_nonce_field( TicketooShortcode::NONCE_ACTION, TicketooShortcode::REPLY_NONCE_FIELD ); ?>

			<p class="ticketoo-field">
				<label for="ticketoo_content"><?php esc_html_e( 'Your reply', 'ticketoo' ); ?></label>
				<textarea id="ticketoo_content" name="ticketoo_content" rows="4" required="required"></textarea>
			</p>

			<p class="ticketoo-field">
				<label for="ticketoo_files"><?php esc_html_e( 'Attach files', 'ticketoo' ); ?></label>
				<input type="file" id="ticketoo_files" name="files[]" multiple="multiple" />
			</p>

			<p class="ticketoo-actions">
				<button type="submit" class="ticketoo-button"><?php esc_html_e( 'Send reply', 'ticketoo' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>
