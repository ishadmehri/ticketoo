<?php
/**
 * Tests for the admin menu page and the settings screen.
 *
 * The menu must be registered with the plugin's capability so wp-admin
 * hides it from everyone without the support capability, and all seven
 * settings must be sanitized on save through the Settings API.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Activator;
use Ticketoo\Admin\Capabilities;
use Ticketoo\Admin\MenuPage;
use Ticketoo\Admin\SettingsPage;

// The WordPress test bootstrap does not load the admin includes; the menu
// and screen APIs (add_menu_page, get_current_screen, settings_fields)
// live in wp-admin/includes/, which is loaded for real admin requests.
require_once ABSPATH . 'wp-admin/includes/admin.php';

class Test_Admin_Menu_Settings extends Ticketoo_Database_TestCase {

	/**
	 * Creates the plugin's tables, agent role and capability — the menu
	 * capability checks depend on the role existing.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Activator::activate();
	}

	public function test_menu_hidden_without_capability(): void {
		MenuPage::register();

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$cap = $this->ticketoo_menu_capability();

		$this->assertNotNull( $cap, 'The Ticketoo menu entry must be registered.' );
		$this->assertFalse( current_user_can( $cap ), 'menu_not_visible' );
	}

	public function test_menu_visible_for_agent_role(): void {
		MenuPage::register();

		$agent = self::factory()->user->create( array( 'role' => Capabilities::ROLE ) );
		wp_set_current_user( $agent );

		$cap = $this->ticketoo_menu_capability();

		$this->assertNotNull( $cap, 'The Ticketoo menu entry must be registered.' );
		$this->assertSame( Capabilities::CAP, $cap, 'The menu must require the plugin capability.' );
		$this->assertTrue( current_user_can( $cap ), 'menu_visible' );
	}

	public function test_settings_sanitize_blocks_junk(): void {
		$this->register_settings();

		update_option( 'ticketoo_auto_close_days', 'abc' );

		$this->assertSame(
			0,
			(int) get_option( 'ticketoo_auto_close_days' ),
			'Non-numeric auto-close days must be saved as 0 (off).'
		);

		update_option( 'ticketoo_attachment_max_mb', 9999 );

		$this->assertSame(
			100,
			(int) get_option( 'ticketoo_attachment_max_mb' ),
			'The attachment size must be clamped to 100 MB.'
		);
	}

	public function test_settings_saved_values_persist(): void {
		$this->register_settings();

		$written = array(
			'ticketoo_auto_close_days'         => '7',
			'ticketoo_attachment_max_mb'       => '25',
			'ticketoo_attachment_types'        => 'png,PDF',
			'ticketoo_from_name'               => 'Ticketoo Support',
			'ticketoo_from_email'              => 'help@example.com',
			'ticketoo_allow_guests'            => '0',
			'ticketoo_counter_refresh_seconds' => '120',
		);

		foreach ( $written as $option => $value ) {
			update_option( $option, $value );
		}

		$this->assertSame( 7, SettingsPage::get_auto_close_days() );
		$this->assertSame( 25, SettingsPage::get_attachment_max_mb() );
		$this->assertSame( array( 'png', 'pdf' ), SettingsPage::get_attachment_types() );
		$this->assertSame( 'Ticketoo Support', SettingsPage::get_from_name() );
		$this->assertSame( 'help@example.com', SettingsPage::get_from_email() );
		$this->assertFalse( SettingsPage::get_allow_guests() );
		$this->assertSame( 120, SettingsPage::get_counter_refresh_seconds() );
	}

	/**
	 * Capability of the registered Ticketoo menu entry, mirroring the check
	 * wp-admin performs before printing a top-level item
	 * (wp-admin/menu-header.php: `current_user_can( $item[1] )`).
	 *
	 * @return string|null The capability, or null when the menu is missing.
	 */
	private function ticketoo_menu_capability(): ?string {
		global $menu;

		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && 'ticketoo' === $item[2] ) {
				return (string) $item[1];
			}
		}

		return null;
	}

	/**
	 * Registers the menu and the settings exactly as Plugin::boot() wires
	 * them (menu first, so the submenu has its parent).
	 *
	 * @return void
	 */
	private function register_settings(): void {
		MenuPage::register();
		SettingsPage::register();
	}
}
