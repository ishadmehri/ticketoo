<?php
/**
 * New-ticket form template: a classic self-posting form that the front-end
 * scripts enhance (ADR 0003: markup already works without JavaScript).
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Shortcode\TicketooShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ticketoo ticketoo-view-form">
	<form
		id="ticketoo-form"
		class="ticketoo-form"
		method="post"
		action="<?php echo esc_url( $page_url ); ?>"
	>
		<input type="hidden" name="ticketoo_action" value="create" />
		<input type="hidden" name="ticketoo_return" value="<?php echo esc_url( $page_url ); ?>" />
		<?php wp_nonce_field( TicketooShortcode::NONCE_ACTION, TicketooShortcode::NONCE_FIELD ); ?>

		<p class="ticketoo-field">
			<label for="ticketoo_subject"><?php esc_html_e( 'Subject', 'ticketoo' ); ?></label>
			<input type="text" id="ticketoo_subject" name="ticketoo_subject" required="required" />
		</p>

		<?php if ( ! is_user_logged_in() ) : ?>
			<p class="ticketoo-field">
				<label for="ticketoo_email"><?php esc_html_e( 'Your email', 'ticketoo' ); ?></label>
				<input type="email" id="ticketoo_email" name="ticketoo_email" required="required" />
			</p>
		<?php endif; ?>

		<p class="ticketoo-field">
			<label for="ticketoo_content"><?php esc_html_e( 'Message', 'ticketoo' ); ?></label>
			<textarea id="ticketoo_content" name="ticketoo_content" rows="6" required="required"></textarea>
		</p>

		<p class="ticketoo-actions">
			<button type="submit" class="ticketoo-button"><?php esc_html_e( 'Open ticket', 'ticketoo' ); ?></button>
		</p>
	</form>
</div>
