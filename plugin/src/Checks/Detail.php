<?php
/**
 * Says in plain words what a check found. Findings store a short code, never a sentence,
 * so the text follows the language of whoever reads it.
 *
 * @package GeoTool
 */

namespace MDIA\Checks;

defined( 'ABSPATH' ) || exit;

final class Detail {

	public static function text( string $check_id, string $detail, string $value ): string {
		if ( 'post.acf_empty' === $check_id ) {
			/* translators: %s: label of a custom field. */
			return '' === $value ? '' : sprintf( __( 'The field "%s" is empty on this page.', 'geo-tool' ), $value );
		}
		if ( 'filler_text' === $detail && '' !== $value ) {
			/* translators: %s: fragment of the page text. */
			return sprintf( __( 'It contains filler text: "%s"', 'geo-tool' ), $value );
		}
		if ( 0 === strpos( $detail, 'blocks_ai:' ) ) {
			/* translators: %s: comma-separated names of AI crawlers. */
			return sprintf( __( 'robots.txt keeps these AI crawlers out: %s.', 'geo-tool' ), substr( $detail, 10 ) );
		}

		$sentences = array(
			'missing'         => __( 'It is empty.', 'geo-tool' ),
			'too_short'       => __( 'It is too short to be useful.', 'geo-tool' ),
			'too_long'        => __( 'It is too long: search engines will cut it.', 'geo-tool' ),
			'same_as_title'   => __( 'It only repeats the title of the page.', 'geo-tool' ),
			'duplicate'       => __( 'Another page has exactly the same text.', 'geo-tool' ),
			'meta_missing'    => __( 'The description exists, but the one shown in search results is empty.', 'geo-tool' ),
			'noindex'         => __( 'This published page asks search engines not to show it.', 'geo-tool' ),
			'test_title'      => __( 'Its title says it is a test.', 'geo-tool' ),
			'maybe_test'      => __( 'Its title looks like a test. If it is a real page, accept it as it is.', 'geo-tool' ),
			'default_content' => __( 'It still has the sample content WordPress ships with.', 'geo-tool' ),
			'filler_text'     => __( 'It contains filler text (lorem ipsum or similar).', 'geo-tool' ),
			'several_h1'      => __( 'The content has more than one H1.', 'geo-tool' ),
			'long_site_name'  => __( 'The site name is long and it is added to the title of every page, so search engines cut the titles. Take it out of the title templates of the SEO plugin, or accept it as it is.', 'geo-tool' ),
			'skipped_level'   => __( 'A heading level is skipped (an H2 followed by an H4, for instance).', 'geo-tool' ),
			'blocks_all'      => __( 'robots.txt keeps every crawler out of the whole site.', 'geo-tool' ),
			'fail'            => __( 'This is not in place yet.', 'geo-tool' ),
		);

		$found = array();
		foreach ( explode( ',', $detail ) as $code ) {
			if ( isset( $sentences[ $code ] ) ) {
				$found[] = $sentences[ $code ];
			}
		}
		return implode( ' ', $found );
	}
}
