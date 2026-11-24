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
		$this->assertSame( Schema::DB_VERSION, (int) get_option( 'mdia_db_version' ) );
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

		$marked = self::factory()->post->create();
		update_post_meta( $marked, '_mdia_fp_desc', 'abc' );
		update_post_meta( $marked, '_mdia_fp_title', 'abc' );
		set_transient( 'mdia_status', array( 'state' => 'green' ), 60 );

		Schema::uninstall();

		$this->assertSame( '', get_post_meta( $marked, '_mdia_fp_desc', true ) );
		$this->assertSame( '', get_post_meta( $marked, '_mdia_fp_title', true ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%mdia\\_%'" ) );

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

	public function test_capabilities_come_back_after_roles_are_reset() {
		foreach ( array( 'administrator', 'editor' ) as $role ) {
			get_role( $role )->remove_cap( 'mdia_manage' );
			get_role( $role )->remove_cap( 'mdia_review' );
		}

		$this->assertNotFalse( has_action( 'admin_init', array( Schema::class, 'ensure_capabilities' ) ) );
		Schema::ensure_capabilities();

		$this->assertTrue( get_role( 'administrator' )->has_cap( 'mdia_manage' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'mdia_review' ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'mdia_review' ) );
	}

	public function test_a_deliberate_change_to_editors_is_respected() {
		get_role( 'editor' )->remove_cap( 'mdia_review' );

		Schema::ensure_capabilities();

		$this->assertFalse( get_role( 'editor' )->has_cap( 'mdia_review' ), 'Administrators kept their capability, so this was not a reset.' );
		get_role( 'editor' )->add_cap( 'mdia_review' );
	}
}
