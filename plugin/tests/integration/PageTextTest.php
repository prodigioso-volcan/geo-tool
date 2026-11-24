<?php

use MDIA\Content\Acf;
use MDIA\Content\Page_Text;

class PageTextTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Page_Text::flush();
	}

	public function tear_down() {
		if ( function_exists( 'acf_remove_local_field_group' ) ) {
			acf_remove_local_field_group( 'group_mdia_test' );
		}
		parent::tear_down();
	}

	private function register_fields(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			$this->markTestSkipped( 'ACF / Secure Custom Fields is not installed in the test WordPress.' );
		}
		acf_add_local_field_group(
			array(
				'key'      => 'group_mdia_test',
				'title'    => 'Prueba',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
				'fields'   => array(
					array( 'key' => 'field_mdia_kicker', 'name' => 'kicker', 'label' => 'Antetítulo', 'type' => 'text', 'required' => 1 ),
					array( 'key' => 'field_mdia_body', 'name' => 'cuerpo', 'label' => 'Cuerpo', 'type' => 'wysiwyg' ),
					array( 'key' => 'field_mdia_note', 'name' => 'nota', 'label' => 'Nota', 'type' => 'textarea' ),
					array( 'key' => 'field_mdia_photo', 'name' => 'foto', 'label' => 'Foto', 'type' => 'image' ),
					array( 'key' => 'field_mdia_count', 'name' => 'cifra', 'label' => 'Cifra', 'type' => 'number' ),
					array(
						'key'        => 'field_mdia_rows',
						'name'       => 'filas',
						'label'      => 'Filas',
						'type'       => 'group',
						'sub_fields' => array(
							array( 'key' => 'field_mdia_row_title', 'name' => 'titulo', 'label' => 'Título', 'type' => 'text' ),
						),
					),
				),
			)
		);
	}

	public function test_block_content_is_resolved_to_html_and_text() {
		$id = self::factory()->post->create(
			array( 'post_content' => "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Nuestra historia</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Fundada en <strong>1998</strong>.</p>\n<!-- /wp:paragraph -->" )
		);

		$this->assertStringContainsString( '<h2 class="wp-block-heading">Nuestra historia</h2>', Page_Text::html( $id ) );
		$this->assertSame( 'Nuestra historia Fundada en 1998.', Page_Text::text( $id ) );
	}

	public function test_dynamic_blocks_are_rendered() {
		register_block_type( 'mdia/test-dynamic', array( 'render_callback' => static fn( $attrs ) => '<h3>' . esc_html( $attrs['titulo'] ?? '' ) . '</h3><p>Texto del tema</p>' ) );
		$id = self::factory()->post->create( array( 'post_content' => '<!-- wp:mdia/test-dynamic {"titulo":"Desde el bloque"} /-->' ) );

		$this->assertSame( 'Desde el bloque Texto del tema', Page_Text::text( $id ) );
		unregister_block_type( 'mdia/test-dynamic' );
	}

	public function test_a_block_that_throws_does_not_break_the_page() {
		register_block_type( 'mdia/test-broken', array( 'render_callback' => static function () { throw new \RuntimeException( 'boom' ); } ) );
		$id = self::factory()->post->create( array( 'post_content' => "<!-- wp:paragraph -->\n<p>Antes</p>\n<!-- /wp:paragraph -->\n<!-- wp:mdia/test-broken /-->" ) );

		$this->assertSame( '', Page_Text::html( $id ) );
		$this->assertSame( '', Page_Text::text( $id ) );
		unregister_block_type( 'mdia/test-broken' );
	}

	public function test_output_printed_by_a_block_is_not_leaked() {
		register_block_type( 'mdia/test-echo', array( 'render_callback' => static function () { echo 'RUIDO'; return '<p>Bien</p>'; } ) );
		$id = self::factory()->post->create( array( 'post_content' => '<!-- wp:mdia/test-echo /-->' ) );

		ob_start();
		$text = Page_Text::text( $id );
		$leak = ob_get_clean();

		$this->assertSame( 'Bien', $text );
		$this->assertSame( '', $leak );
		unregister_block_type( 'mdia/test-echo' );
	}

	public function test_a_shortcode_that_asks_for_the_page_again_does_not_loop() {
		$id = self::factory()->post->create( array( 'post_content' => '<p>Antes.</p>[mdia_again]' ) );
		add_shortcode( 'mdia_again', static fn() => 'dentro:' . Page_Text::fresh( $id ) );
		Page_Text::flush();

		$this->assertStringContainsString( 'Antes.', Page_Text::html( $id ) );
		$this->assertStringContainsString( 'dentro:', Page_Text::html( $id ) );
		remove_shortcode( 'mdia_again' );
	}

	public function test_page_without_any_text_is_empty() {
		$id = self::factory()->post->create( array( 'post_content' => '' ) );

		$this->assertSame( '', Page_Text::text( $id ) );
		$this->assertSame( '', Page_Text::text( 999999 ) );
	}

	public function test_acf_text_fields_are_part_of_the_page_text() {
		$this->register_fields();
		$id = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => "<!-- wp:paragraph -->\n<p>Del editor.</p>\n<!-- /wp:paragraph -->" ) );
		update_field( 'kicker', 'LA FUNDACIÓN', $id );
		update_field( 'cuerpo', '<h2>Misión</h2><p>Promover la investigación.</p>', $id );
		update_field( 'nota', "Línea uno\nLínea dos", $id );
		update_field( 'cifra', 42, $id );
		update_field( 'filas', array( 'titulo' => 'Dentro del grupo' ), $id );
		Page_Text::flush();

		$text = Page_Text::text( $id );
		$this->assertStringContainsString( 'Del editor.', $text );
		$this->assertStringContainsString( 'LA FUNDACIÓN', $text );
		$this->assertStringContainsString( 'Misión Promover la investigación.', $text );
		$this->assertStringContainsString( 'Línea uno Línea dos', $text );
		$this->assertStringContainsString( 'Dentro del grupo', $text );
		$this->assertStringNotContainsString( '42', $text, 'Numbers and other non-text fields are not page text.' );
		$this->assertStringContainsString( '<h2>Misión</h2>', Page_Text::html( $id ) );
	}

	public function test_an_image_in_an_acf_field_counts_as_shown_on_the_page() {
		$this->register_fields();
		$image = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg', 'file' => '2026/11/hero-acf.jpg' ) );
		$id    = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<p>Texto.</p>' ) );
		update_field( 'foto', $image, $id );

		( new \MDIA\Audit\Usage_Indexer() )->index_post( $id );

		$usages = ( new \MDIA\Data\Usages() )->for_attachment( $image );
		$this->assertCount( 1, $usages );
		$this->assertSame( 'library', $usages[0]['kind'] );
	}

	public function test_empty_acf_content_fields_are_listed_with_their_required_flag() {
		$this->register_fields();
		$id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_field( 'nota', 'Rellena', $id );

		$empty = Acf::empty_fields( $id );

		$this->assertSame( array( 'kicker', 'cuerpo', 'foto' ), wp_list_pluck( $empty, 'name' ) );
		$this->assertTrue( $empty[0]['required'] );
		$this->assertFalse( $empty[1]['required'] );
		$this->assertSame( 'Antetítulo', $empty[0]['label'] );
	}

	public function test_posts_outside_the_field_group_have_no_acf_findings() {
		$this->register_fields();
		$id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$this->assertSame( array(), Acf::empty_fields( $id ) );
	}
}
