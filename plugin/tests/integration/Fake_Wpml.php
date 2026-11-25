<?php
/**
 * Answers the hooks of WPML's public API the way WPML does, for sites of the test suite.
 * The language of a post is kept in the post meta "_fake_lang"; of a term, in term meta.
 */
final class MDIA_Fake_Wpml {

	public static string $current = 'es';

	/** @var string[] Every language the plugin switched to, in order. */
	public static array $switches = array();

	private const LOCALES = array(
		'es' => array( 'es_ES', 'Español' ),
		'en' => array( 'en_US', 'English' ),
		'ca' => array( 'ca', 'Català' ),
	);

	/**
	 * @param string[] $codes Active languages; the first is the default.
	 */
	public static function install( array $codes = array( 'es', 'en', 'ca' ) ): void {
		self::$current  = $codes[0];
		self::$switches = array();
		\MDIA\I18n\Languages::flush();
		$original = $codes[0];

		add_filter( 'wpml_default_language', static fn() => $codes[0] );
		add_filter( 'wpml_current_language', static fn() => self::$current );
		add_action(
			'wpml_switch_language',
			static function ( $code ) use ( &$original ): void {
				// As WPML: null goes back to the language the request started in.
				self::$current    = null === $code ? $original : (string) $code;
				self::$switches[] = null === $code ? 'null' : (string) $code;
			}
		);
		add_filter(
			'wpml_active_languages',
			static function () use ( $codes ): array {
				$languages = array();
				foreach ( $codes as $code ) {
					$languages[ $code ] = array(
						'code'            => $code,
						'language_code'   => $code,
						'default_locale'  => self::LOCALES[ $code ][0],
						'native_name'     => self::LOCALES[ $code ][1],
						'translated_name' => self::LOCALES[ $code ][1],
						'active'          => $code === self::$current ? 1 : 0,
					);
				}
				return $languages;
			}
		);
		add_filter(
			'wpml_post_language_details',
			static function ( $details, $post_id ) use ( $codes ) {
				$code = self::of_post( (int) $post_id, $codes[0] );
				if ( 'none' === $code ) {
					return new WP_Error( 'post_type_not_translatable', 'x' );
				}
				return array(
					'language_code' => $code,
					'locale'        => self::LOCALES[ $code ][0] ?? $code . '_XX',
					'display_name'  => self::LOCALES[ $code ][1] ?? $code,
				);
			},
			10,
			2
		);
		add_filter(
			'wpml_element_language_code',
			static function ( $code, $args ) use ( $codes ) {
				$type = (string) ( $args['element_type'] ?? '' );
				$id   = (int) ( $args['element_id'] ?? 0 );
				if ( taxonomy_exists( $type ) ) {
					$term = get_term_by( 'term_taxonomy_id', $id );
					return $term ? ( get_term_meta( $term->term_id, '_fake_lang', true ) ?: $codes[0] ) : null;
				}
				return self::of_post( $id, $codes[0] );
			},
			10,
			2
		);
		add_filter(
			'wpml_object_id',
			static function ( $id, $type, $return_original, $lang ) use ( $codes ) {
				foreach ( (array) apply_filters( 'wpml_get_element_translations', null, (int) get_post_meta( (int) $id, '_fake_trid', true ), $type ) as $code => $translation ) {
					if ( $code === $lang ) {
						return (int) $translation->element_id;
					}
				}
				return self::of_post( (int) $id, $codes[0] ) === $lang || $return_original ? (int) $id : null;
			},
			10,
			4
		);
		// A translation group: posts sharing the meta "_fake_trid".
		add_filter( 'wpml_element_trid', static fn( $trid, $id ) => (int) get_post_meta( (int) $id, '_fake_trid', true ) ?: null, 10, 2 );
		add_filter(
			'wpml_get_element_translations',
			static function ( $translations, $trid ) use ( $codes ) {
				global $wpdb;
				$found = array();
				foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_fake_trid' AND meta_value = %s", (string) $trid ) ) as $id ) {
					$code           = self::of_post( (int) $id, $codes[0] );
					$found[ $code ] = (object) array(
						'element_id'    => (string) $id,
						'language_code' => $code,
					);
				}
				return $found;
			},
			10,
			2
		);
	}

	private static function of_post( int $post_id, string $fallback ): string {
		return (string) ( get_post_meta( $post_id, '_fake_lang', true ) ?: $fallback );
	}

	/** The language the request "starts" in, as when the admin bar says "All languages". */
	public static function start_in( string $code ): void {
		self::$current = $code;
	}

	public static function set_post( int $post_id, string $code, int $trid = 0 ): void {
		update_post_meta( $post_id, '_fake_lang', $code );
		if ( $trid ) {
			update_post_meta( $post_id, '_fake_trid', $trid );
		}
	}

	public static function set_term( int $term_id, string $code ): void {
		update_term_meta( $term_id, '_fake_lang', $code );
	}
}
