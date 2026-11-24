<?php

use MDIA\AI\Budget;
use MDIA\AI\Generator;
use MDIA\Settings;

class GeneratorTest extends WP_UnitTestCase {
	use MDIA_Test_Helpers;

	private MDIA_Fake_Provider $fake;

	public function set_up() {
		parent::set_up();
		$this->fake = MDIA_Fake_Provider::install();
		Settings::update( array( 'auto_generate_new' => false, 'wizard_done' => true ) );
	}

	public function test_success_stores_a_clean_proposal_for_review() {
		$id                    = $this->make_real_image();
		$this->fake->responses = array( '"Imagen de un faro blanco sobre un acantilado."' );

		$this->assertSame( 'needs_review', Generator::run( $id ) );

		$row = $this->item( $id );
		$this->assertSame( 'Un faro blanco sobre un acantilado.', $row['proposed_alt'] );
		$this->assertSame( 'Fake', $row['provider'] );
		$this->assertSame( 'fake-1', $row['model'] );
		$this->assertSame( 0, $row['queued'] );
		$this->assertSame( 0, $row['attempts'] );
		$this->assertNotNull( $row['generated_at'] );
		$this->assertSame( 1, Budget::used_today() );
		$this->assertTrue( get_option( 'mdia_provider_health' )['ok'] );
		$this->assertSame( '', get_post_meta( $id, '_wp_attachment_image_alt', true ), 'Nothing is written before review.' );
	}

	public function test_prompt_carries_context_and_language() {
		$id = $this->make_real_image( 300, 200, 'jpeg', 'IMG_2034' );
		$this->make_post( $this->image_block( $id, 'IMG_2034' ), array( 'post_title' => 'Rutas por las Rías Baixas' ) );

		Generator::run( $id );

		$call = $this->fake->calls[0];
		$this->assertStringStartsWith( 'data:image/jpeg;base64,', $call['data_uri'] );
		$this->assertStringContainsString( '125', $call['system'] );
		$this->assertStringContainsString( '15 words', $call['system'], 'Models respect a word limit far better than a character limit.' );
		$this->assertStringContainsString( 'English', $call['system'] );
		$this->assertStringContainsString( 'Rutas por las Rías Baixas', $call['prompt'] );
		$this->assertStringContainsString( 'IMG_2034', $call['prompt'] );
		$this->assertStringContainsString( 'real-', $call['prompt'] );
	}

	public function test_three_provider_failures_end_in_error() {
		$id                    = $this->make_real_image();
		$this->fake->responses = array( new WP_Error( 'http', 'Timeout' ), new WP_Error( 'http', 'Timeout' ), new WP_Error( 'http', 'Rate limited' ) );

		$this->assertSame( 'needs_generation', Generator::run( $id ) );
		$this->assertCount( 1, $this->pending_actions( 'mdia_generate_item' ) );
		$this->assertSame( 'needs_generation', Generator::run( $id ) );
		$this->assertSame( 'error', Generator::run( $id ) );

		$row = $this->item( $id );
		$this->assertSame( 3, $row['attempts'] );
		$this->assertSame( 'Rate limited', $row['last_error'] );
		$this->assertSame( 0, $row['queued'] );
		$this->assertSame( 3, Budget::used_today() );
		$this->assertFalse( get_option( 'mdia_provider_health' )['ok'] );
	}

	public function test_unusable_response_counts_as_a_failed_attempt() {
		$id                    = $this->make_real_image();
		$this->fake->responses = array( "I'm sorry, I can't help with that" );

		$this->assertSame( 'needs_generation', Generator::run( $id ) );
		$this->assertSame( 1, $this->item( $id )['attempts'] );
		$this->assertNull( $this->item( $id )['proposed_alt'] );
	}

	public function test_missing_file_is_a_final_error_that_costs_nothing() {
		$id = $this->make_real_image();
		unlink( get_attached_file( $id ) );

		$this->assertSame( 'error', Generator::run( $id ) );
		$this->assertSame( 0, Budget::used_today() );
		$this->assertCount( 0, $this->fake->calls );
		$this->assertCount( 0, $this->pending_actions( 'mdia_generate_item' ) );
		$this->assertNotEmpty( $this->item( $id )['last_error'] );
	}

	public function test_without_budget_the_item_waits_for_tomorrow() {
		Settings::update( array( 'daily_limit' => 1 ) );
		Budget::consume();
		$id = $this->make_real_image();

		$this->assertSame( 'needs_generation', Generator::run( $id ) );
		$this->assertSame( 1, $this->item( $id )['queued'] );
		$this->assertCount( 0, $this->fake->calls );

		$this->assertGreaterThan( time(), as_next_scheduled_action( 'mdia_resume_generation', array(), 'mdia' ) );
	}

	public function test_queue_resumes_the_next_day_after_hitting_the_daily_limit() {
		Settings::update( array( 'daily_limit' => 1 ) );
		$a = $this->make_real_image();
		$b = $this->make_real_image();
		\MDIA\Queue\Scheduler::enqueue_generation( array( $a, $b ) );
		$this->run_queue();

		$this->assertSame( array( 'needs_review', 'needs_generation' ), array( $this->item( $a )['status'], $this->item( $b )['status'] ) );
		$this->assertSame( 1, $this->item( $b )['queued'] );
		$this->assertCount( 1, $this->pending_actions( 'mdia_resume_generation' ) );
		$this->assertCount( 0, $this->pending_actions( 'mdia_generate_item' ) );

		update_option( 'mdia_budget_date', '2000-01-01' ); // A new day starts.
		\MDIA\Queue\Scheduler::handle_resume_generation();
		$this->run_queue();

		$this->assertSame( 'needs_review', $this->item( $b )['status'] );
	}

	public function test_resume_only_enqueues_what_today_s_budget_allows() {
		Settings::update( array( 'daily_limit' => 2 ) );
		$ids = array( $this->make_real_image(), $this->make_real_image(), $this->make_real_image() );
		foreach ( $ids as $id ) {
			( new \MDIA\Data\Items() )->upsert( $id, array( 'queued' => 1 ) );
		}

		\MDIA\Queue\Scheduler::handle_resume_generation();

		$this->assertCount( 2, $this->pending_actions( 'mdia_generate_item' ) );
		$this->assertCount( 1, $this->pending_actions( 'mdia_resume_generation' ) );
	}

	public function test_three_consecutive_provider_errors_stop_the_queue() {
		$ids = array( $this->make_real_image(), $this->make_real_image(), $this->make_real_image(), $this->make_real_image() );
		\MDIA\Queue\Scheduler::enqueue_generation( $ids );
		$this->fake->responses = array_fill( 0, 4, new WP_Error( 'http', 'Invalid API key' ) );
		$this->run_queue();

		$this->assertCount( 3, $this->fake->calls, 'The fourth image must not spend a call.' );
		$this->assertCount( 0, $this->pending_actions( 'mdia_generate_item' ) );
		$health = get_option( 'mdia_provider_health' );
		$this->assertFalse( $health['ok'] );
		$this->assertSame( 'Invalid API key', $health['message'] );
		$this->assertSame( 0, $this->item( $ids[3] )['queued'] );
		$this->assertSame( 'needs_generation', $this->item( $ids[0] )['status'] );
	}

	public function test_a_success_resets_the_consecutive_error_count() {
		$ids                   = array( $this->make_real_image(), $this->make_real_image(), $this->make_real_image(), $this->make_real_image() );
		$this->fake->responses = array( new WP_Error( 'http', 'x' ), new WP_Error( 'http', 'x' ), 'Un faro blanco sobre un acantilado', new WP_Error( 'http', 'x' ) );
		foreach ( $ids as $id ) {
			Generator::run( $id );
		}

		$this->assertTrue( get_option( 'mdia_provider_health' )['ok'] );
	}

	public function test_a_decision_taken_while_the_request_is_in_flight_is_not_overwritten() {
		$id                      = $this->make_real_image();
		$editor                  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->fake->on_describe = static fn() => \MDIA\Review\Actions::ignore( $id, $editor );

		$this->assertSame( 'ignored', Generator::run( $id ) );

		$row = $this->item( $id );
		$this->assertSame( 'ignored', $row['status'] );
		$this->assertNull( $row['proposed_alt'] );
		$this->assertSame( 1, $row['human_locked'] );
	}

	public function test_budget_taken_by_a_concurrent_run_stops_this_one_before_calling_the_provider() {
		Settings::update( array( 'daily_limit' => 1 ) );
		$id = $this->make_real_image();
		// Another runner takes the last call after this run checked the budget (the filter fires while encoding).
		add_filter(
			'mdia_max_source_bytes',
			static function ( $bytes ) {
				Budget::consume();
				return $bytes;
			}
		);

		$this->assertSame( 'needs_generation', Generator::run( $id ) );

		$this->assertCount( 0, $this->fake->calls );
		$this->assertSame( 1, Budget::used_today() );
		$this->assertSame( 1, $this->item( $id )['queued'] );
		$this->assertGreaterThan( time(), as_next_scheduled_action( 'mdia_resume_generation', array(), 'mdia' ) );
	}

	public function test_unavailable_provider_pauses_the_queue_without_spending() {
		$a = $this->make_real_image();
		$b = $this->make_real_image();
		\MDIA\Queue\Scheduler::enqueue_generation( array( $a, $b ) );
		$this->fake->availability = array( 'available' => false, 'reason' => 'no_connector' );

		$this->assertSame( 'needs_generation', Generator::run( $a ) );

		$this->assertSame( 0, Budget::used_today() );
		$this->assertCount( 0, $this->fake->calls );
		$this->assertCount( 0, $this->pending_actions( 'mdia_generate_item' ) );
		$this->assertSame( 0, $this->item( $b )['queued'] );
		$health = get_option( 'mdia_provider_health' );
		$this->assertFalse( $health['ok'] );
		$this->assertSame( 'no_connector', $health['reason'] );
	}

	public function test_does_nothing_for_images_that_do_not_need_generation() {
		$id = $this->make_real_image( 300, 200, 'jpeg', 'Vista aérea del puerto de Vigo' );

		$this->assertSame( 'ok', Generator::run( $id ) );
		$this->assertSame( '', Generator::run( 999999 ) );
		$this->assertCount( 0, $this->fake->calls );
	}

	public function test_retry_after_error_starts_a_fresh_attempt_count() {
		$id = $this->make_real_image();
		( new \MDIA\Data\Items() )->upsert( $id, array( 'status' => 'error', 'attempts' => 3, 'last_error' => 'x' ) );

		$this->assertSame( 'needs_review', Generator::run( $id ) );
		$this->assertNull( $this->item( $id )['last_error'] );
	}

	public function test_new_upload_is_queued_when_auto_generate_is_on() {
		Settings::update( array( 'auto_generate_new' => true, 'wizard_done' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$id = $this->make_image();

		$this->assertTrue( as_has_scheduled_action( 'mdia_generate_item', array( $id ), 'mdia' ) );
		$this->assertSame( 1, $this->item( $id )['queued'] );
	}

	public function test_new_upload_is_not_sent_to_the_provider_before_setup_is_finished() {
		Settings::update( array( 'auto_generate_new' => true, 'wizard_done' => false ) );
		$id = $this->make_image();

		$this->assertFalse( as_has_scheduled_action( 'mdia_generate_item', array( $id ), 'mdia' ) );
		$this->assertSame( 0, $this->item( $id )['queued'] );
	}

	public function test_new_upload_is_not_queued_when_auto_generate_is_off() {
		$id = $this->make_image();

		$this->assertFalse( as_has_scheduled_action( 'mdia_generate_item', array( $id ), 'mdia' ) );
	}

	public function test_queue_handler_runs_the_generator() {
		$id = $this->make_real_image();
		\MDIA\Queue\Scheduler::enqueue_generation( array( $id ) );
		$this->run_queue();

		$this->assertSame( 'needs_review', $this->item( $id )['status'] );
	}

	private const LONG = 'Un círculo amarillo sobre un cielo gris azulado, con una franja de suelo verde oscuro en la parte inferior y el texto "Demo 3" en la esquina superior izquierda.';

	public function test_too_long_answer_is_rewritten_shorter_instead_of_cut() {
		$id                    = $this->make_real_image();
		$this->fake->responses = array( self::LONG );
		$this->fake->rewrites  = array( 'Círculo amarillo sobre cielo gris y suelo verde, con el texto "Demo 3" arriba a la izquierda.' );

		$this->assertSame( 'needs_review', Generator::run( $id ) );

		$this->assertSame( 'Círculo amarillo sobre cielo gris y suelo verde, con el texto "Demo 3" arriba a la izquierda.', $this->item( $id )['proposed_alt'] );
		$this->assertCount( 1, $this->fake->rewrite_calls );
		$this->assertStringContainsString( self::LONG, $this->fake->rewrite_calls[0]['prompt'] );
		$this->assertStringContainsString( '12 words', $this->fake->rewrite_calls[0]['system'] );
		$this->assertSame( 2, Budget::used_today(), 'The rewrite is a paid request too.' );
	}

	public function test_answer_within_the_limit_is_not_rewritten() {
		$id                    = $this->make_real_image();
		$this->fake->responses = array( 'Un faro blanco sobre un acantilado al atardecer.' );

		Generator::run( $id );

		$this->assertCount( 0, $this->fake->rewrite_calls );
		$this->assertSame( 1, Budget::used_today() );
	}

	public function test_rewrite_that_is_still_too_long_falls_back_to_a_clean_cut() {
		$id                    = $this->make_real_image();
		$this->fake->responses = array( self::LONG );
		$this->fake->rewrites  = array( self::LONG );

		Generator::run( $id );

		$proposal = $this->item( $id )['proposed_alt'];
		$this->assertLessThanOrEqual( 125, mb_strlen( $proposal ) );
		$this->assertStringEndsWith( 'en la parte inferior', $proposal );
		$this->assertCount( 1, $this->fake->rewrite_calls, 'Only one rewrite is attempted.' );
	}

	public function test_failed_or_unusable_rewrite_falls_back_to_a_clean_cut() {
		$id                    = $this->make_real_image();
		$this->fake->responses = array( self::LONG );
		$this->fake->rewrites  = array( new WP_Error( 'http', 'Timeout' ) );

		$this->assertSame( 'needs_review', Generator::run( $id ) );
		$this->assertStringEndsWith( 'en la parte inferior', $this->item( $id )['proposed_alt'] );
		$this->assertTrue( get_option( 'mdia_provider_health' )['ok'] );
	}

	public function test_without_budget_for_a_rewrite_the_answer_is_cut() {
		Settings::update( array( 'daily_limit' => 1 ) );
		$id                    = $this->make_real_image();
		$this->fake->responses = array( self::LONG );

		Generator::run( $id );

		$this->assertCount( 0, $this->fake->rewrite_calls );
		$this->assertSame( 1, Budget::used_today() );
		$this->assertLessThanOrEqual( 125, mb_strlen( $this->item( $id )['proposed_alt'] ) );
	}

	public function test_nothing_is_generated_or_queued_before_setup_is_finished() {
		Settings::update( array( 'wizard_done' => false ) );
		$id = $this->make_real_image();

		$this->assertSame( 0, \MDIA\Queue\Scheduler::enqueue_generation( array( $id ) ) );
		$this->assertSame( 'needs_generation', Generator::run( $id ) );
		$this->assertCount( 0, $this->fake->calls );
		$this->assertSame( 0, Budget::used_today() );
	}

	public function test_upload_by_someone_who_cannot_review_does_not_trigger_paid_calls() {
		Settings::update( array( 'auto_generate_new' => true, 'wizard_done' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$id = $this->make_image();

		$this->assertFalse( as_has_scheduled_action( 'mdia_generate_item', array( $id ), 'mdia' ) );
		$this->assertSame( 'needs_generation', $this->item( $id )['status'] );
	}
}
