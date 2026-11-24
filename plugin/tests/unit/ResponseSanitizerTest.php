<?php

use MDIA\AI\Response_Sanitizer;
use PHPUnit\Framework\TestCase;

class ResponseSanitizerTest extends TestCase {

	public function clean_cases(): array {
		return array(
			'wrapping quotes and spaces' => array( "  \"Un faro al atardecer.\"  ", 'Un faro al atardecer.' ),
			'typographic quotes'         => array( '«Un faro al atardecer»', 'Un faro al atardecer' ),
			'alt prefix'                 => array( 'Alt: Un faro', 'Un faro' ),
			'alt text prefix'            => array( 'Alt text: "Un faro"', 'Un faro' ),
			'spanish prefix'             => array( 'Texto alternativo: Un faro', 'Un faro' ),
			'first non-empty line'       => array( "\n\nUn faro al atardecer\nOtra línea que sobra", 'Un faro al atardecer' ),
			'forbidden start es'         => array( 'Imagen de un faro', 'Un faro' ),
			'forbidden start foto'       => array( 'Foto de una mujer leyendo', 'Una mujer leyendo' ),
			'forbidden start del'        => array( 'Imagen del faro de Vigo', 'El faro de Vigo' ),
			'forbidden start en'         => array( 'Image of a lighthouse', 'A lighthouse' ),
			'html removed'               => array( '<p>Un <strong>faro</strong> blanco</p>', 'Un faro blanco' ),
			'inner whitespace collapsed' => array( "Un   faro\tblanco", 'Un faro blanco' ),
			'inner quotes kept'          => array( 'Cartel con el texto "Abierto"', 'Cartel con el texto "Abierto"' ),
			'capitalizes first letter'   => array( 'árbol en un prado', 'Árbol en un prado' ),
		);
	}

	/**
	 * @dataProvider clean_cases
	 */
	public function test_clean( string $raw, string $expected ) {
		$this->assertSame( $expected, Response_Sanitizer::clean( $raw ) );
	}

	public function unusable_cases(): array {
		return array(
			'empty'           => array( '' ),
			'whitespace'      => array( "   \n " ),
			'only quotes'     => array( '""' ),
			'refusal en'      => array( "I'm sorry, I can't help with that" ),
			'refusal en 2'    => array( 'I cannot describe this image.' ),
			'refusal es'      => array( 'Lo siento, no puedo describir esta imagen' ),
			'refusal es 2'    => array( 'No puedo ver la imagen.' ),
			'as an ai'        => array( 'As an AI model, I am unable to view images' ),
		);
	}

	/**
	 * @dataProvider unusable_cases
	 */
	public function test_unusable_responses_return_null( string $raw ) {
		$this->assertNull( Response_Sanitizer::clean( $raw ) );
	}

	public function test_long_text_is_cut_at_a_word_boundary_within_125_chars() {
		$raw   = implode( ' ', array_fill( 0, 50, 'palabra' ) ); // 399 chars.
		$clean = Response_Sanitizer::clean( $raw );

		$this->assertLessThanOrEqual( 125, mb_strlen( $clean ) );
		$this->assertGreaterThan( 100, mb_strlen( $clean ) );
		$this->assertStringEndsWith( 'palabra', $clean );
		$this->assertStringNotContainsString( '…', $clean );
	}

	public function test_long_text_cut_drops_trailing_punctuation() {
		$raw   = str_repeat( 'a', 120 ) . ', ' . str_repeat( 'b', 40 );
		$this->assertSame( 'A' . str_repeat( 'a', 119 ), Response_Sanitizer::clean( $raw ) );
	}

	public function test_exactly_125_chars_is_untouched() {
		$raw = 'A' . str_repeat( 'b', 124 );
		$this->assertSame( $raw, Response_Sanitizer::clean( $raw ) );
	}

	public function cut_cases(): array {
		return array(
			'real answer cut inside a quoted text' => array(
				'Un círculo amarillo sobre un cielo gris azulado, con una franja de suelo verde oscuro en la parte inferior y el texto "Demo 3" en la esquina superior izquierda.',
				'Un círculo amarillo sobre un cielo gris azulado, con una franja de suelo verde oscuro en la parte inferior',
			),
			'dangling article and preposition' => array(
				'Círculo amarillo sobre fondo verde, con una franja oscura en la parte inferior y un cartel rojo colgado en la esquina superior de la pared',
				'Círculo amarillo sobre fondo verde, con una franja oscura en la parte inferior', // Cut at the last clause, not mid-phrase.
			),
			'real answer that lost its last adjective' => array(
				'Un círculo amarillo brillante en el lado derecho de un cielo gris claro, sobre una franja de tierra verde oscuro en la parte inferior de la imagen.',
				'Un círculo amarillo brillante en el lado derecho de un cielo gris claro',
			),
			'no clause to cut at: falls back to the last whole word' => array(
				'Retrato en primer plano de una mujer mayor de pelo blanco recogido que sonríe mientras sostiene entre las manos una taza humeante de café recién hecho',
				'Retrato en primer plano de una mujer mayor de pelo blanco recogido que sonríe mientras sostiene entre las manos una taza',
			),
			'english dangling words' => array(
				'A yellow circle over a pale blue sky with a dark green strip of grass along the bottom edge and a small white label in the upper left corner',
				'A yellow circle over a pale blue sky with a dark green strip of grass along the bottom edge',
			),
		);
	}

	/**
	 * @dataProvider cut_cases
	 */
	public function test_cut_text_never_ends_mid_phrase( string $raw, string $expected ) {
		$clean = Response_Sanitizer::clean( $raw );

		$this->assertSame( $expected, $clean );
		$this->assertLessThanOrEqual( 125, mb_strlen( $clean ) );
	}

	public function test_text_within_the_limit_keeps_its_ending_even_if_it_is_a_short_word() {
		$this->assertSame( 'Cartel con la palabra "de"', Response_Sanitizer::clean( 'Cartel con la palabra "de"' ) );
		$this->assertSame( 'Dos personas miran hacia el', Response_Sanitizer::clean( 'Dos personas miran hacia el' ) );
	}

	public function test_normalize_cleans_without_cutting() {
		$long = 'Imagen de ' . implode( ' ', array_fill( 0, 30, 'palabra' ) );

		$normalized = Response_Sanitizer::normalize( '"' . $long . '"' );

		$this->assertSame( 'Palabra ' . implode( ' ', array_fill( 0, 29, 'palabra' ) ), $normalized );
		$this->assertFalse( Response_Sanitizer::fits( $normalized ) );
		$this->assertTrue( Response_Sanitizer::fits( 'Un faro blanco' ) );
		$this->assertNull( Response_Sanitizer::normalize( "I'm sorry, I can't help with that" ) );
	}

	public function test_links_emails_and_invisible_characters_never_reach_the_proposal() {
		$this->assertSame( 'Cartel de oferta con un enlace', Response_Sanitizer::clean( 'Cartel de oferta con un enlace https://spam.example/buy?x=1' ) );
		$this->assertSame( 'Visita para más información', Response_Sanitizer::clean( 'Visita www.spam.example para más información' ) );
		$this->assertSame( 'Tarjeta con el contacto', Response_Sanitizer::clean( 'Tarjeta con el contacto ventas@spam.example' ) );
		$this->assertSame( 'Un faro blanco', Response_Sanitizer::clean( "Un \u{202E}faro\u{200B} blanco\x07" ) );
		$this->assertNull( Response_Sanitizer::clean( 'https://spam.example' ) );
	}
}
