<?php
/**
 * Tests for the status/assign REST routes and attachment upload/download.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

use Ticketoo\Activator;
use Ticketoo\Admin\Capabilities;
use Ticketoo\Database\TicketRepository;
use Ticketoo\Guest\TokenAccess;

class Test_Rest_Status_Attachments extends Ticketoo_Database_TestCase {

	/**
	 * Temporary upload files created by a test, removed in tear_down().
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	/**
	 * Recreates the plugin's tables: tear_down() drops them after every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Activator::activate();
	}

	/**
	 * Removes files the test stored on disk before the base class wipes the
	 * plugin's tables (the attachments rows are the authoritative list of
	 * everything this test uploaded).
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wpdb;

		$table = Activator::table_names()['attachments'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test cleanup reads the file paths this test uploaded; table name comes from Activator::table_names().
		$paths = (array) $wpdb->get_col( "SELECT file_path FROM {$table}" );

		foreach ( $paths as $path ) {
			if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
				unlink( $path );
			}
		}

		foreach ( $this->temp_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		$this->temp_files = array();

		parent::tear_down();
	}

	public function test_owner_closes_own_ticket(): void {
		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 'owner@example.com',
			)
		);

		$fired    = array();
		$capture  = static function ( int $id, string $old, string $new ) use ( &$fired ): void {
			$fired = array( $id, $old, $new );
		};
		add_action( 'ticketoo_ticket_status_changed', $capture, 10, 3 );

		wp_set_current_user( $user );

		$response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/status', $ticket_id ),
			array(),
			array( 'status' => 'closed' )
		);

		remove_action( 'ticketoo_ticket_status_changed', $capture );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'closed', $data['status'] );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'closed', $ticket->status );

		$this->assertSame(
			array( $ticket_id, 'open', 'closed' ),
			$fired,
			'ticketoo_ticket_status_changed must fire with the ticket id, the old status and the new status.'
		);
	}

	public function test_owner_cannot_set_pending(): void {
		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 'owner@example.com',
			)
		);

		wp_set_current_user( $user );

		$response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/status', $ticket_id ),
			array(),
			array( 'status' => 'pending' )
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ticketoo_rest_forbidden', $response->get_data()['code'] );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'open', $ticket->status, 'A rejected status must not be stored.' );
	}

	public function test_user_cannot_close_foreign_ticket(): void {
		$owner     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$intruder  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $owner,
				'email'   => 'owner@example.com',
			)
		);

		wp_set_current_user( $intruder );

		$response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/status', $ticket_id ),
			array(),
			array( 'status' => 'closed' )
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame(
			'ticketoo_rest_ticket_not_found',
			$response->get_data()['code'],
			'A foreign ticket must look missing so ids cannot be probed.'
		);

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'open', $ticket->status );
	}

	public function test_agent_sets_status_and_assigns(): void {
		$agent     = self::factory()->user->create( array( 'role' => Capabilities::ROLE ) );
		$target    = self::factory()->user->create( array( 'role' => Capabilities::ROLE ) );
		$owner     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $owner,
				'email'   => 'owner@example.com',
			)
		);

		$fired   = array();
		$capture = static function ( int $id, int $user_id, int $previous ) use ( &$fired ): void {
			$fired = array( $id, $user_id, $previous );
		};
		add_action( 'ticketoo_ticket_assigned', $capture, 10, 3 );

		wp_set_current_user( $agent );

		$status_response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/status', $ticket_id ),
			array(),
			array( 'status' => 'pending' )
		);

		$this->assertSame( 200, $status_response->get_status(), 'An agent may choose any allowed status.' );
		$this->assertSame( 'pending', $status_response->get_data()['status'] );

		$assign_response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/assign', $ticket_id ),
			array(),
			array( 'user_id' => $target )
		);

		remove_action( 'ticketoo_ticket_assigned', $capture );

		$this->assertSame( 200, $assign_response->get_status() );
		$this->assertSame( $target, $assign_response->get_data()['assigned_to'] );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'pending', $ticket->status );
		$this->assertSame( $target, $ticket->assigned_to, 'The assign endpoint must persist the new assignee.' );

		$this->assertSame(
			array( $ticket_id, $target, 0 ),
			$fired,
			'ticketoo_ticket_assigned must fire with the ticket id, the new assignee and the previous one (0 when none).'
		);
	}

	public function test_assign_requires_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$target     = self::factory()->user->create( array( 'role' => Capabilities::ROLE ) );
		$ticket_id  = $this->create_ticket(
			array(
				'user_id' => $subscriber,
				'email'   => 'subscriber@example.com',
			)
		);

		wp_set_current_user( $subscriber );

		$response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/assign', $ticket_id ),
			array(),
			array( 'user_id' => $target )
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ticketoo_rest_forbidden', $response->get_data()['code'] );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertNull( $ticket->assigned_to, 'A rejected assignment must not be stored.' );
	}

	public function test_assign_zero_unassigns(): void {
		$agent     = self::factory()->user->create( array( 'role' => Capabilities::ROLE ) );
		$target    = self::factory()->user->create( array( 'role' => Capabilities::ROLE ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => 0,
				'email'   => 'guest@example.com',
			)
		);

		wp_set_current_user( $agent );

		$assign = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/assign', $ticket_id ),
			array(),
			array( 'user_id' => $target )
		);
		$this->assertSame( 200, $assign->get_status() );

		$unassign = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/assign', $ticket_id ),
			array(),
			array( 'user_id' => 0 )
		);

		$this->assertSame( 200, $unassign->get_status() );
		$this->assertNull( $unassign->get_data()['assigned_to'] );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertNull( $ticket->assigned_to, 'user_id 0 must store SQL NULL, not user 0.' );
	}

	public function test_guest_cannot_change_status(): void {
		$token     = TokenAccess::issue();
		$ticket_id = $this->create_ticket(
			array(
				'user_id'     => 0,
				'email'       => 'guest@example.com',
				'guest_token' => $token,
			)
		);

		wp_set_current_user( 0 );

		$response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/status', $ticket_id ),
			array( 'token' => $token ),
			array( 'status' => 'closed' )
		);

		$this->assertSame(
			403,
			$response->get_status(),
			'Spec 5 lists only read/reply/download as guest routes: guests may not change status even with a valid token.'
		);
		$this->assertSame( 'ticketoo_rest_forbidden', $response->get_data()['code'] );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'open', $ticket->status );
	}

	public function test_status_must_be_in_allowed_list(): void {
		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 'owner@example.com',
			)
		);

		wp_set_current_user( $user );

		$response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/status', $ticket_id ),
			array(),
			array( 'status' => 'bogus' )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'ticketoo_rest_invalid_status', $response->get_data()['code'] );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'open', $ticket->status );
	}

	public function test_statuses_filter_extends_allowed_values(): void {
		$agent     = self::factory()->user->create( array( 'role' => Capabilities::ROLE ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => 0,
				'email'   => 'guest@example.com',
			)
		);

		$extend = static function ( array $statuses ): array {
			$statuses['waiting'] = 'Waiting';

			return $statuses;
		};
		add_filter( 'ticketoo_statuses', $extend );

		wp_set_current_user( $agent );

		$response = $this->dispatch(
			'POST',
			sprintf( '/ticketoo/v1/tickets/%d/status', $ticket_id ),
			array(),
			array( 'status' => 'waiting' )
		);

		remove_filter( 'ticketoo_statuses', $extend );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'waiting', $response->get_data()['status'] );

		$ticket = TicketRepository::find( $ticket_id );
		$this->assertNotNull( $ticket );
		$this->assertSame( 'waiting', $ticket->status );
	}

	public function test_upload_stores_file_and_row(): void {
		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 'owner@example.com',
			)
		);

		$tmp = $this->temp_copy( self::fixture() );

		wp_set_current_user( $user );

		$response = $this->dispatch_with_files(
			sprintf( '/ticketoo/v1/tickets/%d/messages', $ticket_id ),
			array( 'content' => 'Attaching the sample log.' ),
			$this->file_params( 'sample.txt', $tmp )
		);

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'attachments', $data );
		$this->assertCount( 1, $data['attachments'], 'The reply payload must list the stored attachment.' );

		$attachment_id = (int) $data['attachments'][0]['id'];
		$row           = $this->attachment_row( $attachment_id );

		$this->assertNotNull( $row, 'An attachment row must exist after a successful upload.' );
		$this->assertSame( (int) $data['id'], (int) $row->message_id );
		$this->assertSame( 'sample.txt', $row->original_name );
		$this->assertSame( 'text/plain', $row->mime );
		$this->assertSame( filesize( $tmp ), (int) $row->size );
		$this->assertSame( 1, $this->attachment_count() );

		$this->assertFileExists( $row->file_path, 'The stored file must exist on disk.' );
		$this->assertSame(
			file_get_contents( self::fixture() ),
			file_get_contents( $row->file_path ),
			'The stored file must keep the uploaded bytes.'
		);

		$basename = basename( $row->file_path );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{24}\.txt$/',
			$basename,
			'The filesystem name must be 24 random hex chars plus the extension, never the client filename.'
		);
		$this->assertStringContainsString( '/ticketoo/', $row->file_path, 'Uploads must live under the ticketoo/ upload directory.' );

		$htaccess = wp_upload_dir()['basedir'] . '/ticketoo/.htaccess';
		$this->assertFileExists( $htaccess, 'The ticketoo upload directory must carry an .htaccess deny rule.' );
		$rules = (string) file_get_contents( $htaccess );
		$this->assertTrue(
			false !== strpos( $rules, 'Require all denied' ) || false !== strpos( $rules, 'Deny from all' ),
			'The .htaccess must deny direct web access to stored attachments.'
		);

		$read = $this->dispatch( 'GET', sprintf( '/ticketoo/v1/tickets/%d', $ticket_id ) );
		$this->assertSame( 200, $read->get_status() );
		$read_data = $read->get_data();
		$this->assertCount( 1, $read_data['attachments'] );
		$this->assertSame( $attachment_id, (int) $read_data['attachments'][0]['id'] );
		$this->assertArrayNotHasKey(
			'file_path',
			$read_data['attachments'][0],
			'The server-side file path must never appear in a REST response.'
		);
	}

	public function test_upload_rejects_disallowed_type(): void {
		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 'owner@example.com',
			)
		);

		$tmp = tempnam( sys_get_temp_dir(), 'ticketoo' );
		file_put_contents( $tmp, "<?php echo 'gotcha';" );
		$this->temp_files[] = $tmp;

		wp_set_current_user( $user );

		$response = $this->dispatch_with_files(
			sprintf( '/ticketoo/v1/tickets/%d/messages', $ticket_id ),
			array( 'content' => 'Malicious payload.' ),
			$this->file_params( 'shell.php', $tmp )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'ticketoo_rest_disallowed_file', $response->get_data()['code'] );
		$this->assertSame( 0, $this->attachment_count(), 'A rejected upload must not create a row.' );
		$this->assertSame(
			array(),
			$this->stored_filenames(),
			'A rejected upload must not store any file (no PHP payload may land in the upload directory).'
		);
	}

	public function test_upload_rejects_oversized_file(): void {
		update_option( 'ticketoo_attachment_max_mb', 1 );

		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $user,
				'email'   => 'owner@example.com',
			)
		);

		$tmp = tempnam( sys_get_temp_dir(), 'ticketoo' );
		file_put_contents( $tmp, str_repeat( 'A', 2 * 1024 * 1024 ) );
		$this->temp_files[] = $tmp;

		wp_set_current_user( $user );

		$response = $this->dispatch_with_files(
			sprintf( '/ticketoo/v1/tickets/%d/messages', $ticket_id ),
			array( 'content' => 'Too big.' ),
			$this->file_params( 'big.txt', $tmp )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'ticketoo_rest_file_too_large', $response->get_data()['code'] );
		$this->assertSame( 0, $this->attachment_count() );
		$this->assertSame( array(), $this->stored_filenames() );
	}

	public function test_download_owner_ok_other_forbidden(): void {
		$owner    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ticket_id = $this->create_ticket(
			array(
				'user_id' => $owner,
				'email'   => 'owner@example.com',
			)
		);

		$tmp = $this->temp_copy( self::fixture() );

		wp_set_current_user( $owner );

		$upload = $this->dispatch_with_files(
			sprintf( '/ticketoo/v1/tickets/%d/messages', $ticket_id ),
			array( 'content' => 'Log attached.' ),
			$this->file_params( 'sample.txt', $tmp )
		);
		$this->assertSame( 201, $upload->get_status() );
		$this->assertArrayHasKey( 'attachments', $upload->get_data() );

		$attachment_id = (int) $upload->get_data()['attachments'][0]['id'];

		$download = $this->dispatch( 'GET', sprintf( '/ticketoo/v1/attachments/%d', $attachment_id ) );

		$this->assertSame( 200, $download->get_status() );
		$this->assertSame( file_get_contents( self::fixture() ), $download->get_data() );

		$headers = $download->get_headers();
		$this->assertArrayHasKey( 'Content-Disposition', $headers );
		$this->assertStringStartsWith( 'attachment;', $headers['Content-Disposition'] );

		// Denied: "other" means a foreign logged-in user. The ticket-read
		// access rule answers 404 so attachment ids cannot be probed either.
		wp_set_current_user( $other );

		$denied = $this->dispatch( 'GET', sprintf( '/ticketoo/v1/attachments/%d', $attachment_id ) );

		$this->assertSame( 404, $denied->get_status() );
		$this->assertSame( 'ticketoo_rest_attachment_not_found', $denied->get_data()['code'] );
	}

	public function test_download_wrong_token_forbidden(): void {
		$token     = TokenAccess::issue();
		$ticket_id = $this->create_ticket(
			array(
				'user_id'     => 0,
				'email'       => 'guest@example.com',
				'guest_token' => $token,
			)
		);

		$tmp = $this->temp_copy( self::fixture() );

		wp_set_current_user( 0 );

		$upload = $this->dispatch_with_files(
			sprintf( '/ticketoo/v1/tickets/%d/messages', $ticket_id ),
			array( 'content' => 'Screenshot attached.' ),
			$this->file_params( 'sample.txt', $tmp ),
			array( 'token' => $token )
		);
		$this->assertSame( 201, $upload->get_status() );
		$this->assertArrayHasKey( 'attachments', $upload->get_data() );

		$attachment_id = (int) $upload->get_data()['attachments'][0]['id'];

		$wrong = $this->dispatch(
			'GET',
			sprintf( '/ticketoo/v1/attachments/%d', $attachment_id ),
			array( 'token' => 'nope' )
		);

		$this->assertSame( 403, $wrong->get_status() );
		$this->assertSame( 'ticketoo_rest_forbidden', $wrong->get_data()['code'] );

		$ok = $this->dispatch(
			'GET',
			sprintf( '/ticketoo/v1/attachments/%d', $attachment_id ),
			array( 'token' => $token )
		);

		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( file_get_contents( self::fixture() ), $ok->get_data() );
	}

	/**
	 * Path of the upload fixture shipped with the test suite.
	 *
	 * @return string Absolute path to tests/fixtures/sample.txt.
	 */
	private static function fixture(): string {
		return __DIR__ . '/fixtures/sample.txt';
	}

	/**
	 * Dispatches a request through the REST server.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $route  Route path.
	 * @param array      $query  Query-string parameters (also used for tokens).
	 * @param array|null $json   JSON request body, or null when there is none.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, string $route, array $query = array(), ?array $json = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( array() !== $query ) {
			$request->set_query_params( $query );
		}

		if ( null !== $json ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $json ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Dispatches a multipart POST with body fields and file uploads, mirroring
	 * what WP_REST_Server::serve_request builds from $_POST and $_FILES.
	 *
	 * @param string $route Route path.
	 * @param array  $body  Non-file body parameters.
	 * @param array  $files $_FILES-shaped parameter tree.
	 * @param array  $query Query-string parameters (also used for tokens).
	 * @return WP_REST_Response
	 */
	private function dispatch_with_files( string $route, array $body, array $files, array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'multipart/form-data' );

		if ( array() !== $query ) {
			$request->set_query_params( $query );
		}

		$request->set_body_params( $body );
		$request->set_file_params( $files );

		return rest_do_request( $request );
	}

	/**
	 * Builds an $_FILES-shaped tree for a single file under the `files[]`
	 * input name, exactly as PHP parses a multipart field named files[].
	 *
	 * @param string $name Client-side filename (the original name).
	 * @param string $tmp  Path of the uploaded temporary file.
	 * @return array File parameter tree for WP_REST_Request::set_file_params().
	 */
	private function file_params( string $name, string $tmp ): array {
		return array(
			'files' => array(
				'name'     => array( $name ),
				'type'     => array( 'text/plain' ),
				'tmp_name' => array( $tmp ),
				'error'    => array( UPLOAD_ERR_OK ),
				'size'     => array( (int) filesize( $tmp ) ),
			),
		);
	}

	/**
	 * Copies a fixture into a fresh temporary file the controller may consume.
	 *
	 * @param string $source Path of the file to copy.
	 * @return string Path of the temporary copy (tracked for tear_down()).
	 */
	private function temp_copy( string $source ): string {
		$tmp = tempnam( sys_get_temp_dir(), 'ticketoo' );
		copy( $source, $tmp );
		$this->temp_files[] = $tmp;

		return $tmp;
	}

	/**
	 * Fetches one attachment row straight from the database.
	 *
	 * @param int $id Attachment id.
	 * @return object|null Row, or null when it does not exist.
	 */
	private function attachment_row( int $id ): ?object {
		global $wpdb;

		$table = Activator::table_names()['attachments'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion reads the row it just created; table name comes from Activator::table_names().
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Counts the attachment rows currently stored.
	 *
	 * @return int Row count.
	 */
	private function attachment_count(): int {
		global $wpdb;

		$table = Activator::table_names()['attachments'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion counts rows in a table named by Activator::table_names().
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Lists every uploaded file stored under the ticketoo upload directory.
	 *
	 * Control files (dotfiles such as .htaccess) are excluded: they are
	 * written once and are not uploads.
	 *
	 * @return string[] File names (basename only).
	 */
	private function stored_filenames(): array {
		$base = wp_upload_dir()['basedir'] . '/ticketoo';

		if ( ! is_dir( $base ) ) {
			return array();
		}

		$names    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && ! str_starts_with( $file->getFilename(), '.' ) ) {
				$names[] = $file->getFilename();
			}
		}

		sort( $names );

		return $names;
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
}
