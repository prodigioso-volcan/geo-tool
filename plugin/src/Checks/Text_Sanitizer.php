<?php
/**
 * Makes an AI answer usable as a short text field and measures it. Pure.
 *
 * @package GeoTool
 */

namespace MDIA\Checks;

use MDIA\AI\Response_Sanitizer;

final class Text_Sanitizer {

	/**
	 * @return string|null Plain one-line text; null when the answer cannot be used.
	 */
	public static function clean( string $raw ): ?string {
		return Response_Sanitizer::normalize( $raw );
	}

	public static function size( string $text, string $unit ): int {
		return 'words' === $unit ? count( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) ) : mb_strlen( $text );
	}

	/**
	 * A little short is acceptable (a person reviews it); too long is not.
	 *
	 * @param array{min:int,max:int,unit:string} $limits
	 */
	public static function fits( string $text, array $limits ): bool {
		$size = self::size( $text, $limits['unit'] );
		return $size <= $limits['max'] && $size >= (int) floor( $limits['min'] * 0.6 );
	}

	/**
	 * @param array{min:int,max:int,unit:string} $limits
	 */
	public static function cut( string $text, array $limits ): string {
		if ( 'words' === $limits['unit'] ) {
			$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
			return count( $words ) <= $limits['max'] ? $text : rtrim( implode( ' ', array_slice( $words, 0, $limits['max'] ) ), ' ,;:' );
		}
		return Response_Sanitizer::cut( $text, $limits['max'] );
	}
}
