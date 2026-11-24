<?php
namespace MDIA\Checks\Post;

use MDIA\Checks\Rules\Headings as Rules;
use MDIA\Content\Page_Text;

defined( 'ABSPATH' ) || exit;

final class Headings extends Post_Check {

	public function id(): string {
		return 'post.headings';
	}

	public function fix(): string {
		return 'manual';
	}

	public function label(): string {
		return __( 'Heading structure', 'geo-tool' );
	}

	public function why(): string {
		return __( 'Screen readers, search engines and AI assistants read headings as the outline of the page. More than one H1 or a skipped level breaks it.', 'geo-tool' );
	}

	protected function check( \WP_Post $post ): ?array {
		if ( '' === Page_Text::text( $post->ID ) ) {
			return null; // Nothing readable: not evaluable, never a failure.
		}
		$issues = Rules::issues( Page_Text::html( $post->ID ) );

		return $issues ? array( $this->fail( implode( ',', $issues ) ) ) : array();
	}
}
