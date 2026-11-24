<?php
/**
 * Classifies an alt text without AI. Pure: no WordPress dependencies.
 *
 * @package GeoTool
 */

namespace MDIA\Audit;

final class Rules {

	public const MISSING   = 'missing';
	public const FILENAME  = 'filename';
	public const GENERIC   = 'generic';
	public const TOO_SHORT = 'too_short';
	public const TOO_LONG  = 'too_long';
	public const DUPLICATE = 'duplicate';

	private const EXTENSIONS = 'jpe?g|png|gif|webp|avif|svg|bmp|tiff?|heic';

	private const CAMERA_PREFIXES = 'img|dsc|dscn|dscf|pxl|mvimg|screenshot|captura|image|imagen|photo|foto|pic|whatsapp';

	/**
	 * @return array{min_length:int,max_length:int,duplicate_threshold:int,generic_terms:string[]}
	 */
	public static function default_config(): array {
		return array(
			'min_length'          => 5,
			'max_length'          => 150,
			'duplicate_threshold' => 5,
			'generic_terms'       => array(
				'imagen', 'image', 'foto', 'fotografía', 'photo', 'picture', 'img', 'logo', 'banner', 'icono', 'icon',
				'gráfico', 'graphic', 'sin título', 'untitled', 'placeholder', 'captura', 'screenshot',
			),
		);
	}

	/**
	 * @param string $alt            Alt text to classify.
	 * @param string $filename       File name of the image, used to detect alts copied from it.
	 * @param int    $same_alt_count How many images share this exact alt text.
	 * @param array  $config         Overrides for default_config().
	 * @return string[] Issue codes in canonical order; empty when the alt is fine.
	 */
	public static function classify( string $alt, string $filename = '', int $same_alt_count = 1, array $config = array() ): array {
		$config = array_merge( self::default_config(), $config );
		$alt    = trim( $alt );

		if ( '' === $alt ) {
			return array( self::MISSING );
		}

		$issues  = array();
		$generic = self::is_generic( $alt, (array) $config['generic_terms'] );
		$length  = mb_strlen( $alt );

		if ( self::looks_like_filename( $alt, $filename ) ) {
			$issues[] = self::FILENAME;
		}
		if ( $generic ) {
			$issues[] = self::GENERIC;
		}
		if ( ! $generic && $length < (int) $config['min_length'] ) {
			$issues[] = self::TOO_SHORT;
		}
		if ( $length > (int) $config['max_length'] ) {
			$issues[] = self::TOO_LONG;
		}
		if ( $same_alt_count >= (int) $config['duplicate_threshold'] ) {
			$issues[] = self::DUPLICATE;
		}

		return $issues;
	}

	private static function looks_like_filename( string $alt, string $filename ): bool {
		if ( preg_match( '/\.(' . self::EXTENSIONS . ')$/i', $alt ) ) {
			return true;
		}

		if ( '' !== $filename ) {
			$base = preg_replace( '/\.[a-z0-9]{2,5}$/i', '', basename( $filename ) );
			if ( '' !== $base && self::normalize( $base ) === self::normalize( $alt ) ) {
				return true;
			}
		}

		if ( preg_match( '/\s/u', $alt ) ) {
			return false;
		}

		return str_contains( $alt, '_' )
			|| preg_match_all( '/[-_]/', $alt ) >= 2
			|| 1 === preg_match( '/^(' . self::CAMERA_PREFIXES . ')[-_]?\d+/i', $alt );
	}

	/**
	 * @param string[] $terms
	 */
	private static function is_generic( string $alt, array $terms ): bool {
		$clean = mb_strtolower( rtrim( $alt, " \t.!:;," ) );
		return in_array( $clean, array_map( 'mb_strtolower', $terms ), true );
	}

	private static function normalize( string $text ): string {
		return trim( preg_replace( '/[-_\s]+/u', ' ', mb_strtolower( $text ) ) );
	}
}
