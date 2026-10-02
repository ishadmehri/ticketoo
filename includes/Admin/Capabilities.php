<?php
/**
 * Capability and role registration for the support panel.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines and registers the plugin's capability and support-agent role.
 */
class Capabilities {

	/**
	 * Capability required to manage tickets (panel access, assignment,
	 * scope=all REST queries, new-ticket notifications).
	 *
	 * @var string
	 */
	const CAP = 'ticketoo_manage_tickets';

	/**
	 * Slug of the support-agent role.
	 *
	 * @var string
	 */
	const ROLE = 'ticketoo_agent';

	/**
	 * Creates the agent role and grants the capability to administrators.
	 *
	 * Idempotent: existing roles and grants are left untouched, so it is
	 * safe to run on activation and on every boot.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( null === get_role( self::ROLE ) ) {
			add_role(
				self::ROLE,
				'Support Agent',
				array(
					'read'    => true,
					self::CAP => true,
				)
			);
		}

		$administrator = get_role( 'administrator' );

		if ( null !== $administrator && ! $administrator->has_cap( self::CAP ) ) {
			$administrator->add_cap( self::CAP );
		}
	}
}
