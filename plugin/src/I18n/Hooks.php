<?php
/**
 * Keeps up with languages given to a page after it was saved: an importer, WP-CLI or the
 * translation screens set them later, and what was recorded about the page has to follow.
 *
 * @package GeoTool
 */

namespace MDIA\I18n;

defined( 'ABSPATH' ) || exit;

final class Hooks {

	/** @var array<int,bool> Pages to look at again when the request ends. */
	private static array $pending = array();

	public static function register(): void {
		// WPML: a row of its translations table was written.
		add_action( 'wpml_translation_update', array( self::class, 'on_wpml_update' ) );
		// Polylang: the language is a taxonomy term of the page.
		add_action( 'set_object_terms', array( self::class, 'on_object_terms' ), 10, 4 );
	}

	public static function on_wpml_update( $update ): void {
		if ( is_array( $update ) && 'post' === ( $update['context'] ?? '' ) && ! empty( $update['element_id'] ) ) {
			self::recheck( (int) $update['element_id'] );
		}
	}

	public static function on_object_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( 'language' === $taxonomy ) {
			self::recheck( (int) $object_id );
		}
	}

	/**
	 * Once per page and per request, when everything else is done: several of these arrive in a row.
	 */
	private static function recheck( int $post_id ): void {
		if ( ! self::$pending ) {
			add_action( 'shutdown', array( self::class, 'run' ), 5 );
		}
		self::$pending[ $post_id ] = true;
	}

	public static function run(): void {
		$ids           = array_keys( self::$pending );
		self::$pending = array();

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			if ( 'attachment' === $post->post_type ) {
				( new \MDIA\Audit\Auditor() )->audit( $post_id );
				continue;
			}
			\MDIA\Audit\Hooks::on_save_post( $post_id, $post );
			\MDIA\Checks\Hooks::on_save_post( $post_id, $post );
		}
	}
}
