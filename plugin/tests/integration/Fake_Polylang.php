<?php
/**
 * Polylang's public functions, answering from a switchboard the tests control.
 * Switched off (the default) they answer as a Polylang with no languages configured.
 * The language of a post is kept in the post meta "_fake_pll"; of a term, in term meta.
 */
final class MDIA_Fake_Polylang {

	/** @var array<string,array{0:string,1:string}> Locale and name by slug. Empty: switched off. */
	public static array $languages = array();

	public static ?object $instance = null;

	/** @var string[] Post types and taxonomies Polylang is told not to translate. */
	public static array $untranslated = array();

	public static function install( array $slugs = array( 'es', 'en', 'ca' ) ): void {
		$known           = array(
			'es' => array( 'es_ES', 'Español' ),
			'en' => array( 'en_US', 'English' ),
			'ca' => array( 'ca', 'Català' ),
		);
		self::$languages = array_intersect_key( $known, array_flip( $slugs ) );
		self::$untranslated = array();
		self::$instance  = new class() {
			/** @var object|false|null */
			public $curlang = null;
			public object $model;

			/** @var string[] Locales whose translated strings were loaded, in order. */
			public array $loaded = array();

			public function load_strings_translations( $locale = '' ) {
				$this->loaded[] = (string) $locale;
			}

			public function __construct() {
				$this->model = new class() {
					public function get_language( $slug ) {
						return MDIA_Fake_Polylang::language( (string) $slug );
					}
				};
			}

			public function __set( $name, $value ) {
				$this->$name = $value;
			}
		};
		\MDIA\I18n\Languages::flush();
	}

	public static function uninstall(): void {
		self::$languages = array();
		self::$instance  = null;
		\MDIA\I18n\Languages::flush();
	}

	/**
	 * @return object|false
	 */
	public static function language( string $slug ) {
		if ( ! isset( self::$languages[ $slug ] ) ) {
			return false;
		}
		return (object) array(
			'slug'   => $slug,
			'locale' => self::$languages[ $slug ][0],
			'name'   => self::$languages[ $slug ][1],
		);
	}

	public static function set_post( int $post_id, string $slug, int $group = 0 ): void {
		update_post_meta( $post_id, '_fake_pll', $slug );
		if ( $group ) {
			update_post_meta( $post_id, '_fake_pll_group', $group );
		}
	}

	public static function set_term( int $term_id, string $slug ): void {
		update_term_meta( $term_id, '_fake_pll', $slug );
	}
}

if ( ! function_exists( 'pll_languages_list' ) ) {
	function pll_languages_list( $args = array() ) {
		$field = $args['fields'] ?? 'slug';
		$list  = array();
		foreach ( array_keys( MDIA_Fake_Polylang::$languages ) as $slug ) {
			$language = MDIA_Fake_Polylang::language( $slug );
			$list[]   = '' === $field ? $language : $language->$field;
		}
		return $list;
	}

	function pll_default_language( $field = 'slug' ) {
		$slugs = array_keys( MDIA_Fake_Polylang::$languages );
		return $slugs ? MDIA_Fake_Polylang::language( $slugs[0] )->$field : false;
	}

	function pll_get_post_language( $post_id, $field = 'slug' ) {
		$language = MDIA_Fake_Polylang::language( (string) get_post_meta( (int) $post_id, '_fake_pll', true ) );
		return $language ? $language->$field : false;
	}

	function pll_get_term_language( $term_id, $field = 'slug' ) {
		$language = MDIA_Fake_Polylang::language( (string) get_term_meta( (int) $term_id, '_fake_pll', true ) );
		return $language ? $language->$field : false;
	}

	function pll_get_post_translations( $post_id ) {
		global $wpdb;
		$group = get_post_meta( (int) $post_id, '_fake_pll_group', true );
		$found = array();
		if ( $group ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_fake_pll_group' AND meta_value = %s", (string) $group ) ) as $id ) {
				$found[ (string) pll_get_post_language( (int) $id ) ] = (int) $id;
			}
		}
		return $found;
	}

	function pll_is_translated_post_type( $type ) {
		return ! in_array( $type, MDIA_Fake_Polylang::$untranslated, true );
	}

	function pll_is_translated_taxonomy( $taxonomy ) {
		return ! in_array( $taxonomy, MDIA_Fake_Polylang::$untranslated, true );
	}

	function pll_current_language( $field = 'slug' ) {
		$current = MDIA_Fake_Polylang::$instance ? MDIA_Fake_Polylang::$instance->curlang : false;
		return $current ? $current->$field : false;
	}

	function PLL() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
		return MDIA_Fake_Polylang::$instance;
	}
}
