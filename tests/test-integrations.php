<?php
/**
 * Tests for the Gutenberg blocks and the optional Elementor widget.
 *
 * The blocks are thin wrappers over the [ticketoo] shortcode, so their
 * rendered output must be byte-identical to the shortcode's. Elementor is a
 * soft dependency: with Elementor absent its integration file must never be
 * loaded.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Activator;
use Ticketoo\Database\TicketRepository;

class Test_Integrations extends Ticketoo_Database_TestCase {

	/**
	 * Creates the plugin's tables; block rendering reaches them through the
	 * shortcode's list and conversation views.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Activator::activate();

		unset( $_GET['ticketoo_ticket'], $_GET['token'], $_GET['ticketoo_status'], $_GET['ticketoo_page'] );
	}

	public function test_gutenberg_blocks_registered(): void {
		$registered = WP_Block_Type_Registry::get_instance()->get_all_registered();

		foreach ( array( 'ticketoo/list', 'ticketoo/form', 'ticketoo/conversation' ) as $name ) {
			$this->assertArrayHasKey(
				$name,
				$registered,
				sprintf( 'The %s block must be registered on init.', $name )
			);
		}

		$conversation = WP_Block_Type_Registry::get_instance()->get_registered( 'ticketoo/conversation' );

		$this->assertNotNull( $conversation );
		$this->assertTrue(
			is_callable( $conversation->render_callback ),
			'The conversation block must be server-rendered through a render_callback.'
		);
	}

	public function test_block_render_matches_shortcode_output(): void {
		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = TicketRepository::create(
			array(
				'subject' => 'Block delegation ticket',
				'user_id' => $user,
				'email'   => 'block@example.com',
			)
		);

		wp_set_current_user( $user );

		$cases = array(
			'<!-- wp:ticketoo/list /-->'                                   => '[ticketoo view="list" id="0"]',
			'<!-- wp:ticketoo/form /-->'                                   => '[ticketoo view="form" id="0"]',
			'<!-- wp:ticketoo/conversation {"id":' . $ticket_id . '} /-->' => '[ticketoo view="ticket" id="' . $ticket_id . '"]',
		);

		foreach ( $cases as $markup => $shortcode ) {
			$this->assertSame(
				do_shortcode( $shortcode ),
				do_blocks( $markup ),
				sprintf( 'The %s block must render exactly what the shortcode renders.', $markup )
			);
		}
	}

	public function test_elementor_not_loaded_without_elementor(): void {
		$this->assertSame(
			0,
			did_action( 'elementor/loaded' ),
			'Elementor must not be present in the test environment.'
		);

		$this->assertNotFalse(
			has_action( 'elementor/loaded' ),
			'The soft-dependency hook must be registered even though Elementor never fires it.'
		);

		$this->assertFalse(
			class_exists( 'Ticketoo\Integrations\Elementor', false ),
			'The Elementor integration class must not be loaded while Elementor is absent.'
		);

		$this->assertFalse(
			class_exists( 'Ticketoo\Integrations\TicketWidget', false ),
			'The Elementor widget class must not be loaded while Elementor is absent.'
		);

		$this->assertTrue(
			class_exists( 'Ticketoo\Integrations\Gutenberg', false ),
			'The Gutenberg integration must be loaded, proving the absence check above is meaningful.'
		);
	}
}
