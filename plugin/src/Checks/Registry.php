<?php
/**
 * Every check GEO Tool knows.
 *
 * @package GeoTool
 */

namespace MDIA\Checks;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/**
	 * @return array<string, Check> Checks by id.
	 */
	public static function all(): array {
		$checks = array(
			new Post\Test_Page(), // First: the other page checks stand aside for a page that has to go.
			new Post\Metadesc(),
			new Post\Seo_Title(),
			new Post\Excerpt(),
			new Post\Headings(),
			new Post\Placeholder_Content(),
			new Post\Acf_Empty(),
			new Post\Featured_Image(),
			new Post\Noindex(),
			new Term_Description(),
			new Author_Check(
				'user.bio',
				__( 'Author biography', 'geo-tool' ),
				__( 'Search engines and AI assistants weigh who wrote a text. An author with no biography is an unknown.', 'geo-tool' ),
				static fn( \WP_User $user ): bool => '' !== trim( (string) $user->description )
			),
			new Author_Check(
				'user.display_name',
				__( 'Author public name', 'geo-tool' ),
				__( 'The name shown on articles should be a real name, not the login name.', 'geo-tool' ),
				static fn( \WP_User $user ): bool => '' !== trim( $user->display_name ) && 0 !== strcasecmp( $user->display_name, $user->user_login ) && 'admin' !== strtolower( $user->display_name )
			),
		);
		$checks = array_merge( $checks, Site\Catalogue::checks() );

		$by_id = array();
		foreach ( $checks as $check ) {
			$by_id[ $check->id() ] = $check;
		}

		/**
		 * Filters the SEO/GEO checks. Add instances of MDIA\Checks\Check keyed by id.
		 *
		 * @param array<string, Check> $checks Checks by id.
		 */
		$filtered = (array) apply_filters( 'mdia_checks', $by_id );

		return array_filter( $filtered, static fn( $check ): bool => $check instanceof Check );
	}

	public static function get( string $id ): ?Check {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * @return Check[]
	 */
	public static function for_type( string $target_type ): array {
		return array_values( array_filter( self::all(), static fn( Check $check ): bool => $check->target_type() === $target_type ) );
	}
}
