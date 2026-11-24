<?php

use MDIA\Data\Changes;
use MDIA\Data\Items;
use MDIA\Review\Actions;

class ReviewFlowTest extends WP_UnitTestCase {
	use MDIA_Test_Helpers;

	private int $editor;

	public function set_up() {
		parent::set_up();
		$this->images_in_use = true;
		\MDIA\Settings::update( array( 'auto_generate_new' => false, 'wizard_done' => true ) );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	private function alt( int $id ): string {
		return (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	}

	private function content_alts( int $post_id, int $attachment_id ): array {
		clean_post_cache( $post_id );
		return ( new \MDIA\Content\Alt_Rewriter() )->find( get_post( $post_id )->post_content )[ $attachment_id ]['alts'] ?? array();
	}

	private function proposed( int $id, string $text = 'Un faro blanco sobre un acantilado' ): void {
		( new Items() )->upsert( $id, array( 'status' => 'needs_review', 'proposed_alt' => $text ) );
	}

	public function test_approve_updates_library_and_published_content() {
		$id   = $this->make_image();
		$post = $this->make_post( '<p>Intro</p>' . $this->image_block( $id ) );
		$this->proposed( $id );
		$revisions = count( wp_get_post_revisions( $post ) );

		$this->assertTrue( Actions::approve( $id, 'Un faro blanco', $this->editor ) );
		$this->assertSame( 'applying', $this->item( $id )['status'] );
		$this->assertSame( '', $this->alt( $id ), 'Nothing is written until the queue runs.' );
		$this->run_queue();

		$this->assertSame( 'Un faro blanco', $this->alt( $id ) );
		$this->assertSame( array( 'Un faro blanco' ), $this->content_alts( $post, $id ) );
		$this->assertSame( $revisions + 1, count( wp_get_post_revisions( $post ) ) );

		$row = $this->item( $id );
		$this->assertSame( 'applied', $row['status'] );
		$this->assertSame( 1, $row['human_locked'] );
		$this->assertSame( $this->editor, $row['reviewed_by'] );
		$this->assertSame( '', $row['issues'] );
		$this->assertNotNull( $row['applied_at'] );

		$changes = ( new Changes() )->latest_batch( $id );
		$this->assertCount( 2, $changes );
		$this->assertSame( array( 0, $post ), array_map( 'intval', wp_list_pluck( $changes, 'post_id' ) ) );
		$this->assertSame( (string) $this->editor, $changes[0]['user_id'] );
		$this->assertCount( 1, array_unique( wp_list_pluck( $changes, 'batch' ) ) );
	}

	public function test_applied_image_survives_a_later_audit() {
		$id = $this->make_image();
		$this->make_post( $this->image_block( $id ) );
		Actions::approve( $id, 'Un faro blanco', $this->editor );
		$this->run_queue();

		$this->assertSame( 'applied', ( new \MDIA\Audit\Auditor() )->audit( $id ) );
		$this->assertSame( 0, ( new \MDIA\Data\Usages() )->count_deficient() );
	}

	public function test_good_page_specific_alt_is_respected() {
		$id   = $this->make_image();
		$post = $this->make_post( $this->image_block( $id, 'Retrato de Ana en su taller' ) );

		Actions::approve( $id, 'Una mujer trabaja la madera', $this->editor );
		$this->run_queue();

		$this->assertSame( array( 'Retrato de Ana en su taller' ), $this->content_alts( $post, $id ) );
		$this->assertCount( 1, ( new Changes() )->latest_batch( $id ) );
	}

	public function test_content_alt_equal_to_old_library_alt_is_replaced() {
		$id   = $this->make_image( 'IMG_2034' );
		$post = $this->make_post( $this->image_block( $id, 'IMG_2034' ) );

		Actions::approve( $id, 'Un faro blanco', $this->editor );
		$this->run_queue();

		$this->assertSame( array( 'Un faro blanco' ), $this->content_alts( $post, $id ) );
	}

	public function test_old_library_alt_copied_into_content_is_replaced_even_if_it_passes_the_rules() {
		$id   = $this->make_image( 'Equipo reunido en la oficina' );
		$post = $this->make_post( $this->image_block( $id, 'Equipo reunido en la oficina' ) );

		Actions::approve( $id, 'Cinco personas alrededor de una mesa', $this->editor );
		$this->run_queue();

		$this->assertSame( array( 'Cinco personas alrededor de una mesa' ), $this->content_alts( $post, $id ) );
	}

	public function test_drafts_and_scheduled_posts_are_updated_but_trash_is_not() {
		$id      = $this->make_image();
		$draft   = $this->make_post( $this->image_block( $id ), array( 'post_status' => 'draft' ) );
		$future  = $this->make_post( $this->image_block( $id ), array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) ) );
		$trashed = $this->make_post( $this->image_block( $id ) );
		wp_trash_post( $trashed );

		Actions::approve( $id, 'Un faro blanco', $this->editor );
		$this->run_queue();

		$this->assertSame( array( 'Un faro blanco' ), $this->content_alts( $draft, $id ) );
		$this->assertSame( array( 'Un faro blanco' ), $this->content_alts( $future, $id ) );
		$this->assertSame( array( '' ), $this->content_alts( $trashed, $id ) );
		$this->assertSame( 'future', get_post_status( $future ) );
	}

	public function test_apply_does_not_strip_markup_from_content() {
		$id      = $this->make_image();
		$content = '<iframe src="https://example.org/embed"></iframe><script>var a = 1;</script>' . $this->image_block( $id );
		$post    = $this->make_post( $content );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post ) );
		clean_post_cache( $post );

		Actions::approve( $id, 'Un faro blanco', $this->editor );
		$this->run_queue();

		clean_post_cache( $post );
		$this->assertSame( str_replace( 'alt=""', 'alt="Un faro blanco"', $content ), get_post( $post )->post_content );
	}

	public function test_mark_decorative_empties_library_and_deficient_content() {
		$id   = $this->make_image( 'IMG_2034' );
		$post = $this->make_post( $this->image_block( $id, 'IMG_2034' ) . $this->image_block( $id, 'Retrato de Ana en su taller' ) );

		$this->assertTrue( Actions::mark_decorative( $id, $this->editor ) );
		$this->run_queue();

		$this->assertSame( '', $this->alt( $id ) );
		$this->assertSame( array( '', 'Retrato de Ana en su taller' ), $this->content_alts( $post, $id ) );
		$this->assertSame( 'decorative', $this->item( $id )['status'] );
		$this->assertSame( 'decorative', ( new \MDIA\Audit\Auditor() )->audit( $id ) );
	}

	public function test_ignore_changes_nothing_and_sticks() {
		$id   = $this->make_image( 'IMG_2034' );
		$post = $this->make_post( $this->image_block( $id, 'IMG_2034' ) );

		$this->assertTrue( Actions::ignore( $id, $this->editor ) );
		$this->run_queue();

		$this->assertSame( 'IMG_2034', $this->alt( $id ) );
		$this->assertSame( array( 'IMG_2034' ), $this->content_alts( $post, $id ) );
		$this->assertSame( 'ignored', ( new \MDIA\Audit\Auditor() )->audit( $id ) );
	}

	public function test_undo_restores_library_and_content() {
		$id   = $this->make_image( 'IMG_2034' );
		$post = $this->make_post( $this->image_block( $id, 'IMG_2034' ) );
		Actions::approve( $id, 'Un faro blanco', $this->editor );
		$this->run_queue();

		$this->assertTrue( Actions::undo( $id, $this->editor ) );

		$this->assertSame( 'IMG_2034', $this->alt( $id ) );
		$this->assertSame( array( 'IMG_2034' ), $this->content_alts( $post, $id ) );
		$this->assertSame( 'needs_generation', $this->item( $id )['status'] );
		$this->assertSame( 0, $this->item( $id )['human_locked'] );
		$this->assertWPError( Actions::undo( $id, $this->editor ), 'There is nothing left to undo.' );
	}

	public function test_post_edited_between_approval_and_apply_keeps_the_edit() {
		$id   = $this->make_image();
		$post = $this->make_post( $this->image_block( $id ) );
		Actions::approve( $id, 'Un faro blanco', $this->editor );

		wp_update_post( array( 'ID' => $post, 'post_content' => wp_slash( $this->image_block( $id ) . '<p>Párrafo nuevo</p>' ) ) );
		$this->run_queue();

		clean_post_cache( $post );
		$this->assertStringContainsString( '<p>Párrafo nuevo</p>', get_post( $post )->post_content );
		$this->assertSame( array( 'Un faro blanco' ), $this->content_alts( $post, $id ) );
	}

	public function test_undo_does_not_touch_an_alt_edited_by_hand_afterwards() {
		$id   = $this->make_image( 'IMG_2034' );
		$post = $this->make_post( $this->image_block( $id, 'IMG_2034' ) );
		Actions::approve( $id, 'Un faro blanco', $this->editor );
		$this->run_queue();

		wp_update_post( array( 'ID' => $post, 'post_content' => wp_slash( $this->image_block( $id, 'Texto del editor de la página' ) ) ) );
		Actions::undo( $id, $this->editor );

		$this->assertSame( 'IMG_2034', $this->alt( $id ) );
		$this->assertSame( array( 'Texto del editor de la página' ), $this->content_alts( $post, $id ) );
	}

	public function test_locked_post_is_left_for_later() {
		$id     = $this->make_image();
		$post   = $this->make_post( $this->image_block( $id ) );
		$free   = $this->make_post( $this->image_block( $id ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		wp_set_post_lock( $post );
		wp_set_current_user( 0 );

		Actions::approve( $id, 'Un faro blanco', $this->editor );
		$this->run_queue();

		$this->assertSame( 'Un faro blanco', $this->alt( $id ) );
		$this->assertSame( array( '' ), $this->content_alts( $post, $id ) );
		$this->assertSame( array( 'Un faro blanco' ), $this->content_alts( $free, $id ) );
		$this->assertSame( 'applying', $this->item( $id )['status'] );
		$this->assertGreaterThan( time(), as_next_scheduled_action( 'mdia_apply_item' ) );

		delete_post_meta( $post, '_edit_lock' );
		\MDIA\Review\Applier::apply( $id, ( new Changes() )->latest_batch( $id )[0]['batch'], 1, array( $post ) );

		$this->assertSame( array( 'Un faro blanco' ), $this->content_alts( $post, $id ) );
		$this->assertSame( 'applied', $this->item( $id )['status'] );
	}

	public function test_users_without_the_capability_cannot_review() {
		$id         = $this->make_image();
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		foreach ( array( 'approve', 'mark_decorative', 'ignore', 'regenerate', 'undo' ) as $action ) {
			$result = 'approve' === $action ? Actions::approve( $id, 'Un faro', $subscriber ) : Actions::$action( $id, $subscriber );
			$this->assertWPError( $result, $action );
			$this->assertSame( 'mdia_forbidden', $result->get_error_code() );
		}
		$this->assertSame( 'needs_generation', $this->item( $id )['status'] );
	}

	public function test_approve_rejects_empty_text_and_unknown_images() {
		$id = $this->make_image();

		$this->assertSame( 'mdia_empty', Actions::approve( $id, '   ', $this->editor )->get_error_code() );
		$this->assertSame( 'mdia_not_found', Actions::approve( 999999, 'Un faro', $this->editor )->get_error_code() );
		$this->assertSame( 'needs_generation', $this->item( $id )['status'] );
	}

	public function test_approve_strips_markup_from_the_text() {
		$id = $this->make_image();
		Actions::approve( $id, "  <b>Un faro</b>\nblanco  ", $this->editor );
		$this->run_queue();

		$this->assertSame( 'Un faro blanco', $this->alt( $id ) );
	}

	public function test_an_image_being_applied_cannot_be_acted_on_again() {
		$id = $this->make_image();
		Actions::approve( $id, 'Un faro blanco', $this->editor );

		$this->assertSame( 'mdia_busy', Actions::approve( $id, 'Otro texto', $this->editor )->get_error_code() );
		$this->assertSame( 'mdia_busy', Actions::undo( $id, $this->editor )->get_error_code() );
	}

	public function test_approve_many_applies_each_proposal() {
		$ids = array( $this->make_image(), $this->make_image(), $this->make_image() );
		foreach ( $ids as $n => $id ) {
			$this->proposed( $id, "Propuesta número $n" );
		}
		$without = $this->make_image();

		$entries = array(
			array( 'id' => $ids[0] ),
			array( 'id' => $ids[1], 'text' => 'Texto corregido por la revisora' ),
			array( 'id' => $ids[2] ),
			array( 'id' => $without ),
		);
		$result  = Actions::approve_many( $entries, $this->editor );
		$this->run_queue();

		$this->assertSame( array( 'done' => 3, 'failed' => 1 ), $result );
		$this->assertSame( 'Texto corregido por la revisora', $this->alt( $ids[1] ), 'Inline edits must be what gets published.' );
		$this->assertSame( 'Propuesta número 2', $this->alt( $ids[2] ) );
		$this->assertSame( '', $this->alt( $without ) );
	}

	public function test_regenerate_clears_the_proposal_and_queues_generation() {
		$id = $this->make_image();
		$this->proposed( $id );

		$this->assertTrue( Actions::regenerate( $id, $this->editor ) );

		$row = $this->item( $id );
		$this->assertSame( 'needs_generation', $row['status'] );
		$this->assertNull( $row['proposed_alt'] );
		$this->assertSame( 1, $row['queued'] );
		$this->assertTrue( as_has_scheduled_action( 'mdia_generate_item', array( $id ), 'mdia' ) );
	}

	public function test_pages_built_with_a_page_builder_are_not_rewritten() {
		$id   = $this->make_image();
		$post = $this->make_post( $this->image_block( $id ) );
		update_post_meta( $post, '_elementor_edit_mode', 'builder' );

		Actions::approve( $id, 'Un faro blanco', $this->editor );
		$this->run_queue();

		$this->assertSame( array( '' ), $this->content_alts( $post, $id ) );
		$this->assertSame( 'applied', $this->item( $id )['status'] );
	}

	public function test_good_library_text_is_copied_to_the_page_without_being_replaced() {
		$fake = MDIA_Fake_Provider::install();
		$id   = $this->make_image( 'Vista aérea del puerto de Vigo' );
		$post = $this->make_post( $this->image_block( $id ) );

		$this->assertTrue( Actions::approve( $id, (string) $this->item( $id )['proposed_alt'], $this->editor ) );
		$this->run_queue();

		$this->assertSame( 'Vista aérea del puerto de Vigo', $this->alt( $id ) );
		$this->assertSame( array( 'Vista aérea del puerto de Vigo' ), $this->content_alts( $post, $id ) );
		$this->assertSame( 'applied', $this->item( $id )['status'] );
		$this->assertCount( 0, $fake->calls );
	}

	public function test_image_used_in_many_posts_is_applied_in_several_actions() {
		$id    = $this->make_image();
		$posts = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$posts[] = $this->make_post( $this->image_block( $id ) );
		}

		Actions::approve( $id, 'Un faro blanco', $this->editor );
		$this->run_queue();

		$actions = as_get_scheduled_actions( array( 'hook' => 'mdia_apply_item', 'status' => \ActionScheduler_Store::STATUS_COMPLETE, 'per_page' => 50 ), 'ids' );
		$this->assertCount( 3, $actions, 'One action must not rewrite every post: 60 posts in chunks of 25.' );

		$this->assertSame( 'applied', $this->item( $id )['status'] );
		$this->assertSame( array( 'Un faro blanco' ), $this->content_alts( $posts[0], $id ) );
		$this->assertSame( array( 'Un faro blanco' ), $this->content_alts( $posts[59], $id ) );
		$this->assertSame( 0, ( new \MDIA\Data\Usages() )->count_deficient() );
	}

	public function test_watchdog_recovers_an_image_stuck_in_applying() {
		$id   = $this->make_image();
		$post = $this->make_post( $this->image_block( $id ) );
		Actions::approve( $id, 'Un faro blanco', $this->editor );
		as_unschedule_all_actions( 'mdia_apply_item' ); // The action died.

		\MDIA\Queue\Watchdog::run();
		$this->run_queue();

		$this->assertSame( 'applied', $this->item( $id )['status'] );
		$this->assertSame( 'Un faro blanco', $this->alt( $id ) );
		$this->assertSame( array( 'Un faro blanco' ), $this->content_alts( $post, $id ) );
	}

	public function test_watchdog_does_not_touch_an_apply_that_is_still_scheduled() {
		$id = $this->make_image();
		Actions::approve( $id, 'Un faro blanco', $this->editor );

		\MDIA\Queue\Watchdog::run();

		$this->assertCount( 1, $this->pending_actions( 'mdia_apply_item' ) );
	}

	public function test_watchdog_finishes_pages_that_were_being_edited_when_the_text_was_applied() {
		$id   = $this->make_image( 'IMG_2034' );
		$post = $this->make_post( $this->image_block( $id, 'IMG_2034' ) );
		wp_set_current_user( $this->editor );
		wp_set_post_lock( $post );
		wp_set_current_user( 0 );
		Actions::approve( $id, 'Un faro blanco', $this->editor );
		$this->run_queue();
		$batch = ( new Changes() )->latest_batch( $id )[0]['batch'];
		\MDIA\Review\Applier::apply( $id, $batch, 99, array( $post ) ); // Retries exhausted, post still locked.
		$this->assertSame( 'applied', $this->item( $id )['status'] );
		$this->assertSame( array( 'IMG_2034' ), $this->content_alts( $post, $id ) );

		delete_post_meta( $post, '_edit_lock' );
		\MDIA\Queue\Watchdog::run();
		$this->run_queue();

		$this->assertSame( array( 'Un faro blanco' ), $this->content_alts( $post, $id ) );
		$this->assertSame( 'applied', $this->item( $id )['status'] );
		$this->assertSame( 'Un faro blanco', $this->alt( $id ) );
	}

	public function test_watchdog_requeues_generations_whose_action_was_lost() {
		$id = $this->make_image();
		( new Items() )->upsert( $id, array( 'queued' => 1 ) );

		\MDIA\Queue\Watchdog::run();

		$this->assertTrue( as_has_scheduled_action( 'mdia_generate_item', array( $id ), 'mdia' ) );
	}

	public function test_regenerate_cannot_reset_a_decision_someone_already_took() {
		$applied = $this->make_image();
		Actions::approve( $applied, 'Un faro blanco', $this->editor );
		$this->run_queue();
		$decorative = $this->make_image();
		Actions::mark_decorative( $decorative, $this->editor );
		$this->run_queue();

		foreach ( array( $applied, $decorative ) as $id ) {
			$result = Actions::regenerate( $id, $this->editor );
			$this->assertWPError( $result );
			$this->assertSame( 'mdia_invalid_status', $result->get_error_code() );
			$this->assertSame( 1, $this->item( $id )['human_locked'] );
		}
		$this->assertSame( 'Un faro blanco', $this->alt( $applied ) );
	}

	public function test_approval_does_not_write_to_content_the_reviewer_cannot_edit() {
		$id       = $this->make_image();
		$post     = $this->make_post( $this->image_block( $id ) );
		$template = $this->make_post( $this->image_block( $id ), array( 'post_type' => 'wp_template_part' ) );
		$this->assertFalse( user_can( $this->editor, 'edit_post', $template ), 'Editors cannot edit templates.' );

		Actions::approve( $id, 'Un faro blanco', $this->editor );
		$this->run_queue();

		$this->assertSame( array( 'Un faro blanco' ), $this->content_alts( $post, $id ) );
		$this->assertSame( array( '' ), $this->content_alts( $template, $id ) );
		$this->assertSame( 'applied', $this->item( $id )['status'] );
	}

	public function test_administrator_approval_reaches_templates() {
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$id       = $this->make_image();
		$template = $this->make_post( $this->image_block( $id ), array( 'post_type' => 'wp_template_part' ) );

		Actions::approve( $id, 'Un faro blanco', $admin );
		$this->run_queue();

		$this->assertSame( array( 'Un faro blanco' ), $this->content_alts( $template, $id ) );
	}

	public function test_content_filters_are_back_after_saving_as_the_same_user() {
		$id   = $this->make_image();
		$post = $this->make_post( $this->image_block( $id ) );
		$user = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user );
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'Authors save through KSES.' );

		\MDIA\Review\Applier::save_content( $post, $this->image_block( $id, 'x' ), $user );

		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
		$this->assertSame( $user, get_current_user_id() );
	}

	public function test_content_filters_stay_off_for_users_who_never_had_them() {
		$id    = $this->make_image();
		$post  = $this->make_post( $this->image_block( $id ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		\MDIA\Review\Applier::save_content( $post, $this->image_block( $id, 'x' ), $admin );

		$this->assertFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
	}
}
