<?php
/**
 * The three Gutenberg blocks, each a thin wrapper over the [ticketoo] shortcode.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers block.js and the server-rendered ticketoo/list, ticketoo/form
 * and ticketoo/conversation blocks.
 *
 * There is deliberately no markup logic here (ADR 0003/0004): every
 * render_callback delegates to do_shortcode() so the blocks, the shortcode
 * and the Elementor widget share one rendering source.
 */
final class Gutenberg {

	/**
	 * Block name => shortcode view it renders.
	 *
	 * @var array<string, string>
	 */
	private const BLOCKS = array(
		'ticketoo/list'         => 'list',
		'ticketoo/form'         => 'form',
		'ticketoo/conversation' => 'ticket',
	);

	/**
	 * Registers the buildless editor script and the three dynamic blocks.
	 *
	 * Hooked on `init` from Plugin::boot(). The editor script only builds a
	 * shell (save() returns null); the markup is produced on the server.
	 *
	 * @return void
	 */
	public static function register(): void {
		wp_register_script(
			'ticketoo-block',
			plugins_url( 'assets/js/block.js', TICKETOO_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			TICKETOO_VERSION,
			true
		);

		wp_set_script_translations( 'ticketoo-block', 'ticketoo', TICKETOO_DIR . 'languages' );

		foreach ( self::BLOCKS as $name => $view ) {
			register_block_type(
				$name,
				array(
					'editor_script'   => 'ticketoo-block',
					'render_callback' => static function ( array $attributes ) use ( $view ): string {
						$id = (int) ( $attributes['id'] ?? 0 );

						return do_shortcode( sprintf( '[ticketoo view="%s" id="%d"]', $view, $id ) );
					},
					'attributes'      => array(
						'id' => array(
							'type'    => 'number',
							'default' => 0,
						),
					),
				)
			);
		}
	}
}
