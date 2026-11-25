<?php
/**
 * A page that was only ever a test: the sample content WordPress ships with, or a page
 * named "Test", "Prueba 2"... It is not content to complete, it is a page to delete.
 *
 * @package GeoTool
 */

namespace MDIA\Checks\Post;

use MDIA\Checks\Rules\Placeholder;
use MDIA\Checks\Targets;
use MDIA\Data\Findings;

defined( 'ABSPATH' ) || exit;

final class Test_Page extends Post_Check {

	public const ID = 'post.test_page';

	public function id(): string {
		return self::ID;
	}

	public function fix(): string {
		return 'manual';
	}

	public function severity(): string {
		return self::REQUIRED;
	}

	public function label(): string {
		return __( 'Test page to delete', 'geo-tool' );
	}

	public function why(): string {
		return __( 'Test pages and the sample content WordPress ships with must not be public on a finished site. Delete it or, if it is real content, accept it as it is.', 'geo-tool' );
	}

	/**
	 * Published is enough: a test page hidden from search engines is still public.
	 */
	public function evaluate( int $target_id ): ?array {
		if ( Targets::OUT === Targets::post_scope( $target_id ) ) {
			return null;
		}
		return $this->check( get_post( $target_id ) );
	}

	protected function check( \WP_Post $post ): ?array {
		$detail = self::detect( $post );
		if ( '' === $detail ) {
			return array();
		}
		// A title a real page could have is a suggestion, not something that blocks the delivery.
		return array( $this->fail( $detail, '', '', self::MAYBE === $detail ? self::RECOMMENDED : self::REQUIRED ) );
	}

	public const MAYBE = 'maybe_test';

	/**
	 * @return string 'default_content', 'test_title', 'maybe_test' or '' when the page looks real.
	 */
	public static function detect( \WP_Post $post ): string {
		if ( self::is_special( (int) $post->ID ) ) {
			return '';
		}
		// The sample page renamed and rewritten keeps its old address; the title is what tells.
		if ( Placeholder::is_default_title( $post->post_title ) ) {
			return 'default_content';
		}
		$kind = Placeholder::test_title( $post->post_title );
		if ( 'sure' === $kind ) {
			return 'test_title';
		}
		return 'maybe' === $kind ? self::MAYBE : '';
	}

	/**
	 * Pages the site is built on (home, posts page, privacy policy, shop pages) are never tests,
	 * whatever they are called.
	 */
	public static function is_special( int $post_id ): bool {
		$special = array(
			(int) get_option( 'page_on_front' ),
			(int) get_option( 'page_for_posts' ),
			(int) get_option( 'wp_page_for_privacy_policy' ),
		);
		foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' ) as $page ) {
			$special[] = (int) get_option( 'woocommerce_' . $page . '_page_id' );
		}

		// The home page of each language, and so on.
		foreach ( array_filter( $special ) as $id ) {
			$special = array_merge( $special, \MDIA\I18n\Languages::translations_of( (int) $id ) );
		}

		/**
		 * Filters the ids of pages that are never treated as test pages.
		 *
		 * @param int[] $special Page ids.
		 */
		return in_array( $post_id, array_map( 'intval', (array) apply_filters( 'mdia_special_pages', array_filter( $special ) ) ), true );
	}

	/**
	 * Whether the page is to be deleted rather than completed: it can only be a test and
	 * nobody has said it is real content. The other checks stand aside for it.
	 */
	public static function is_pending( \WP_Post $post ): bool {
		if ( ! in_array( self::detect( $post ), array( 'default_content', 'test_title' ), true ) ) {
			return false;
		}
		$row = ( new Findings() )->find( self::ID, 'post', (int) $post->ID, '' );
		return ! $row || 'waived' !== $row['status'];
	}
}
