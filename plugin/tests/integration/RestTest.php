<?php

use MDIA\Data\Items;

class RestTest extends WP_Test_REST_TestCase {
	use MDIA_Test_Helpers;

	private int $admin;
	private int $editor;
	private int $subscriber;
	private MDIA_Fake_Provider $fake;

	public function set_up() {
		parent::set_up();
		$this->images_in_use = true;
		\MDIA\Settings::update( array( 'auto_generate_new' => false, 'wizard_done' => true ) );
		$this->fake       = MDIA_Fake_Provider::install();
		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_filter( 'mdia_checks', static fn( $checks ) => array_filter( $checks, static fn( $check ) => 'site' !== $check->target_type() ) );
		update_option( 'mdia_full_audit', array( 'running' => false, 'started_at' => gmdate( 'c' ), 'finished_at' => gmdate( 'c' ) ) );
		do_action( 'rest_api_init' );
	}

	private function call( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/mdia/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_status_requires_the_review_capability() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'GET', '/status' )->get_status() );

		wp_set_current_user( $this->subscriber );
		$this->assertSame( 403, $this->call( 'GET', '/status' )->get_status() );

		wp_set_current_user( $this->editor );
		$response = $this->call( 'GET', '/status' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $response->get_data()['state'], array( 'green', 'amber', 'red' ) );
		$this->assertArrayHasKey( 'areas', $response->get_data() );
	}

	public function test_editors_cannot_manage() {
		wp_set_current_user( $this->editor );

		$this->assertSame( 403, $this->call( 'GET', '/settings' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/settings', array( 'daily_limit' => 5 ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/generation' )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', '/generation' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/audit' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/wizard/test' )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', '/wizard/requirements' )->get_status() );
	}

	public function test_items_are_filtered_paginated_and_decorated() {
		$this->images_in_use = false; // The page each image is on is part of what is checked.
		wp_set_current_user( $this->editor );
		$review = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$id       = $this->make_real_image();
			$review[] = $id;
			( new Items() )->upsert( $id, array( 'status' => 'needs_review', 'proposed_alt' => "Propuesta $i" ) );
		}
		$this->make_image( 'Vista aérea del puerto de Vigo' );
		$this->make_post( $this->image_block( $review[0] ) );

		$response = $this->call( 'GET', '/items', array( 'status' => 'needs_review', 'per_page' => 2 ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data );
		$this->assertSame( 3, $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 2, $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( $review[2], $data[0]['attachment_id'] );

		$all   = $this->call( 'GET', '/items', array( 'status' => 'needs_review' ) )->get_data();
		$first = array_values( array_filter( $all, static fn( $item ) => $item['attachment_id'] === $review[0] ) )[0];
		$this->assertSame( 1, $first['usage_count'] );
		$this->assertSame( 0, $first['unmanaged_count'] );
		$this->assertSame( 0, $first['fixed_count'] );
		$this->assertStringContainsString( 'real-', $first['filename'] );
		$this->assertNotEmpty( $first['thumbnail_url'] );
		$this->assertStringContainsString( 'post.php?post=' . $review[0], $first['edit_url'] );
		$this->assertSame( array( 'missing' ), $first['issues'] );
		$this->assertTrue( $first['can_undo'] === false );
	}

	public function test_items_rejects_unknown_status_and_oversized_pages() {
		wp_set_current_user( $this->editor );

		$this->assertSame( 400, $this->call( 'GET', '/items', array( 'status' => 'nope' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/items', array( 'per_page' => 500 ) )->get_status() );
	}

	public function test_approve_starts_applying_as_the_current_user() {
		wp_set_current_user( $this->editor );
		$id = $this->make_image();

		$response = $this->call( 'POST', "/items/$id/approve", array( 'text' => 'Un faro blanco' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'applying', $response->get_data()['status'] );
		$this->assertSame( $this->editor, $this->item( $id )['reviewed_by'] );
	}

	public function test_approve_with_empty_text_is_a_400() {
		wp_set_current_user( $this->editor );
		$id = $this->make_image();

		$response = $this->call( 'POST', "/items/$id/approve", array( 'text' => '  ' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'mdia_empty', $response->get_data()['code'] );
	}

	public function test_unknown_image_is_a_404() {
		wp_set_current_user( $this->editor );

		$this->assertSame( 404, $this->call( 'POST', '/items/999999/ignore' )->get_status() );
	}

	public function test_item_actions_route_to_the_right_action() {
		wp_set_current_user( $this->editor );
		$a = $this->make_image();
		$b = $this->make_image();
		$c = $this->make_image();

		$this->assertSame( 'ignored', $this->call( 'POST', "/items/$a/ignore" )->get_data()['status'] );
		$this->assertSame( 'applying', $this->call( 'POST', "/items/$b/decorative" )->get_data()['status'] );
		$this->assertSame( 'needs_generation', $this->call( 'POST', "/items/$c/regenerate" )->get_data()['status'] );
		$this->assertSame( 1, $this->item( $c )['queued'] );
		$this->assertSame( 400, $this->call( 'POST', "/items/$a/undo" )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', "/items/$b/ignore" )->get_status() );
	}

	public function test_bulk_approve() {
		wp_set_current_user( $this->editor );
		$ids = array( $this->make_image(), $this->make_image() );
		foreach ( $ids as $id ) {
			( new Items() )->upsert( $id, array( 'status' => 'needs_review', 'proposed_alt' => 'Un faro blanco' ) );
		}

		$entries  = array(
			array( 'id' => $ids[0], 'text' => 'Texto corregido a mano' ),
			array( 'id' => $ids[1] ),
		);
		$response = $this->call( 'POST', '/items/approve', array( 'items' => $entries ) );
		$this->assertSame( array( 'done' => 2, 'failed' => 0 ), $response->get_data() );
		$this->assertSame( 'Texto corregido a mano', $this->item( $ids[0] )['proposed_alt'] );
		$this->assertSame( 'Un faro blanco', $this->item( $ids[1] )['proposed_alt'] );

		$too_many = array_map( static fn( $n ) => array( 'id' => $n ), range( 1, 101 ) );
		$this->assertSame( 400, $this->call( 'POST', '/items/approve', array( 'items' => $too_many ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/items/approve', array( 'items' => 'x' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/items/approve', array( 'items' => array( array( 'text' => 'sin id' ) ) ) )->get_status() );
	}

	public function test_settings_are_validated_and_saved() {
		wp_set_current_user( $this->admin );

		$this->assertSame( 400, $this->call( 'POST', '/settings', array( 'daily_limit' => 0 ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/settings', array( 'daily_limit' => 5001 ) )->get_status() );

		$response = $this->call( 'POST', '/settings', array( 'daily_limit' => 50, 'auto_generate_new' => true ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 50, $response->get_data()['daily_limit'] );
		$this->assertTrue( $this->call( 'GET', '/settings' )->get_data()['auto_generate_new'] );
		$this->assertTrue( $this->call( 'GET', '/settings' )->get_data()['wizard_done'] );
	}

	public function test_generation_is_started_by_a_person_and_can_be_limited_and_paused() {
		wp_set_current_user( $this->admin );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_image();
		}

		$this->assertSame( array( 'queued' => 2, 'remaining' => 3 ), $this->call( 'POST', '/generation', array( 'limit' => 2 ) )->get_data() );
		$this->assertSame( array( 'queued' => 3, 'remaining' => 0 ), $this->call( 'POST', '/generation' )->get_data() );
		$this->assertSame( array( 'paused' => 5 ), $this->call( 'DELETE', '/generation' )->get_data() );
	}

	public function test_audit_can_be_started() {
		wp_set_current_user( $this->admin );

		$this->assertTrue( $this->call( 'POST', '/audit' )->get_data()['running'] );
	}

	public function test_wizard_requirements() {
		wp_set_current_user( $this->admin );
		$this->make_image();

		$data = $this->call( 'GET', '/wizard/requirements' )->get_data();
		$this->assertTrue( $data['wp_ok'] );
		$this->assertSame( array( 'available' => true, 'reason' => '' ), $data['provider'] );
		$this->assertSame( 1, $data['image_count'] );
		$this->assertStringContainsString( 'options-connectors.php', $data['connectors_url'] );
		$this->assertSame( \MDIA\Seo\Seo::adapter()->is_active(), $data['seo']['active'] );
		$this->assertSame( \MDIA\Seo\Seo::adapter()->name(), $data['seo']['name'] );
		$this->assertStringContainsString( 'plugin-install.php', $data['seo']['install_url'] );
	}

	public function test_wizard_test_describes_an_image_without_storing_anything() {
		wp_set_current_user( $this->admin );
		$id                    = $this->make_real_image();
		$this->fake->responses = array( 'Un faro blanco sobre un acantilado' );

		$response = $this->call( 'POST', '/wizard/test' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $id, $data['attachment_id'] );
		$this->assertSame( 'Un faro blanco sobre un acantilado', $data['text'] );
		$this->assertSame( 'Fake', $data['provider'] );
		$this->assertNull( $this->item( $id )['proposed_alt'] );
		$this->assertSame( 'needs_generation', $this->item( $id )['status'] );
		$this->assertSame( 1, \MDIA\AI\Budget::used_today() );
	}

	public function test_wizard_test_reports_provider_errors_and_missing_images() {
		wp_set_current_user( $this->admin );
		$this->assertSame( 'mdia_no_images', $this->call( 'POST', '/wizard/test' )->get_data()['code'] );

		$this->make_real_image();
		$this->fake->responses = array( new WP_Error( 'http', 'Invalid API key' ) );
		$response              = $this->call( 'POST', '/wizard/test' );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'Invalid API key', $response->get_data()['message'] );
	}

	public function test_starting_generation_by_hand_clears_a_previous_provider_failure() {
		wp_set_current_user( $this->admin );
		$this->make_image();
		\MDIA\AI\Generator::set_health( false, 'failed', 'Invalid API key' );

		$this->call( 'POST', '/generation' );

		$this->assertNull( \MDIA\AI\Generator::health() );
	}

	public function test_wizard_test_also_rewrites_an_answer_that_is_too_long() {
		wp_set_current_user( $this->admin );
		$this->make_real_image();
		$this->fake->responses = array( str_repeat( 'palabra ', 30 ) );
		$this->fake->rewrites  = array( 'Un faro blanco sobre un acantilado' );

		$this->assertSame( 'Un faro blanco sobre un acantilado', $this->call( 'POST', '/wizard/test' )->get_data()['text'] );
	}

	public function test_raw_provider_errors_are_only_shown_to_managers() {
		$id = $this->make_image();
		( new Items() )->upsert( $id, array( 'status' => 'error', 'last_error' => 'AccessDenied: arn:aws:iam::123456789012:user/x is not authorized' ) );
		\MDIA\AI\Generator::set_health( false, 'failed', 'AccessDenied: arn:aws:iam::123456789012:user/x' );

		wp_set_current_user( $this->editor );
		$item   = $this->call( 'GET', '/items', array( 'status' => 'error' ) )->get_data()[0];
		$status = wp_json_encode( $this->call( 'GET', '/status' )->get_data() );
		$this->assertStringNotContainsString( 'arn:aws', $item['last_error'] );
		$this->assertNotSame( '', $item['last_error'] );
		$this->assertStringNotContainsString( 'arn:aws', $status );

		wp_set_current_user( $this->admin );
		$item   = $this->call( 'GET', '/items', array( 'status' => 'error' ) )->get_data()[0];
		$status = wp_json_encode( $this->call( 'GET', '/status' )->get_data() );
		$this->assertStringContainsString( 'arn:aws', $item['last_error'] );
		$this->assertStringContainsString( 'arn:aws', $status );
	}

	public function test_generation_cannot_be_started_before_setup_is_finished() {
		wp_set_current_user( $this->admin );
		\MDIA\Settings::update( array( 'wizard_done' => false ) );
		$this->make_image();

		$this->assertSame( 0, $this->call( 'POST', '/generation' )->get_data()['queued'] );
	}

	private function page_with_finding(): array {
		$id  = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'La Fundación', 'post_content' => "<!-- wp:paragraph -->\n<p>Texto de la página.</p>\n<!-- /wp:paragraph -->" ) );
		$row = ( new \MDIA\Data\Findings() )->find( 'post.metadesc', 'post', $id );
		return array( $id, $row ? $row['id'] : 0 );
	}

	public function test_findings_are_listed_by_area_and_kind_of_fix() {
		if ( ! class_exists( 'WPSEO_Meta' ) ) {
			$this->markTestSkipped( 'Yoast SEO is not installed.' );
		}
		wp_set_current_user( $this->editor );
		list( $id, $fid ) = $this->page_with_finding();

		$texts = $this->call( 'GET', '/findings', array( 'fix' => 'ai', 'area' => 'pages', 'per_page' => 100 ) );
		$mine  = array_values( array_filter( $texts->get_data(), static fn( $item ) => $item['id'] === $fid ) )[0];

		$this->assertSame( 200, $texts->get_status() );
		$this->assertSame( 'post.metadesc', $mine['check_id'] );
		$this->assertSame( 'La Fundación', $mine['target_label'] );
		$this->assertSame( 'required', $mine['severity'] );
		$this->assertSame( 'ai', $mine['fix'] );
		$this->assertSame( array( 'min' => 120, 'max' => 155, 'unit' => 'chars' ), $mine['limits'] );
		$this->assertNotSame( '', $mine['label'] );
		$this->assertNotSame( '', $mine['why'] );
		$this->assertStringContainsString( 'post.php?post=' . $id, $mine['fix_url'] );
		$this->assertTrue( $mine['can_edit'] );
		$this->assertGreaterThan( 0, $texts->get_headers()['X-WP-Total'] );

		$manual = wp_list_pluck( $this->call( 'GET', '/findings', array( 'fix' => 'manual', 'per_page' => 100 ) )->get_data(), 'check_id' );
		$this->assertNotContains( 'post.metadesc', $manual );
		$this->assertContains( 'post.featured_image', $manual );

		wp_set_current_user( $this->subscriber );
		$this->assertSame( 403, $this->call( 'GET', '/findings' )->get_status() );
	}

	public function test_finding_actions_over_rest() {
		if ( ! class_exists( 'WPSEO_Meta' ) ) {
			$this->markTestSkipped( 'Yoast SEO is not installed.' );
		}
		wp_set_current_user( $this->editor );
		list( $id, $fid ) = $this->page_with_finding();
		$text             = 'Cinco rutas a pie por la costa de las Rías Baixas, con mapas, tiempos y consejos prácticos para cada tramo del camino.';

		$this->assertSame( 400, $this->call( 'POST', "/findings/$fid/approve", array( 'text' => ' ' ) )->get_status() );
		$approved = $this->call( 'POST', "/findings/$fid/approve", array( 'text' => $text ) );
		$this->assertSame( 'applied', $approved->get_data()['status'] );
		$this->assertTrue( $approved->get_data()['can_undo'] );
		$this->assertSame( $text, \MDIA\Seo\Seo::adapter()->post_description( $id ) );

		$this->assertSame( 200, $this->call( 'POST', "/findings/$fid/undo" )->get_status() );
		$this->assertSame( '', \MDIA\Seo\Seo::adapter()->post_raw_description( $id ) );

		$fid = ( new \MDIA\Data\Findings() )->find( 'post.metadesc', 'post', $id )['id'];
		$this->assertSame( 403, $this->call( 'POST', "/findings/$fid/waive" )->get_status(), 'Required checks need a manager.' );
		wp_set_current_user( $this->admin );
		$this->assertSame( 'waived', $this->call( 'POST', "/findings/$fid/waive" )->get_data()['status'] );
		$this->assertSame( 200, $this->call( 'POST', "/findings/$fid/reopen" )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/findings/999999/waive' )->get_status() );
	}

	public function test_text_generation_needs_consent_and_is_started_by_a_manager() {
		if ( ! class_exists( 'WPSEO_Meta' ) ) {
			$this->markTestSkipped( 'Yoast SEO is not installed.' );
		}
		list( , $fid ) = $this->page_with_finding();

		wp_set_current_user( $this->editor );
		$this->assertSame( 403, $this->call( 'POST', '/findings/generation' )->get_status() );

		wp_set_current_user( $this->admin );
		$this->assertSame( 0, $this->call( 'POST', '/findings/generation' )->get_data()['queued'], 'No consent yet.' );
		$this->assertSame( 200, $this->call( 'POST', '/settings', array( 'text_consent' => true ) )->get_status() );
		$this->assertGreaterThan( 0, $this->call( 'POST', '/findings/generation', array( 'area' => 'pages' ) )->get_data()['queued'] );
		$this->assertSame( 1, ( new \MDIA\Data\Findings() )->get( $fid )['queued'] );
	}

	public function test_acf_field_can_be_waived_site_wide_over_rest() {
		( new \MDIA\Data\Findings() )->upsert( 'post.acf_empty', 'post', 601, array( 'status' => 'open' ), 'pie' );

		wp_set_current_user( $this->editor );
		$this->assertSame( 403, $this->call( 'POST', '/findings/acf-field', array( 'name' => 'pie' ) )->get_status() );
		wp_set_current_user( $this->admin );
		$this->assertSame( 200, $this->call( 'POST', '/findings/acf-field', array( 'name' => 'pie' ) )->get_status() );
		$this->assertNull( ( new \MDIA\Data\Findings() )->find( 'post.acf_empty', 'post', 601, 'pie' ) );
	}
	public function test_a_test_page_is_trashed_through_the_api() {
		wp_set_current_user( $this->editor );
		$id  = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Prueba' ) );
		$row = ( new \MDIA\Data\Findings() )->find( 'post.test_page', 'post', $id, '' );

		$listed = $this->call( 'GET', '/findings', array( 'check_id' => 'post.test_page' ) )->get_data();
		$this->assertTrue( $listed[0]['can_trash'] );

		wp_set_current_user( $this->subscriber );
		$this->assertSame( 403, $this->call( 'POST', '/findings/' . $row['id'] . '/trash' )->get_status() );
		wp_set_current_user( $this->editor );
		$response = $this->call( 'POST', '/findings/' . $row['id'] . '/trash' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'resolved', $response->get_data()['status'] );
		$this->assertSame( 'trash', get_post_status( $id ) );
	}

}
