<?php

use MDIA\Content\Alt_Rewriter;

class AltRewriterTest extends WP_UnitTestCase {

	private Alt_Rewriter $rewriter;

	public function set_up() {
		parent::set_up();
		$this->rewriter = new Alt_Rewriter();
	}

	private function image_block( int $id, string $alt_attr = 'alt=""' ): string {
		return "<!-- wp:image {\"id\":$id,\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"http://example.org/wp-content/uploads/foto-$id-1024x768.jpg\" $alt_attr class=\"wp-image-$id\"/></figure>\n<!-- /wp:image -->";
	}

	public function test_replaces_empty_alt_in_image_block_and_nothing_else() {
		$content = "<!-- wp:paragraph -->\n<p>Hola</p>\n<!-- /wp:paragraph -->\n\n" . $this->image_block( 12 );
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo', '__return_true' );

		$this->assertSame( str_replace( 'alt=""', 'alt="Nuevo"', $content ), $result['content'] );
		$this->assertSame( array( '' ), $result['replaced'] );
		$this->assertSame( array(), $result['kept'] );
	}

	public function test_keeps_alt_when_callback_says_no() {
		$content = $this->image_block( 12, 'alt="Retrato de Ana"' );
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo', '__return_false' );

		$this->assertSame( $content, $result['content'] );
		$this->assertSame( array( 'Retrato de Ana' ), $result['kept'] );
		$this->assertSame( array(), $result['replaced'] );
	}

	public function test_replaces_only_occurrences_the_callback_accepts() {
		$content = $this->image_block( 12, 'alt="Retrato de Ana"' ) . "\n\n" . $this->image_block( 12 );
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo', static fn( $alt ) => '' === $alt );

		$this->assertSame( array( 'Retrato de Ana', 'Nuevo' ), $this->rewriter->find( $result['content'] )[12]['alts'] );
	}

	public function test_adds_alt_attribute_when_absent() {
		$content = '<p><img src="http://example.org/a.jpg" class="wp-image-12"></p>';
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo', '__return_true' );

		$this->assertStringContainsString( 'alt="Nuevo"', $result['content'] );
		$this->assertSame( array( '' ), $result['replaced'] );
	}

	public function test_escapes_special_characters_and_find_returns_them_decoded() {
		$text    = 'El "faro" de <Vigo> & alrededores';
		$result  = $this->rewriter->replace( $this->image_block( 12 ), 12, $text, '__return_true' );

		$this->assertStringContainsString( '&quot;faro&quot;', $result['content'] );
		$this->assertStringContainsString( '&lt;Vigo&gt;', $result['content'] );
		$this->assertStringContainsString( '&amp; alrededores', $result['content'] );
		$this->assertStringNotContainsString( '<Vigo>', $result['content'] );
		$this->assertSame( array( $text ), $this->rewriter->find( $result['content'] )[12]['alts'] );
	}

	public function test_replaces_in_classic_editor_content() {
		$content = '<p>Texto <img class="alignleft wp-image-12 size-medium" src="http://example.org/a-300x200.jpg" alt="IMG_2034" width="300" height="200" /> más texto</p>';
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo', '__return_true' );

		$this->assertStringContainsString( 'alt="Nuevo"', $result['content'] );
		$this->assertSame( array( 'IMG_2034' ), $result['replaced'] );
		$this->assertFalse( has_blocks( $result['content'] ) );
	}

	public function test_gallery_only_changes_the_target_image() {
		$content = "<!-- wp:gallery {\"linkTo\":\"none\"} -->\n<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\">" . $this->image_block( 12 ) . "\n\n" . $this->image_block( 13 ) . "</figure>\n<!-- /wp:gallery -->";
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo', '__return_true' );
		$found   = $this->rewriter->find( $result['content'] );

		$this->assertSame( array( 'Nuevo' ), $found[12]['alts'] );
		$this->assertSame( array( '' ), $found[13]['alts'] );
	}

	public function test_cover_block_updates_comment_attribute_too() {
		$content = "<!-- wp:cover {\"url\":\"http://example.org/wp-content/uploads/c.jpg\",\"id\":12,\"alt\":\"viejo\",\"dimRatio\":50} -->\n<div class=\"wp-block-cover\"><img class=\"wp-block-cover__image-background wp-image-12\" alt=\"viejo\" src=\"http://example.org/wp-content/uploads/c.jpg\" data-object-fit=\"cover\"/><span aria-hidden=\"true\" class=\"wp-block-cover__background has-background-dim\"></span><div class=\"wp-block-cover__inner-container\"><!-- wp:paragraph -->\n<p>Hola</p>\n<!-- /wp:paragraph --></div></div>\n<!-- /wp:cover -->";
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo "texto"', '__return_true' );
		$blocks  = parse_blocks( $result['content'] );

		$this->assertSame( 'core/cover', $blocks[0]['blockName'] );
		$this->assertSame( 'Nuevo "texto"', $blocks[0]['attrs']['alt'] );
		$this->assertSame( 50, $blocks[0]['attrs']['dimRatio'] );
		$this->assertSame( array( 'Nuevo "texto"' ), $this->rewriter->find( $result['content'] )[12]['alts'] );
		$this->assertSame( array( 'viejo' ), $result['replaced'] );
	}

	public function test_cover_block_comment_untouched_when_callback_says_no() {
		$content = "<!-- wp:cover {\"id\":12,\"alt\":\"Atardecer sobre la ría\"} -->\n<div class=\"wp-block-cover\"><img class=\"wp-block-cover__image-background wp-image-12\" alt=\"Atardecer sobre la ría\" src=\"http://example.org/c.jpg\"/></div>\n<!-- /wp:cover -->";
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo', '__return_false' );

		$this->assertSame( $content, $result['content'] );
	}

	public function test_media_text_block_keeps_valid_attributes() {
		$content = "<!-- wp:media-text {\"mediaId\":12,\"mediaLink\":\"http://example.org/?attachment_id=12\",\"mediaType\":\"image\"} -->\n<div class=\"wp-block-media-text is-stacked-on-mobile\"><figure class=\"wp-block-media-text__media\"><img src=\"http://example.org/m.jpg\" alt=\"\" class=\"wp-image-12 size-full\"/></figure><div class=\"wp-block-media-text__content\"></div></div>\n<!-- /wp:media-text -->";
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo', '__return_true' );
		$blocks  = parse_blocks( $result['content'] );

		$this->assertSame( 12, $blocks[0]['attrs']['mediaId'] );
		$this->assertStringContainsString( 'alt="Nuevo"', $result['content'] );
	}

	public function test_other_attachments_are_left_alone() {
		$content = $this->image_block( 99, 'alt="IMG_1"' );
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo', '__return_true' );

		$this->assertSame( $content, $result['content'] );
		$this->assertSame( array(), $result['replaced'] );
	}

	public function test_id_prefix_does_not_match_longer_ids() {
		$content = $this->image_block( 123, 'alt="IMG_1"' );
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo', '__return_true' );

		$this->assertSame( $content, $result['content'] );
	}

	public function test_find_by_urls_matches_sized_and_scaled_sources() {
		$content = '<p><img src="http://example.org/wp-content/uploads/2026/11/foto-300x200.jpg?ver=2" alt="x"><img src="http://example.org/wp-content/uploads/2026/11/otra-scaled.jpg"><img class="wp-image-7" src="http://example.org/wp-content/uploads/2026/11/foto.jpg" alt="con clase"></p>';
		$map     = array(
			'http://example.org/wp-content/uploads/2026/11/foto.jpg' => 12,
			'http://example.org/wp-content/uploads/2026/11/otra.jpg' => 13,
		);

		$this->assertSame(
			array(
				12 => array( 'x' ),
				13 => array( '' ),
			),
			wp_list_pluck( $this->rewriter->find_by_urls( $content, $map ), 'alts' )
		);
	}

	public function test_replace_by_url_for_images_without_class() {
		$content = '<p><img src="http://example.org/wp-content/uploads/foto-300x200.jpg" alt=""></p>';
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo', '__return_true', array( 'http://example.org/wp-content/uploads/foto.jpg' ) );

		$this->assertStringContainsString( 'alt="Nuevo"', $result['content'] );
	}

	public function test_empty_and_malformed_content_do_not_fail() {
		$this->assertSame( '', $this->rewriter->replace( '', 12, 'Nuevo', '__return_true' )['content'] );
		$this->assertSame( array(), $this->rewriter->find( '' ) );

		$broken = '<div><p>Sin cerrar <img class="wp-image-99" src="a.jpg" <!-- wp:image {roto -->';
		$this->assertSame( $broken, $this->rewriter->replace( $broken, 12, 'Nuevo', '__return_true' )['content'] );
	}

	public function test_normalize_url_strips_size_scaled_and_query() {
		$this->assertSame( 'http://e.org/a/foto.jpg', Alt_Rewriter::normalize_url( 'http://e.org/a/foto-1024x768.jpg?x=1' ) );
		$this->assertSame( 'http://e.org/a/foto.jpg', Alt_Rewriter::normalize_url( 'http://e.org/a/foto-scaled.jpg' ) );
		$this->assertSame( 'http://e.org/a/casa-2x4.png', Alt_Rewriter::normalize_url( 'http://e.org/a/casa-2x4-300x200.png' ) );
	}

	public function test_cover_block_without_alt_attribute_gets_one_so_it_stays_valid() {
		$content = "<!-- wp:cover {\"url\":\"http://example.org/c.jpg\",\"id\":12,\"dimRatio\":50} -->\n<div class=\"wp-block-cover\"><img class=\"wp-block-cover__image-background wp-image-12\" alt=\"\" src=\"http://example.org/c.jpg\" data-object-fit=\"cover\"/></div>\n<!-- /wp:cover -->";
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo', '__return_true' );
		$blocks  = parse_blocks( $result['content'] );

		$this->assertSame( 'Nuevo', $blocks[0]['attrs']['alt'] );
		$this->assertSame( 12, $blocks[0]['attrs']['id'] );
		$this->assertSame( array( 'Nuevo' ), $this->rewriter->find( $result['content'] )[12]['alts'] );
	}

	public function test_other_blocks_do_not_get_an_alt_attribute_added() {
		$result = $this->rewriter->replace( $this->image_block( 12 ), 12, 'Nuevo', '__return_true' );

		$this->assertArrayNotHasKey( 'alt', parse_blocks( $result['content'] )[0]['attrs'] );
	}

	public function test_stale_image_id_pointing_to_another_file_is_not_touched() {
		$content = '<p><img class="wp-image-12" src="http://old-site.example/wp-content/uploads/barco-300x200.jpg" alt=""></p>';
		$result  = $this->rewriter->replace( $content, 12, 'Un faro blanco', '__return_true', array(), 'faro.jpg' );

		$this->assertSame( $content, $result['content'] );
		$this->assertSame( array(), $result['replaced'] );
	}

	public function test_expected_file_matches_across_domains_sizes_and_scaled_names() {
		$content = '<p><img class="wp-image-12" src="https://cdn.example/uploads/Faro-1024x683.jpg" alt=""></p>';
		$result  = $this->rewriter->replace( $content, 12, 'Un faro blanco', '__return_true', array(), 'faro-scaled.jpg' );

		$this->assertSame( array( '' ), $result['replaced'] );
	}

	public function test_find_reports_the_source_of_each_image() {
		$found = $this->rewriter->find( '<img class="wp-image-12" src="http://e.org/a/faro-300x200.jpg" alt="x">' );

		$this->assertSame( array( 'http://e.org/a/faro-300x200.jpg' ), $found[12]['srcs'] );
	}

	public function test_updating_a_cover_block_keeps_its_empty_objects_as_objects() {
		$content = "<!-- wp:cover {\"id\":12,\"alt\":\"viejo\",\"style\":{},\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-cover\"><img class=\"wp-block-cover__image-background wp-image-12\" alt=\"viejo\" src=\"http://example.org/c.jpg\"/></div>\n<!-- /wp:cover -->";
		$result  = $this->rewriter->replace( $content, 12, 'Nuevo', '__return_true' );

		$this->assertStringContainsString( '"style":{}', $result['content'] );
		$this->assertStringContainsString( '"alt":"Nuevo"', $result['content'] );
		$this->assertStringContainsString( '"layout":{"type":"constrained"}', $result['content'] );
	}

	public function test_hostile_block_comments_are_handled_quickly() {
		$image = '<img class="wp-image-12" src="a.jpg" alt="">';
		$cases = array(
			'many lines'  => str_repeat( "<!-- wp:x {\"a\":1 -->\n", 20000 ) . $image,
			'one line'    => str_repeat( '<!-- wp:x {"a":1 --> ', 20000 ) . $image,
			'no closing'  => $image . str_repeat( ' <!-- wp:x {', 20000 ),
		);

		foreach ( $cases as $name => $content ) {
			$start  = microtime( true );
			$result = $this->rewriter->replace( $content, 12, 'Nuevo', '__return_true' );

			$this->assertLessThan( 2, microtime( true ) - $start, $name );
			$this->assertSame( array( '' ), $result['replaced'], $name );
		}
	}
}
