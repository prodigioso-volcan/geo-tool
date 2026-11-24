<?php
/**
 * Prepares an image to be sent to the AI provider.
 *
 * @package GeoTool
 */

namespace MDIA\AI;

defined( 'ABSPATH' ) || exit;

final class Image_Encoder {

	public const MAX_SIDE = 1024;

	private const MAX_SOURCE_BYTES  = 30 * MB_IN_BYTES;
	private const MAX_SOURCE_PIXELS = 50000000;

	/**
	 * @return string|\WP_Error Base64 data URI of a copy no larger than 1024 px on its longest side.
	 */
	public static function encode( int $attachment_id ) {
		$original = get_attached_file( $attachment_id );

		if ( ! $original || ! is_file( $original ) ) {
			return new \WP_Error( 'mdia_file_missing', __( 'The image file is missing from the server.', 'geo-tool' ) );
		}

		$too_large  = new \WP_Error( 'mdia_file_too_large', __( 'The image file is too large to be analysed.', 'geo-tool' ) );
		$unreadable = new \WP_Error( 'mdia_file_unreadable', __( 'The image file could not be read.', 'geo-tool' ) );
		$path       = self::source_path( $attachment_id, $original );

		/**
		 * Filters the largest source file, in bytes, the plugin will load to describe.
		 *
		 * @param int $bytes Maximum file size.
		 */
		if ( filesize( $path ) > (int) apply_filters( 'mdia_max_source_bytes', self::MAX_SOURCE_BYTES ) ) {
			return $too_large;
		}

		// Decoding needs memory for every pixel whatever the file weighs: check before loading.
		$dimensions = wp_getimagesize( $path );
		if ( ! $dimensions || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
			return $unreadable;
		}

		/**
		 * Filters the largest image, in pixels (width x height), the plugin will decode.
		 *
		 * @param int $pixels Maximum number of pixels.
		 */
		if ( $dimensions[0] * $dimensions[1] > (int) apply_filters( 'mdia_max_source_pixels', self::MAX_SOURCE_PIXELS ) ) {
			return $too_large;
		}

		// GD re-encodes pixels only, so EXIF data such as GPS position never leaves the site.
		// This is only a preference: whatever editor is used, the result is checked below.
		$prefer_gd = static fn( $editors ) => array_values( array_unique( array_merge( array( 'WP_Image_Editor_GD' ), (array) $editors ) ) );
		add_filter( 'wp_image_editors', $prefer_gd, 99 );
		$editor = wp_get_image_editor( $path );
		remove_filter( 'wp_image_editors', $prefer_gd, 99 );

		if ( is_wp_error( $editor ) ) {
			return $unreadable;
		}

		if ( max( (int) $dimensions[0], (int) $dimensions[1] ) > self::MAX_SIDE ) {
			$resized = $editor->resize( self::MAX_SIDE, self::MAX_SIDE, false );
			if ( is_wp_error( $resized ) ) {
				return $unreadable;
			}
		}

		$keep_png = in_array( (string) ( $dimensions['mime'] ?? '' ), array( 'image/png', 'image/gif' ), true );
		$target   = trailingslashit( get_temp_dir() ) . 'mdia-' . wp_generate_password( 12, false ) . ( $keep_png ? '.png' : '.jpg' );

		if ( ! $keep_png ) {
			$editor->set_quality( 82 );
		}

		$saved = $editor->save( $target, $keep_png ? 'image/png' : 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( $saved['path'] ) ) {
			return $unreadable;
		}

		// Any editor other than GD (Imagick, or one added by another plugin) may carry the
		// original metadata over. Strip it explicitly, and send nothing if that is not possible.
		if ( ! $editor instanceof \WP_Image_Editor_GD && ! self::strip_metadata( $saved['path'] ) ) {
			wp_delete_file( $saved['path'] );
			return new \WP_Error( 'mdia_metadata_not_removed', __( 'The image was not sent because its embedded data (such as location) could not be removed on this server.', 'geo-tool' ) );
		}

		$bytes = file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
		wp_delete_file( $saved['path'] );

		if ( false === $bytes || '' === $bytes ) {
			return $unreadable;
		}

		// The site may convert formats on save (for example JPEG to WebP): declare what was really written.
		$mime = ! empty( $saved['mime-type'] ) ? $saved['mime-type'] : ( $keep_png ? 'image/png' : 'image/jpeg' );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- image payload for the AI provider.
		return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
	}

	/**
	 * Removes EXIF, GPS, comments and colour profiles from an image file, in place.
	 *
	 * @return bool False when it cannot be guaranteed that the metadata is gone.
	 */
	private static function strip_metadata( string $path ): bool {
		/**
		 * Filters whether embedded metadata can be removed with Imagick on this server.
		 *
		 * @param bool $can Whether the Imagick extension is available.
		 */
		if ( ! apply_filters( 'mdia_can_strip_metadata', class_exists( '\Imagick' ) ) ) {
			return false;
		}

		try {
			$image = new \Imagick( $path );
			$image->stripImage();
			$written = $image->writeImage( $path );
			$image->clear();
			return (bool) $written;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * The "large" size WordPress already generated is enough to describe the image
	 * and much cheaper to load than a camera original.
	 */
	private static function source_path( int $attachment_id, string $original ): string {
		$meta = wp_get_attachment_metadata( $attachment_id );
		// Metadata can be written by importers and other plugins: never follow a path out of the image's folder.
		$file = is_array( $meta ) ? wp_basename( (string) ( $meta['sizes']['large']['file'] ?? '' ) ) : '';
		$path = '' !== $file ? trailingslashit( dirname( $original ) ) . $file : '';

		return '' !== $path && is_file( $path ) ? $path : $original;
	}
}
