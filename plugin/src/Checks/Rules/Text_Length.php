<?php
/**
 * Length and repetition rules for short texts (titles, descriptions). Pure.
 *
 * @package GeoTool
 */

namespace MDIA\Checks\Rules;

final class Text_Length {

	/**
	 * @return string 'missing', 'too_short', 'too_long' or '' when the text fits.
	 */
	public static function classify( string $text, int $min, int $max ): string {
		$length = mb_strlen( trim( $text ) );

		if ( 0 === $length ) {
			return 'missing';
		}
		if ( $length < $min ) {
			return 'too_short';
		}
		if ( $length > $max ) {
			return 'too_long';
		}
		return '';
	}

	/**
	 * Value used to find the same text on two pages, ignoring case, spacing and punctuation.
	 */
	public static function fingerprint( string $text ): string {
		$normal = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( $text ) );
		$normal = trim( $normal );

		return '' === $normal ? '' : md5( $normal );
	}
}
