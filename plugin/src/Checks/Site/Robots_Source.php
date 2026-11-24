<?php
namespace MDIA\Checks\Site;

defined( 'ABSPATH' ) || exit;

/**
 * The robots.txt the site serves, without requesting it over HTTP.
 */
final class Robots_Source {

	public static function content(): string {
		$file = ABSPATH . 'robots.txt';
		if ( is_readable( $file ) ) {
			return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		}

		// Same text WordPress generates in do_robots(), including what plugins add.
		$public = (string) get_option( 'blog_public' );
		$path   = (string) wp_parse_url( site_url(), PHP_URL_PATH );
		$output = "User-agent: *\n";
		if ( '0' === $public ) {
			$output .= "Disallow: /\n";
		} else {
			$output .= "Disallow: $path/wp-admin/\nAllow: $path/wp-admin/admin-ajax.php\n";
		}

		/** This filter is documented in wp-includes/functions.php */
		return (string) apply_filters( 'robots_txt', $output, $public );
	}
}
