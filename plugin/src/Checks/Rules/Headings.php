<?php
/**
 * Heading structure of a page body. Pure.
 *
 * @package GeoTool
 */

namespace MDIA\Checks\Rules;

final class Headings {

	public const SEVERAL_H1 = 'several_h1';
	public const SKIPPED_LEVEL = 'skipped_level';

	/**
	 * @return array<int, array{0:int,1:string}> Level and text of each non-empty heading, in order.
	 */
	public static function outline( string $html ): array {
		$outline = array();
		if ( preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $match[2] ), ENT_QUOTES, 'UTF-8' ) ) );
				if ( '' !== $text ) {
					$outline[] = array( (int) $match[1], $text );
				}
			}
		}
		return $outline;
	}

	/**
	 * A page has one H1. Whether the theme prints it or a block of the content does cannot
	 * be known from here, so a single H1 in the body is accepted; more than one never is.
	 * Levels must not jump more than one step down.
	 *
	 * @return string[] Issue codes; empty when the structure is fine.
	 */
	public static function issues( string $html ): array {
		$issues   = array();
		$previous = 1;
		$h1       = 0;

		foreach ( self::outline( $html ) as $heading ) {
			$level = $heading[0];
			if ( 1 === $level ) {
				++$h1;
				$previous = 1;
				continue;
			}
			if ( $level > $previous + 1 ) {
				$issues[ self::SKIPPED_LEVEL ] = true;
			}
			$previous = $level;
		}
		if ( $h1 > 1 ) {
			$issues[ self::SEVERAL_H1 ] = true;
		}

		return array_values( array_intersect( array( self::SEVERAL_H1, self::SKIPPED_LEVEL ), array_keys( $issues ) ) );
	}
}
