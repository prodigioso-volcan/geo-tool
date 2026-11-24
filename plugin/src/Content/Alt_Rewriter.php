<?php
/**
 * Finds and replaces image alt text inside post content (blocks and classic HTML).
 *
 * @package GeoTool
 */

namespace MDIA\Content;

defined( 'ABSPATH' ) || exit;

final class Alt_Rewriter {

	/**
	 * Block attributes that duplicate the alt text of the block's image, keyed by the
	 * attribute holding the attachment id.
	 */
	private const BLOCK_ALT_KEYS = array(
		'id'      => 'alt',
		'mediaId' => 'mediaAlt',
	);

	/**
	 * Blocks whose alt lives only in the block comment (not read back from the markup).
	 */
	private const BLOCKS_WITH_UNSOURCED_ALT = array( 'cover', 'core/cover' );

	/**
	 * Images referenced by their `wp-image-{ID}` class.
	 *
	 * @return array<int, array{alts: string[], srcs: string[]}> Alt texts and sources per attachment id, in document order.
	 */
	public function find( string $content ): array {
		$found = array();
		if ( '' === $content ) {
			return $found;
		}

		$tags = new \WP_HTML_Tag_Processor( $content );
		while ( $tags->next_tag( 'img' ) ) {
			$id = self::id_from_class( $tags );
			if ( $id ) {
				$found[ $id ]['alts'][] = self::alt_of( $tags );
				$found[ $id ]['srcs'][] = self::src_of( $tags );
			}
		}
		return $found;
	}

	/**
	 * Images with no `wp-image-{ID}` class, matched by file URL.
	 *
	 * @param array<string,int> $url_map Normalized URL (see normalize_url) => attachment id.
	 * @return array<int, array{alts: string[]}>
	 */
	public function find_by_urls( string $content, array $url_map ): array {
		$found = array();
		if ( '' === $content || ! $url_map ) {
			return $found;
		}

		$tags = new \WP_HTML_Tag_Processor( $content );
		while ( $tags->next_tag( 'img' ) ) {
			if ( self::id_from_class( $tags ) ) {
				continue;
			}
			$src = self::src_of( $tags );
			$key = self::normalize_url( $src );
			if ( isset( $url_map[ $key ] ) ) {
				$found[ (int) $url_map[ $key ] ]['alts'][] = self::alt_of( $tags );
				$found[ (int) $url_map[ $key ] ]['srcs'][] = $src;
			}
		}
		return $found;
	}

	/**
	 * @param callable $should_replace fn( string $current_alt ): bool.
	 * @param string[] $urls           Normalized URLs of the attachment, for images without the class.
	 * @param string   $expected_file  File name of the attachment. When given, an image carrying the
	 *                                 `wp-image-{ID}` class but showing a different file is left alone:
	 *                                 imported or migrated content often keeps ids of another site.
	 * @return array{content: string, replaced: string[], kept: string[]} `replaced` and `kept` hold previous alts.
	 */
	public function replace( string $content, int $attachment_id, string $new_alt, callable $should_replace, array $urls = array(), string $expected_file = '' ): array {
		$result = array(
			'content'  => $content,
			'replaced' => array(),
			'kept'     => array(),
		);
		if ( '' === $content ) {
			return $result;
		}

		$tags = new \WP_HTML_Tag_Processor( $content );
		while ( $tags->next_tag( 'img' ) ) {
			if ( ! $this->is_target( $tags, $attachment_id, $urls, $expected_file ) ) {
				continue;
			}
			$current = self::alt_of( $tags );
			if ( $current === $new_alt || ! $should_replace( $current ) ) {
				$result['kept'][] = $current;
				continue;
			}
			$tags->set_attribute( 'alt', $new_alt );
			$result['replaced'][] = $current;
		}

		if ( ! $result['replaced'] ) {
			return $result;
		}

		$result['content'] = $this->sync_block_attributes( $tags->get_updated_html(), $attachment_id, $new_alt, $should_replace );
		return $result;
	}

	/**
	 * Strips the query string and the `-{w}x{h}` / `-scaled` suffixes WordPress adds to file names.
	 */
	public static function normalize_url( string $url ): string {
		$url = strtok( $url, '?#' );
		return (string) preg_replace( '/(?:-\d+x\d+|-scaled)(\.[a-z0-9]+)$/i', '$1', (string) $url );
	}

	/**
	 * Comparable file name of an image URL or path: no directory, no size suffix, lower case.
	 */
	public static function file_key( string $url_or_path ): string {
		return strtolower( wp_basename( self::normalize_url( $url_or_path ) ) );
	}

	/**
	 * Whether an image tagged with an attachment id really shows that attachment's file.
	 * Compared by file name so that a CDN or a changed domain does not matter.
	 */
	public static function shows_file( string $src, string $expected_file ): bool {
		return '' === $expected_file || '' === $src || self::file_key( $src ) === self::file_key( $expected_file );
	}

	private function is_target( \WP_HTML_Tag_Processor $tags, int $attachment_id, array $urls, string $expected_file ): bool {
		$id = self::id_from_class( $tags );
		if ( $id ) {
			return $id === $attachment_id && self::shows_file( self::src_of( $tags ), $expected_file );
		}
		if ( ! $urls ) {
			return false;
		}
		$src = $tags->get_attribute( 'src' );
		return is_string( $src ) && in_array( self::normalize_url( $src ), $urls, true );
	}

	private static function id_from_class( \WP_HTML_Tag_Processor $tags ): int {
		$class = $tags->get_attribute( 'class' );
		if ( is_string( $class ) && preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class, $match ) ) {
			return (int) $match[1];
		}
		return 0;
	}

	private static function src_of( \WP_HTML_Tag_Processor $tags ): string {
		$src = $tags->get_attribute( 'src' );
		return is_string( $src ) ? $src : '';
	}

	private static function alt_of( \WP_HTML_Tag_Processor $tags ): string {
		$alt = $tags->get_attribute( 'alt' );
		return is_string( $alt ) ? $alt : '';
	}

	/**
	 * Some blocks (cover, older media-text) also store the alt in the block comment.
	 * If only the HTML changed, the editor would flag the block as invalid.
	 */
	private function sync_block_attributes( string $content, int $attachment_id, string $new_alt, callable $should_replace ): string {
		if ( ! str_contains( $content, '<!-- wp:' ) ) {
			return $content;
		}

		$sync = static function ( array $match ) use ( $attachment_id, $new_alt, $should_replace ) {
				// Decoded as objects so that an empty object stays `{}` and does not become `[]`.
				$attrs = json_decode( $match[3] );
				if ( ! $attrs instanceof \stdClass ) {
					return $match[0];
				}
				$changed = false;
				$is_ours = static fn( string $key ): bool => isset( $attrs->$key ) && is_scalar( $attrs->$key ) && (int) $attrs->$key === $attachment_id;

				// A cover with no alt keeps no `alt` in its comment, but its saved markup must match it.
				if ( in_array( $match[2], self::BLOCKS_WITH_UNSOURCED_ALT, true ) && $is_ours( 'id' ) && ! isset( $attrs->alt ) && '' !== $new_alt && $should_replace( '' ) ) {
					$attrs->alt = $new_alt;
					$changed    = true;
				}
				foreach ( self::BLOCK_ALT_KEYS as $id_key => $alt_key ) {
					if ( $is_ours( $id_key ) && isset( $attrs->$alt_key ) && is_string( $attrs->$alt_key ) ) {
						if ( $attrs->$alt_key !== $new_alt && $should_replace( $attrs->$alt_key ) ) {
							$attrs->$alt_key = $new_alt;
							$changed         = true;
						}
					}
				}
				return $changed ? $match[1] . serialize_block_attributes( (array) $attrs ) . $match[4] : $match[0];
			};

		// Walk the comments one by one instead of running one pattern over the whole content:
		// the work stays proportional to its size even with thousands of malformed comments.
		$result = '';
		$offset = 0;
		while ( false !== ( $open = strpos( $content, '<!-- wp:', $offset ) ) ) {
			$close = strpos( $content, '-->', $open );
			if ( false === $close ) {
				break;
			}
			$close  += 3;
			$comment = substr( $content, $open, $close - $open );
			$result .= substr( $content, $offset, $open - $offset );
			$result .= 1 === preg_match( '/^(<!--\s+wp:([a-z][a-z0-9_\/-]*)\s+)(\{.*\})(\s+\/?-->)$/s', $comment, $match ) ? $sync( $match ) : $comment;
			$offset  = $close;
		}

		return $result . substr( $content, $offset );
	}
}
