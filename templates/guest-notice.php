<?php
/**
 * Guest notice template: the no-access page for logged-out visitors, broken
 * token links and disabled guest creation.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ticketoo ticketoo-view-notice">
	<div class="ticketoo-guest-notice" role="status">
		<p class="ticketoo-guest-notice__text"><?php echo esc_html( $notice_message ); ?></p>
		<?php if ( '' !== $login_url ) : ?>
			<p class="ticketoo-guest-notice__actions">
				<a class="ticketoo-button" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'ticketoo' ); ?></a>
			</p>
		<?php endif; ?>
	</div>
</div>
