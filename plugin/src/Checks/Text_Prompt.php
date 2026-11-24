<?php
/**
 * Instructions sent to the model to propose page metadata.
 *
 * @package GeoTool
 */

namespace MDIA\Checks;

use MDIA\Content\Page_Text;

defined( 'ABSPATH' ) || exit;

final class Text_Prompt {

	/**
	 * Characters of page text sent as context. Enough to summarise a page, small enough to stay cheap.
	 */
	public const MAX_CONTEXT = 3000;

	private const LANGUAGES = array(
		'es' => 'Spanish',
		'ca' => 'Catalan',
		'gl' => 'Galician',
		'eu' => 'Basque',
		'en' => 'English',
		'pt' => 'Portuguese',
		'fr' => 'French',
		'it' => 'Italian',
		'de' => 'German',
	);

	private const KINDS = array(
		'meta_description' => array(
			'the meta description of a web page',
			'Summarise what the page offers so that someone reading it in search results, or an AI assistant quoting it, knows what they will find. Be specific; no slogans, no calls to click.',
		),
		'seo_title'        => array(
			'the title of a web page as shown in search results, without the site name (it is added automatically)',
			'Keep the subject of the page and its most distinctive words. Do not add the site name or separators.',
		),
		'excerpt'          => array(
			'the excerpt of an article',
			'Say what the article is about in plain words, as it would appear in a list of articles.',
		),
		'term_description' => array(
			'the description of a category or tag page of a website',
			'Say what kind of content the visitor finds under this category or tag.',
		),
	);

	/**
	 * @param array{min:int,max:int,unit:string} $limits
	 */
	public static function system( string $kind, array $limits, string $locale ): string {
		list( $what, $how ) = self::KINDS[ $kind ] ?? self::KINDS['meta_description'];

		$system = implode(
			"\n",
			array(
				sprintf( 'You write %s.', $what ),
				sprintf( 'Write in %s.', self::language( $locale ) ),
				self::size_rule( $limits ),
				$how,
				'Use only what the page says. Do not invent facts, names, figures or dates.',
				'Plain text on one line. No quotation marks around it, no links, no emoji.',
				'Answer with the text only.',
			)
		);

		/**
		 * Filters the system instruction used to propose a text field.
		 *
		 * @param string $system System instruction.
		 * @param string $kind   meta_description, seo_title, excerpt or term_description.
		 * @param array  $limits min, max and unit.
		 */
		return (string) apply_filters( 'mdia_text_system_prompt', $system, $kind, $limits );
	}

	/**
	 * Instruction for the second try, when the first answer did not fit.
	 *
	 * @param array{min:int,max:int,unit:string} $limits
	 */
	public static function adjust_system( array $limits ): string {
		return implode(
			"\n",
			array(
				'You are given a text that does not have the required length.',
				'Rewrite it in the same language. ' . self::size_rule( $limits ),
				'Keep its meaning; drop or add detail only as needed. It must be complete sentences, not cut ones.',
				'Answer with the text only.',
			)
		);
	}

	public static function user( Check $check, array $finding ): string {
		$target_id = (int) $finding['target_id'];
		$lines     = array( 'Site: ' . get_bloginfo( 'name' ) );

		if ( 'term' === $check->target_type() ) {
			$term = get_term( $target_id );
			if ( $term && ! is_wp_error( $term ) ) {
				$taxonomy = get_taxonomy( $term->taxonomy );
				$lines[]  = sprintf( '%s: %s', $taxonomy ? $taxonomy->labels->singular_name : 'Term', $term->name );
				if ( '' !== trim( wp_strip_all_tags( $term->description ) ) ) {
					$lines[] = 'Existing description: ' . mb_substr( trim( wp_strip_all_tags( $term->description ) ), 0, 600 );
				}
				$titles = wp_list_pluck(
					get_posts(
						array(
							'post_type'      => 'any',
							'post_status'    => 'publish',
							'posts_per_page' => 10,
							'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
								array(
									'taxonomy' => $term->taxonomy,
									'terms'    => $term->term_id,
								),
							),
						)
					),
					'post_title'
				);
				if ( $titles ) {
					$lines[] = "Pages under it:\n- " . implode( "\n- ", $titles );
				}
			}
			return implode( "\n", $lines );
		}

		$lines[] = 'Page title: ' . get_the_title( $target_id );
		$current = trim( (string) $finding['current_value'] );
		if ( '' !== $current ) {
			$lines[] = 'Current text, which has to be improved: ' . mb_substr( $current, 0, 400 );
		}

		// Only published pages get here; their text is what visitors already read.
		$text    = mb_substr( Page_Text::text( $target_id ), 0, self::MAX_CONTEXT );
		$lines[] = '' === $text ? 'The page has no body text; use the title.' : "Page content:\n" . $text;

		return implode( "\n", $lines );
	}

	/**
	 * @param array{min:int,max:int,unit:string} $limits
	 */
	private static function size_rule( array $limits ): string {
		if ( 'words' === $limits['unit'] ) {
			return sprintf( 'At most %d words.', $limits['max'] );
		}
		if ( $limits['min'] > 0 ) {
			return sprintf( 'Between %1$d and %2$d characters, spaces included. Longer text is cut off.', $limits['min'], $limits['max'] );
		}
		return sprintf( 'At most %d characters, spaces included. Longer text is cut off.', $limits['max'] );
	}

	private static function language( string $locale ): string {
		return self::LANGUAGES[ strtolower( substr( $locale, 0, 2 ) ) ] ?? sprintf( 'the language with locale code %s', $locale );
	}
}
