<?php
/**
 * Rank Math. The only class that talks to Rank Math.
 *
 * Values are read from its stored fields and settings, so a field written in this
 * request is read back correctly.
 *
 * @package GeoTool
 */

namespace MDIA\Seo;

defined( 'ABSPATH' ) || exit;

final class Rank_Math implements Adapter {

	private const TITLE_SUFFIX = ' %sep% %sitename%';

	private const DEFAULT_TITLE = '%title% %sep% %sitename%';

	/** Variables Rank Math fills with the first lines of the content when nobody wrote a text. */
	private const AUTOMATIC = array( '%excerpt%', '%excerpt_only%', '%seo_description%' );

	private const MODULES = array(
		'sitemap'  => 'sitemap',
		'schema'   => 'rich-snippet',
		'llms_txt' => 'llms-txt',
	);

	private const SITE_STRINGS = array(
		'organization_name' => 'knowledgegraph_name',
		'organization_logo' => 'knowledgegraph_logo',
		'social_image'      => 'open_graph_image',
		'home_description'  => 'homepage_description',
	);

	private const SCREENS = array(
		'homepage'            => 'rank-math-options-titles#setting-panel-homepage',
		'site-representation' => 'rank-math-options-titles#setting-panel-local',
		'site-basics'         => 'rank-math-options-titles#setting-panel-global',
		'site-features'       => 'rank-math',
		'robots'              => 'rank-math-options-general#setting-panel-robots',
	);

	/**
	 * Rank Math publishes nothing until its own setup screen is completed or skipped;
	 * until then it is not a working SEO plugin.
	 */
	public static function available(): bool {
		return class_exists( '\RankMath\Helper' ) && function_exists( 'rank_math' ) && null !== rank_math()->variables;
	}

	public function is_active(): bool {
		return self::available();
	}

	public function name(): string {
		return 'Rank Math';
	}

	public function watched_meta(): array {
		return array( 'rank_math_description', 'rank_math_title', 'rank_math_robots' );
	}

	/**
	 * Rank Math publishes the first lines of the content when nobody wrote a description.
	 * That is not a written description, so it does not count here.
	 */
	public function post_description( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$raw = $this->post_raw_description( $post_id );
		if ( '' !== $raw ) {
			return $this->render( $raw, $post );
		}
		if ( '' !== trim( $post->post_excerpt ) ) {
			return $this->clean( $post->post_excerpt );
		}
		$template = (string) $this->setting( 'pt_' . $post->post_type . '_description', '' );
		return self::is_automatic( $template ) ? '' : $this->render( $template, $post );
	}

	public function post_raw_description( int $post_id ): string {
		return trim( (string) get_post_meta( $post_id, 'rank_math_description', true ) );
	}

	public function set_post_description( int $post_id, string $text ): bool {
		return $this->set_meta( 'rank_math_description', self::literal( $text ), $post_id );
	}

	public function post_title( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$template = $this->post_raw_title( $post_id );
		if ( '' === $template ) {
			$template = (string) $this->setting( 'pt_' . $post->post_type . '_title', self::DEFAULT_TITLE );
		}
		return $this->render( $template, $post );
	}

	public function post_raw_title( int $post_id ): string {
		return trim( (string) get_post_meta( $post_id, 'rank_math_title', true ) );
	}

	public function set_post_title( int $post_id, string $text, bool $with_site_name = true ): bool {
		$text = self::literal( trim( $text ) );
		// A type whose titles do not carry the site name gets none added.
		$post     = get_post( $post_id );
		$template = $post ? (string) $this->setting( 'pt_' . $post->post_type . '_title', self::DEFAULT_TITLE ) : '';
		$suffix   = $with_site_name && str_contains( $template, '%sitename%' ) ? self::TITLE_SUFFIX : '';
		return $this->set_meta( 'rank_math_title', '' === $text ? $text : $text . $suffix, $post_id );
	}

	public function restore_post_description( int $post_id, string $raw ): bool {
		return $this->set_meta( 'rank_math_description', $raw, $post_id );
	}

	public function restore_post_title( int $post_id, string $raw ): bool {
		return $this->set_meta( 'rank_math_title', $raw, $post_id );
	}

	public function is_post_noindex( int $post_id ): bool {
		return in_array( 'noindex', (array) get_post_meta( $post_id, 'rank_math_robots', true ), true );
	}

	public function is_type_noindex( string $post_type ): bool {
		// Rank Math stores its switches as the words "on" and "off".
		return in_array( $this->setting( 'pt_' . $post_type . '_custom_robots', 'off' ), array( 'on', true, 1, '1' ), true )
			&& in_array( 'noindex', (array) $this->setting( 'pt_' . $post_type . '_robots', array() ), true );
	}

	public function term_description_meta( int $term_id, string $taxonomy ): string {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		$raw = trim( (string) get_term_meta( $term_id, 'rank_math_description', true ) );
		if ( '' !== $raw ) {
			return $this->render( $raw, $term );
		}
		$template = (string) $this->setting( 'tax_' . $taxonomy . '_description', '' );
		// Rank Math's default: the description of the term itself is what gets published.
		if ( str_contains( $template, '%term_description%' ) ) {
			return $this->clean( (string) $term->description );
		}
		return self::is_automatic( $template ) ? '' : $this->render( $template, $term );
	}

	public function term_raw_description_meta( int $term_id, string $taxonomy ): string {
		return trim( (string) get_term_meta( $term_id, 'rank_math_description', true ) );
	}

	public function set_term_description_meta( int $term_id, string $taxonomy, string $text ): bool {
		$text = sanitize_text_field( self::literal( $text ) );
		if ( '' === $text ) {
			delete_term_meta( $term_id, 'rank_math_description' );
		} else {
			update_term_meta( $term_id, 'rank_math_description', $text );
		}
		return true;
	}

	public function site( string $key ) {
		if ( isset( self::MODULES[ $key ] ) ) {
			return in_array( self::MODULES[ $key ], (array) get_option( 'rank_math_modules', array() ), true );
		}
		if ( isset( self::SITE_STRINGS[ $key ] ) ) {
			return trim( (string) $this->setting( self::SITE_STRINGS[ $key ], '' ) );
		}
		if ( 'title_site_name' === $key ) {
			return $this->render( '%sitename%', array() );
		}
		return null;
	}

	public function settings_url( string $section ): string {
		return admin_url( 'admin.php?page=' . ( self::SCREENS[ $section ] ?? 'rank-math-options-titles' ) );
	}

	/**
	 * @param mixed $fallback
	 * @return mixed
	 */
	private function setting( string $key, $fallback ) {
		$titles = (array) get_option( 'rank-math-options-titles', array() );
		return isset( $titles[ $key ] ) && '' !== $titles[ $key ] ? $titles[ $key ] : $fallback;
	}

	private static function is_automatic( string $template ): bool {
		if ( '' === trim( $template ) ) {
			return true;
		}
		foreach ( self::AUTOMATIC as $variable ) {
			if ( str_contains( $template, $variable ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A text written by a person or proposed by the AI is never a template: a word between
	 * percent signs must not be read by Rank Math as one of its variables.
	 */
	private static function literal( string $text ): string {
		// Same shape Rank Math looks for: %name%, %name(arguments)% or anything without spaces between two signs.
		$pattern = '/%(([a-z0-9_-]+)\(([^)]*)\)|[^\s%]+)%/iu';
		for ( $pass = 0; $pass < 10 && preg_match( $pattern, $text ); $pass++ ) {
			$text = (string) preg_replace( $pattern, '% $1 %', $text );
		}
		return $text;
	}

	private function set_meta( string $key, string $value, int $post_id ): bool {
		if ( ! get_post( $post_id ) ) {
			return false;
		}
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
		return true;
	}

	/**
	 * @param \WP_Post|\WP_Term|array $object
	 */
	private function render( string $template, $object ): string {
		if ( '' === trim( $template ) ) {
			return '';
		}
		// Rank Math registers its variables on screen hooks that never fire in cron or REST requests.
		if ( method_exists( rank_math()->variables, 'setup' ) ) {
			rank_math()->variables->setup();
		}
		return $this->clean( (string) \RankMath\Helper::replace_vars( $template, $object ) );
	}

	private function clean( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
	}
}
