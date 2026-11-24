<?php

use MDIA\Audit\Auditor;
use MDIA\Data\Items;

class AuditorTest extends WP_UnitTestCase {
	use MDIA_Test_Helpers;

	public function set_up() {
		parent::set_up();
		// Uploads would otherwise queue their own generation and blur what each test checks.
		\MDIA\Settings::update( array( 'auto_generate_new' => false, 'wizard_done' => true ) );
	}

	public function test_image_without_alt_shown_on_a_page_needs_generation() {
		$id = $this->make_image();
		$this->show_on_a_page( $id );

		$this->assertSame( 'needs_generation', $this->item( $id )['status'] );
		$this->assertSame( 'missing', $this->item( $id )['issues'] );
		$this->assertSame( 0, $this->item( $id )['queued'] );
	}

	public function test_an_image_no_page_uses_is_unused_until_one_does() {
		$a = $this->make_image();
		$this->assertSame( 'unused', $this->item( $a )['status'] );

		$this->make_post( $this->theme_block( 'library', $a ) );
		$this->assertSame( 'needs_generation', $this->item( $a )['status'] );
	}

	public function test_the_site_logo_and_icon_are_in_use_even_if_no_page_shows_them() {
		$logo = $this->make_image();
		$icon = $this->make_image();
		set_theme_mod( 'custom_logo', $logo );
		update_option( 'site_icon', $icon );

		$auditor = new \MDIA\Audit\Auditor();
		$this->assertSame( 'needs_generation', $auditor->audit( $logo ) );
		$this->assertSame( 'needs_generation', $auditor->audit( $icon ) );

		remove_theme_mod( 'custom_logo' );
		delete_option( 'site_icon' );
	}

	public function test_an_unused_image_with_good_alt_is_simply_ok() {
		$a = $this->make_image( 'Vista aérea del puerto de Vigo' );
		$this->assertSame( 'ok', $this->item( $a )['status'] );
	}

	public function test_an_image_the_theme_always_shows_without_alt_is_proposed_as_decorative_without_ai() {
		$a = $this->make_image();
		$this->make_post( $this->theme_block( 'fixed', $a ) );

		$item = $this->item( $a );
		$this->assertSame( 'needs_review', $item['status'] );
		$this->assertSame( 'theme', $item['provider'] );
		$this->assertSame( '', (string) $item['proposed_alt'] );
		$this->assertSame( array(), $this->pending_actions( 'mdia_generate_item' ) );
	}

	public function test_the_decorative_proposal_is_dropped_when_another_page_shows_the_library_text() {
		$a = $this->make_image();
		$this->make_post( $this->theme_block( 'fixed', $a ) );
		$this->make_post( $this->theme_block( 'library', $a ) );

		$item = $this->item( $a );
		$this->assertSame( 'needs_generation', $item['status'] );
		$this->assertNull( $item['provider'] );
	}

	public function test_a_requested_proposal_for_an_unused_image_is_not_undone_by_the_next_audit() {
		\MDIA\Settings::update( array( 'wizard_done' => true ) );
		$a = $this->make_image();

		$this->assertSame( 1, \MDIA\Queue\Scheduler::enqueue_generation( array( $a ), true ) );
		( new \MDIA\Audit\Auditor() )->audit( $a );

		$this->assertSame( 'needs_generation', $this->item( $a )['status'] );
		$this->assertSame( 1, (int) $this->item( $a )['queued'] );
	}

	public function test_bulk_generation_leaves_unused_images_alone() {
		\MDIA\Settings::update( array( 'wizard_done' => true ) );
		$a = $this->make_image();

		$this->assertSame( 0, \MDIA\Queue\Scheduler::enqueue_generation( array( $a ) ) );
		$this->assertSame( 'unused', $this->item( $a )['status'] );
	}

	public function test_good_library_alt_with_empty_alt_in_published_post_is_flagged() {
		$id = $this->make_image( 'Vista aérea del puerto de Vigo' );
		$this->assertSame( 'ok', $this->item( $id )['status'] );

		$this->make_post( $this->image_block( $id ) );

		// The library text is good: it is proposed for that page, without AI and without replacing it.
		$row = $this->item( $id );
		$this->assertSame( 'needs_review', $row['status'] );
		$this->assertSame( 'missing', $row['issues'] );
		$this->assertSame( 'Vista aérea del puerto de Vigo', $row['proposed_alt'] );
		$this->assertSame( 'library', $row['provider'] );
		$this->assertSame( 0, $row['queued'] );
	}

	public function test_library_text_proposal_follows_later_edits_of_the_library_text() {
		$id = $this->make_image( 'Vista aérea del puerto de Vigo' );
		$this->make_post( $this->image_block( $id ) );

		update_post_meta( $id, '_wp_attachment_image_alt', 'El puerto de Vigo desde el aire' );
		$this->assertSame( 'El puerto de Vigo desde el aire', $this->item( $id )['proposed_alt'] );

		update_post_meta( $id, '_wp_attachment_image_alt', 'foto' );
		$this->assertSame( 'needs_generation', $this->item( $id )['status'] );
		$this->assertNull( $this->item( $id )['proposed_alt'] );
	}

	public function test_good_alt_everywhere_is_ok() {
		$id = $this->make_image( 'Vista aérea del puerto de Vigo' );
		$this->make_post( $this->image_block( $id, 'El puerto de Vigo al amanecer' ) );

		$this->assertSame( 'ok', $this->item( $id )['status'] );
		$this->assertSame( '', $this->item( $id )['issues'] );
	}

	public function test_human_decision_survives_re_audit_even_if_a_rule_fails() {
		$long = str_repeat( 'palabra ', 20 ); // 160 chars.
		$id   = $this->make_image();
		Auditor::$suspended = true;
		update_post_meta( $id, '_wp_attachment_image_alt', trim( $long ) );
		Auditor::$suspended = false;
		( new Items() )->upsert( $id, array( 'status' => 'applied', 'human_locked' => 1, 'library_alt' => trim( $long ) ) );

		$this->assertSame( 'applied', ( new Auditor() )->audit( $id ) );
		$this->assertSame( 1, $this->item( $id )['human_locked'] );
	}

	public function test_manual_edit_outside_plugin_unlocks_and_re_flags() {
		$id = $this->make_image( 'Vista aérea del puerto de Vigo' );
		( new Items() )->upsert( $id, array( 'status' => 'applied', 'human_locked' => 1 ) );
		$this->show_on_a_page( $id );

		update_post_meta( $id, '_wp_attachment_image_alt', 'foto' );

		$this->assertSame( 'needs_generation', $this->item( $id )['status'] );
		$this->assertSame( 0, $this->item( $id )['human_locked'] );
		$this->assertSame( 'generic', $this->item( $id )['issues'] );
	}

	public function test_ignored_image_stays_ignored() {
		$id = $this->make_image();
		( new Items() )->upsert( $id, array( 'status' => 'ignored', 'human_locked' => 1 ) );

		$this->assertSame( 'ignored', ( new Auditor() )->audit( $id ) );
	}

	public function test_pending_review_is_kept_while_still_deficient() {
		$id = $this->make_image();
		( new Items() )->upsert( $id, array( 'status' => 'needs_review', 'proposed_alt' => 'Un faro' ) );

		$this->assertSame( 'needs_review', ( new Auditor() )->audit( $id ) );
		$this->assertSame( 'Un faro', $this->item( $id )['proposed_alt'] );
	}

	public function test_fixing_the_alt_by_hand_resolves_a_pending_review() {
		$id = $this->make_image();
		( new Items() )->upsert( $id, array( 'status' => 'needs_review', 'proposed_alt' => 'Un faro' ) );

		update_post_meta( $id, '_wp_attachment_image_alt', 'Faro de Cabo Silleiro al atardecer' );

		$this->assertSame( 'ok', $this->item( $id )['status'] );
	}

	public function test_unsupported_files_get_no_row() {
		$pdf = $this->make_image( '', '2026/11/doc.pdf', 'application/pdf' );
		$svg = $this->make_image( '', '2026/11/logo.svg', 'image/svg+xml' );

		$this->assertNull( $this->item( $pdf ) );
		$this->assertNull( $this->item( $svg ) );
		$this->assertSame( '', ( new Auditor() )->audit( 999999 ) );
	}

	public function test_deleting_an_attachment_removes_its_row() {
		$id = $this->make_image();
		wp_delete_attachment( $id, true );

		$this->assertNull( $this->item( $id ) );
	}

	public function test_unmanageable_usage_does_not_change_an_ok_image() {
		$id   = $this->make_image( 'Vista aérea del puerto de Vigo' );
		$post = $this->make_post( '<p>Hola</p>' );
		update_post_meta( $post, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post, '_elementor_data', wp_slash( '[{"settings":{"image":{"url":"http://example.org/a.jpg","id":' . $id . '}}}]' ) );

		$this->assertCount( 1, $this->usages( $id ) );
		$this->assertSame( 'ok', $this->item( $id )['status'] );
	}

	public function test_five_images_sharing_an_alt_are_all_duplicates() {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->make_image( 'Equipo reunido en la oficina' );
		}
		$auditor = new Auditor();
		foreach ( $ids as $id ) {
			$auditor->audit( $id );
		}

		foreach ( $ids as $id ) {
			$this->assertSame( 'duplicate', $this->item( $id )['issues'] );
		}
	}

	public function test_audit_config_filter_changes_thresholds() {
		add_filter( 'mdia_audit_config', static fn( $config ) => array_merge( $config, array( 'min_length' => 2 ) ) );
		$id = $this->make_image( 'Sol' );

		$this->assertSame( 'ok', $this->item( $id )['status'] );
	}
}
