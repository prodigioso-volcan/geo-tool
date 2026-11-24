<?php
/**
 * Detects template leftovers: filler text and the content WordPress ships with. Pure.
 *
 * @package GeoTool
 */

namespace MDIA\Checks\Rules;

final class Placeholder {

	private const FILLER = array(
		'lorem ipsum',
		'dolor sit amet',
		'this is your first post. edit or delete it',
		'esta es tu primera entrada. edítala o bórrala',
		'this is an example page. it\'s different from a blog post',
		'esta es una página de ejemplo. es diferente a una entrada',
	);

	private const DEFAULT_SLUGS = array( 'hello-world', 'hola-mundo', 'sample-page', 'pagina-ejemplo', 'pagina-de-ejemplo' );

	private const DEFAULT_TITLES = array( 'hello world!', '¡hola, mundo!', '¡hola mundo!', 'sample page', 'página de ejemplo' );

	private const DEFAULT_TAGLINES = array(
		'just another wordpress site',
		'otro sitio realizado con wordpress',
		'otro sitio más de wordpress',
		'otro sitio wordpress',
	);

	/** A figure nobody filled in ("+xxx projects"). Lower case only: XXX is also a Roman numeral. */
	private const UNFILLED = '/(?<![\p{L}\d])x{3,}(?![\p{L}\d])/u';

	public static function has_filler( string $text ): bool {
		return null !== self::filler_position( $text );
	}

	/**
	 * @return string The words around the first filler found, to show where it is. '' when there is none.
	 */
	public static function filler_excerpt( string $text ): string {
		$position = self::filler_position( $text );
		if ( null === $position ) {
			return '';
		}
		$start   = max( 0, $position - 40 );
		$excerpt = trim( mb_substr( $text, $start, 110 ) );
		return ( $start > 0 ? '…' : '' ) . $excerpt . ( $start + 110 < mb_strlen( $text ) ? '…' : '' );
	}

	/**
	 * @return int|null Character offset of the first filler.
	 */
	private static function filler_position( string $text ): ?int {
		$lower = mb_strtolower( $text );
		$first = null;
		foreach ( self::FILLER as $needle ) {
			$position = mb_strpos( $lower, $needle );
			if ( false !== $position && ( null === $first || $position < $first ) ) {
				$first = $position;
			}
		}
		if ( preg_match( self::UNFILLED, $text, $match, PREG_OFFSET_CAPTURE ) ) {
			$position = mb_strlen( substr( $text, 0, $match[0][1] ) );
			if ( null === $first || $position < $first ) {
				$first = $position;
			}
		}
		return $first;
	}

	public static function is_default_content( string $slug, string $title ): bool {
		return in_array( strtolower( $slug ), self::DEFAULT_SLUGS, true )
			|| in_array( mb_strtolower( trim( $title ) ), self::DEFAULT_TITLES, true );
	}

	/**
	 * A title made only of a test word, with or without a number: "Test", "Prueba 2",
	 * "Página de prueba". A real title that merely contains the word is not one.
	 */
	public static function is_test_title( string $title ): bool {
		$title = mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $title ) ) );

		return (bool) preg_match( '/^((p[aá]gina|entrada|post|page)( de)? )?(pruebas?|test(ing)?|dummy|borrar|lorem ipsum|asdf+|sin t[ií]tulo|untitled)( (page|post|p[aá]gina|entrada))?( ?\d+)?$/u', $title );
	}

	public static function is_default_tagline( string $tagline ): bool {
		$tagline = mb_strtolower( trim( $tagline ) );
		return '' === $tagline || in_array( $tagline, self::DEFAULT_TAGLINES, true );
	}
}
