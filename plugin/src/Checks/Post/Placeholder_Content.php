<?php
namespace MDIA\Checks\Post;

use MDIA\Checks\Rules\Placeholder;
use MDIA\Content\Page_Text;

defined( 'ABSPATH' ) || exit;

final class Placeholder_Content extends Post_Check {

	public function id(): string {
		return 'post.placeholder';
	}

	public function fix(): string {
		return 'manual';
	}

	public function severity(): string {
		return self::REQUIRED;
	}

	public function label(): string {
		return __( 'Leftover template content', 'geo-tool' );
	}

	public function why(): string {
		return __( 'Filler text must not be public on a finished site.', 'geo-tool' );
	}

	protected function check( \WP_Post $post ): ?array {
		$text = Page_Text::text( $post->ID );
		if ( '' === $text ) {
			return null;
		}
		$excerpt = Placeholder::filler_excerpt( $text );
		return '' !== $excerpt ? array( $this->fail( 'filler_text', $excerpt ) ) : array();
	}
}
