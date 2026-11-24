<?php
/**
 * Which posts, terms and authors the checks apply to.
 *
 * @package GeoTool
 */

namespace MDIA\Checks;

use MDIA\Seo\Seo;

defined( 'ABSPATH' ) || exit;

final class Targets {

	public const IN_SCOPE = 'in';
	public const HIDDEN   = 'hidden';
	public const OUT      = 'out';

	/**
	 * @return string[] Public post types with their own pages.
	 */
	public static function post_types(): array {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );

		/**
		 * Filters the post types the SEO/GEO checks apply to.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( array_unique( (array) apply_filters( 'mdia_checked_post_types', $types ) ) );
	}

	/**
	 * @return string[] Public taxonomies with archive pages.
	 */
	public static function taxonomies(): array {
		return array_values( array_diff( get_taxonomies( array( 'public' => true ) ), array( 'post_format' ) ) );
	}

	/**
	 * IN_SCOPE: published and visible to search engines. HIDDEN: published but this page is
	 * marked noindex. OUT: everything else, including whole post types hidden from search.
	 */
	public static function post_scope( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return self::OUT;
		}

		$seo = Seo::adapter();
		if ( $seo->is_type_noindex( $post->post_type ) ) {
			return self::OUT;
		}
		return $seo->is_post_noindex( $post_id ) ? self::HIDDEN : self::IN_SCOPE;
	}

	public static function count_posts(): int {
		global $wpdb;
		$types = self::post_types();
		if ( ! $types ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ($in)", $types ) );
	}

	/**
	 * @return int[]
	 */
	public static function post_ids_after( int $after, int $limit ): array {
		global $wpdb;
		$types = self::post_types();
		if ( ! $types ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type IN ($in) ORDER BY ID ASC LIMIT %d", array_merge( array( $after ), $types, array( $limit ) ) ) ) );
	}

	/**
	 * Terms of public taxonomies that have content.
	 *
	 * @return int[]
	 */
	public static function term_ids_after( int $after, int $limit ): array {
		global $wpdb;
		$taxonomies = self::taxonomies();
		if ( ! $taxonomies ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_id > %d AND count > 0 AND taxonomy IN ($in) ORDER BY term_id ASC LIMIT %d", array_merge( array( $after ), $taxonomies, array( $limit ) ) ) ) );
	}

	/**
	 * People with published content.
	 *
	 * @return int[]
	 */
	public static function author_ids_after( int $after, int $limit ): array {
		global $wpdb;
		$types = self::post_types();
		if ( ! $types ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_author > %d AND post_status = 'publish' AND post_type IN ($in) ORDER BY post_author ASC LIMIT %d", array_merge( array( $after ), $types, array( $limit ) ) ) ) );
	}

	public static function is_author( int $user_id ): bool {
		return (bool) self::author_ids_after( $user_id - 1, 1 ) && self::author_ids_after( $user_id - 1, 1 )[0] === $user_id;
	}
}
