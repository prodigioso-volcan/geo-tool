<?php

class MenuTest extends WP_UnitTestCase {

	private function submenu_slugs(): array {
		global $submenu, $menu;
		$submenu = array();
		$menu    = array();
		do_action( 'admin_menu' );
		set_current_screen( 'toplevel_page_mdia' ); // Core callbacks on admin_enqueue_scripts read the screen.
		return wp_list_pluck( $menu, 2 );
	}

	public function test_reviewers_get_a_top_level_screen() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertContains( 'mdia', $this->submenu_slugs() );
	}

	public function test_scripts_load_only_on_the_plugin_screen_with_the_right_data() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->submenu_slugs();

		do_action( 'admin_enqueue_scripts', 'upload.php' );
		$this->assertFalse( wp_script_is( 'mdia-admin', 'enqueued' ) );

		do_action( 'admin_enqueue_scripts', 'toplevel_page_mdia' );
		$this->assertTrue( wp_script_is( 'mdia-admin', 'enqueued' ) );

		$inline = implode( '', (array) wp_scripts()->get_data( 'mdia-admin', 'before' ) );
		$this->assertStringContainsString( '"canManage":false', $inline );
		$this->assertStringContainsString( '"restNamespace":"mdia\/v1"', $inline );
		$this->assertStringContainsString( 'options-connectors.php', $inline );
	}

	public function test_administrators_can_manage() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->submenu_slugs();
		do_action( 'admin_enqueue_scripts', 'toplevel_page_mdia' );

		$inline = implode( '', (array) wp_scripts()->get_data( 'mdia-admin', 'before' ) );
		$this->assertStringContainsString( '"canManage":true', $inline );
		$this->assertStringContainsString( '"wizardDone":false', $inline );
	}
}
