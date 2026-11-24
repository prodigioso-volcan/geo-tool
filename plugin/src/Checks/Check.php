<?php
/**
 * One SEO or GEO good practice: how it is detected and, when possible, how it is fixed.
 *
 * @package GeoTool
 */

namespace MDIA\Checks;

defined( 'ABSPATH' ) || exit;

abstract class Check {

	public const REQUIRED    = 'required';
	public const RECOMMENDED = 'recommended';

	abstract public function id(): string;

	/**
	 * @return string 'post', 'term', 'user' or 'site'.
	 */
	abstract public function target_type(): string;

	/**
	 * @return string 'ai' (text proposed by AI), 'manual' (a person must do it) or 'setting' (a settings screen).
	 */
	abstract public function fix(): string;

	abstract public function label(): string;

	/**
	 * One sentence on why it matters, for the person who has to fix it.
	 */
	abstract public function why(): string;

	/**
	 * @return array<int, array{key?: string, value?: string, detail?: string, severity?: string}>|null
	 *         Failures found (empty = passes). Null when the check does not apply or cannot be evaluated.
	 */
	abstract public function evaluate( int $target_id ): ?array;

	public function severity(): string {
		return self::RECOMMENDED;
	}

	/**
	 * Where a person fixes it by hand.
	 */
	public function fix_url( int $target_id ): string {
		switch ( $this->target_type() ) {
			case 'post':
				return (string) get_edit_post_link( $target_id, 'raw' );
			case 'term':
				$term = get_term( $target_id );
				return $term && ! is_wp_error( $term ) ? (string) get_edit_term_link( $term, $term->taxonomy ) : '';
			case 'user':
				return (string) get_edit_user_link( $target_id );
		}
		return '';
	}

	public function can_edit( int $user_id, int $target_id ): bool {
		switch ( $this->target_type() ) {
			case 'post':
				return user_can( $user_id, 'edit_post', $target_id );
			case 'term':
				return user_can( $user_id, 'edit_term', $target_id );
			case 'user':
				return user_can( $user_id, 'edit_user', $target_id );
		}
		return user_can( $user_id, 'manage_options' );
	}

	/**
	 * Writes an approved text. Only for checks whose fix is 'ai'.
	 */
	public function apply( int $target_id, string $text ): bool {
		return false;
	}

	/**
	 * Current value of the field an 'ai' check fills, to keep it for undo.
	 */
	public function current( int $target_id ): string {
		return '';
	}

	/**
	 * Everything an approval writes, as one string. Undo compares it with what is there now,
	 * so it never overwrites something a person changed afterwards.
	 */
	public function written( int $target_id ): string {
		return $this->current( $target_id );
	}

	/**
	 * Whether what an approval recorded is still what is there.
	 */
	public function was_written( string $recorded, int $target_id ): bool {
		return $recorded === $this->written( $target_id );
	}

	/**
	 * Restores a previous value on undo. Defaults to applying it.
	 */
	public function restore( int $target_id, string $value ): bool {
		return $this->apply( $target_id, $value );
	}

	/**
	 * Whether the current value was typed by a person (and must not be replaced without them choosing).
	 */
	public function is_human_value( int $target_id ): bool {
		return false;
	}

	/**
	 * @return array{min: int, max: int, unit: string} Size of the text an 'ai' check asks for; unit is 'chars' or 'words'.
	 */
	public function limits( int $target_id ): array {
		return array(
			'min'  => 0,
			'max'  => 160,
			'unit' => 'chars',
		);
	}

	/**
	 * What the text is, for the AI instruction: 'meta_description', 'seo_title', 'excerpt' or 'term_description'.
	 */
	public function text_kind(): string {
		return '';
	}

	/**
	 * Human-readable name of the target, for lists.
	 */
	public function target_label( int $target_id ): string {
		switch ( $this->target_type() ) {
			case 'post':
				return (string) get_the_title( $target_id );
			case 'term':
				$term = get_term( $target_id );
				return $term && ! is_wp_error( $term ) ? (string) $term->name : '';
			case 'user':
				$user = get_userdata( $target_id );
				return $user ? (string) $user->display_name : '';
		}
		return (string) get_bloginfo( 'name' );
	}
}
