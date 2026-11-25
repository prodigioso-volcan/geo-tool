<?php
/**
 * The languages of the site. With WPML or Polylang, each page, term and image has its own;
 * without them, the site has one. The only class that talks to those plugins: to WPML through
 * its public hooks, to Polylang through its public functions.
 *
 * @package GeoTool
 */

namespace MDIA\I18n;

defined( 'ABSPATH' ) || exit;

final class Languages {

	/** @var array<string,array>|null Active languages, asked once per request: WPML builds its whole switcher to answer. */
	private static ?array $active = null;

	/** @var string|null 'wpml', 'polylang' or '' for a site with one language. */
	private static ?string $provider = null;

	/** @var int How many calls to in() are running one inside another. */
	private static int $depth = 0;

	public static function flush(): void {
		self::$active   = null;
		self::$provider = null;
	}

	/**
	 * @return array<string,string> Name of each active language by its code. Empty on a site with one language.
	 */
	public static function all(): array {
		$names = array();
		foreach ( self::active() as $code => $language ) {
			$names[ $code ] = (string) ( $language['native_name'] ?? $language['translated_name'] ?? $code );
		}
		return $names;
	}

	public static function is_multilingual(): bool {
		return array() !== self::active();
	}

	public static function name( string $code ): string {
		return '' === $code ? '' : ( self::all()[ $code ] ?? strtoupper( $code ) );
	}

	public static function default_code(): string {
		if ( ! self::is_multilingual() ) {
			return '';
		}
		return 'polylang' === self::provider() ? (string) pll_default_language() : (string) apply_filters( 'wpml_default_language', null );
	}

	/**
	 * @return string Language code of a page ('post', which includes images), or a term. '' when
	 *                the site has one language or the thing is not translated.
	 */
	public static function of( string $target_type, int $target_id ): string {
		return self::details( $target_type, $target_id )['code'];
	}

	/**
	 * @return string WordPress locale the texts of this page or term are written in.
	 */
	public static function locale_of( string $target_type, int $target_id ): string {
		$details = self::details( $target_type, $target_id );
		if ( '' !== $details['locale'] ) {
			return $details['locale'];
		}
		if ( '' !== $details['code'] ) {
			return $details['code'];
		}
		// What has no language of its own (the site, people, media that is not translated) is
		// written in the main language of the site, whatever language the admin is shown in.
		$default = self::default_code();
		if ( '' === $default ) {
			return get_locale();
		}
		$locale = (string) ( self::active()[ $default ]['default_locale'] ?? '' );
		return '' !== $locale ? $locale : $default;
	}

	/**
	 * Language recorded with a finding, or looked up for the ones recorded before the site had languages.
	 */
	public static function of_row( array $row ): string {
		$lang = (string) ( $row['lang'] ?? '' );
		return '' !== $lang ? $lang : self::of( (string) ( $row['target_type'] ?? '' ), (int) ( $row['target_id'] ?? 0 ) );
	}

	/**
	 * Runs some work as if the site were being visited in a language, and puts the previous one back.
	 *
	 * @param string   $code Language code. '' runs the work as things are.
	 * @param callable $work
	 * @return mixed What the work returns.
	 */
	public static function in( string $code, callable $work ) {
		if ( '' === $code || ! self::is_multilingual() ) {
			return $work();
		}

		if ( 'polylang' === self::provider() ) {
			return self::in_polylang( $code, $work );
		}

		$previous = apply_filters( 'wpml_current_language', null );
		if ( $previous === $code ) {
			return $work();
		}

		do_action( 'wpml_switch_language', $code );
		++self::$depth;
		try {
			return $work();
		} finally {
			--self::$depth;
			// The outermost call hands the request back to WPML as it was (null), so nothing
			// else in the request sees a language this plugin chose.
			do_action( 'wpml_switch_language', self::$depth > 0 && is_string( $previous ) && '' !== $previous ? $previous : null );
		}
	}

	/**
	 * Polylang keeps the language of the request in one place; it is swapped and put back as it was.
	 *
	 * @return mixed
	 */
	private static function in_polylang( string $code, callable $work ) {
		$polylang = PLL();
		$language = $polylang->model->get_language( $code );
		if ( ! $language ) {
			return $work();
		}

		// Put back exactly as it was, also when Polylang had not chosen any language yet.
		$was_set  = isset( $polylang->curlang );
		$previous = $was_set ? $polylang->curlang : null;
		$strings  = method_exists( $polylang, 'load_strings_translations' );
		$original = get_locale();

		$polylang->curlang = $language;
		// The site title, the tagline and the options of the SEO plugin are translated strings
		// in Polylang: they follow the locale, not the language of the request.
		$switched = (bool) switch_to_locale( (string) $language->locale );
		if ( $strings ) {
			$polylang->load_strings_translations( (string) $language->locale );
		}
		try {
			return $work();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
			$polylang->curlang = $previous;
			if ( $strings ) {
				$polylang->load_strings_translations( $was_set && $previous && isset( $previous->locale ) ? (string) $previous->locale : $original );
			}
		}
	}

	/**
	 * @return int[] The same image (or page) in the other languages.
	 */
	public static function translations_of( int $post_id ): array {
		if ( ! self::is_multilingual() ) {
			return array();
		}
		if ( 'polylang' === self::provider() ) {
			return array_values( array_diff( array_map( 'intval', (array) pll_get_post_translations( $post_id ) ), array( $post_id ) ) );
		}
		$type = 'post_' . get_post_type( $post_id );
		$trid = apply_filters( 'wpml_element_trid', null, $post_id, $type );
		if ( ! $trid ) {
			return array();
		}

		$ids = array();
		foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, $type ) as $translation ) {
			$id = (int) ( is_object( $translation ) ? ( $translation->element_id ?? 0 ) : 0 );
			if ( $id && $id !== $post_id ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Which image a use on a page belongs to. A translated page keeps the image of the original
	 * page, but the alt text it needs is the one of its own language.
	 *
	 * @return int|null The image itself, its copy in the language of the page, or null when
	 *                  the page is in a language this image has no text for.
	 */
	public static function image_for_page( int $attachment_id, int $post_id ): ?int {
		if ( ! self::is_multilingual() ) {
			return $attachment_id;
		}
		$page = self::of( 'post', $post_id );
		if ( '' === $page ) {
			return $attachment_id;
		}

		// An image without a language (media not translated) holds a text in the default one.
		$image = self::of( 'post', $attachment_id );
		if ( '' === $image ) {
			$image = self::default_code();
		}
		if ( $image === $page ) {
			return $attachment_id;
		}

		foreach ( self::translations_of( $attachment_id ) as $copy ) {
			if ( self::of( 'post', $copy ) === $page ) {
				return $copy;
			}
		}
		return null;
	}

	/**
	 * @return array{code: string, locale: string}
	 */
	private static function details( string $target_type, int $target_id ): array {
		$none = array(
			'code'   => '',
			'locale' => '',
		);
		if ( ! $target_id || ! self::is_multilingual() ) {
			return $none;
		}

		if ( 'polylang' === self::provider() ) {
			if ( 'post' !== $target_type && 'term' !== $target_type ) {
				return $none;
			}
			// A language left on something Polylang no longer translates is not its language.
			if ( 'post' === $target_type ) {
				$translated = ! function_exists( 'pll_is_translated_post_type' ) || pll_is_translated_post_type( (string) get_post_type( $target_id ) );
			} else {
				global $wpdb;
				$taxonomy   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1", $target_id ) );
				$translated = ! function_exists( 'pll_is_translated_taxonomy' ) || pll_is_translated_taxonomy( $taxonomy );
			}
			if ( ! $translated ) {
				return $none;
			}
			$get  = 'post' === $target_type ? 'pll_get_post_language' : 'pll_get_term_language';
			$code = $get( $target_id, 'slug' );
			return ! is_string( $code ) || '' === $code ? $none : array(
				'code'   => $code,
				'locale' => (string) $get( $target_id, 'locale' ),
			);
		}

		if ( 'post' === $target_type ) {
			// Answers also for hidden languages, which the list of active ones leaves out for most visitors.
			$details = apply_filters( 'wpml_post_language_details', null, $target_id );
			$code    = is_array( $details ) ? (string) ( $details['language_code'] ?? '' ) : '';
			return '' === $code ? $none : array(
				'code'   => $code,
				'locale' => (string) ( $details['locale'] ?? ( self::active()[ $code ]['default_locale'] ?? '' ) ),
			);
		}

		if ( 'term' === $target_type ) {
			global $wpdb;
			// Read straight from the table: with WPML, get_term() may answer with the term of another language.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT term_taxonomy_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1", $target_id ), ARRAY_A );
			if ( ! $row ) {
				return $none;
			}
			$code = apply_filters(
				'wpml_element_language_code',
				null,
				array(
					'element_id'   => (int) $row['term_taxonomy_id'],
					'element_type' => (string) $row['taxonomy'],
				)
			);
			return ! is_string( $code ) || '' === $code ? $none : array(
				'code'   => $code,
				'locale' => (string) ( self::active()[ $code ]['default_locale'] ?? '' ),
			);
		}

		return $none;
	}

	/**
	 * Polylang is asked first: it also answers WPML's hooks, to stand in for it.
	 */
	private static function provider(): string {
		if ( null !== self::$provider ) {
			return self::$provider;
		}
		if ( function_exists( 'pll_languages_list' ) && function_exists( 'PLL' ) && PLL() && pll_languages_list() ) {
			self::$provider = 'polylang';
		} elseif ( null !== apply_filters( 'wpml_default_language', null ) ) {
			self::$provider = 'wpml';
		} else {
			// Not remembered: the language plugin may simply not have loaded yet.
			return '';
		}
		return self::$provider;
	}

	/**
	 * @return array<string,array{native_name: string, default_locale: string}> Active languages by code. Empty on a site with one.
	 */
	private static function active(): array {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$provider = self::provider();
		if ( '' === $provider ) {
			return array();
		}

		if ( 'polylang' === $provider ) {
			$names        = (array) pll_languages_list( array( 'fields' => 'name' ) );
			$locales      = (array) pll_languages_list( array( 'fields' => 'locale' ) );
			self::$active = array();
			foreach ( array_values( (array) pll_languages_list( array( 'fields' => 'slug' ) ) ) as $index => $slug ) {
				self::$active[ (string) $slug ] = array(
					'native_name'    => (string) ( $names[ $index ] ?? $slug ),
					'default_locale' => (string) ( $locales[ $index ] ?? '' ),
				);
			}
			return self::$active;
		}

		$languages    = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		self::$active = is_array( $languages ) ? $languages : array();

		return self::$active;
	}
}
