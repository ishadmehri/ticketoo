<?php
/**
 * Ticket list template: the requester's own tickets with status filters and
 * pagination.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Shortcode\TicketooShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$all_url = remove_query_arg( array( 'ticketoo_status', 'ticketoo_page' ), $page_url );
?>
<div class="ticketoo ticketoo-view-list">
	<div class="ticketoo-filters">
		<a
			class="ticketoo-filter<?php echo '' === $filter_status ? ' is-active' : ''; ?>"
			href="<?php echo esc_url( $all_url ); ?>"
			data-filter-status=""
		><?php esc_html_e( 'All', 'ticketoo' ); ?></a>
		<?php foreach ( $statuses as $status_slug => $status_label ) : ?>
			<?php
			$status_url = add_query_arg(
				array(
					'ticketoo_status' => $status_slug,
					'ticketoo_page'   => 1,
				),
				$page_url
			);
			?>
			<a
				class="ticketoo-filter<?php echo $filter_status === $status_slug ? ' is-active' : ''; ?>"
				href="<?php echo esc_url( $status_url ); ?>"
				data-filter-status="<?php echo esc_attr( $status_slug ); ?>"
			><?php echo esc_html( $status_label ); ?></a>
		<?php endforeach; ?>
	</div>

	<div class="ticketoo-list">
		<?php if ( array() === $tickets ) : ?>
			<p class="ticketoo-empty"><?php esc_html_e( 'You have not opened a ticket yet.', 'ticketoo' ); ?></p>
		<?php else : ?>
			<ul class="ticketoo-items">
				<?php foreach ( $tickets as $ticket ) : ?>
					<li class="ticketoo-item ticketoo-item--<?php echo esc_attr( $ticket->status ); ?>">
						<a class="ticketoo-item__link" href="<?php echo esc_url( TicketooShortcode::ticket_url( $ticket, $page_url ) ); ?>">
							<span class="ticketoo-item__subject"><?php echo esc_html( $ticket->subject ); ?></span>
						</a>
						<span class="ticketoo-badge ticketoo-badge--<?php echo esc_attr( $ticket->status ); ?>">
							<?php echo esc_html( $statuses[ $ticket->status ] ?? $ticket->status ); ?>
						</span>
						<time class="ticketoo-item__time" datetime="<?php echo esc_attr( $ticket->last_activity_at ); ?>">
							<?php echo esc_html( $ticket->last_activity_at ); ?>
						</time>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>

	<?php if ( 1 < $pagination['total_pages'] ) : ?>
		<nav class="ticketoo-pagination">
			<?php if ( 1 < $pagination['page'] ) : ?>
				<a
					class="ticketoo-pagination__link"
					href="<?php echo esc_url( add_query_arg( 'ticketoo_page', $pagination['page'] - 1, $page_url ) ); ?>"
					data-page="<?php echo esc_attr( (string) ( $pagination['page'] - 1 ) ); ?>"
				><?php esc_html_e( 'Previous', 'ticketoo' ); ?></a>
			<?php endif; ?>
			<?php for ( $page_number = 1; $page_number <= $pagination['total_pages']; $page_number++ ) : ?>
				<a
					class="ticketoo-pagination__link<?php echo $page_number === $pagination['page'] ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( 'ticketoo_page', $page_number, $page_url ) ); ?>"
					data-page="<?php echo esc_attr( (string) $page_number ); ?>"
				><?php echo esc_html( (string) $page_number ); ?></a>
			<?php endfor; ?>
			<?php if ( $pagination['page'] < $pagination['total_pages'] ) : ?>
				<a
					class="ticketoo-pagination__link"
					href="<?php echo esc_url( add_query_arg( 'ticketoo_page', $pagination['page'] + 1, $page_url ) ); ?>"
					data-page="<?php echo esc_attr( (string) ( $pagination['page'] + 1 ) ); ?>"
				><?php esc_html_e( 'Next', 'ticketoo' ); ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
</div>
