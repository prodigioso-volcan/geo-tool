<?php
/**
 * Test double: an image editor that is not GD and copies the file as is, metadata
 * included. Stands for Imagick, or for any editor another plugin may register.
 */

class MDIA_Metadata_Keeping_Editor extends WP_Image_Editor {

	public static function test( $args = array() ) {
		return true;
	}

	public static function supports_mime_type( $mime_type ) {
		return true;
	}

	public function load() {
		$size = wp_getimagesize( $this->file );
		if ( ! $size ) {
			return new WP_Error( 'invalid_image', 'Not an image.' );
		}
		$this->update_size( $size[0], $size[1] );
		$this->mime_type = $size['mime'];
		return true;
	}

	public function save( $destfilename = null, $mime_type = null ) {
		copy( $this->file, $destfilename );
		return array(
			'path'      => $destfilename,
			'file'      => wp_basename( $destfilename ),
			'width'     => $this->size['width'],
			'height'    => $this->size['height'],
			'mime-type' => $this->mime_type,
		);
	}

	public function resize( $max_w, $max_h, $crop = false ) {
		return true;
	}

	public function multi_resize( $sizes ) {
		return array();
	}

	public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false ) {
		return true;
	}

	public function rotate( $angle ) {
		return true;
	}

	public function flip( $horz, $vert ) {
		return true;
	}

	public function stream( $mime_type = null ) {
		return true;
	}
}
