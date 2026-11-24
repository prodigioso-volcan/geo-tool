<?php
namespace MDIA\Checks\Post;

use MDIA\Checks\Rules\Text_Length;
use MDIA\Seo\Seo;

defined( 'ABSPATH' ) || exit;

final class Seo_Title extends Post_Check {

	public const MAX = 60;

	private const FINGERPRINT = '_mdia_fp_title';

	public function id(): string {
		return 'post.seo_title';
	}

	public function fix(): string {
		return 'ai';
	}

	public function label(): string {
		return __( 'Title shown in search results', 'geo-tool' );
	}

	public function why(): string {
		return __( 'Search engines cut titles longer than about 60 characters, and two pages with the same title compete with each other.', 'geo-tool' );
	}

	protected function check( \WP_Post $post ): ?array {
		$seo = Seo::adapter();
		if ( ! $seo->is_active() ) {
			return null;
		}

		$title       = $seo->post_title( $post->ID );
		$fingerprint = Text_Length::fingerprint( $title );
		update_post_meta( $post->ID, self::FINGERPRINT, $fingerprint );

		if ( mb_strlen( self::own_part( $title ) ) > self::MAX ) {
			return array( $this->fail( 'too_long', $title ) );
		}
		if ( $this->others_with( self::FINGERPRINT, $fingerprint, $post->ID ) > 0 ) {
			return array( $this->fail( 'duplicate', $title ) );
		}
		return array();
	}

	/**
	 * Site names longer than this leave no room for the page in a title. That is one problem
	 * of the site (reported by its own check), not one of every page.
	 */
	public const LONG_SITE_NAME = 30;

	public static function has_long_site_name(): bool {
		return mb_strlen( (string) Seo::adapter()->site( 'title_site_name' ) ) > self::LONG_SITE_NAME;
	}

	/**
	 * @return string The title without the site name, when that name is too long to judge pages by it.
	 */
	private static function own_part( string $title ): string {
		$site = (string) Seo::adapter()->site( 'title_site_name' );
		if ( ! self::has_long_site_name() || ! str_contains( $title, $site ) ) {
			return $title;
		}
		return (string) preg_replace( '/^[\s\-–—|·:»«>]+|[\s\-–—|·:»«>]+$/u', '', str_replace( $site, '', $title ) );
	}

	public function current( int $target_id ): string {
		return Seo::adapter()->post_raw_title( $target_id );
	}

	/**
	 * The approved text is the page's own part; the site name is kept.
	 */
	public function apply( int $target_id, string $text ): bool {
		$seo = Seo::adapter();
		if ( ! $seo->set_post_title( $target_id, $text ) ) {
			return false;
		}
		// If it does not fit even so, the page's own title goes alone.
		if ( ! self::has_long_site_name() && mb_strlen( $seo->post_title( $target_id ) ) > self::MAX ) {
			$seo->set_post_title( $target_id, $text, false );
		}
		return true;
	}

	/**
	 * Undo restores the stored field as it was, variables included.
	 */
	public function restore( int $target_id, string $value ): bool {
		return Seo::adapter()->restore_post_title( $target_id, $value );
	}

	public function is_human_value( int $target_id ): bool {
		return '' !== Seo::adapter()->post_raw_title( $target_id );
	}

	/**
	 * Room left for the page's own part once the separator and the site name are added.
	 */
	public function limits( int $target_id ): array {
		$suffix = self::has_long_site_name() ? 0 : mb_strlen( ' - ' . Seo::adapter()->site( 'title_site_name' ) );
		return array(
			'min'  => 0,
			'max'  => max( 25, self::MAX - $suffix ),
			'unit' => 'chars',
		);
	}

	public function text_kind(): string {
		return 'seo_title';
	}
}
