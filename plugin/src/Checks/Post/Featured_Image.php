<?php
namespace MDIA\Checks\Post;

defined( 'ABSPATH' ) || exit;

final class Featured_Image extends Post_Check {

	public function id(): string {
		return 'post.featured_image';
	}

	public function fix(): string {
		return 'manual';
	}

	public function label(): string {
		return __( 'Featured image', 'geo-tool' );
	}

	public function why(): string {
		return __( 'It is the image shown when the page is shared and in listings.', 'geo-tool' );
	}

	protected function check( \WP_Post $post ): ?array {
		if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return null;
		}
		return has_post_thumbnail( $post ) ? array() : array( $this->fail( 'missing' ) );
	}
}
