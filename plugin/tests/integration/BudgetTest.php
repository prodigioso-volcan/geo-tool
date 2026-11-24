<?php

use MDIA\AI\Budget;
use MDIA\Settings;

class BudgetTest extends WP_UnitTestCase {

	public function test_consume_stops_at_the_daily_limit() {
		Settings::update( array( 'daily_limit' => 2 ) );

		$this->assertSame( 2, Budget::remaining() );
		$this->assertTrue( Budget::consume() );
		$this->assertTrue( Budget::consume() );
		$this->assertFalse( Budget::consume() );
		$this->assertSame( 2, Budget::used_today() );
		$this->assertSame( 0, Budget::remaining() );
		$this->assertSame( 2, Budget::limit() );
	}

	public function test_counter_resets_on_a_new_day() {
		Settings::update( array( 'daily_limit' => 2 ) );
		Budget::consume();
		Budget::consume();
		update_option( 'mdia_budget_date', '2000-01-01' );

		$this->assertSame( 2, Budget::remaining() );
		$this->assertSame( 0, Budget::used_today() );
	}

	public function test_consume_never_goes_past_the_limit_even_with_a_stale_read() {
		global $wpdb;
		Settings::update( array( 'daily_limit' => 3 ) );
		Budget::consume();
		// Another process used the rest; this process still holds the old value in its object cache.
		$wpdb->query( "UPDATE {$wpdb->options} SET option_value = '3' WHERE option_name = 'mdia_budget_used'" );

		$this->assertFalse( Budget::consume() );
		$this->assertSame( 3, Budget::used_today() );
	}

	public function test_default_limit_is_200() {
		$this->assertSame( 200, Budget::limit() );
	}
}
