<?php
/**
 * Reads ACF (or Secure Custom Fields) values of a post. Does nothing when ACF is absent.
 *
 * @package GeoTool
 */

namespace MDIA\Content;

defined( 'ABSPATH' ) || exit;

final class Acf {

	/**
	 * Field types whose value is content a visitor reads or sees.
	 */
	private const CONTENT_TYPES = array( 'text', 'textarea', 'wysiwyg', 'image', 'link' );

	private const CONTAINER_TYPES = array( 'group', 'repeater', 'flexible_content' );

	public static function available(): bool {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) && function_exists( 'get_field' );
	}

	/**
	 * Text written in ACF fields of the post, as simple HTML.
	 */
	public static function html( int $post_id ): string {
		$html = '';
		foreach ( self::fields( $post_id ) as $field ) {
			$html .= self::field_html( $field, get_field( $field['name'], $post_id ) );
		}
		return $html;
	}

	/**
	 * Content fields assigned to the post that nobody has filled in.
	 *
	 * @return array<int, array{name: string, label: string, type: string, required: bool}>
	 */
	public static function empty_fields( int $post_id ): array {
		$empty = array();
		foreach ( self::fields( $post_id ) as $field ) {
			if ( ! in_array( $field['type'], self::CONTENT_TYPES, true ) ) {
				continue;
			}
			$value = get_field( $field['name'], $post_id );
			if ( is_string( $value ) ? '' === trim( wp_strip_all_tags( $value ) ) : empty( $value ) ) {
				$empty[] = array(
					'name'     => (string) $field['name'],
					'label'    => (string) ( $field['label'] ?? $field['name'] ),
					'type'     => (string) $field['type'],
					'required' => ! empty( $field['required'] ),
				);
			}
		}
		return $empty;
	}

	/**
	 * @return array<int,array> Top-level fields of every field group shown on this post.
	 */
	private static function fields( int $post_id ): array {
		if ( ! self::available() || ! get_post( $post_id ) ) {
			return array();
		}

		$fields = array();
		try {
			foreach ( (array) acf_get_field_groups( array( 'post_id' => $post_id ) ) as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					if ( is_array( $field ) && ! empty( $field['name'] ) && ! empty( $field['type'] ) ) {
						$fields[] = $field;
					}
				}
			}
		} catch ( \Throwable $error ) {
			return array();
		}
		return $fields;
	}

	/**
	 * An image chosen in a field is shown by the theme somewhere on the page. It is written
	 * here with the media library text, which is what themes print by default.
	 *
	 * @param mixed $image Array, id or URL, depending on the return format of the field.
	 */
	private static function image_html( $image ): string {
		if ( is_array( $image ) ) {
			$id = (int) ( $image['ID'] ?? $image['id'] ?? 0 );
		} elseif ( is_numeric( $image ) ) {
			$id = (int) $image;
		} else {
			$id = is_string( $image ) && '' !== $image ? (int) attachment_url_to_postid( $image ) : 0;
		}
		$url = $id ? wp_get_attachment_url( $id ) : '';
		if ( ! $url ) {
			return '';
		}
		return sprintf( '<img src="%s" alt="%s" class="wp-image-%d">' . "\n", esc_url( $url ), esc_attr( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ), $id );
	}

	/**
	 * @param mixed $value Formatted value of the field.
	 */
	private static function field_html( array $field, $value ): string {
		$type = (string) ( $field['type'] ?? '' );

		if ( in_array( $type, array( 'text', 'textarea' ), true ) ) {
			$text = is_scalar( $value ) ? trim( (string) $value ) : '';
			return '' === $text ? '' : '<p>' . esc_html( $text ) . "</p>\n";
		}
		if ( 'wysiwyg' === $type ) {
			return is_string( $value ) && '' !== trim( $value ) ? $value . "\n" : '';
		}
		if ( 'image' === $type || 'gallery' === $type ) {
			$html = '';
			foreach ( 'gallery' === $type ? (array) $value : array( $value ) as $image ) {
				$html .= self::image_html( $image );
			}
			return $html;
		}
		if ( ! in_array( $type, self::CONTAINER_TYPES, true ) || ! is_array( $value ) ) {
			return '';
		}

		// Sub-fields by name, whatever layout or row they belong to.
		$subs = array();
		foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
			$subs[ $sub['name'] ] = $sub;
		}
		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			foreach ( (array) ( $layout['sub_fields'] ?? array() ) as $sub ) {
				$subs[ $sub['name'] ] = $sub;
			}
		}

		$rows = 'group' === $type ? array( $value ) : $value;
		$html = '';
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $row as $name => $sub_value ) {
				if ( isset( $subs[ $name ] ) ) {
					$html .= self::field_html( $subs[ $name ], $sub_value );
				}
			}
		}
		return $html;
	}
}
