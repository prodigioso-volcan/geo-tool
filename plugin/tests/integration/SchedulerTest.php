<?php

use MDIA\Queue\Scheduler;
use MDIA\Settings;

class SchedulerTest extends WP_UnitTestCase {
	use MDIA_Test_Helpers;

	public function set_up() {
		parent::set_up();
		$this->images_in_use = true;
		// Uploads would otherwise queue their own generation and blur what each test checks.
		\MDIA\Settings::update( array( 'auto_generate_new' => false, 'wizard_done' => true ) );
	}

	public function test_settings_defaults_and_update() {
		delete_option( 'mdia_settings' );
		$this->assertSame( 200, Settings::get( 'daily_limit' ) );
		$this->assertTrue( Settings::get( 'auto_generate_new' ) );
		$this->assertFalse( Settings::get( 'wizard_done' ) );

		Settings::update( array( 'daily_limit' => 50, 'unknown' => 'x' ) );

		$this->assertSame( 50, Settings::get( 'daily_limit' ) );
		$this->assertTrue( Settings::get( 'auto_generate_new' ) );
		$this->assertNull( Settings::get( 'unknown' ) );
	}

	public function test_full_audit_covers_every_post_and_image_in_batches() {
		global $wpdb;
		$this->images_in_use = false; // This one counts pages and usages exactly.
		$images              = array();
		for ( $i = 0; $i < 120; $i++ ) {
			$images[] = $this->make_image();
		}
		for ( $i = 0; $i < 60; $i++ ) {
			$this->make_post( $this->image_block( $images[ $i ], 'Vista aérea del puerto de Vigo' ) );
		}
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mdia_items" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mdia_usages" );

		Scheduler::start_full_audit();
		$this->assertTrue( Scheduler::full_audit_state()['running'] );
		$this->run_queue();

		$this->assertSame( 120, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mdia_items" ) );
		$this->assertSame( 60, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mdia_usages" ) );
		$state = Scheduler::full_audit_state();
		$this->assertFalse( $state['running'] );
		$this->assertNotNull( $state['finished_at'] );
		$this->assertNotNull( Scheduler::last_run_at() );
	}

	public function test_full_audit_does_not_start_twice() {
		Scheduler::start_full_audit();
		Scheduler::start_full_audit();

		$this->assertCount( 1, $this->pending_actions( 'mdia_index_batch' ) );
	}

	public function test_enqueue_generation_skips_duplicates_and_marks_queued() {
		$a = $this->make_image();
		$b = $this->make_image();
		$c = $this->make_image();

		$this->assertSame( 3, Scheduler::enqueue_generation( array( $a, $b, $b, $c ) ) );
		$this->assertCount( 3, $this->pending_actions( 'mdia_generate_item' ) );
		$this->assertSame( 1, $this->item( $a )['queued'] );
		$this->assertSame( 0, Scheduler::enqueue_generation( array( $a, $b, $c ) ) );
	}

	public function test_enqueue_generation_ignores_images_that_do_not_need_it() {
		$ok = $this->make_image( 'Vista aérea del puerto de Vigo' );

		$this->assertSame( 0, Scheduler::enqueue_generation( array( $ok, 999999 ) ) );
	}

	public function test_pause_generation_cancels_pending_and_clears_flag() {
		$a = $this->make_image();
		$b = $this->make_image();
		$c = $this->make_image();
		Scheduler::enqueue_generation( array( $a, $b, $c ) );

		$this->assertSame( 3, Scheduler::pause_generation() );
		$this->assertCount( 0, $this->pending_actions( 'mdia_generate_item' ) );
		$this->assertSame( 0, $this->item( $a )['queued'] );
	}

	public function test_daily_action_is_scheduled() {
		Scheduler::ensure_daily();

		$this->assertNotFalse( as_next_scheduled_action( 'mdia_daily', array(), 'mdia' ) );
	}
}
