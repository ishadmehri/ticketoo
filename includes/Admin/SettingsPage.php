<?php
/**
 * Settings screen: option registration, sanitization and form markup.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the seven ticketoo_* options with the Settings API (group
 * ticketoo_settings) and renders the form that posts to options.php
 * (spec §6, plan Global Constraints).
 */
class SettingsPage {

	/**
	 * Settings API option group — the form's `option_page` value.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'ticketoo_settings';

	/**
	 * Submenu slug under the Ticketoo menu.
	 *
	 * @var string
	 */
	const SLUG = 'ticketoo-settings';

	/**
	 * Auto-close delay in days (0 = off).
	 *
	 * @var string
	 */
	const OPTION_AUTO_CLOSE_DAYS = 'ticketoo_auto_close_days';

	/**
	 * Attachment size limit in megabytes.
	 *
	 * @var string
	 */
	const OPTION_ATTACHMENT_MAX_MB = 'ticketoo_attachment_max_mb';

	/**
	 * CSV of allowed attachment extensions.
	 *
	 * @var string
	 */
	const OPTION_ATTACHMENT_TYPES = 'ticketoo_attachment_types';

	/**
	 * Sender name for notification emails.
	 *
	 * @var string
	 */
	const OPTION_FROM_NAME = 'ticketoo_from_name';

	/**
	 * Sender address for notification emails.
	 *
	 * @var string
	 */
	const OPTION_FROM_EMAIL = 'ticketoo_from_email';

	/**
	 * Whether guests may open tickets (1) or not (0).
	 *
	 * @var string
	 */
	const OPTION_ALLOW_GUESTS = 'ticketoo_allow_guests';

	/**
	 * Seconds between new-ticket counter refreshes (0 = no polling).
	 *
	 * @var string
	 */
	const OPTION_COUNTER_REFRESH_SECONDS = 'ticketoo_counter_refresh_seconds';

	/**
	 * Hard ceiling for the attachment size limit: the sanitizer clamps to
	 * it and the number field advertises it, so the two can never drift.
	 *
	 * @var int
	 */
	const ATTACHMENT_MAX_MB_LIMIT = 100;

	/**
	 * Adds the Settings submenu and registers every option with its
	 * per-option sanitize callback and default.
	 *
	 * The capability-gated submenu and the `option_page_capability_*`
	 * filter (wired in Plugin::boot()) keep saving limited to holders of
	 * the plugin capability.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_submenu_page(
			MenuPage::SLUG,
			__( 'Settings', 'ticketoo' ),
			__( 'Settings', 'ticketoo' ),
			Capabilities::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);

		$defaults   = self::defaults();
		$sanitizers = self::sanitizers();

		foreach ( $sanitizers as $option => $callback ) {
			register_setting(
				self::OPTION_GROUP,
				$option,
				array(
					'default'           => $defaults[ $option ],
					'sanitize_callback' => $callback,
				)
			);
		}
	}

	/**
	 * Sanitize callback of every registered option, keyed by option name.
	 *
	 * @return array<string, callable> Option name => sanitize callback.
	 */
	private static function sanitizers(): array {
		return array(
			self::OPTION_AUTO_CLOSE_DAYS         => array( self::class, 'sanitize_auto_close_days' ),
			self::OPTION_ATTACHMENT_MAX_MB       => array( self::class, 'sanitize_attachment_max_mb' ),
			self::OPTION_ATTACHMENT_TYPES        => array( self::class, 'sanitize_attachment_types' ),
			self::OPTION_FROM_NAME               => array( self::class, 'sanitize_from_name' ),
			self::OPTION_FROM_EMAIL              => array( self::class, 'sanitize_from_email' ),
			self::OPTION_ALLOW_GUESTS            => array( self::class, 'sanitize_allow_guests' ),
			self::OPTION_COUNTER_REFRESH_SECONDS => array( self::class, 'sanitize_counter_refresh_seconds' ),
		);
	}

	/**
	 * Default of every setting — the single source of truth. Tasks 11/12/13
	 * read these through the get_*() accessors below, never by repeating
	 * the literals.
	 *
	 * @return array<string, int|string> Option name => default value.
	 */
	public static function defaults(): array {
		return array(
			self::OPTION_AUTO_CLOSE_DAYS         => 14,
			self::OPTION_ATTACHMENT_MAX_MB       => 5,
			self::OPTION_ATTACHMENT_TYPES        => 'jpg,jpeg,png,gif,pdf,zip,txt,docx',
			self::OPTION_FROM_NAME               => '',
			self::OPTION_FROM_EMAIL              => '',
			self::OPTION_ALLOW_GUESTS            => 1,
			self::OPTION_COUNTER_REFRESH_SECONDS => 60,
		);
	}

	/**
	 * Capability options.php requires when submitting this option group
	 * (it defaults to manage_options).
	 *
	 * @return string Plugin capability.
	 */
	public static function option_page_capability(): string {
		return Capabilities::CAP;
	}

	/**
	 * Auto-close days: whole number of days, 0 disables (junk collapses
	 * to 0 instead of an unbounded value).
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int Sanitized day count.
	 */
	public static function sanitize_auto_close_days( $value ): int {
		return absint( $value );
	}

	/**
	 * Attachment size limit: whole megabytes, clamped to 100 MB.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int Sanitized limit in MB.
	 */
	public static function sanitize_attachment_max_mb( $value ): int {
		return min( self::ATTACHMENT_MAX_MB_LIMIT, absint( $value ) );
	}

	/**
	 * Attachment extensions: CSV of lowercase alphanumeric extensions;
	 * anything else is stripped and duplicates collapse.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string Sanitized CSV.
	 */
	public static function sanitize_attachment_types( $value ): string {
		$types = array();

		foreach ( explode( ',', (string) $value ) as $type ) {
			$type = strtolower( trim( $type ) );
			$type = (string) preg_replace( '/[^a-z0-9.]/', '', $type );

			if ( '' !== $type && ! in_array( $type, $types, true ) ) {
				$types[] = $type;
			}
		}

		return implode( ',', $types );
	}

	/**
	 * Sender name: text-field sanitized, tags and control chars removed.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string Sanitized name.
	 */
	public static function sanitize_from_name( $value ): string {
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Sender address: email sanitized — junk collapses to '' rather than
	 * being stored.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string Sanitized address.
	 */
	public static function sanitize_from_email( $value ): string {
		return sanitize_email( (string) $value );
	}

	/**
	 * Guest tickets flag: stored strictly as 0 or 1.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int 1 when truthy, 0 otherwise.
	 */
	public static function sanitize_allow_guests( $value ): int {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Counter refresh interval: whole seconds, 0 disables polling.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int Sanitized interval.
	 */
	public static function sanitize_counter_refresh_seconds( $value ): int {
		return absint( $value );
	}

	/**
	 * Auto-close delay read back with its default applied.
	 *
	 * @return int Days (0 = off).
	 */
	public static function get_auto_close_days(): int {
		return (int) get_option( self::OPTION_AUTO_CLOSE_DAYS, self::defaults()[ self::OPTION_AUTO_CLOSE_DAYS ] );
	}

	/**
	 * Attachment size limit read back with its default applied.
	 *
	 * @return int Megabytes.
	 */
	public static function get_attachment_max_mb(): int {
		return (int) get_option( self::OPTION_ATTACHMENT_MAX_MB, self::defaults()[ self::OPTION_ATTACHMENT_MAX_MB ] );
	}

	/**
	 * Allowed attachment extensions read back with its default applied.
	 *
	 * @return string[] Lowercased extension list.
	 */
	public static function get_attachment_types(): array {
		$raw   = (string) get_option( self::OPTION_ATTACHMENT_TYPES, self::defaults()[ self::OPTION_ATTACHMENT_TYPES ] );
		$types = array();

		foreach ( explode( ',', $raw ) as $type ) {
			$type = strtolower( trim( $type ) );

			if ( '' !== $type ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * Sender name read back with its default applied.
	 *
	 * @return string Empty string falls back to the site name downstream.
	 */
	public static function get_from_name(): string {
		return (string) get_option( self::OPTION_FROM_NAME, self::defaults()[ self::OPTION_FROM_NAME ] );
	}

	/**
	 * Sender address read back with its default applied.
	 *
	 * @return string Empty string falls back to the site address downstream.
	 */
	public static function get_from_email(): string {
		return (string) get_option( self::OPTION_FROM_EMAIL, self::defaults()[ self::OPTION_FROM_EMAIL ] );
	}

	/**
	 * Whether guests may open tickets, with the default applied.
	 *
	 * @return bool True unless the option is explicitly 0.
	 */
	public static function get_allow_guests(): bool {
		return (bool) (int) get_option( self::OPTION_ALLOW_GUESTS, self::defaults()[ self::OPTION_ALLOW_GUESTS ] );
	}

	/**
	 * Counter refresh interval read back with its default applied.
	 *
	 * @return int Seconds (0 = polling off).
	 */
	public static function get_counter_refresh_seconds(): int {
		return (int) get_option(
			self::OPTION_COUNTER_REFRESH_SECONDS,
			self::defaults()[ self::OPTION_COUNTER_REFRESH_SECONDS ]
		);
	}

	/**
	 * Prints the settings form; it posts to options.php like every core
	 * Settings API screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ticketoo' ) );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ticketoo Settings', 'ticketoo' ); ?></h1>

			<?php settings_errors(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<table class="form-table" role="presentation">
					<?php
					self::field_row(
						self::OPTION_AUTO_CLOSE_DAYS,
						__( 'Auto-close after (days)', 'ticketoo' ),
						__( 'Idle open or pending tickets are closed after this many days. 0 disables auto-close.', 'ticketoo' ),
						'number',
						array(
							'min'  => '0',
							'step' => '1',
						)
					);
					self::field_row(
						self::OPTION_ATTACHMENT_MAX_MB,
						__( 'Attachment size limit (MB)', 'ticketoo' ),
						__( 'Uploads larger than this are rejected; the maximum accepted value is 100.', 'ticketoo' ),
						'number',
						array(
							'min'  => '0',
							'max'  => (string) self::ATTACHMENT_MAX_MB_LIMIT,
							'step' => '1',
						)
					);
					self::field_row(
						self::OPTION_ATTACHMENT_TYPES,
						__( 'Allowed attachment types', 'ticketoo' ),
						__( 'Comma-separated file extensions, e.g. jpg,png,pdf.', 'ticketoo' ),
						'text',
						array(
							'placeholder' => (string) self::defaults()[ self::OPTION_ATTACHMENT_TYPES ],
						)
					);
					self::field_row(
						self::OPTION_FROM_NAME,
						__( 'Sender name', 'ticketoo' ),
						__( 'Name shown on notification emails. Leave empty to use the site name.', 'ticketoo' )
					);
					self::field_row(
						self::OPTION_FROM_EMAIL,
						__( 'Sender email', 'ticketoo' ),
						__( 'Address notification emails are sent from. Leave empty to use the site address.', 'ticketoo' ),
						'email'
					);
					?>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::OPTION_ALLOW_GUESTS ); ?>">
								<?php esc_html_e( 'Allow guest tickets', 'ticketoo' ); ?>
							</label>
						</th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_ALLOW_GUESTS ); ?>" value="0" />
							<input
								type="checkbox"
								id="<?php echo esc_attr( self::OPTION_ALLOW_GUESTS ); ?>"
								name="<?php echo esc_attr( self::OPTION_ALLOW_GUESTS ); ?>"
								value="1"
								<?php checked( self::get_allow_guests() ); ?>
							/>
							<label for="<?php echo esc_attr( self::OPTION_ALLOW_GUESTS ); ?>">
								<?php esc_html_e( 'Visitors without an account can open tickets.', 'ticketoo' ); ?>
							</label>
						</td>
					</tr>
					<?php
					self::field_row(
						self::OPTION_COUNTER_REFRESH_SECONDS,
						__( 'Counter refresh (seconds)', 'ticketoo' ),
						__( 'How often the panel refreshes its new-ticket counter. 0 disables polling.', 'ticketoo' ),
						'number',
						array(
							'min'  => '0',
							'step' => '1',
						)
					);
					?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * One labeled settings row: translation-ready label and description,
	 * value read through the registered default.
	 *
	 * @param string               $option      Option name (also the field id/name).
	 * @param string               $label       Row label.
	 * @param string               $description Help text under the field.
	 * @param string               $type        Input type.
	 * @param array<string,string> $attributes  Extra input attributes (hardcoded keys).
	 * @return void
	 */
	private static function field_row(
		string $option,
		string $label,
		string $description,
		string $type = 'text',
		array $attributes = array()
	): void {
		$value = (string) get_option( $option, self::defaults()[ $option ] );
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					id="<?php echo esc_attr( $option ); ?>"
					name="<?php echo esc_attr( $option ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					class="regular-text"
					<?php foreach ( $attributes as $attribute => $attribute_value ) : ?>
						<?php echo esc_attr( $attribute ); ?>="<?php echo esc_attr( $attribute_value ); ?>"
					<?php endforeach; ?>
				/>
				<p class="description"><?php echo esc_html( $description ); ?></p>
			</td>
		</tr>
		<?php
	}
}
