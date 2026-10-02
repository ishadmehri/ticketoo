<?php
/**
 * The optional Elementor widget: a soft dependency, loaded only once
 * Elementor has fired `elementor/loaded`.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Integrations;

use Elementor\Controls_Manager;
use Elementor\Widgets_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the `ticketoo-ticket` widget with Elementor.
 *
 * The file itself must never load before Elementor does — the widget class
 * below extends \Elementor\Widget_Base — so Plugin::boot() requires this file
 * only from the `elementor/loaded` path.
 */
final class Elementor {

	/**
	 * Whether the widget has already been handed to Elementor.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Queues the widget registration for Elementor's widget manager.
	 *
	 * Called on `elementor/loaded`, the earliest hook at which Elementor's
	 * classes are guaranteed to exist; the widget itself is handed over on
	 * `elementor/widgets/register`, which Elementor fires during WordPress's
	 * `init`.
	 *
	 * @return void
	 */
	public static function maybe_register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widget' ) );
	}

	/**
	 * Passes the widget instance to Elementor's widgets manager.
	 *
	 * @param Widgets_Manager $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public static function register_widget( Widgets_Manager $widgets_manager ): void {
		$widgets_manager->register( new TicketWidget() );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The widget extends \Elementor\Widget_Base, so both structures belong to this one file, which is only ever loaded once Elementor itself has loaded.
/**
 * The Ticketoo ticket widget: a `view` select and an `id` number control
 * whose render output is the [ticketoo] shortcode, so the widget adds no
 * markup of its own.
 */
class TicketWidget extends Widget_Base {

	/**
	 * Widget name persisted in Elementor's saved content.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'ticketoo-ticket';
	}

	/**
	 * Panel title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Ticketoo Ticket', 'ticketoo' );
	}

	/**
	 * Panel icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'dashicons-feedback';
	}

	/**
	 * Panel categories the widget appears in.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return array( 'general' );
	}

	/**
	 * Registers the `view` select and the `id` number controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'ticketoo_content_section',
			array(
				'label' => __( 'Ticketoo ticket', 'ticketoo' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'view',
			array(
				'label'   => __( 'View', 'ticketoo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'list'   => __( 'Ticket list', 'ticketoo' ),
					'form'   => __( 'New ticket form', 'ticketoo' ),
					'ticket' => __( 'Conversation', 'ticketoo' ),
				),
				'default' => 'list',
			)
		);

		$this->add_control(
			'id',
			array(
				'label'     => __( 'Ticket ID', 'ticketoo' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 0,
				'default'   => 0,
				'condition' => array( 'view' => 'ticket' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Renders the widget through the [ticketoo] shortcode.
	 *
	 * @return void
	 */
	public function render(): void {
		$settings = $this->get_settings_for_display();
		$view     = isset( $settings['view'] ) ? (string) $settings['view'] : 'list';

		if ( ! in_array( $view, array( 'list', 'form', 'ticket' ), true ) ) {
			$view = 'list';
		}

		$id = isset( $settings['id'] ) ? (int) $settings['id'] : 0;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Server-rendered [ticketoo] markup; the same output the block render_callback returns.
		echo do_shortcode( sprintf( '[ticketoo view="%s" id="%d"]', $view, $id ) );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
