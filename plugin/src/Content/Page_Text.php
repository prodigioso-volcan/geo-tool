<?php
/**
 * The text of a page as visitors get it, without requesting the page from the server:
 * the editor content with its blocks resolved (ACF blocks included) plus ACF fields.
 *
 * What a theme template prints on its own, outside these two sources, is not seen.
 *
 * @package GeoTool
 */

namespace MDIA\Content;

defined( 'ABSPATH' ) || exit;

final class Page_Text {

	/** @var array<int,string> */
	private static array $cache = array();

	/** @var array<int,bool> Pages being composed right now. */
	private static array $composing = array();

	public static function flush(): void {
		self::$cache = array();
	}

	/**
	 * @return string HTML of the page body. '' when there is none or it could not be composed.
	 */
	public static function html( int $post_id ): string {
		if ( isset( self::$cache[ $post_id ] ) ) {
			return self::$cache[ $post_id ];
		}

		$post = get_post( $post_id );
		$html = $post ? self::compose( $post ) : '';

		self::$cache[ $post_id ] = $html;
		return $html;
	}

	/**
	 * Composes the page again, ignoring what was composed before. For callers that change
	 * what the page reads (a simulated value) and must not leave it behind.
	 */
	public static function fresh( int $post_id ): string {
		$post = get_post( $post_id );
		return $post ? self::compose( $post ) : '';
	}

	/**
	 * @return string Plain text of the page body, on one line.
	 */
	public static function text( int $post_id ): string {
		$html = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', self::html( $post_id ) );
		// Block-level tags separate words; inline ones do not.
		$html = (string) preg_replace( '#</?(p|div|section|article|li|ul|ol|h[1-6]|br|tr|td|th|figure|figcaption|blockquote|header|footer|nav)\b[^>]*>#i', ' ', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	private static function compose( \WP_Post $post ): string {
		// A translated page is composed as its own language shows it.
		return (string) \MDIA\I18n\Languages::in( \MDIA\I18n\Languages::of( 'post', (int) $post->ID ), static fn() => self::compose_now( $post ) );
	}

	private static function compose_now( \WP_Post $post ): string {
		// A block or shortcode that asks for the page it is part of would never end.
		if ( isset( self::$composing[ $post->ID ] ) ) {
			return '';
		}
		self::$composing[ $post->ID ] = true;

		// Third-party blocks run here: one that fails or prints must not break the audit.
		$previous = $GLOBALS['post'] ?? null;
		$level    = ob_get_level();
		ob_start();

		try {
			// ACF remembers formatted field values during a request; a page composed twice must read them twice.
			if ( function_exists( 'acf_get_store' ) ) {
				acf_get_store( 'values' )->reset();
			}
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
			setup_postdata( $post );
			$html  = do_shortcode( do_blocks( $post->post_content ) );
			$html .= Acf::html( $post->ID );
		} catch ( \Throwable $error ) {
			$html = '';
		} finally {
			unset( self::$composing[ $post->ID ] );
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			if ( $previous instanceof \WP_Post ) {
				setup_postdata( $previous );
			} else {
				wp_reset_postdata();
			}
		}

		return $html;
	}
}
