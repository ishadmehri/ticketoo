<?php
/**
 * Tests for the [ticketoo] shortcode dispatcher, its templates and the
 * no-JS form fallback.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Activator;
use Ticketoo\Database\MessageRepository;
use Ticketoo\Database\TicketRepository;
use Ticketoo\Guest\TokenAccess;
use Ticketoo\Shortcode\TicketooShortcode;

class Test_Shortcode extends Ticketoo_Database_TestCase {

	/**
	 * Theme override files created by tests; removed again in tear_down().
	 *
	 * @var string[]
	 */
	private array $theme_overrides = array();

	/**
	 * REQUEST_METHOD as the CLI harness provides it (always GET), saved so
	 * tear_down() can restore it after a test simulates a form POST.
	 *
	 * @var string|null
	 */
	private ?string $request_method = null;

	/**
	 * REQUEST_URI as the harness provides it (unset in CLI), saved so
	 * tear_down() can restore it after a test injects a permalink.
	 *
	 * @var string|null
	 */
	private ?string $request_uri = null;

	/**
	 * Recreates the plugin's tables and clears shortcode view query args.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Activator::activate();

		$this->request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? $_SERVER['REQUEST_METHOD']
			: null;

		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? $_SERVER['REQUEST_URI']
			: null;

		$this->clear_query();
	}

	/**
	 * Removes theme override files and superglobal leftovers.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->theme_overrides as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}

		$this->theme_overrides = array();

		$override_dir = $this->theme_override_dir();

		if ( is_dir( $override_dir ) ) {
			// phpcs:ignore -- The directory only exists when a test created it; suppression keeps a non-empty dir from raising a PHPUnit warning.
			@rmdir( $override_dir );
		}

		unset( $_POST );
		$this->clear_query();

		if ( null === $this->request_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->request_method;
		}

		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}

		parent::tear_down();
	}

	public function test_shortcode_registered(): void {
		$this->assertTrue( shortcode_exists( 'ticketoo' ), 'The ticketoo shortcode must be registered.' );
	}

	public function test_form_renders_fields_for_guest(): void {
		wp_set_current_user( 0 );

		$html = $this->render( '[ticketoo view="form"]' );

		$this->assertStringContainsString( 'id="ticketoo-form"', $html, 'The new-ticket form must carry the #ticketoo-form hook.' );
		$this->assertStringContainsString( 'name="ticketoo_subject"', $html );
		$this->assertStringContainsString( 'name="ticketoo_email"', $html );
		$this->assertStringContainsString( 'name="ticketoo_content"', $html );
		$this->assertStringContainsString( 'name="ticketoo_nonce"', $html, 'The form must carry a wp_nonce_field() nonce.' );

		$this->assertSame(
			1,
			preg_match( '/name="ticketoo_nonce"\s+value="([^"]+)"/', $html, $matches ),
			'The nonce field must render with a value.'
		);
		$this->assertNotFalse(
			wp_verify_nonce( $matches[1], 'ticketoo_submit' ),
			'The rendered nonce must verify against the shortcode action.'
		);
	}

	public function test_form_hides_email_for_logged_in_users(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$html = $this->render( '[ticketoo view="form"]' );

		$this->assertStringContainsString( 'id="ticketoo-form"', $html );
		$this->assertStringContainsString( 'name="ticketoo_subject"', $html );
		$this->assertStringNotContainsString( 'name="ticketoo_email"', $html, 'The email field is for guests only; members use their account email.' );
	}

	public function test_list_renders_user_tickets_only(): void {
		$user_a = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user_b = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->create_ticket(
			array(
				'subject' => 'Ticket owned by A',
				'user_id' => $user_a,
				'email'   => 'a@example.com',
			)
		);
		$this->create_ticket(
			array(
				'subject' => 'Ticket owned by B',
				'user_id' => $user_b,
				'email'   => 'b@example.com',
			)
		);

		wp_set_current_user( $user_a );

		$html = $this->render( '[ticketoo view="list"]' );

		$this->assertStringContainsString( 'Ticket owned by A', $html );
		$this->assertStringNotContainsString( 'Ticket owned by B', $html, 'The list must only contain the current user\'s tickets.' );
	}

	public function test_unknown_view_falls_back_to_list(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->create_ticket(
			array(
				'subject' => 'Fallback ticket',
				'user_id' => $user,
				'email'   => 'f@example.com',
			)
		);

		wp_set_current_user( $user );

		$html = $this->render( '[ticketoo view="nonsense"]' );

		$this->assertStringContainsString( 'ticketoo-list', $html, 'An unknown view must fall back to the list view.' );
		$this->assertStringContainsString( 'Fallback ticket', $html );
	}

	public function test_theme_template_override_wins(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 'o@example.com',
			)
		);

		wp_set_current_user( $user );

		$override_dir = $this->theme_override_dir();
		$override     = $override_dir . '/list.php';

		wp_mkdir_p( $override_dir );
		file_put_contents( $override, "<?php echo 'THEME-OVERRIDE-WINS';" );
		$this->theme_overrides[] = $override;

		$html = $this->render( '[ticketoo view="list"]' );

		$this->assertStringContainsString( 'THEME-OVERRIDE-WINS', $html, 'A yourtheme/ticketoo/{name}.php file must win over the plugin template.' );
	}

	public function test_list_template_emits_element_contract(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		for ( $i = 1; $i <= 21; $i++ ) {
			$this->create_ticket(
				array(
					'subject' => sprintf( 'Contract ticket %02d', $i ),
					'user_id' => $user,
					'email'   => 'c@example.com',
				)
			);
		}

		wp_set_current_user( $user );

		$html = $this->render( '[ticketoo view="list"]' );

		$this->assertStringContainsString( 'class="ticketoo-list', $html, 'The list container must carry .ticketoo-list.' );
		$this->assertStringContainsString( 'data-filter-status=""', $html, 'The status filters must carry [data-filter-status] (All).' );
		$this->assertStringContainsString( 'data-filter-status="open"', $html, 'The status filters must carry [data-filter-status].' );
		$this->assertStringContainsString( 'class="ticketoo-pagination"', $html, 'Pagination must be wrapped in .ticketoo-pagination.' );
		$this->assertStringContainsString( 'data-page="2"', $html, 'Pagination controls must carry [data-page].' );
	}

	public function test_ticket_template_emits_element_contract(): void {
		$user     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'subject' => 'Conversation subject',
				'user_id' => $user,
				'email'   => 'c@example.com',
			)
		);

		MessageRepository::add( $ticket_id, 0, 'c@example.com', 0, 'Hello support' );

		wp_set_current_user( $user );

		$html = $this->render( '[ticketoo view="ticket" id="' . $ticket_id . '"]' );

		$this->assertStringContainsString( 'Conversation subject', $html );
		$this->assertStringContainsString( 'Hello support', $html );
		$this->assertStringContainsString( 'id="ticketoo-reply"', $html, 'The reply form must carry #ticketoo-reply.' );
		$this->assertStringContainsString( 'name="ticketoo_content"', $html, 'The reply form must offer a content field.' );
		$this->assertStringContainsString( 'name="files[]"', $html, 'The reply form must offer the files[] input.' );
		$this->assertStringContainsString( 'data-close-ticket=', $html, 'The close control must carry [data-close-ticket].' );
	}

	public function test_render_enqueues_frontend_style(): void {
		$GLOBALS['wp_styles'] = null;

		wp_set_current_user( 0 );

		$this->render( '[ticketoo view="form"]' );

		$this->assertTrue( wp_style_is( 'ticketoo-frontend', 'enqueued' ), 'Rendering the shortcode must enqueue frontend.css.' );
	}

	public function test_list_view_requires_login(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->create_ticket(
			array(
				'subject' => 'Members only ticket',
				'user_id' => $user,
				'email'   => 'm@example.com',
			)
		);

		wp_set_current_user( 0 );

		$html = $this->render( '[ticketoo view="list"]' );

		$this->assertStringContainsString( 'ticketoo-guest-notice', $html, 'A logged-out visitor must get the guest notice instead of a list.' );
		$this->assertStringNotContainsString( 'Members only ticket', $html );
	}

	public function test_guest_needs_valid_token_to_view_ticket(): void {
		$token     = TokenAccess::issue();
		$ticket_id = $this->create_ticket(
			array(
				'subject'     => 'Token thread',
				'guest_token' => $token,
			)
		);

		MessageRepository::add( $ticket_id, 0, 'guest@example.com', 0, 'Secret guest message' );

		wp_set_current_user( 0 );

		$denied = $this->render( '[ticketoo view="ticket" id="' . $ticket_id . '"]' );

		$this->assertStringContainsString( 'ticketoo-guest-notice', $denied, 'A guest without a token must not see the conversation.' );
		$this->assertStringNotContainsString( 'Secret guest message', $denied );

		$_GET['token'] = $token;

		$allowed = $this->render( '[ticketoo view="ticket" id="' . $ticket_id . '"]' );

		unset( $_GET['token'] );

		$this->assertStringContainsString( 'Secret guest message', $allowed, 'The valid token from the email link must grant access.' );
		$this->assertStringContainsString( 'id="ticketoo-reply"', $allowed, 'A guest with a valid token must be able to reply.' );
	}

	public function test_deep_link_renders_ticket_view(): void {
		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'subject' => 'Deep linked ticket',
				'user_id' => $user,
				'email'   => 'd@example.com',
			)
		);

		wp_set_current_user( $user );

		$_GET['ticketoo_ticket'] = (string) $ticket_id;

		$html = $this->render( '[ticketoo view="list"]' );

		unset( $_GET['ticketoo_ticket'] );

		$this->assertStringContainsString( 'Deep linked ticket', $html, '?ticketoo_ticket=ID must open the ticket view (spec §5 email link).' );
		$this->assertStringNotContainsString( 'class="ticketoo-list', $html, 'The deep link must render the conversation, not the list.' );
	}

	public function test_close_control_hidden_when_closed(): void {
		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'subject' => 'Already closed',
				'user_id' => $user,
				'email'   => 'x@example.com',
				'status'  => 'closed',
			)
		);

		wp_set_current_user( $user );

		$html = $this->render( '[ticketoo view="ticket" id="' . $ticket_id . '"]' );

		$this->assertStringNotContainsString( 'data-close-ticket=', $html, 'A closed ticket must not offer the close control.' );
		$this->assertStringContainsString( 'id="ticketoo-reply"', $html, 'Replying stays possible (reply reopens the conversation).' );
	}

	/**
	 * A percent-encoded REQUEST_URI (non-ASCII permalinks — this project
	 * targets Persian/RTL paths) must survive untouched into the form
	 * action, the hidden return URL and every generated link.
	 */
	public function test_percent_encoded_request_uri_survives_into_links(): void {
		$uri = '/%D8%AA%DB%8C%DA%A9%D8%AA-locale/';

		$_SERVER['REQUEST_URI'] = $uri;
		wp_set_current_user( 0 );

		$html = $this->render( '[ticketoo view="form"]' );

		$action = esc_url( home_url( $uri ) );

		$this->assertStringContainsString(
			'%D8%AA%DB%8C%DA%A9%D8%AA-locale',
			$html,
			'A percent-encoded REQUEST_URI must not be mangled in rendered output.'
		);
		$this->assertStringContainsString(
			'action="' . $action . '"',
			$html,
			'The form action must carry the untouched encoded path (no-JS submit target).'
		);
		$this->assertStringContainsString(
			'value="' . $action . '"',
			$html,
			'ticketoo_return must carry the untouched encoded path (PRG redirect target).'
		);
	}

	/**
	 * base_context() and load_template()'s explicit assignment list must
	 * stay in sync: every context key reaches templates as a local
	 * variable, and nothing else does.
	 */
	public function test_template_context_keys_match_base_context(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $user );

		$override_dir = $this->theme_override_dir();
		$override     = $override_dir . '/list.php';

		wp_mkdir_p( $override_dir );
		file_put_contents( $override, '<?php echo wp_json_encode( array_keys( get_defined_vars() ) );' );
		$this->theme_overrides[] = $override;

		$html = $this->render( '[ticketoo view="list"]' );

		$defined = json_decode( trim( $html ), true );

		$this->assertIsArray( $defined, 'The probe template must dump its local variable names as JSON.' );

		$context_method = new ReflectionMethod( TicketooShortcode::class, 'base_context' );
		$context_method->setAccessible( true );
		$expected = array_keys( $context_method->invoke( null ) );

		// $name, $context and $file are load_template()'s own locals, not context keys.
		$received = array_values( array_diff( $defined, array( 'name', 'context', 'file' ) ) );

		sort( $expected );
		sort( $received );

		$this->assertSame(
			$expected,
			$received,
			'base_context() keys and load_template()\'s assignment list must match exactly.'
		);
	}

	/**
	 * The conversation must render the newest page with a total that counts
	 * every message (single-fetch refactor pin).
	 */
	public function test_conversation_second_page_renders_with_total(): void {
		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 'p@example.com',
			)
		);

		for ( $i = 1; $i <= 55; $i++ ) {
			MessageRepository::add( $ticket_id, $user, 'p@example.com', 0, sprintf( 'Message number %02d', $i ) );
		}

		wp_set_current_user( $user );

		$_GET['ticketoo_msg_page'] = '2';

		$html = $this->render( '[ticketoo view="ticket" id="' . $ticket_id . '"]' );

		unset( $_GET['ticketoo_msg_page'] );

		$this->assertStringContainsString( 'Message number 55', $html, 'Page 2 must render the newest slice.' );
		$this->assertStringNotContainsString( 'Message number 01', $html, 'Page 1 must not leak into page 2.' );
		$this->assertStringContainsString( 'ticketoo_msg_page=1', $html, 'The messages nav must link back to page 1 (total counts every message).' );
	}

	/**
	 * A stale message-page link must clamp back to the last real page.
	 */
	public function test_conversation_stale_page_clamps_to_last(): void {
		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 's@example.com',
			)
		);

		for ( $i = 1; $i <= 55; $i++ ) {
			MessageRepository::add( $ticket_id, $user, 's@example.com', 0, sprintf( 'Message number %02d', $i ) );
		}

		wp_set_current_user( $user );

		$_GET['ticketoo_msg_page'] = '99';

		$html = $this->render( '[ticketoo view="ticket" id="' . $ticket_id . '"]' );

		unset( $_GET['ticketoo_msg_page'] );

		$this->assertStringContainsString( 'Message number 55', $html, 'A stale page link must clamp back to the last real page.' );
		$this->assertStringNotContainsString( 'Message number 01', $html );
	}

	public function test_nojs_form_post_creates_ticket(): void {
		update_option( 'ticketoo_allow_guests', 1 );
		wp_set_current_user( 0 );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                    = array(
			'ticketoo_action'  => 'create',
			'ticketoo_nonce'   => wp_create_nonce( 'ticketoo_submit' ),
			'ticketoo_subject' => 'Created without JS',
			'ticketoo_content' => 'Posted by a plain form.',
			'ticketoo_email'   => 'nojs@example.com',
			'ticketoo_return'  => home_url( '/support/' ),
		);

		$result = TicketooShortcode::process_post();

		unset( $_POST );

		$this->assertIsArray( $result, 'A classic create POST must be handled without JS.' );
		$this->assertStringContainsString( 'ticketoo_notice=created', $result['url'] );
		$this->assertStringContainsString( 'ticketoo_ticket=', $result['url'] );
		$this->assertStringContainsString( 'token=', $result['url'], 'The redirect must hand the guest their token link.' );

		$listed = TicketRepository::list( array() );

		$this->assertSame( 1, $listed['total'] );
		$this->assertSame( 'Created without JS', $listed['items'][0]->subject );
		$this->assertSame( 'nojs@example.com', $listed['items'][0]->email );
		$this->assertNotNull( $listed['items'][0]->guest_token, 'A guest ticket created without JS still receives its token.' );

		$messages = MessageRepository::for_ticket( $listed['items'][0]->id );
		$this->assertSame( 1, $messages['total'] );
		$this->assertSame( 'Posted by a plain form.', $messages['items'][0]->content );
	}

	public function test_nojs_reply_post_appends_message(): void {
		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 'r@example.com',
			)
		);

		wp_set_current_user( $user );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                    = array(
			'ticketoo_action'   => 'reply',
			'ticketoo_nonce'    => wp_create_nonce( 'ticketoo_submit' ),
			'ticketoo_id'       => (string) $ticket_id,
			'ticketoo_content'  => 'Reply without JS',
			'ticketoo_return'   => home_url( '/support/' ),
		);

		$result = TicketooShortcode::process_post();

		unset( $_POST );

		$this->assertIsArray( $result, 'A classic reply POST must be handled without JS.' );
		$this->assertStringContainsString( 'ticketoo_notice=replied', $result['url'] );
		$this->assertStringContainsString( 'ticketoo_ticket=' . $ticket_id, $result['url'] );

		$messages = MessageRepository::for_ticket( $ticket_id );

		$this->assertSame( 1, $messages['total'] );
		$this->assertSame( 'Reply without JS', $messages['items'][0]->content );
		$this->assertSame( $user, $messages['items'][0]->user_id );
	}

	public function test_nojs_close_post_closes_ticket(): void {
		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 'k@example.com',
			)
		);

		wp_set_current_user( $user );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                    = array(
			'ticketoo_action' => 'close',
			'ticketoo_nonce'  => wp_create_nonce( 'ticketoo_submit' ),
			'ticketoo_id'     => (string) $ticket_id,
			'ticketoo_return' => home_url( '/support/' ),
		);

		$result = TicketooShortcode::process_post();

		unset( $_POST );

		$this->assertIsArray( $result, 'A classic close POST must be handled without JS.' );
		$this->assertStringContainsString( 'ticketoo_notice=closed', $result['url'] );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'closed', $ticket->status );
	}

	public function test_nojs_post_without_nonce_is_rejected(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $user );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                    = array(
			'ticketoo_action'  => 'create',
			'ticketoo_subject' => 'Forged request',
			'ticketoo_content' => 'CSRF payload',
			'ticketoo_email'   => 'csrf@example.com',
		);

		$result = TicketooShortcode::process_post();

		unset( $_POST );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'ticketoo_notice=expired', $result['url'], 'A post without a valid nonce must be rejected.' );
		$this->assertSame( 0, TicketRepository::list( array() )['total'], 'A rejected post must not create anything.' );
	}

	/**
	 * Renders a shortcode through do_shortcode().
	 *
	 * @param string $shortcode Shortcode markup.
	 * @return string Rendered HTML.
	 */
	private function render( string $shortcode ): string {
		return (string) do_shortcode( $shortcode );
	}

	/**
	 * Inserts a ticket row through the repository.
	 *
	 * @param array $overrides Replacement values for a single ticket.
	 * @return int Inserted ticket id.
	 */
	private function create_ticket( array $overrides = array() ): int {
		return TicketRepository::create(
			array_merge(
				array(
					'subject'     => 'Sample ticket',
					'user_id'     => 0,
					'email'       => 'guest@example.com',
					'guest_token' => null,
				),
				$overrides
			)
		);
	}

	/**
	 * Removes every query argument the shortcode reads from the request.
	 *
	 * @return void
	 */
	private function clear_query(): void {
		unset(
			$_GET['ticketoo_ticket'],
			$_GET['token'],
			$_GET['ticketoo_status'],
			$_GET['ticketoo_page'],
			$_GET['ticketoo_notice'],
			$_GET['ticketoo_msg_page']
		);
	}

	/**
	 * Absolute path of the theme's ticketoo/ override directory.
	 *
	 * In the test environment get_stylesheet_directory() resolves through an
	 * "includes/.." path that wp_mkdir_p() refuses to create; realpath()
	 * normalizes it while locate_template() still finds the same file.
	 *
	 * @return string Directory path.
	 */
	private function theme_override_dir(): string {
		$stylesheet = realpath( get_stylesheet_directory() );

		return ( false !== $stylesheet ? $stylesheet : get_stylesheet_directory() ) . '/ticketoo';
	}
}
