<?php
/**
 * Manual check, run inside the `wordpress` container (its Imagick reads JPEG):
 * an image handled by a non-GD editor must leave without its embedded metadata.
 *
 *   docker compose exec -T wordpress php wp-content/plugins/<plugin>/tests/tools/verify-imagick-strip.php
 */

require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once __DIR__ . '/../integration/Metadata_Keeping_Editor.php';

$mdia_path = wp_upload_dir()['path'] . '/mdia-verify-strip.jpg';
$mdia_gd   = imagecreatetruecolor( 300, 200 );
imagejpeg( $mdia_gd, $mdia_path );
$mdia_jpeg = file_get_contents( $mdia_path );
$mdia_exif = "Exif\0\0II*\0\x08\0\0\0\0\0MDIA-GPS-40.4168,-3.7038";
file_put_contents( $mdia_path, substr( $mdia_jpeg, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $mdia_exif ) + 2 ) . $mdia_exif . substr( $mdia_jpeg, 2 ) );

$mdia_id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'verify', 'post_status' => 'inherit' ), $mdia_path );
add_filter( 'wp_image_editors', static fn() => array( 'MDIA_Metadata_Keeping_Editor' ), 1000 );

$mdia_uri   = \MDIA\AI\Image_Encoder::encode( $mdia_id );
$mdia_bytes = is_wp_error( $mdia_uri ) ? '' : base64_decode( substr( $mdia_uri, strpos( $mdia_uri, ',' ) + 1 ) );

echo 'Imagick JPEG: ', class_exists( 'Imagick' ) && Imagick::queryFormats( 'JPEG' ) ? 'yes' : 'no', "\n";
echo 'source has marker: ', str_contains( file_get_contents( $mdia_path ), 'MDIA-GPS' ) ? 'yes' : 'no', "\n";
echo 'result: ', is_wp_error( $mdia_uri ) ? 'ERROR ' . $mdia_uri->get_error_code() : substr( $mdia_uri, 0, 23 ), "\n";
echo 'sent bytes have marker: ', str_contains( $mdia_bytes, 'MDIA-GPS' ) ? 'YES (FAIL)' : 'no (ok)', "\n";

wp_delete_attachment( $mdia_id, true );
