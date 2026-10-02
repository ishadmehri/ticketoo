<?php
/**
 * Guest bearer-token issue and verification helpers.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

namespace Ticketoo\Guest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues and verifies the raw 64-hex guest tokens stored in
 * ticketoo_tickets.guest_token.
 *
 * The token is stored raw (spec §4, amended 2026-09-28) so later emails can
 * rebuild token links; it is a bearer capability: compare only with
 * hash_equals, never log it, never return it from a REST response.
 */
class TokenAccess {

	/**
	 * Generates a fresh guest token.
	 *
	 * @return string 64-char lowercase hex string (256 bits of entropy).
	 */
	public static function issue(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Checks a raw token against the value stored on a ticket.
	 *
	 * Timing-safe via hash_equals; a null or empty stored value never
	 * verifies, not even against an empty raw token.
	 *
	 * @param string      $raw    Token supplied by the requester.
	 * @param string|null $stored Token stored on the ticket, or null/empty
	 *                            when the ticket has no guest token.
	 * @return bool True only on an exact match against a non-empty stored token.
	 */
	public static function verify( string $raw, ?string $stored ): bool {
		if ( null === $stored || '' === $stored ) {
			return false;
		}

		return hash_equals( $stored, $raw );
	}
}
