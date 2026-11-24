<?php

use MDIA\Install\Schema;

class SchemaTest extends WP_UnitTestCase {

	private function table_exists( string $name ): bool {
		global $wpdb;
		return $wpdb->prefix . $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $name ) );
	}

	public function test_install_creates_tables_version_and_capabilities() {
		Schema::install();

		$this->assertTrue( $this->table_exists( 'mdia_items' ) );
		$this->assertTrue( $this->table_exists( 'mdia_usages' ) );
		$this->assertTrue( $this->table_exists( 'mdia_changes' ) );
		$this->assertSame( 1, (int) get_option( 'mdia_db_version' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'mdia_manage' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'mdia_review' ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'mdia_review' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'mdia_manage' ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'mdia_review' ) );
	}

	public function test_uninstall_removes_tables_options_and_capabilities() {
		global $wpdb;
		// DDL must hit the real tables, not the per-test temporary ones.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		update_option( 'mdia_settings', array( 'daily_limit' => 5 ) );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'mdia_generate_item', array( 987 ), 'mdia' );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'other_plugin_action', array(), 'other' );

		Schema::uninstall();

		$tables  = array( $this->table_exists( 'mdia_items' ), $this->table_exists( 'mdia_usages' ), $this->table_exists( 'mdia_changes' ) );
		$options = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'mdia\\_%'" );
		$cap     = get_role( 'editor' )->has_cap( 'mdia_review' );
		$ours    = as_get_scheduled_actions( array( 'group' => 'mdia', 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
		$theirs  = as_get_scheduled_actions( array( 'group' => 'other', 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
		as_unschedule_all_actions( 'other_plugin_action' );

		Schema::install(); // Restore for the rest of the suite.

		$this->assertSame( array( false, false, false ), $tables );
		$this->assertSame( 0, $options );
		$this->assertFalse( $cap );
		$this->assertCount( 0, $ours, 'Our scheduled actions must not be left behind.' );
		$this->assertCount( 1, $theirs, 'Actions of other plugins must be left alone.' );
	}
}
