<?php
namespace MDIA\Checks\Post;

use MDIA\Checks\Targets;

defined( 'ABSPATH' ) || exit;

final class Noindex extends Post_Check {

	public function id(): string {
		return 'post.noindex';
	}

	public function fix(): string {
		return 'manual';
	}

	public function label(): string {
		return __( 'Page hidden from search engines', 'geo-tool' );
	}

	public function why(): string {
		return __( 'A page marked as "noindex" never shows up in search results or AI answers. Confirm it is on purpose.', 'geo-tool' );
	}

	/**
	 * The only page check that looks at hidden pages: it is the one reporting them.
	 */
	public function evaluate( int $target_id ): ?array {
		$scope = Targets::post_scope( $target_id );
		if ( Targets::OUT === $scope ) {
			return null;
		}
		return Targets::HIDDEN === $scope ? array( $this->fail( 'noindex' ) ) : array();
	}

	protected function check( \WP_Post $post ): ?array {
		return array();
	}
}
