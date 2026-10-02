<?php
/**
 * Tests for the i18n bootstrap and the shared uninstall cleanup (task 14).
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Activator;
use Ticketoo\Admin\Capabilities;
use Ticketoo\Admin\SettingsPage;
use Ticketoo\AutoClose;
use Ticketoo\Integrations\Gutenberg;

class Test_I18n_Uninstall extends Ticketoo_Database_TestCase {

	/**
	 * Path of the .mo fixture written by test_textdomain_loaded, so the
	 * file is removed even when the assertions fail.
	 *
	 * @var string
	 */
	private string $mo_file = '';

	/**
	 * Removes the .mo fixture before the shared database cleanup runs.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( '' !== $this->mo_file && file_exists( $this->mo_file ) ) {
			unlink( $this->mo_file );
			$this->mo_file = '';
		}

		parent::tear_down();
	}

	/**
	 * The plugin must register its text domain on init so translations
	 * resolve from languages/ticketoo-{locale}.mo.
	 *
	 * Note: the brief names get_loaded_textdomains(), which does not exist
	 * in modern WordPress; the assertion reads the $l10n registry directly.
	 * Since WP 6.7, load_plugin_textdomain() only registers the path and
	 * hands the actual load to the just-in-time loader, so a string lookup
	 * after init is what really pulls the .mo file in — a NOOP fallback
	 * (what an unregistered domain gets) must not count as "loaded".
	 *
	 * @return void
	 */
	public function test_textdomain_loaded(): void {
		$languages_dir = dirname( TICKETOO_FILE ) . '/languages';

		if ( ! is_dir( $languages_dir ) ) {
			mkdir( $languages_dir, 0777, true );
		}

		$this->mo_file = $languages_dir . '/ticketoo-' . determine_locale() . '.mo';
		file_put_contents( $this->mo_file, self::minimal_mo() );

		// Re-firing init runs the plugin's load_plugin_textdomain callback
		// (it also resets a stale NOOP entry left by earlier tests, exactly
		// as load_plugin_textdomain() itself does). The block was already
		// registered during bootstrap's init, and WP_Block_Type_Registry
		// reports re-registration as incorrect usage — detach it for the
		// re-run; the per-test hook backup restores it afterwards.
		remove_action( 'init', array( Gutenberg::class, 'register' ) );

		do_action( 'init' );

		// A string lookup triggers the just-in-time load for the domain.
		__( 'Ticketoo', 'ticketoo' );

		global $l10n;

		$this->assertArrayHasKey(
			'ticketoo',
			$l10n,
			'The ticketoo text domain must be present in the loaded translations after init.'
		);
		$this->assertNotInstanceOf(
			NOOP_Translations::class,
			$l10n['ticketoo'],
			'The domain must hold a real translation set, not the no-op fallback for unknown domains.'
		);
	}

	/**
	 * Uninstall must remove tables, role, capability and options, and it
	 * must be safe to run twice (the second call raises no error).
	 *
	 * @return void
	 */
	public function test_uninstall_cleanup_removes_tables_role_options(): void {
		global $wpdb;

		Activator::activate();
		update_option( SettingsPage::OPTION_AUTO_CLOSE_DAYS, 14 );

		$this->assertNotFalse(
			wp_next_scheduled( AutoClose::HOOK ),
			'Precondition: activation schedules the daily sweep event.'
		);

		$tables = Activator::table_names();

		Activator::uninstall();
		// Idempotency: the second run must not error.
		Activator::uninstall();

		$remaining = $wpdb->get_col( 'SHOW TABLES' );

		foreach ( $tables as $table ) {
			$this->assertNotContains( $table, $remaining, "Expected table {$table} to be dropped." );
		}

		$this->assertNull( get_role( Capabilities::ROLE ), 'The ticketoo_agent role must be removed.' );
		$this->assertFalse(
			get_option( SettingsPage::OPTION_AUTO_CLOSE_DAYS ),
			'ticketoo_* options must be deleted.'
		);
		$this->assertFalse(
			wp_next_scheduled( AutoClose::HOOK ),
			'Uninstall must unschedule the daily sweep event.'
		);

		$administrator = get_role( 'administrator' );
		$this->assertNotNull( $administrator );
		$this->assertFalse(
			$administrator->has_cap( Capabilities::CAP ),
			'Administrators must lose the capability.'
		);
	}

	/**
	 * Minimal valid .mo catalog (headers entry only) so the just-in-time
	 * loader has a real file to import from the plugin's languages dir.
	 *
	 * Layout mirrors MO::export_to_file_handle(): a 28-byte header, one
	 * index pair per table, then the NUL-terminated string pool.
	 *
	 * @return string Raw bytes of the .mo file.
	 */
	private static function minimal_mo(): string {
		$headers = "Content-Type: text/plain; charset=UTF-8\n";

		return pack(
			'V*',
			0x950412de, // magic
			0,          // revision
			1,          // total (the header entry only)
			28,         // originals_lengths_addr
			36,         // translations_lengths_addr
			0,          // hash_length
			44          // hash_addr — also the start of the string pool
		) . pack( 'VV', 0, 44 )                    // original: empty string @44
			. pack( 'VV', strlen( $headers ), 45 ) // translation: headers @45
			. "\0"                                 // NUL after the empty original
			. $headers . "\0";
	}
}
