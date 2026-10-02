<?php
/**
 * Top-level admin menu page and the support panel shell.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Ticketoo admin menu and prints the static panel shell the
 * admin enhancement script (assets/js/admin.js) mounts onto (spec §6).
 */
class MenuPage {

	/**
	 * Menu slug, also the `page` query arg of the panel screen.
	 *
	 * @var string
	 */
	const SLUG = 'ticketoo';

	/**
	 * Adds the top-level menu page, gated by the plugin capability so
	 * wp-admin prints it only for agents and administrators.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_menu_page(
			__( 'Ticketoo', 'ticketoo' ),
			__( 'Ticketoo', 'ticketoo' ),
			Capabilities::CAP,
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-tickets-alt',
			26
		);
	}

	/**
	 * Whether the current admin screen is the Ticketoo panel.
	 *
	 * Asset loading (spec §6: "Plugin CSS/JS enqueued only on this screen")
	 * is keyed off this.
	 *
	 * @return bool True on the panel screen, false everywhere else.
	 */
	public static function is_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen instanceof \WP_Screen && 'toplevel_page_' . self::SLUG === $screen->id;
	}

	/**
	 * Enqueues the panel stylesheet and enhancement script — only on the
	 * Ticketoo screen, never across wp-admin or the front end.
	 *
	 * The script consumes the localized `ticketooAdmin` payload: REST base
	 * URL, `wp_rest` nonce, the counter refresh interval (option
	 * ticketoo_counter_refresh_seconds) and translated UI strings.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_screen() ) {
			return;
		}

		wp_enqueue_style(
			'ticketoo-admin',
			plugins_url( 'assets/css/admin.css', TICKETOO_FILE ),
			array(),
			TICKETOO_VERSION
		);

		if ( ! file_exists( TICKETOO_DIR . 'assets/js/admin.js' ) ) {
			return;
		}

		wp_enqueue_script(
			'ticketoo-admin',
			plugins_url( 'assets/js/admin.js', TICKETOO_FILE ),
			array(),
			TICKETOO_VERSION,
			true
		);

		wp_localize_script(
			'ticketoo-admin',
			'ticketooAdmin',
			array(
				'restUrl'         => rest_url( 'ticketoo/v1' ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'counterInterval' => SettingsPage::get_counter_refresh_seconds(),
				'i18n'            => array(
					'statuses'       => self::statuses(),
					'empty'          => __( 'No tickets found.', 'ticketoo' ),
					'error'          => __( 'The request could not be completed. Please try again.', 'ticketoo' ),
					'you'            => __( 'You', 'ticketoo' ),
					'prev'           => __( 'Previous', 'ticketoo' ),
					'next'           => __( 'Next', 'ticketoo' ),
					'view'           => __( 'View ticket', 'ticketoo' ),
					'reply'          => __( 'Reply', 'ticketoo' ),
					'assign'         => __( 'Assign', 'ticketoo' ),
					'unassigned'     => __( 'Unassigned', 'ticketoo' ),
					'close'          => __( 'Close', 'ticketoo' ),
					'loading'        => __( 'Loading…', 'ticketoo' ),
					'your_reply'     => __( 'Your reply', 'ticketoo' ),
					'attach'         => __( 'Attach files', 'ticketoo' ),
					'replied'        => __( 'Your reply has been sent.', 'ticketoo' ),
					'assigned'       => __( 'The ticket has been assigned.', 'ticketoo' ),
					'status_changed' => __( 'The ticket status has been updated.', 'ticketoo' ),
				),
			)
		);
	}

	/**
	 * Prints the panel shell: the container the admin script fills with
	 * REST data, carrying the endpoint base and the nonce every request is
	 * signed with, plus the static filter/toolbar/table skeleton shown
	 * until the data arrives.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ticketoo' ) );
		}

		?>
		<div class="wrap ticketoo-wrap">
			<h1><?php esc_html_e( 'Support Tickets', 'ticketoo' ); ?></h1>

			<div
				id="ticketoo-app"
				data-rest-url="<?php echo esc_url( rest_url( 'ticketoo/v1' ) ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			>
				<div class="ticketoo-toolbar">
					<div class="ticketoo-filters">
						<button type="button" class="ticketoo-filter is-active" data-filter-status="">
							<?php esc_html_e( 'All', 'ticketoo' ); ?>
						</button>
						<?php foreach ( self::statuses() as $slug => $label ) : ?>
							<button type="button" class="ticketoo-filter" data-filter-status="<?php echo esc_attr( $slug ); ?>">
								<?php echo esc_html( $label ); ?>
							</button>
						<?php endforeach; ?>
					</div>

					<label class="screen-reader-text" for="ticketoo-agent-filter">
						<?php esc_html_e( 'Filter by agent', 'ticketoo' ); ?>
					</label>
					<select id="ticketoo-agent-filter" class="ticketoo-agent-filter" data-filter-agent>
						<option value="">
							<?php esc_html_e( 'All agents', 'ticketoo' ); ?>
						</option>
						<?php foreach ( self::agents() as $agent ) : ?>
							<option value="<?php echo esc_attr( (string) $agent->ID ); ?>">
								<?php echo esc_html( $agent->display_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<label class="screen-reader-text" for="ticketoo-search">
						<?php esc_html_e( 'Search tickets', 'ticketoo' ); ?>
					</label>
					<input
						type="search"
						id="ticketoo-search"
						class="ticketoo-search"
						data-search
						placeholder="<?php echo esc_attr__( 'Search…', 'ticketoo' ); ?>"
					/>

					<span class="ticketoo-counter" data-counter aria-live="polite"></span>
				</div>

				<table class="wp-list-table widefat fixed striped ticketoo-table">
					<thead>
						<tr>
							<th scope="col" class="ticketoo-col-number">#</th>
							<th scope="col" class="ticketoo-col-subject"><?php esc_html_e( 'Subject', 'ticketoo' ); ?></th>
							<th scope="col" class="ticketoo-col-customer"><?php esc_html_e( 'Customer', 'ticketoo' ); ?></th>
							<th scope="col" class="ticketoo-col-agent"><?php esc_html_e( 'Agent', 'ticketoo' ); ?></th>
							<th scope="col" class="ticketoo-col-status"><?php esc_html_e( 'Status', 'ticketoo' ); ?></th>
							<th scope="col" class="ticketoo-col-activity"><?php esc_html_e( 'Last activity', 'ticketoo' ); ?></th>
						</tr>
					</thead>
					<tbody data-rows>
						<tr>
							<td colspan="6" class="ticketoo-empty">
								<?php esc_html_e( 'Loading tickets…', 'ticketoo' ); ?>
							</td>
						</tr>
					</tbody>
				</table>

				<nav class="ticketoo-pagination" data-pagination></nav>

				<div class="ticketoo-detail" data-detail hidden></div>
			</div>
		</div>
		<?php
	}

	/**
	 * The status slugs shown as filter buttons and localized labels
	 * (slug => label), extensible through the ticketoo_statuses filter
	 * (spec §10) — same shape the front end uses.
	 *
	 * @return array Status slug => human-readable label.
	 */
	private static function statuses(): array {
		/**
		 * Filters the ticket statuses shown in the admin panel.
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
	 * The users a ticket may be assigned to, in display-name order: the
	 * support-agent role plus administrators — exactly the set
	 * FrontendController::assign_ticket() accepts as a target.
	 *
	 * There is no agents REST route (spec §5), so the shell ships this
	 * roster as <option>s: the toolbar's agent filter and the detail
	 * view's assign select are both built from it by assets/js/admin.js.
	 *
	 * @return WP_User[] Users holding an assignable role.
	 */
	private static function agents(): array {
		return get_users(
			array(
				'role__in' => array( Capabilities::ROLE, 'administrator' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);
	}
}
