<?php
namespace MDIA\Checks\Post;

defined( 'ABSPATH' ) || exit;

final class Excerpt extends Post_Check {

	public function id(): string {
		return 'post.excerpt';
	}

	public function fix(): string {
		return 'ai';
	}

	public function label(): string {
		return __( 'Excerpt', 'geo-tool' );
	}

	public function why(): string {
		return __( 'Listings, feeds and shared links use the excerpt. Without one, WordPress cuts the first lines of the text.', 'geo-tool' );
	}

	protected function check( \WP_Post $post ): ?array {
		if ( ! post_type_supports( $post->post_type, 'excerpt' ) ) {
			return null;
		}
		return '' === trim( $post->post_excerpt ) ? array( $this->fail( 'missing' ) ) : array();
	}

	public function current( int $target_id ): string {
		return (string) get_post_field( 'post_excerpt', $target_id );
	}

	public function apply( int $target_id, string $text ): bool {
		// Saving the excerpt saves the whole post: the content must come out exactly as it was,
		// also for reviewers whose own edits would be filtered.
		$had_kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		kses_remove_filters();
		try {
			$saved = wp_update_post(
				array(
					'ID'           => $target_id,
					'post_excerpt' => wp_slash( $text ),
				),
				true
			);
		} finally {
			if ( $had_kses ) {
				kses_init_filters();
			}
		}
		return ! is_wp_error( $saved ) && (int) $saved === $target_id;
	}

	public function is_human_value( int $target_id ): bool {
		return '' !== trim( $this->current( $target_id ) );
	}

	public function limits( int $target_id ): array {
		return array(
			'min'  => 0,
			'max'  => 40,
			'unit' => 'words',
		);
	}

	public function text_kind(): string {
		return 'excerpt';
	}
}
