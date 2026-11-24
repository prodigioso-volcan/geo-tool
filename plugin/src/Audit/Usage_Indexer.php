<?php
/**
 * Indexes which images appear in a post and with which alt text.
 *
 * @package GeoTool
 */

namespace MDIA\Audit;

use MDIA\Content\Alt_Rewriter;
use MDIA\Data\Usages;

defined( 'ABSPATH' ) || exit;

final class Usage_Indexer {

	private const ISSUE_ORDER = array( Rules::MISSING, Rules::FILENAME, Rules::GENERIC, Rules::TOO_SHORT, Rules::TOO_LONG, Rules::DUPLICATE );

	private Usages $usages;

	private Alt_Rewriter $rewriter;

	public function __construct() {
		$this->usages   = new Usages();
		$this->rewriter = new Alt_Rewriter();
	}

	/**
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = array_values( get_post_types( array( 'public' => true ) ) );
		$types = array_diff( $types, array( 'attachment' ) );
		$types = array_merge( $types, array( 'wp_block', 'wp_template', 'wp_template_part' ) );

		/**
		 * Filters the post types whose content is audited and rewritten.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( array_unique( (array) apply_filters( 'mdia_indexed_post_types', $types ) ) );
	}

	/**
	 * @return string[]
	 */
	public static function statuses(): array {
		return array( 'publish', 'future', 'draft', 'private' );
	}

	public static function is_indexable( ?\WP_Post $post ): bool {
		return $post
			&& in_array( $post->post_type, self::post_types(), true )
			&& in_array( $post->post_status, self::statuses(), true );
	}

	/**
	 * Whether the post is rendered by a page builder, so rewriting post_content has no effect.
	 */
	public static function is_builder_post( int $post_id ): bool {
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	/**
	 * Rewrites the usage rows of one post.
	 *
	 * @return int[] Attachment ids whose usages changed, including the ones no longer used.
	 */
	public function index_post( int $post_id ): array {
		$previous = $this->usages->attachment_ids_for_post( $post_id );
		$post     = get_post( $post_id );

		if ( ! self::is_indexable( $post ) ) {
			$this->usages->delete_for_post( $post_id );
			return $previous;
		}

		$manageable = self::is_builder_post( $post_id ) ? 0 : 1;
		$config     = Auditor::config();
		$rows       = array();
		$items      = new \MDIA\Data\Items();

		foreach ( $this->alts_by_attachment( $post->post_content ) as $attachment_id => $alts ) {
			if ( ! Auditor::is_supported( $attachment_id ) ) {
				continue;
			}

			$filename = wp_basename( (string) get_attached_file( $attachment_id ) );
			$issues   = array();
			$shown    = null;

			// What a person approved, ignored or left empty on purpose is not a problem on a page either.
			$item     = $items->get( $attachment_id );
			$accepted = $item && $item['human_locked'] ? trim( (string) $item['library_alt'] ) : null;

			foreach ( $alts as $alt ) {
				$found = $manageable && trim( $alt ) !== $accepted ? Rules::classify( $alt, $filename, 1, $config ) : array();
				if ( $found && null === $shown ) {
					$shown = $alt;
				}
				$issues = array_merge( $issues, $found );
			}

			$rows[ $attachment_id ] = array(
				'attachment_id' => $attachment_id,
				'alt'           => $shown ?? $alts[0],
				'issues'        => implode( ',', array_values( array_intersect( self::ISSUE_ORDER, $issues ) ) ),
				'occurrences'   => count( $alts ),
				'manageable'    => $manageable,
			);
		}

		foreach ( $this->unmanaged_attachment_ids( $post_id ) as $attachment_id ) {
			if ( isset( $rows[ $attachment_id ] ) || ! Auditor::is_supported( $attachment_id ) ) {
				continue;
			}
			$rows[ $attachment_id ] = array(
				'attachment_id' => $attachment_id,
				'alt'           => '',
				'issues'        => '',
				'occurrences'   => 1,
				'manageable'    => 0,
			);
		}

		$this->usages->replace_for_post( $post_id, array_values( $rows ) );

		return array_values( array_unique( array_merge( $previous, array_keys( $rows ) ) ) );
	}

	/**
	 * Images referenced from page-builder data, which the plugin cannot rewrite.
	 *
	 * @return int[]
	 */
	public function unmanaged_attachment_ids( int $post_id ): array {
		$ids  = array();
		$data = get_post_meta( $post_id, '_elementor_data', true );

		if ( is_string( $data ) && '' !== $data ) {
			if ( preg_match_all( '/"url":"[^"]*","id":"?(\d+)"?|"id":"?(\d+)"?,"url":"/', $data, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$ids[] = (int) ( $match[1] ?: $match[2] );
				}
			}
		}

		/**
		 * Filters the attachment ids a post uses in places the plugin cannot rewrite.
		 *
		 * @param int[] $ids     Attachment ids.
		 * @param int   $post_id Post id.
		 */
		$ids = (array) apply_filters( 'mdia_unmanaged_attachment_ids', $ids, $post_id );

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Normalized upload URLs of an attachment, for images inserted without the wp-image class.
	 *
	 * @return string[]
	 */
	public static function urls_for( int $attachment_id ): array {
		$url = wp_get_attachment_url( $attachment_id );
		return $url ? array( Alt_Rewriter::normalize_url( $url ) ) : array();
	}

	/**
	 * @return array<int,string[]>
	 */
	private function alts_by_attachment( string $content ): array {
		$alts = array();
		foreach ( $this->rewriter->find( $content ) as $id => $data ) {
			// An id left over from another site (imports, migrations) points to a different picture here.
			$file = (string) get_attached_file( $id );
			foreach ( $data['alts'] as $index => $alt ) {
				if ( Alt_Rewriter::shows_file( $data['srcs'][ $index ], $file ) ) {
					$alts[ $id ][] = $alt;
				}
			}
		}
		foreach ( $this->rewriter->find_by_urls( $content, $this->url_map( $content ) ) as $id => $data ) {
			$alts[ $id ] = array_merge( $alts[ $id ] ?? array(), $data['alts'] );
		}
		return $alts;
	}

	/**
	 * Resolves only the upload URLs present in this content: never loads the whole library.
	 *
	 * @return array<string,int>
	 */
	private function url_map( string $content ): array {
		$base = wp_get_upload_dir()['baseurl'];
		if ( '' === $content || ! str_contains( $content, $base ) ) {
			return array();
		}

		$map  = array();
		$tags = new \WP_HTML_Tag_Processor( $content );
		while ( $tags->next_tag( 'img' ) ) {
			$class = $tags->get_attribute( 'class' );
			if ( is_string( $class ) && preg_match( '/(?:^|\s)wp-image-\d+(?:\s|$)/', $class ) ) {
				continue;
			}
			$src = $tags->get_attribute( 'src' );
			if ( ! is_string( $src ) || ! str_starts_with( $src, $base ) ) {
				continue;
			}
			$url = Alt_Rewriter::normalize_url( $src );
			if ( ! isset( $map[ $url ] ) ) {
				$map[ $url ] = (int) attachment_url_to_postid( $url );
			}
		}
		return array_filter( $map );
	}
}
