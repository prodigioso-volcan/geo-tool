<?php
/**
 * Base for checks on a published page.
 *
 * @package GeoTool
 */

namespace MDIA\Checks\Post;

use MDIA\Checks\Check;
use MDIA\Checks\Targets;
use MDIA\I18n\Languages;

defined( 'ABSPATH' ) || exit;

abstract class Post_Check extends Check {

	public function target_type(): string {
		return 'post';
	}

	/**
	 * Pages hidden from search engines are left out of every check but the one that reports them.
	 */
	public function evaluate( int $target_id ): ?array {
		if ( Targets::IN_SCOPE !== Targets::post_scope( $target_id ) ) {
			return null;
		}
		$post = get_post( $target_id );
		// Nobody is asked to complete a page that has to be deleted.
		if ( Test_Page::is_pending( $post ) ) {
			return null;
		}
		return $this->check( $post );
	}

	/**
	 * @return array<int,array>|null See Check::evaluate().
	 */
	abstract protected function check( \WP_Post $post ): ?array;

	/**
	 * How many other published pages carry the same fingerprint under a meta key.
	 */
	protected function others_with( string $meta_key, string $fingerprint, int $post_id ): int {
		global $wpdb;
		if ( '' === $fingerprint ) {
			return 0;
		}
		$ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND pm.post_id <> %d AND p.post_status = 'publish' LIMIT 50",
					$meta_key,
					$fingerprint,
					$post_id
				)
			)
		);
		if ( ! $ids || ! Languages::is_multilingual() ) {
			return count( $ids );
		}
		// A translation is another page for search engines: only the same language repeats.
		$lang = Languages::of( 'post', $post_id );
		return count( array_filter( $ids, static fn( int $id ): bool => Languages::of( 'post', $id ) === $lang ) );
	}

	protected function fail( string $detail, string $value = '', string $key = '', string $severity = '' ): array {
		$failure = array(
			'key'    => $key,
			'value'  => $value,
			'detail' => $detail,
		);
		if ( '' !== $severity ) {
			$failure['severity'] = $severity;
		}
		return $failure;
	}
}
