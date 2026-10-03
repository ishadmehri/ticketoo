<?php
/**
 * Guards the shipped stylesheets against physical directional CSS.
 *
 * The plugin ships no `-rtl.css` mirrors: RTL support comes from CSS
 * logical properties (margin-block/inline, inset-inline, float:
 * inline-end, logical borders) which browsers flip automatically under
 * dir="rtl" — documented in both stylesheets' headers. A physical
 * left/right declaration slipping into either file would break RTL
 * layouts silently, so this test fails instead.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Test_Rtl_Css extends WP_UnitTestCase {

	/**
	 * Physical directional declarations that would break RTL rendering:
	 * margin/padding/border/outline left|right shorthands, text-align and
	 * float with physical keywords, and standalone left:/right: properties.
	 */
	private const PHYSICAL_PATTERN = '/(?:margin|padding|border|outline)-(?:left|right)\s*:|text-align\s*:\s*(?:left|right)\b|float\s*:\s*(?:left|right)\b|(?<![\w-])(?:left|right)\s*:/i';

	/**
	 * Asserts that frontend.css and admin.css contain no physical
	 * directional properties.
	 *
	 * @return void
	 */
	public function test_shipped_css_stays_logical_only(): void {
		foreach ( array( 'frontend.css', 'admin.css' ) as $name ) {
			$path = dirname( __DIR__ ) . '/assets/css/' . $name;

			$this->assertFileExists( $path );

			$css     = (string) file_get_contents( $path );
			$matches = array();
			preg_match_all( self::PHYSICAL_PATTERN, $css, $matches );

			$this->assertSame(
				array(),
				$matches[0],
				sprintf(
					'%s must stay RTL-safe through logical properties; found physical directional CSS: %s.',
					$name,
					implode( ', ', $matches[0] )
				)
			);
		}
	}
}
