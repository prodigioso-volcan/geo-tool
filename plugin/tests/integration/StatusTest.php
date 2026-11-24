<?php

use MDIA\Data\Items;
use MDIA\Health\Status;

class StatusTest extends WP_UnitTestCase {
	use MDIA_Test_Helpers;

	private MDIA_Fake_Provider $fake;

	public function set_up() {
		parent::set_up();
		\MDIA\Settings::update( array( 'auto_generate_new' => false, 'wizard_done' => true ) );
		$this->fake = MDIA_Fake_Provider::install();
		update_option( 'mdia_full_audit', array( 'running' => false, 'started_at' => gmdate( 'c' ), 'finished_at' => gmdate( 'c' ) ) );
	}

	public function test_site_that_was_never_audited_is_not_green() {
		delete_option( 'mdia_full_audit' );

		$status = Status::compute( true );
		$this->assertSame( 'amber', $status['state'] );
		$this->assertSame( 'amber', $this->check( $status, 'content' )['state'] );
	}

	public function test_decorative_and_ignored_images_do_not_block_green() {
		$items  = new Items();
		$usages = new \MDIA\Data\Usages();
		$a      = $this->make_image();
		$b      = $this->make_image( 'IMG_2034' );
		$items->upsert( $a, array( 'status' => 'decorative', 'human_locked' => 1 ) );
		$items->upsert( $b, array( 'status' => 'ignored', 'human_locked' => 1 ) );
		$usages->replace_for_post( 4321, array( array( 'attachment_id' => $a, 'alt' => '', 'issues' => 'missing', 'occurrences' => 1, 'manageable' => 1 ) ) );
		$usages->replace_for_post( 4322, array( array( 'attachment_id' => $b, 'alt' => 'IMG_2034', 'issues' => 'filename', 'occurrences' => 1, 'manageable' => 1 ) ) );

		$status = Status::compute( true );
		$this->assertSame( 'green', $this->check( $status, 'content' )['state'] );
		$this->assertSame( 'green', $status['state'] );
	}

	public function test_connector_check_says_whether_generation_can_be_retried() {
		\MDIA\AI\Generator::set_health( false, 'failed', 'Invalid API key' );
		$this->assertSame( 'failed', $this->check( Status::compute( true ), 'connector' )['code'] );

		$this->fake->availability = array( 'available' => false, 'reason' => 'no_connector' );
		$this->assertSame( 'no_connector', $this->check( Status::compute( true ), 'connector' )['code'] );
	}

	private function check( array $status, string $id ): array {
		foreach ( $status['checks'] as $check ) {
			if ( $id === $check['id'] ) {
				return $check;
			}
		}
		$this->fail( "Missing check $id" );
	}

	public function test_empty_library_is_green_with_full_coverage() {
		$status = Status::compute( true );

		$this->assertSame( 'green', $status['state'] );
		$this->assertSame( 100, $status['coverage'] );
		$this->assertSame( 0, $status['counts']['total'] );
		$this->assertSame( array( 'connector', 'queue', 'content', 'budget' ), wp_list_pluck( $status['checks'], 'id' ) );
		foreach ( $status['checks'] as $check ) {
			$this->assertSame( 'green', $check['state'], $check['id'] );
			$this->assertNotSame( '', $check['label'] );
		}
	}

	public function test_pending_review_is_amber_and_lowers_coverage() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->make_image( "Vista aérea número $i del puerto" );
		}
		( new Items() )->upsert( $this->make_image(), array( 'status' => 'needs_review', 'proposed_alt' => 'Un faro' ) );

		$status = Status::compute( true );

		$this->assertSame( 'amber', $status['state'] );
		$this->assertSame( 75, $status['coverage'] );
		$this->assertSame( 4, $status['counts']['total'] );
		$this->assertSame( 3, $status['counts']['correct'] );
		$this->assertSame( 1, $status['counts']['to_review'] );
	}

	public function test_images_to_generate_are_amber() {
		$this->make_image();

		$status = Status::compute( true );
		$this->assertSame( 'amber', $status['state'] );
		$this->assertSame( 1, $status['counts']['to_generate'] );
	}

	public function test_decisions_by_people_count_as_correct() {
		$items = new Items();
		$items->upsert( $this->make_image(), array( 'status' => 'ignored', 'human_locked' => 1 ) );
		$items->upsert( $this->make_image(), array( 'status' => 'decorative', 'human_locked' => 1 ) );
		$items->upsert( $this->make_image(), array( 'status' => 'applied', 'human_locked' => 1 ) );

		$status = Status::compute( true );
		$this->assertSame( 'green', $status['state'] );
		$this->assertSame( 3, $status['counts']['correct'] );
		$this->assertSame( 1, $status['counts']['applied'] );
	}

	public function test_an_image_in_error_is_red() {
		( new Items() )->upsert( $this->make_image(), array( 'status' => 'error', 'last_error' => 'x' ) );

		$status = Status::compute( true );
		$this->assertSame( 'red', $status['state'] );
		$this->assertSame( 1, $status['counts']['errors'] );
	}

	public function test_unavailable_provider_is_red() {
		$this->fake->availability = array( 'available' => false, 'reason' => 'no_connector' );

		$status = Status::compute( true );
		$this->assertSame( 'red', $status['state'] );
		$this->assertSame( 'red', $this->check( $status, 'connector' )['state'] );
		$this->assertNotSame( '', $this->check( $status, 'connector' )['detail'] );
	}

	public function test_provider_that_stopped_answering_is_red_even_with_all_images_fine() {
		$this->make_image( 'Vista aérea del puerto de Vigo' );
		\MDIA\AI\Generator::set_health( false, 'failed', 'Invalid API key' );

		$status = Status::compute( true );
		$this->assertSame( 'red', $status['state'] );
		$this->assertStringContainsString( 'Invalid API key', $this->check( $status, 'connector' )['detail'] );
	}

	public function test_deficient_published_usage_blocks_green() {
		$id = $this->make_image( 'Vista aérea del puerto de Vigo' );
		( new \MDIA\Data\Usages() )->replace_for_post( 4321, array( array( 'attachment_id' => $id, 'alt' => '', 'issues' => 'missing', 'occurrences' => 1, 'manageable' => 1 ) ) );

		$status = Status::compute( true );
		$this->assertSame( 'amber', $this->check( $status, 'content' )['state'] );
		$this->assertSame( 'amber', $status['state'] );
	}

	public function test_unmanageable_usages_are_reported_but_do_not_block_green() {
		$id = $this->make_image( 'Vista aérea del puerto de Vigo' );
		( new \MDIA\Data\Usages() )->replace_for_post( 4321, array( array( 'attachment_id' => $id, 'alt' => '', 'issues' => '', 'occurrences' => 1, 'manageable' => 0 ) ) );

		$status = Status::compute( true );
		$this->assertSame( 'green', $status['state'] );
		$this->assertSame( 1, $status['counts']['unmanaged_usages'] );
	}

	public function test_exhausted_budget_is_amber() {
		\MDIA\Settings::update( array( 'daily_limit' => 1 ) );
		\MDIA\AI\Budget::consume();

		$status = Status::compute( true );
		$this->assertSame( 'amber', $status['state'] );
		$this->assertSame( 'amber', $this->check( $status, 'budget' )['state'] );
	}

	public function test_stalled_background_tasks_are_red() {
		as_schedule_single_action( time() - 2 * HOUR_IN_SECONDS, 'mdia_generate_item', array( 123456 ), 'mdia' );
		update_option( 'mdia_last_run', time() - 2 * DAY_IN_SECONDS );

		$status = Status::compute( true );
		$this->assertSame( 'red', $this->check( $status, 'queue' )['state'] );
		$this->assertSame( 'red', $status['state'] );
	}

	public function test_running_full_audit_is_amber() {
		\MDIA\Queue\Scheduler::start_full_audit();

		$status = Status::compute( true );
		$this->assertTrue( $status['audit']['running'] );
		$this->assertSame( 'amber', $status['state'] );
	}

	public function test_result_is_cached_and_refreshed_when_items_change() {
		$this->assertSame( 0, Status::compute( true )['counts']['total'] );
		$this->make_image( 'Vista aérea del puerto de Vigo' );

		$this->assertSame( 1, Status::compute()['counts']['total'] );
	}

	public function test_site_health_reports_the_status() {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
		$this->assertArrayHasKey( 'mdia_status', $tests['direct'] );

		( new Items() )->upsert( $this->make_image(), array( 'status' => 'error', 'last_error' => 'x' ) );
		$result = call_user_func( $tests['direct']['mdia_status']['test'] );

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'mdia_status', $result['test'] );
		$this->assertNotSame( '', $result['label'] );
	}

	public function test_dashboard_widget_is_only_for_reviewers() {
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		global $wp_meta_boxes;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$wp_meta_boxes = array();
		set_current_screen( 'dashboard' );
		do_action( 'wp_dashboard_setup' );
		$this->assertArrayNotHasKey( 'mdia_status', $wp_meta_boxes['dashboard']['normal']['core'] ?? array() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		do_action( 'wp_dashboard_setup' );
		$this->assertArrayHasKey( 'mdia_status', $wp_meta_boxes['dashboard']['normal']['core'] );

		ob_start();
		\MDIA\Admin\Dashboard_Widget::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'upload.php?page=mdia', $html );
	}
}
