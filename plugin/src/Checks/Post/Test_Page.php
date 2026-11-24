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
		return '' === $detail ? array() : array( $this->fail( $detail ) );
	}

	/**
	 * @return string 'default_content', 'test_title' or '' when the page looks real.
	 */
	public static function detect( \WP_Post $post ): string {
		if ( Placeholder::is_default_content( $post->post_name, $post->post_title ) ) {
			return 'default_content';
		}
		return Placeholder::is_test_title( $post->post_title ) ? 'test_title' : '';
	}

	/**
	 * Whether the page is to be deleted rather than completed: it looks like a test and
	 * nobody has said it is real content.
	 */
	public static function is_pending( \WP_Post $post ): bool {
		if ( '' === self::detect( $post ) ) {
			return false;
		}
		$row = ( new Findings() )->find( self::ID, 'post', (int) $post->ID, '' );
		return ! $row || 'waived' !== $row['status'];
	}
}
