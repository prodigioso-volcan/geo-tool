<?php

use MDIA\Checks\Rules\Headings;
use MDIA\Checks\Rules\Placeholder;
use MDIA\Checks\Rules\Robots_Txt;
use MDIA\Checks\Rules\Text_Length;
use PHPUnit\Framework\TestCase;

class SeoRulesTest extends TestCase {

	public function test_text_length_classifies_by_characters() {
		$this->assertSame( 'missing', Text_Length::classify( '  ', 70, 160 ) );
		$this->assertSame( 'too_short', Text_Length::classify( 'Corta.', 70, 160 ) );
		$this->assertSame( 'too_long', Text_Length::classify( str_repeat( 'á', 161 ), 70, 160 ) );
		$this->assertSame( '', Text_Length::classify( str_repeat( 'á', 160 ), 70, 160 ) );
		$this->assertSame( '', Text_Length::classify( 'Título breve', 0, 60 ) );
	}

	public function test_fingerprint_ignores_case_spacing_and_punctuation() {
		$this->assertSame( Text_Length::fingerprint( 'Rutas  por las Rías Baixas.' ), Text_Length::fingerprint( 'rutas por las rías baixas' ) );
		$this->assertNotSame( Text_Length::fingerprint( 'Rutas por las Rías Baixas' ), Text_Length::fingerprint( 'Rutas por las Rías Altas' ) );
		$this->assertSame( '', Text_Length::fingerprint( '   ' ) );
	}

	public function heading_cases(): array {
		return array(
			'clean'                 => array( '<h2>Uno</h2><p>x</p><h3>Dos</h3><h2>Tres</h2>', array() ),
			// Many themes leave the H1 to a block of the content: one is not a problem.
			'one h1 in content'     => array( '<h1>Título</h1><h2>Uno</h2>', array() ),
			'several h1'            => array( '<h1>Título</h1><h2>Uno</h2><h1>Otro</h1>', array( 'several_h1' ) ),
			'skipped level'         => array( '<h2>Uno</h2><h4>Dos</h4>', array( 'skipped_level' ) ),
			'both'                  => array( '<h1>T</h1><h1>U</h1><h3>Dos</h3>', array( 'several_h1', 'skipped_level' ) ),
			'going back up is fine' => array( '<h2>A</h2><h3>B</h3><h4>C</h4><h2>D</h2>', array() ),
			'empty heading ignored' => array( '<h2>A</h2><h4></h4><h3>B</h3>', array() ),
			'no headings'           => array( '<p>Solo texto</p>', array() ),
			'starts at h3'          => array( '<h3>Uno</h3>', array( 'skipped_level' ) ),
		);
	}

	/**
	 * @dataProvider heading_cases
	 */
	public function test_headings( string $html, array $expected ) {
		$this->assertSame( $expected, Headings::issues( $html ) );
	}

	public function test_headings_outline_is_reported_for_the_reviewer() {
		$this->assertSame( array( array( 2, 'Uno' ), array( 4, 'Dos y medio' ) ), Headings::outline( '<h2>Uno</h2><h4>Dos <em>y medio</em></h4>' ) );
	}

	public function test_placeholder_text_is_detected() {
		$this->assertTrue( Placeholder::has_filler( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.' ) );
		$this->assertTrue( Placeholder::has_filler( 'Texto de ejemplo. LOREM IPSUM dolor.' ) );
		$this->assertTrue( Placeholder::has_filler( 'Bienvenido a WordPress. Esta es tu primera entrada. Edítala o bórrala, ¡luego empieza a escribir!' ) );
		$this->assertTrue( Placeholder::has_filler( 'Welcome to WordPress. This is your first post. Edit or delete it, then start writing!' ) );
		$this->assertFalse( Placeholder::has_filler( 'La fundación promueve la investigación biomédica.' ) );
		$this->assertFalse( Placeholder::has_filler( '' ) );
	}

	public function test_figures_left_as_xxx_are_filler_but_roman_numerals_are_not() {
		$this->assertTrue( Placeholder::has_filler( '+300 investigadores, +xxx ensayos clínicos' ) );
		$this->assertFalse( Placeholder::has_filler( 'XXX Congreso Nacional de Cardiología' ) );
		$this->assertFalse( Placeholder::has_filler( 'El Dr. Foxxx publicó el estudio' ) );
	}

	public function test_the_reviewer_is_shown_where_the_filler_is() {
		$text = str_repeat( 'Texto real de la página. ', 10 ) . 'Convocatorias Lorem ipsum dolor amet y más.' . str_repeat( ' Otro texto real.', 10 );

		$excerpt = Placeholder::filler_excerpt( $text );
		$this->assertStringContainsString( 'Convocatorias Lorem ipsum', $excerpt );
		$this->assertLessThan( 140, mb_strlen( $excerpt ) );
		$this->assertSame( '', Placeholder::filler_excerpt( 'La fundación promueve la investigación biomédica.' ) );
	}

	public function test_titles_that_can_only_be_a_test_are_sure() {
		foreach ( array( 'prueba 2', 'Página de prueba', 'Pagina de pruebas', 'TEST 3', 'Test page', 'Entrada de prueba', 'Borrar', 'Lorem ipsum', 'asdf', 'Sin título', 'Untitled', 'Pàgina de prova', 'Prova 2', 'Sense títol' ) as $title ) {
			$this->assertSame( 'sure', Placeholder::test_title( $title ), $title );
		}
	}

	public function test_a_single_word_that_may_be_a_real_page_is_only_a_maybe() {
		foreach ( array( 'Prueba', 'Pruebas', 'Test', 'Testing', 'Dummy', 'Prova', 'Untitled 3', 'Sin título 2' ) as $title ) {
			$this->assertSame( 'maybe', Placeholder::test_title( $title ), $title );
		}
	}

	public function test_real_titles_that_contain_a_test_word_are_not_tests() {
		foreach ( array( 'Pruebas diagnósticas', 'Test de esfuerzo', 'Demo', 'La Fundación', 'Banco de pruebas de software', '' ) as $title ) {
			$this->assertSame( '', Placeholder::test_title( $title ), $title );
		}
	}

	public function test_default_wordpress_content_is_recognised_by_slug_and_title() {
		$this->assertTrue( Placeholder::is_default_content( 'hello-world', 'Hello world!' ) );
		$this->assertTrue( Placeholder::is_default_content( 'hola-mundo', '¡Hola, mundo!' ) );
		$this->assertTrue( Placeholder::is_default_content( 'sample-page', 'Sample Page' ) );
		$this->assertTrue( Placeholder::is_default_content( 'pagina-ejemplo', 'Página de ejemplo' ) );
		$this->assertFalse( Placeholder::is_default_content( 'la-fundacion', 'La Fundación' ) );
		$this->assertTrue( Placeholder::is_default_title( 'Hello world!' ) );
		$this->assertFalse( Placeholder::is_default_title( 'Inicio' ) );
	}

	public function test_default_tagline_is_recognised() {
		$this->assertTrue( Placeholder::is_default_tagline( 'Just another WordPress site' ) );
		$this->assertTrue( Placeholder::is_default_tagline( 'Otro sitio realizado con WordPress' ) );
		$this->assertTrue( Placeholder::is_default_tagline( '' ) );
		$this->assertFalse( Placeholder::is_default_tagline( 'Investigación e innovación biomédica' ) );
	}

	public function test_robots_blocking_everything_is_detected() {
		$this->assertTrue( Robots_Txt::blocks_all( "User-agent: *\nDisallow: /\n" ) );
		$this->assertFalse( Robots_Txt::blocks_all( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n" ) );
		$this->assertFalse( Robots_Txt::blocks_all( "User-agent: *\nDisallow:\n" ) );
		$this->assertFalse( Robots_Txt::blocks_all( '' ) );
		$this->assertFalse( Robots_Txt::blocks_all( "User-agent: BadBot\nDisallow: /\n\nUser-agent: *\nDisallow: /privado/\n" ) );
	}

	public function test_robots_blocked_ai_crawlers_are_listed() {
		$robots = "# comentario\nUser-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /\n\nuser-agent: PerplexityBot\ndisallow: /privado/\n\nUser-agent: *\nDisallow: /wp-admin/\n";

		$this->assertSame( array( 'GPTBot', 'ClaudeBot' ), Robots_Txt::blocked_agents( $robots, array( 'GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended' ) ) );
		$this->assertSame( array(), Robots_Txt::blocked_agents( "User-agent: *\nDisallow: /wp-admin/\n", array( 'GPTBot' ) ) );
	}

	public function test_global_block_also_blocks_every_ai_crawler_without_its_own_rule() {
		$robots = "User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nAllow: /\n";

		$this->assertSame( array( 'ClaudeBot' ), Robots_Txt::blocked_agents( $robots, array( 'GPTBot', 'ClaudeBot' ) ) );
	}

	public function test_robots_sitemap_reference() {
		$this->assertTrue( Robots_Txt::has_sitemap( "User-agent: *\nDisallow:\n\nSitemap: https://example.org/sitemap_index.xml\n" ) );
		$this->assertFalse( Robots_Txt::has_sitemap( "User-agent: *\nDisallow:\n" ) );
	}
}
