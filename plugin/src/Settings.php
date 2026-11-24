<?php
/**
 * Plugin settings stored in a single option.
 *
 * @package GeoTool
 */

namespace MDIA;

defined( 'ABSPATH' ) || exit;

final class Settings {

	private const OPTION = 'mdia_settings';

	public static function defaults(): array {
		return array(
			'auto_generate_new' => true,
			'daily_limit'       => 200,
			'wizard_done'       => false,
			// Accepted once by a manager: page text may be sent to the AI provider to propose metadata.
			'text_consent'      => false,
		);
	}

	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$values = array_merge( self::defaults(), array_intersect_key( is_array( $stored ) ? $stored : array(), self::defaults() ) );

		$values['auto_generate_new'] = (bool) $values['auto_generate_new'];
		$values['wizard_done']       = (bool) $values['wizard_done'];
		$values['text_consent']      = (bool) $values['text_consent'];
		$values['daily_limit']       = max( 1, (int) $values['daily_limit'] );

		return $values;
	}

	/**
	 * @return mixed|null Null for unknown keys.
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	public static function update( array $values ): void {
		$merged = array_merge( self::all(), array_intersect_key( $values, self::defaults() ) );
		update_option( self::OPTION, $merged, false );
		delete_transient( 'mdia_status' );
	}
}
