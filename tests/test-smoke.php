<?php
/**
 * Smoke tests for the plugin skeleton.
 *
 * @package Ticketoo
 */

class Test_Smoke extends WP_UnitTestCase {
	public function test_plugin_constants_defined() {
		$this->assertTrue( defined( 'TICKETOO_FILE' ) );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', TICKETOO_VERSION );
	}
}
