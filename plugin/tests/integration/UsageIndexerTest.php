<?php

use MDIA\Audit\Usage_Indexer;

class UsageIndexerTest extends WP_UnitTestCase {
	use MDIA_Test_Helpers;

	public function test_indexes_each_image_with_its_issues() {
		$a = $this->make_image();
		$b = $this->make_image();
		$this->make_post( $this->image_block( $a ) . $this->image_block( $b, 'Vista aérea del puerto de Vigo' ) );

		$this->assertSame( 'missing', $this->usages( $a )[0]['issues'] );
		$this->assertSame( '1', $this->usages( $a )[0]['manageable'] );
		$this->assertSame( '', $this->usages( $b )[0]['issues'] );
		$this->assertSame( 'Vista aérea del puerto de Vigo', $this->usages( $b )[0]['alt'] );
	}

	public function test_an_image_written_in_the_content_is_a_content_usage() {
		$a = $this->make_image();
		$this->make_post( $this->image_block( $a ) );

		$this->assertSame( 'content', $this->usages( $a )[0]['kind'] );
	}

	public function test_an_image_painted_by_a_block_with_the_library_text_follows_the_library() {
		$a = $this->make_image( 'Vista aérea del puerto de Vigo' );
		$this->make_post( $this->theme_block( 'library', $a ) );

		$row = $this->usages( $a )[0];
		$this->assertSame( 'library', $row['kind'] );
		$this->assertSame( '0', $row['manageable'], 'There is nothing to rewrite in the saved content.' );
		$this->assertSame( '', $row['issues'], 'The media library entry carries the problem, not the page.' );
		$this->assertSame( 'Vista aérea del puerto de Vigo', get_post_meta( $a, '_wp_attachment_image_alt', true ), 'Probing writes nothing.' );
	}

	public function test_an_image_whose_alt_is_written_by_the_theme_is_fixed() {
		$empty = $this->make_image( 'Vista aérea del puerto de Vigo' );
		$text  = $this->make_image();
		$this->make_post( $this->theme_block( 'fixed', $empty ) . $this->theme_block( 'fixed', $text, 'Logotipo' ) );

		$this->assertSame( 'fixed', $this->usages( $empty )[0]['kind'] );
		$this->assertSame( '', $this->usages( $empty )[0]['alt'] );
		$this->assertSame( 'fixed', $this->usages( $text )[0]['kind'] );
		$this->assertSame( 'Logotipo', $this->usages( $text )[0]['alt'] );
	}

	public function test_an_image_used_both_ways_counts_as_following_the_library() {
		$a = $this->make_image();
		$this->make_post( $this->theme_block( 'fixed', $a ) . $this->theme_block( 'library', $a ) );

		$this->assertSame( 'library', $this->usages( $a )[0]['kind'] );
	}

	public function test_the_featured_image_follows_the_library() {
		$a    = $this->make_image();
		$post = $this->make_post( '<p>Texto</p>' );
		set_post_thumbnail( $post, $a );
		( new Usage_Indexer() )->index_post( $post );

		$this->assertSame( 'library', $this->usages( $a )[0]['kind'] );
	}

	public function test_probing_does_not_leak_into_the_page_text_used_by_other_checks() {
		$a    = $this->make_image( 'Vista aérea del puerto de Vigo' );
		$post = $this->make_post( $this->theme_block( 'library', $a ) );

		\MDIA\Content\Page_Text::flush();
		( new Usage_Indexer() )->index_post( $post );

		$this->assertStringContainsString( 'Vista aérea del puerto de Vigo', \MDIA\Content\Page_Text::html( $post ) );
		$this->assertStringNotContainsString( 'mdia-probe', \MDIA\Content\Page_Text::html( $post ) );
	}

	public function test_only_published_pages_are_composed() {
		$a = $this->make_image();
		$this->make_post( $this->theme_block( 'library', $a ), array( 'post_status' => 'draft' ) );

		$this->assertCount( 0, $this->usages( $a ) );
	}

	public function test_removed_image_loses_its_row_and_is_reported_as_affected() {
		$a    = $this->make_image();
		$b    = $this->make_image();
		$post = $this->make_post( $this->image_block( $a ) . $this->image_block( $b, 'Vista aérea del puerto de Vigo' ) );

		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $this->image_block( $b, 'Vista aérea del puerto de Vigo' ) ), array( 'ID' => $post ) );
		clean_post_cache( $post );
		$affected = ( new Usage_Indexer() )->index_post( $post );
		sort( $affected );

		$this->assertSame( array( $a, $b ), $affected );
		$this->assertCount( 0, $this->usages( $a ) );
		$this->assertCount( 1, $this->usages( $b ) );
	}

	public function test_counts_occurrences_and_merges_issues() {
		$a = $this->make_image();
		$this->make_post( $this->image_block( $a, 'Vista aérea del puerto de Vigo' ) . $this->image_block( $a, 'foto' ) . $this->image_block( $a ) );

		$row = $this->usages( $a )[0];
		$this->assertSame( '3', $row['occurrences'] );
		$this->assertSame( 'missing,generic', $row['issues'] );
	}

	public function test_drafts_are_indexed_and_trashed_posts_are_not() {
		$a     = $this->make_image();
		$draft = $this->make_post( $this->image_block( $a ), array( 'post_status' => 'draft' ) );
		$this->assertCount( 1, $this->usages( $a ) );

		wp_trash_post( $draft );
		$this->assertCount( 0, $this->usages( $a ) );
	}

	public function test_deleting_a_post_removes_its_usages() {
		$a    = $this->make_image();
		$post = $this->make_post( $this->image_block( $a ) );
		wp_delete_post( $post, true );

		$this->assertCount( 0, $this->usages( $a ) );
	}

	public function test_synced_patterns_are_indexed() {
		$a = $this->make_image();
		$this->make_post( $this->image_block( $a ), array( 'post_type' => 'wp_block' ) );

		$this->assertCount( 1, $this->usages( $a ) );
	}

	public function test_revisions_are_not_indexed() {
		$a    = $this->make_image();
		$post = $this->make_post( $this->image_block( $a ) );
		wp_update_post( array( 'ID' => $post, 'post_content' => $this->image_block( $a ) . '<p>x</p>' ) );

		$this->assertCount( 1, $this->usages( $a ) );
	}

	public function test_images_without_class_are_matched_by_url() {
		$a   = $this->make_image( '', '2026/11/sin-clase.jpg' );
		$url = wp_get_upload_dir()['baseurl'] . '/2026/11/sin-clase-300x200.jpg';
		$this->make_post( '<p><img src="' . esc_url( $url ) . '" alt="IMG_1"></p>' );

		$this->assertSame( 'filename', $this->usages( $a )[0]['issues'] );
	}

	public function test_non_image_attachments_are_ignored() {
		$pdf = $this->make_image( '', '2026/11/doc.pdf', 'application/pdf' );
		$this->make_post( '<p><img src="' . esc_url( wp_get_attachment_url( $pdf ) ) . '" class="wp-image-' . $pdf . '" alt=""></p>' );

		$this->assertCount( 0, $this->usages( $pdf ) );
	}

	public function test_elementor_data_creates_unmanageable_usage() {
		$a    = $this->make_image();
		$post = $this->make_post( '<p>Hola</p>' );
		update_post_meta( $post, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post, '_elementor_data', wp_slash( '[{"elType":"widget","settings":{"image":{"url":"http://example.org/a.jpg","id":' . $a . ',"size":""}}}]' ) );

		$rows = $this->usages( $a );
		$this->assertCount( 1, $rows );
		$this->assertSame( '0', $rows[0]['manageable'] );
	}

	public function test_stale_image_id_from_migrated_content_is_not_indexed() {
		$a = $this->make_image( '', '2026/11/faro.jpg' );
		$this->make_post( '<p><img class="wp-image-' . $a . '" src="http://old-site.example/uploads/barco-300x200.jpg" alt=""></p>' );

		$this->assertCount( 0, $this->usages( $a ) );
	}
}
