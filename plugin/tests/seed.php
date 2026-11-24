<?php
/**
 * Local demo data: 12 generated images with assorted alt text and 3 posts using them.
 * Run with: bin/seed            (add "proposals" to also write fake proposals to review)
 *
 * Nothing is downloaded: images are drawn with GD.
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/image.php';

$mdia_alts = array(
	'',
	'',
	'IMG_0001',
	'foto',
	'Sol',
	'Vista aérea del puerto de Vigo al amanecer',
	'Equipo reunido en la oficina',
	'Equipo reunido en la oficina',
	'Equipo reunido en la oficina',
	'Equipo reunido en la oficina',
	'Equipo reunido en la oficina',
	'',
);

$mdia_upload = wp_upload_dir();
$mdia_ids    = array();

foreach ( $mdia_alts as $mdia_n => $mdia_alt ) {
	$mdia_image = imagecreatetruecolor( 1200, 800 );
	$mdia_hue   = $mdia_n * 30;
	imagefill( $mdia_image, 0, 0, imagecolorallocate( $mdia_image, 40 + ( $mdia_hue % 200 ), 90 + ( ( $mdia_hue * 3 ) % 150 ), 200 - ( $mdia_hue % 160 ) ) );
	imagefilledellipse( $mdia_image, 300 + $mdia_n * 50, 400, 300, 300, imagecolorallocate( $mdia_image, 250, 220, 90 ) );
	imagefilledrectangle( $mdia_image, 0, 620, 1200, 800, imagecolorallocate( $mdia_image, 30, 60, 40 ) );
	imagestring( $mdia_image, 5, 40, 40, 'Demo ' . ( $mdia_n + 1 ), imagecolorallocate( $mdia_image, 255, 255, 255 ) );

	$mdia_path = $mdia_upload['path'] . '/mdia-demo-' . ( $mdia_n + 1 ) . '.jpg';
	imagejpeg( $mdia_image, $mdia_path, 85 );
	imagedestroy( $mdia_image );

	$mdia_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Demo ' . ( $mdia_n + 1 ),
			'post_status'    => 'inherit',
		),
		$mdia_path
	);
	wp_update_attachment_metadata( $mdia_id, wp_generate_attachment_metadata( $mdia_id, $mdia_path ) );
	if ( '' !== $mdia_alt ) {
		update_post_meta( $mdia_id, '_wp_attachment_image_alt', $mdia_alt );
	}
	$mdia_ids[] = $mdia_id;
}

$mdia_block = static function ( int $id, string $alt ): string {
	return sprintf(
		"<!-- wp:image {\"id\":%d,\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"%s\" alt=\"%s\" class=\"wp-image-%d\"/></figure>\n<!-- /wp:image -->",
		$id,
		esc_url( wp_get_attachment_image_url( $id, 'large' ) ),
		esc_attr( $alt ),
		$id
	);
};

$mdia_posts = array(
	'Demo: imágenes sin texto alternativo' => $mdia_block( $mdia_ids[0], '' ) . "\n\n" . $mdia_block( $mdia_ids[2], 'IMG_0001' ),
	'Demo: texto propio en la página'      => $mdia_block( $mdia_ids[1], 'Retrato de Ana en su taller de cerámica' ) . "\n\n" . $mdia_block( $mdia_ids[5], '' ),
	'Demo: galería del equipo'             => $mdia_block( $mdia_ids[6], 'Equipo reunido en la oficina' ) . "\n\n" . $mdia_block( $mdia_ids[3], 'foto' ),
);
foreach ( $mdia_posts as $mdia_title => $mdia_content ) {
	wp_insert_post(
		array(
			'post_title'   => $mdia_title,
			'post_content' => wp_slash( "<!-- wp:paragraph -->\n<p>Contenido de demostración.</p>\n<!-- /wp:paragraph -->\n\n" . $mdia_content ),
			'post_status'  => 'publish',
		)
	);
}

$mdia_auditor = new \MDIA\Audit\Auditor();
foreach ( $mdia_ids as $mdia_id ) {
	$mdia_auditor->audit( $mdia_id );
}

if ( in_array( 'proposals', $args ?? array(), true ) ) {
	$mdia_items = new \MDIA\Data\Items();
	as_unschedule_all_actions( 'mdia_generate_item' );
	foreach ( $mdia_items->ids_by_status( 'needs_generation', 100 ) as $mdia_n => $mdia_id ) {
		$mdia_items->upsert(
			$mdia_id,
			array(
				'status'       => 'needs_review',
				'queued'       => 0,
				'proposed_alt' => 'Propuesta de prueba ' . ( $mdia_n + 1 ) . ': círculo amarillo sobre fondo de color con una franja verde',
				'provider'     => 'Demo',
				'model'        => 'demo-1',
			)
		);
	}
}

WP_CLI::success( count( $mdia_ids ) . ' demo images and ' . count( $mdia_posts ) . ' demo posts created.' );
