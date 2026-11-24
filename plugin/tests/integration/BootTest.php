<?php

class BootTest extends WP_UnitTestCase {

	public function test_plugin_boots_with_action_scheduler_and_ai_client() {
		$this->assertTrue( class_exists( \MDIA\Plugin::class ) );
		$this->assertTrue( function_exists( 'as_enqueue_async_action' ) );
		$this->assertTrue( function_exists( 'wp_ai_client_prompt' ) );
		$this->assertSame( 10, has_action( 'plugins_loaded', array( \MDIA\Plugin::instance(), 'boot' ) ) );
	}

	public function test_plugin_headers_name_the_product_and_its_author() {
		$data = get_file_data( MDIA_FILE, array( 'name' => 'Plugin Name', 'author' => 'Author', 'version' => 'Version' ) );

		$this->assertSame( 'Revisor ALT by JUNGLE', $data['name'] );
		$this->assertSame( 'JUNGLE', $data['author'] );
		$this->assertSame( MDIA_VERSION, $data['version'] );
	}
}
