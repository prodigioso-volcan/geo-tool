<?php

use MDIA\Audit\Rules;
use PHPUnit\Framework\TestCase;

class RulesTest extends TestCase {

	public function cases(): array {
		return array(
			'empty'                 => array( '', 'a.jpg', 1, array( 'missing' ) ),
			'whitespace only'       => array( '   ', 'a.jpg', 1, array( 'missing' ) ),
			'same as file name'     => array( 'IMG_2034', 'IMG_2034.jpg', 1, array( 'filename' ) ),
			'ends in extension'     => array( 'img-2034.JPG', 'otra.jpg', 1, array( 'filename' ) ),
			'file-like pattern'     => array( 'captura-de-pantalla-2026-11-02', 'x.png', 1, array( 'filename' ) ),
			'generic word'          => array( 'Imagen', 'x.jpg', 1, array( 'generic' ) ),
			'generic phrase'        => array( 'sin título', 'x.jpg', 1, array( 'generic' ) ),
			'generic beats short'   => array( 'foto', 'x.jpg', 1, array( 'generic' ) ),
			'generic with period'   => array( 'Logo.', 'x.jpg', 1, array( 'generic' ) ),
			'too short'             => array( 'Sol', 'x.jpg', 1, array( 'too_short' ) ),
			'too long'              => array( str_repeat( 'a ', 80 ), 'x.jpg', 1, array( 'too_long' ) ),
			'duplicate at 5'        => array( 'Equipo reunido en la oficina', 'x.jpg', 5, array( 'duplicate' ) ),
			'not duplicate at 4'    => array( 'Equipo reunido en la oficina', 'x.jpg', 4, array() ),
			'good'                  => array( 'Vista aérea del puerto de Vigo', 'x.jpg', 1, array() ),
			'multibyte counts chars' => array( 'Niño 👦 con cometa', 'x.jpg', 1, array() ),
			'150 chars is fine'     => array( str_repeat( 'a', 74 ) . '  ' . str_repeat( 'b', 74 ), 'x.jpg', 1, array() ),
			'hyphenated words ok'   => array( 'Punto de venta self-service', 'x.jpg', 1, array() ),
			'year in sentence ok'   => array( 'Equipo en la feria de 2026', 'x.jpg', 1, array() ),
			'file name with words'  => array( 'equipo oficina', 'equipo-oficina.jpg', 1, array( 'filename' ) ),
		);
	}

	/**
	 * @dataProvider cases
	 */
	public function test_classify( string $alt, string $filename, int $count, array $expected ) {
		$this->assertSame( $expected, Rules::classify( $alt, $filename, $count ) );
	}

	public function test_missing_is_never_combined_with_other_codes() {
		$this->assertSame( array( 'missing' ), Rules::classify( '', '', 9 ) );
	}

	public function test_codes_combine_in_canonical_order() {
		$long = 'IMG_' . str_repeat( '1', 160 );
		$this->assertSame( array( 'filename', 'too_long', 'duplicate' ), Rules::classify( $long, 'x.jpg', 7 ) );
	}

	public function test_config_overrides_thresholds_and_terms() {
		$config = array( 'min_length' => 2, 'max_length' => 10, 'duplicate_threshold' => 2, 'generic_terms' => array( 'cosa' ) );
		$this->assertSame( array(), Rules::classify( 'Sol', 'x.jpg', 1, $config ) );
		$this->assertSame( array( 'too_long' ), Rules::classify( 'Un texto largo', 'x.jpg', 1, $config ) );
		$this->assertSame( array( 'generic' ), Rules::classify( 'Cosa', 'x.jpg', 1, $config ) );
		$this->assertSame( array(), Rules::classify( 'Imagen', 'x.jpg', 1, $config ) );
		$this->assertSame( array( 'duplicate' ), Rules::classify( 'Mesa', 'x.jpg', 2, $config ) );
	}

	public function test_default_config_values_match_the_design() {
		$config = Rules::default_config();
		$this->assertSame( 5, $config['min_length'] );
		$this->assertSame( 150, $config['max_length'] );
		$this->assertSame( 5, $config['duplicate_threshold'] );
		$this->assertContains( 'imagen', $config['generic_terms'] );
	}
}
