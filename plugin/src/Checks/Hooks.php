<?php
/**
 * Re-evaluates a target when someone changes it.
 *
 * @package GeoTool
 */

namespace MDIA\Checks;

use MDIA\Content\Page_Text;
use MDIA\Data\Findings;

defined( 'ABSPATH' ) || exit;

final class Hooks {


	private static bool $busy = false;

	public static function register(): void {
		add_action( 'save_post', array( self::class, 'on_save_post' ), 30, 2 );
		add_action( 'deleted_post', array( self::class, 'on_deleted_post' ), 30 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'on_post_meta' ), 30, 3 );
		}
		add_action( 'created_term', array( self::class, 'on_term' ), 30 );
		add_action( 'edited_term', array( self::class, 'on_term' ), 30 );
		add_action( 'delete_term', static fn( $term_id ) => ( new Findings() )->delete_for_target( 'term', (int) $term_id ), 30 );
		add_action( 'profile_update', array( self::class, 'on_user' ), 30 );
		add_action( 'user_register', array( self::class, 'on_user' ), 30 );
		add_action( 'deleted_user', static fn( $user_id ) => ( new Findings() )->delete_for_target( 'user', (int) $user_id ), 30 );
	}

	public static function on_save_post( $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'attachment' === $post->post_type ) {
			return;
		}
		self::run(
			static function () use ( $post_id, $post ): void {
				Page_Text::flush();
				Engine::evaluate( 'post', (int) $post_id );
				// Publishing can turn someone into an author, with their own checks.
				if ( $post->post_author ) {
					Engine::evaluate( 'user', (int) $post->post_author );
				}
			}
		);
	}

	public static function on_deleted_post( $post_id ): void {
		( new Findings() )->delete_for_target( 'post', (int) $post_id );
	}

	public static function on_post_meta( $meta_id, $object_id, $meta_key ): void {
		if ( '_thumbnail_id' === $meta_key || in_array( $meta_key, \MDIA\Seo\Seo::adapter()->watched_meta(), true ) ) {
			self::run( static fn() => Engine::evaluate( 'post', (int) $object_id ) );
		}
	}

	public static function on_term( $term_id ): void {
		self::run( static fn() => Engine::evaluate( 'term', (int) $term_id ) );
	}

	public static function on_user( $user_id ): void {
		self::run( static fn() => Engine::evaluate( 'user', (int) $user_id ) );
	}

	/**
	 * Checks write metadata of their own; never evaluate while evaluating. Also used by the
	 * review actions, which write a field and then record the decision themselves.
	 */
	public static function run( callable $work ): void {
		if ( self::$busy ) {
			return;
		}
		self::$busy = true;
		try {
			$work();
		} finally {
			self::$busy = false;
		}
	}
}
