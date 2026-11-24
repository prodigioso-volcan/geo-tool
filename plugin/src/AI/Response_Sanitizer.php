<?php
/**
 * Turns a raw model answer into a usable alt text, or rejects it. Pure.
 *
 * @package GeoTool
 */

namespace MDIA\AI;

final class Response_Sanitizer {

	public const MAX_LENGTH = 125;

	private const REFUSALS = '/^(i[\'’]?m sorry|i am sorry|sorry\b|i can(no|[\'’])t|i cannot|i[\'’]?m unable|i am unable|as an ai|lo siento|no puedo|como (modelo|ia|inteligencia))/iu';

	private const LABELS = '/^(alt(ernative)?(\s+text)?|texto\s+alternativo|descripción|description)\s*:\s*/iu';

	private const OPENERS = '/^(imagen|foto|fotografía|image|photo|picture)\s+(de|of)\s+/iu';

	private const OPENERS_DEL = '/^(imagen|foto|fotografía)\s+del\s+/iu';

	/**
	 * Words that cannot end a description (Spanish and English). Only checked on text that was cut.
	 */
	private const DANGLING_WORDS = array(
		'y', 'e', 'o', 'u', 'con', 'sin', 'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'de', 'del', 'en',
		'a', 'al', 'por', 'para', 'sobre', 'que', 'su', 'sus', 'texto',
		'and', 'or', 'with', 'the', 'an', 'of', 'in', 'on', 'at', 'to', 'by', 'for', 'its', 'their',
	);

	/**
	 * Where a description can be cut and still be a complete phrase (Spanish and English).
	 */
	private const CLAUSE_BREAKS = array( ', ', '; ', ' y ', ' e ', ' con ', ' and ', ' with ' );

	private const QUOTES = "\"'“”‘’«»`";

	/**
	 * Cleans the answer and, as a last resort, cuts it to the maximum length.
	 *
	 * @return string|null Null when the answer cannot be used as alt text.
	 */
	public static function clean( string $raw ): ?string {
		$text = self::normalize( $raw );
		if ( null === $text ) {
			return null;
		}
		$text = self::truncate( $text );

		return '' === $text ? null : $text;
	}

	public static function fits( string $text ): bool {
		return mb_strlen( $text ) <= self::MAX_LENGTH;
	}

	/**
	 * Cleans the answer without cutting it, so the caller can ask for a shorter one instead.
	 *
	 * @return string|null Null when the answer cannot be used as alt text.
	 */
	public static function normalize( string $raw ): ?string {
		$text = strip_tags( $raw );

		// Text inside an image can steer the model. Whatever it answers, a proposal never carries
		// links, e-mail addresses or invisible characters (bidi overrides, zero-width, control codes).
		$text = (string) preg_replace( '/[^\P{C}\n\t]/u', '', $text );
		$text = (string) preg_replace( '~\b(?:https?://|www\.)\S+|\S+@\S+\.\S+~iu', '', $text );

		$line = '';
		foreach ( preg_split( '/\R/u', $text ) as $candidate ) {
			$candidate = trim( (string) preg_replace( '/\s+/u', ' ', $candidate ) );
			if ( '' !== $candidate ) {
				$line = $candidate;
				break;
			}
		}

		$line = self::unquote( $line );
		$line = self::unquote( (string) preg_replace( self::LABELS, '', $line ) );

		if ( '' === $line || preg_match( self::REFUSALS, $line ) ) {
			return null;
		}

		$line = (string) preg_replace( self::OPENERS_DEL, 'el ', $line );
		$line = (string) preg_replace( self::OPENERS, '', $line );
		$line = trim( $line );

		if ( '' === $line ) {
			return null;
		}

		return mb_strtoupper( mb_substr( $line, 0, 1 ) ) . mb_substr( $line, 1 );
	}

	private static function unquote( string $text ): string {
		$text  = trim( $text );
		$chars = preg_split( '//u', self::QUOTES, -1, PREG_SPLIT_NO_EMPTY );

		while ( mb_strlen( $text ) >= 2 && in_array( mb_substr( $text, 0, 1 ), $chars, true ) && in_array( mb_substr( $text, -1 ), $chars, true ) ) {
			$text = trim( mb_substr( $text, 1, -1 ) );
		}
		return 1 === mb_strlen( $text ) && in_array( $text, $chars, true ) ? '' : $text;
	}

	private static function truncate( string $text ): string {
		return self::cut( $text, self::MAX_LENGTH );
	}

	/**
	 * Shortens a text to a maximum length, ending at a clause or a whole word, never mid-phrase.
	 */
	public static function cut( string $text, int $max ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $max + 1 );
		$space = mb_strrpos( $cut, ' ' );
		$cut   = false !== $space && $space > $max / 2 ? mb_substr( $cut, 0, $space ) : mb_substr( $cut, 0, $max );

		// A quotation opened but cut before it closed would read as broken text.
		if ( 1 === substr_count( $cut, '"' ) % 2 ) {
			$cut = mb_substr( $cut, 0, (int) mb_strrpos( $cut, '"' ) );
		}

		// Prefer ending at the last clause: a shorter complete phrase reads better than a longer broken one.
		$clause = false;
		foreach ( self::CLAUSE_BREAKS as $break ) {
			$position = mb_strrpos( $cut, $break );
			if ( false !== $position && $position >= $max / 2 && ( false === $clause || $position > $clause ) ) {
				$clause = $position;
			}
		}
		if ( false !== $clause ) {
			$cut = mb_substr( $cut, 0, $clause );
		}

		// Do not leave the sentence hanging on an article, a preposition or a conjunction.
		do {
			$cut     = rtrim( $cut, " ,;:-–—" );
			$last    = mb_strrpos( $cut, ' ' );
			$word    = mb_strtolower( false === $last ? $cut : mb_substr( $cut, $last + 1 ) );
			$dangles = false !== $last && in_array( $word, self::DANGLING_WORDS, true );
			if ( $dangles ) {
				$cut = mb_substr( $cut, 0, $last );
			}
		} while ( $dangles );

		return $cut;
	}
}
