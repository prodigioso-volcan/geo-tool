<?php
namespace MDIA\Checks\Post;

use MDIA\Content\Acf;

defined( 'ABSPATH' ) || exit;

final class Acf_Empty extends Post_Check {

	/**
	 * Option with the field names a person declared optional for the whole site.
	 */
	public const WAIVED_OPTION = 'mdia_waived_acf_fields';

	public function id(): string {
		return 'post.acf_empty';
	}

	public function fix(): string {
		return 'manual';
	}

	public function label(): string {
		return __( 'Custom field left empty', 'geo-tool' );
	}

	public function why(): string {
		return __( 'A content field nobody filled in usually means a blank spot on the page.', 'geo-tool' );
	}

	protected function check( \WP_Post $post ): ?array {
		if ( ! Acf::available() ) {
			return null;
		}

		$waived   = (array) get_option( self::WAIVED_OPTION, array() );
		$failures = array();
		foreach ( Acf::empty_fields( $post->ID ) as $field ) {
			if ( in_array( $field['name'], $waived, true ) ) {
				continue;
			}
			// The label is what the reviewer recognises; the name identifies the field.
			$failures[] = $this->fail( $field['type'], $field['label'], $field['name'], $field['required'] ? self::REQUIRED : self::RECOMMENDED );
		}
		return $failures;
	}
}
