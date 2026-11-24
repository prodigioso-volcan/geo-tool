<?php
/**
 * Used when the site has no supported SEO plugin: checks that need one report it.
 *
 * @package GeoTool
 */

namespace MDIA\Seo;

defined( 'ABSPATH' ) || exit;

final class None implements Adapter {

	public function is_active(): bool {
		return false;
	}

	public function name(): string {
		return '';
	}

	public function post_description( int $post_id ): string {
		return '';
	}

	public function post_raw_description( int $post_id ): string {
		return '';
	}

	public function set_post_description( int $post_id, string $text ): bool {
		return false;
	}

	public function post_title( int $post_id ): string {
		return trim( get_the_title( $post_id ) . ' - ' . get_bloginfo( 'name' ), ' -' );
	}

	public function post_raw_title( int $post_id ): string {
		return '';
	}

	public function set_post_title( int $post_id, string $text, bool $with_site_name = true ): bool {
		return false;
	}

	public function restore_post_description( int $post_id, string $raw ): bool {
		return false;
	}

	public function restore_post_title( int $post_id, string $raw ): bool {
		return false;
	}

	public function watched_meta(): array {
		return array();
	}

	public function is_post_noindex( int $post_id ): bool {
		return false;
	}

	public function is_type_noindex( string $post_type ): bool {
		return false;
	}

	public function term_description_meta( int $term_id, string $taxonomy ): string {
		return '';
	}

	public function term_raw_description_meta( int $term_id, string $taxonomy ): string {
		return '';
	}

	public function set_term_description_meta( int $term_id, string $taxonomy, string $text ): bool {
		return false;
	}

	public function site( string $key ) {
		return null;
	}

	public function settings_url( string $section ): string {
		return '';
	}
}
