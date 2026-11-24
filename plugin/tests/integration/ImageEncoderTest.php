<?php

use MDIA\AI\Image_Encoder;

class ImageEncoderTest extends WP_UnitTestCase {
	use MDIA_Test_Helpers;

	private function decode( string $data_uri ) {
		return getimagesizefromstring( base64_decode( substr( $data_uri, strpos( $data_uri, ',' ) + 1 ) ) );
	}

	public function test_large_jpeg_is_downscaled_to_1024() {
		$uri = Image_Encoder::encode( $this->make_real_image( 3000, 2000 ) );

		$this->assertStringStartsWith( 'data:image/jpeg;base64,', $uri );
		$size = $this->decode( $uri );
		$this->assertSame( 1024, $size[0] );
		$this->assertSame( 683, $size[1] );
	}

	public function test_small_image_is_not_upscaled() {
		$size = $this->decode( Image_Encoder::encode( $this->make_real_image( 300, 200 ) ) );

		$this->assertSame( array( 300, 200 ), array( $size[0], $size[1] ) );
	}

	public function test_portrait_image_limits_its_longest_side() {
		$size = $this->decode( Image_Encoder::encode( $this->make_real_image( 1000, 2048 ) ) );

		$this->assertSame( 1024, $size[1] );
	}

	public function test_png_stays_png() {
		$this->assertStringStartsWith( 'data:image/png;base64,', Image_Encoder::encode( $this->make_real_image( 200, 200, 'png' ) ) );
	}

	public function test_gif_is_sent_as_png() {
		$this->assertStringStartsWith( 'data:image/png;base64,', Image_Encoder::encode( $this->make_real_image( 200, 200, 'gif' ) ) );
	}

	public function test_missing_file_is_reported() {
		$id = $this->make_real_image();
		unlink( get_attached_file( $id ) );

		$result = Image_Encoder::encode( $id );
		$this->assertWPError( $result );
		$this->assertSame( 'mdia_file_missing', $result->get_error_code() );
	}

	public function test_corrupt_file_is_reported() {
		$id = $this->make_real_image();
		file_put_contents( get_attached_file( $id ), 'esto no es una imagen' );

		$result = Image_Encoder::encode( $id );
		$this->assertWPError( $result );
		$this->assertSame( 'mdia_file_unreadable', $result->get_error_code() );
	}

	public function test_oversized_file_is_reported_without_loading_it() {
		$id = $this->make_real_image();
		add_filter( 'mdia_max_source_bytes', static fn() => 10 );

		$result = Image_Encoder::encode( $id );
		$this->assertWPError( $result );
		$this->assertSame( 'mdia_file_too_large', $result->get_error_code() );
	}

	public function test_no_temporary_files_are_left_behind() {
		$before = glob( trailingslashit( get_temp_dir() ) . 'mdia-*' );
		Image_Encoder::encode( $this->make_real_image( 2000, 1000 ) );

		$this->assertSame( $before, glob( trailingslashit( get_temp_dir() ) . 'mdia-*' ) );
	}

	public function test_data_uri_type_matches_what_the_site_actually_outputs() {
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$this->markTestSkipped( 'No WebP support in this image library.' );
		}
		add_filter( 'image_editor_output_format', static fn( $formats ) => array_merge( (array) $formats, array( 'image/jpeg' => 'image/webp' ) ) );

		$uri  = Image_Encoder::encode( $this->make_real_image( 2000, 1000 ) );
		$info = getimagesizefromstring( base64_decode( substr( $uri, strpos( $uri, ',' ) + 1 ) ) );

		$this->assertStringStartsWith( 'data:' . $info['mime'] . ';base64,', $uri );
	}

	public function test_images_with_too_many_pixels_are_rejected_before_loading() {
		$id = $this->make_real_image( 400, 300 );
		add_filter( 'mdia_max_source_pixels', static fn() => 1000 );

		$result = Image_Encoder::encode( $id );
		$this->assertWPError( $result );
		$this->assertSame( 'mdia_file_too_large', $result->get_error_code() );
	}

	public function test_the_large_size_is_used_when_wordpress_already_made_one() {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id   = $this->make_real_image( 3000, 2000 );
		$path = get_attached_file( $id );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
		add_filter( 'mdia_max_source_pixels', static fn() => 2000000 ); // The 6 MP original would be rejected.

		$this->assertStringStartsWith( 'data:image/jpeg;base64,', Image_Encoder::encode( $id ) );
	}

	public function test_size_file_from_metadata_cannot_point_outside_the_image_folder() {
		$id     = $this->make_real_image( 300, 200 );
		$secret = $this->make_real_image( 40, 40, 'png' );
		$path   = get_attached_file( $secret );
		rename( $path, dirname( $path, 2 ) . '/mdia-outside.png' );
		wp_update_attachment_metadata( $id, array( 'sizes' => array( 'large' => array( 'file' => '../mdia-outside.png' ) ) ) );

		$uri = Image_Encoder::encode( $id );
		unlink( dirname( $path, 2 ) . '/mdia-outside.png' );

		$this->assertStringStartsWith( 'data:image/jpeg;base64,', $uri, 'The original must be used, not the traversed path.' );
		$this->assertSame( 300, $this->decode( $uri )[0] );
	}

	public function test_embedded_metadata_such_as_gps_is_not_sent() {
		$id    = $this->make_real_image( 300, 200 );
		$path  = get_attached_file( $id );
		$jpeg  = file_get_contents( $path );
		$exif  = "Exif\0\0II*\0\x08\0\0\0\0\0MDIA-GPS-40.4168,-3.7038";
		$app1  = "\xFF\xE1" . pack( 'n', strlen( $exif ) + 2 ) . $exif;
		file_put_contents( $path, substr( $jpeg, 0, 2 ) . $app1 . substr( $jpeg, 2 ) );
		$this->assertStringContainsString( 'MDIA-GPS', file_get_contents( $path ) );

		$uri   = Image_Encoder::encode( $id );
		$bytes = base64_decode( substr( $uri, strpos( $uri, ',' ) + 1 ) );

		$this->assertStringNotContainsString( 'MDIA-GPS', $bytes );
	}
}
