<?php

use MDIA\Admin\Activation;

class ActivationRedirectTest extends WP_UnitTestCase {

	public function tear_down() {
		unset( $_GET['activate-multi'] );
		parent::tear_down();
	}

	public function test_activation_remembers_to_open_the_wizard() {
		do_action( 'activate_' . plugin_basename( MDIA_FILE ) );

		$this->assertNotFalse( get_transient( 'mdia_activation_redirect' ) );
	}

	public function test_managers_are_sent_to_the_plugin_screen_once() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_transient( 'mdia_activation_redirect', 1, 60 );

		$this->assertSame( admin_url( 'admin.php?page=mdia' ), Activation::redirect_target() );
		$this->assertFalse( get_transient( 'mdia_activation_redirect' ) );
		$this->assertNull( Activation::redirect_target() );
	}

	public function test_bulk_activation_does_not_redirect() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_transient( 'mdia_activation_redirect', 1, 60 );
		$_GET['activate-multi'] = '1';

		$this->assertNull( Activation::redirect_target() );
		$this->assertFalse( get_transient( 'mdia_activation_redirect' ) );
	}

	public function test_users_who_cannot_manage_are_not_redirected() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		set_transient( 'mdia_activation_redirect', 1, 60 );

		$this->assertNull( Activation::redirect_target() );
	}

	public function test_no_redirect_once_the_wizard_is_done() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		\MDIA\Settings::update( array( 'wizard_done' => true ) );
		set_transient( 'mdia_activation_redirect', 1, 60 );

		$this->assertNull( Activation::redirect_target() );
	}
}
