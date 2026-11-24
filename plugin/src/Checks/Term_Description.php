<?php
namespace MDIA\Checks;

use MDIA\Seo\Seo;

defined( 'ABSPATH' ) || exit;

final class Term_Description extends Check {

	private const WROTE_SEO = '_mdia_wrote_seo_desc';

	public function id(): string {
		return 'term.description';
	}

	public function target_type(): string {
		return 'term';
	}

	public function fix(): string {
		return 'ai';
	}

	public function label(): string {
		return __( 'Category or tag description', 'geo-tool' );
	}

	public function why(): string {
		return __( 'Category and tag pages are indexed too. Without a description they have no summary to show.', 'geo-tool' );
	}

	public function evaluate( int $target_id ): ?array {
		$term = get_term( $target_id );
		if ( ! $term || is_wp_error( $term ) || $term->count < 1 || ! in_array( $term->taxonomy, Targets::taxonomies(), true ) ) {
			return null;
		}

		$description = trim( wp_strip_all_tags( $term->description ) );
		if ( '' === $description ) {
			return array(
				array(
					'value'  => '',
					'detail' => 'missing',
				),
			);
		}

		$seo = Seo::adapter();
		if ( $seo->is_active() && '' === $seo->term_description_meta( $target_id, $term->taxonomy ) ) {
			// The term has a description, but the SEO plugin would publish none for its archive.
			return array(
				array(
					'value'  => $description,
					'detail' => 'meta_missing',
				),
			);
		}
		return array();
	}

	public function current( int $target_id ): string {
		$term = get_term( $target_id );
		return $term && ! is_wp_error( $term ) ? (string) $term->description : '';
	}

	/**
	 * Fills whatever is empty: the term description, the SEO plugin's field, or both.
	 */
	public function written( int $target_id ): string {
		$term = get_term( $target_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		return $term->description . "\n" . Seo::adapter()->term_description_meta( $target_id, $term->taxonomy );
	}

	public function apply( int $target_id, string $text ): bool {
		$term = get_term( $target_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		$seo = Seo::adapter();
		// A mark left by an earlier approval says nothing about this one.
		delete_term_meta( $target_id, self::WROTE_SEO );
		if ( $seo->is_active() && '' === $seo->term_description_meta( $target_id, $term->taxonomy ) ) {
			$seo->set_term_description_meta( $target_id, $term->taxonomy, $text );
			// Remembered so that undo removes only what was written here.
			update_term_meta( $target_id, self::WROTE_SEO, 1 );
		}
		if ( '' === trim( wp_strip_all_tags( $term->description ) ) ) {
			$saved = wp_update_term( $target_id, $term->taxonomy, array( 'description' => $text ) );
			return ! is_wp_error( $saved );
		}
		return true;
	}

	public function restore( int $target_id, string $value ): bool {
		$term = get_term( $target_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}
		if ( get_term_meta( $target_id, self::WROTE_SEO, true ) ) {
			Seo::adapter()->set_term_description_meta( $target_id, $term->taxonomy, '' );
			delete_term_meta( $target_id, self::WROTE_SEO );
		}
		// Nothing to put back when only the SEO field was written: the description is not saved again.
		if ( (string) $term->description === $value ) {
			return true;
		}
		return ! is_wp_error( wp_update_term( $target_id, $term->taxonomy, array( 'description' => $value ) ) );
	}

	public function is_human_value( int $target_id ): bool {
		return '' !== trim( wp_strip_all_tags( $this->current( $target_id ) ) );
	}

	public function limits( int $target_id ): array {
		return array(
			'min'  => 70,
			'max'  => 155,
			'unit' => 'chars',
		);
	}

	public function text_kind(): string {
		return 'term_description';
	}
}
