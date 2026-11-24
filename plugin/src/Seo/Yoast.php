<?php
/**
 * Yoast SEO. The only class that talks to Yoast.
 *
 * Values are computed from Yoast's stored fields and templates, not from its indexables
 * cache, so a field written in this request is read back correctly.
 *
 * @package GeoTool
 */

namespace MDIA\Seo;

defined( 'ABSPATH' ) || exit;

final class Yoast implements Adapter {

	private const TITLE_SUFFIX = ' %%page%% %%sep%% %%sitename%%';

	private const SITE_BOOLEANS = array(
		'sitemap'  => 'enable_xml_sitemap',
		'schema'   => 'enable_schema',
		'llms_txt' => 'enable_llms_txt',
	);

	private const SITE_STRINGS = array(
		'social_image'     => 'og_default_image',
		'home_description' => 'metadesc-home-wpseo',
	);

	public static function available(): bool {
		return class_exists( '\WPSEO_Meta' ) && class_exists( '\WPSEO_Options' ) && function_exists( 'wpseo_replace_vars' );
	}

	public function is_active(): bool {
		return self::available();
	}

	public function name(): string {
		return 'Yoast SEO';
	}

	public function post_description( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$template = $this->post_raw_description( $post_id );
		if ( '' === $template ) {
			$template = (string) \WPSEO_Options::get( 'metadesc-' . $post->post_type, '' );
		}
		return $this->render( $template, $post );
	}

	public function post_raw_description( int $post_id ): string {
		return trim( (string) \WPSEO_Meta::get_value( 'metadesc', $post_id ) );
	}

	public function set_post_description( int $post_id, string $text ): bool {
		return $this->set_meta( 'metadesc', self::literal( $text ), $post_id );
	}

	public function post_title( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$template = $this->post_raw_title( $post_id );
		if ( '' === $template ) {
			$template = (string) \WPSEO_Options::get( 'title-' . $post->post_type, '%%title%%' . self::TITLE_SUFFIX );
		}
		return $this->render( $template, $post );
	}

	public function post_raw_title( int $post_id ): string {
		return trim( (string) \WPSEO_Meta::get_value( 'title', $post_id ) );
	}

	public function set_post_title( int $post_id, string $text, bool $with_site_name = true ): bool {
		$text = self::literal( trim( $text ) );
		// A type whose titles do not carry the site name gets none added.
		$post     = get_post( $post_id );
		$template = $post ? (string) \WPSEO_Options::get( 'title-' . $post->post_type, '%%title%%' . self::TITLE_SUFFIX ) : '';
		$suffix   = $with_site_name && str_contains( $template, '%%sitename%%' ) ? self::TITLE_SUFFIX : '';
		return $this->set_meta( 'title', '' === $text ? $text : $text . $suffix, $post_id );
	}

	public function restore_post_description( int $post_id, string $raw ): bool {
		return $this->set_meta( 'metadesc', $raw, $post_id );
	}

	public function restore_post_title( int $post_id, string $raw ): bool {
		if ( ! get_post( $post_id ) ) {
			return false;
		}
		\WPSEO_Meta::set_value( 'title', $raw, $post_id );
		return true;
	}

	public function watched_meta(): array {
		return array( '_yoast_wpseo_metadesc', '_yoast_wpseo_title', '_yoast_wpseo_meta-robots-noindex' );
	}

	public function is_post_noindex( int $post_id ): bool {
		return '1' === (string) \WPSEO_Meta::get_value( 'meta-robots-noindex', $post_id );
	}

	public function is_type_noindex( string $post_type ): bool {
		return (bool) \WPSEO_Options::get( 'noindex-' . $post_type, false );
	}

	public function term_description_meta( int $term_id, string $taxonomy ): string {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		$template = trim( (string) \WPSEO_Taxonomy_Meta::get_term_meta( $term, $taxonomy, 'desc' ) );
		if ( '' === $template ) {
			$template = (string) \WPSEO_Options::get( 'metadesc-tax-' . $taxonomy, '' );
		}
		return $this->render( $template, $term );
	}

	public function term_raw_description_meta( int $term_id, string $taxonomy ): string {
		$term = get_term( $term_id, $taxonomy );
		return $term && ! is_wp_error( $term ) ? trim( (string) \WPSEO_Taxonomy_Meta::get_term_meta( $term, $taxonomy, 'desc' ) ) : '';
	}

	public function set_term_description_meta( int $term_id, string $taxonomy, string $text ): bool {
		\WPSEO_Taxonomy_Meta::set_value( $term_id, $taxonomy, 'wpseo_desc', sanitize_text_field( self::literal( $text ) ) );
		return true;
	}

	public function site( string $key ) {
		if ( isset( self::SITE_BOOLEANS[ $key ] ) ) {
			return (bool) \WPSEO_Options::get( self::SITE_BOOLEANS[ $key ], false );
		}
		if ( isset( self::SITE_STRINGS[ $key ] ) ) {
			return trim( (string) \WPSEO_Options::get( self::SITE_STRINGS[ $key ], '' ) );
		}

		if ( 'title_site_name' === $key ) {
			// As the titles print it, which is not always the site title of WordPress.
			return $this->render( '%%sitename%%', array() );
		}

		$person = 'person' === \WPSEO_Options::get( 'company_or_person', 'company' );
		if ( 'organization_name' === $key ) {
			if ( $person ) {
				$user = get_userdata( (int) \WPSEO_Options::get( 'company_or_person_user_id', 0 ) );
				return $user ? (string) $user->display_name : '';
			}
			return trim( (string) \WPSEO_Options::get( 'company_name', '' ) );
		}
		if ( 'organization_logo' === $key ) {
			return trim( (string) \WPSEO_Options::get( $person ? 'person_logo' : 'company_logo', '' ) );
		}
		return null;
	}

	public function settings_url( string $section ): string {
		if ( 'robots' === $section ) {
			return admin_url( 'admin.php?page=wpseo_tools&tool=file-editor' );
		}
		return admin_url( 'admin.php?page=wpseo_page_settings#/' . rawurlencode( $section ) );
	}

	/**
	 * A text written by a person or proposed by the AI is never a template: Yoast reads
	 * anything between double percent signs as one of its variables.
	 */
	private static function literal( string $text ): string {
		return (string) preg_replace( '/%{2,}/', '%', $text );
	}

	private function set_meta( string $key, string $value, int $post_id ): bool {
		if ( ! get_post( $post_id ) ) {
			return false;
		}
		\WPSEO_Meta::set_value( $key, sanitize_text_field( $value ), $post_id );
		return true;
	}

	/**
	 * @param \WP_Post|\WP_Term|array $object
	 */
	private function render( string $template, $object ): string {
		if ( '' === trim( $template ) ) {
			return '';
		}
		$text = (string) wpseo_replace_vars( $template, $object );
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
	}
}
