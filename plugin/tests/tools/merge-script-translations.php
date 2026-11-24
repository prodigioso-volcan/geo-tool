<?php
/**
 * `wp i18n make-json` writes one file per source module, but WordPress loads
 * translations for the single built script. Merges them into one file per locale
 * named after the script handle: geo-tool-{locale}-mdia-admin.json.
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

$mdia_dir    = dirname( __DIR__, 2 ) . '/languages';
$mdia_merged = array();

foreach ( glob( $mdia_dir . '/geo-tool-*-????????????????????????????????.json' ) as $mdia_file ) {
	$mdia_data = json_decode( file_get_contents( $mdia_file ), true );
	preg_match( '/geo-tool-(.+)-[0-9a-f]{32}\.json$/', $mdia_file, $mdia_match );
	$mdia_locale = $mdia_match[1];

	if ( ! isset( $mdia_merged[ $mdia_locale ] ) ) {
		$mdia_merged[ $mdia_locale ]           = $mdia_data;
		$mdia_merged[ $mdia_locale ]['source'] = 'build/index.js';
	} else {
		$mdia_merged[ $mdia_locale ]['locale_data']['messages'] += $mdia_data['locale_data']['messages'];
	}
	unlink( $mdia_file );
}

foreach ( $mdia_merged as $mdia_locale => $mdia_data ) {
	file_put_contents( "$mdia_dir/geo-tool-$mdia_locale-mdia-admin.json", wp_json_encode( $mdia_data ) );
	WP_CLI::success( "$mdia_locale: " . ( count( $mdia_data['locale_data']['messages'] ) - 1 ) . ' script strings.' );
}
