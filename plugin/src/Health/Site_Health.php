<?php
/**
 * Reports the plugin status in Tools > Site Health.
 *
 * @package GeoTool
 */

namespace MDIA\Health;

defined( 'ABSPATH' ) || exit;

final class Site_Health {

	public static function register(): void {
		add_filter( 'site_status_tests', array( self::class, 'add_test' ) );
	}

	public static function add_test( $tests ) {
		$tests['direct']['mdia_status'] = array(
			'label' => __( 'Image alt text', 'geo-tool' ),
			'test'  => array( self::class, 'run' ),
		);
		return $tests;
	}

	public static function run(): array {
		$status = Status::compute( true );
		$counts = $status['counts'];

		$map = array(
			'green' => array( 'good', __( 'Image alt text is in good shape', 'geo-tool' ) ),
			'amber' => array( 'recommended', __( 'Some images are waiting for alt text', 'geo-tool' ) ),
			'red'   => array( 'critical', __( 'Alt text generation needs your attention', 'geo-tool' ) ),
		);

		$lines = array(
			/* translators: 1: images with correct alt text, 2: total images. */
			sprintf( __( '%1$d of %2$d images have correct alt text.', 'geo-tool' ), $counts['correct'], $counts['total'] ),
		);
		foreach ( $status['checks'] as $check ) {
			if ( 'green' !== $check['state'] ) {
				$lines[] = $check['label'] . ': ' . $check['detail'];
			}
		}

		return array(
			'label'       => $map[ $status['state'] ][1],
			'status'      => $map[ $status['state'] ][0],
			'badge'       => array(
				'label' => __( 'Accessibility', 'geo-tool' ),
				'color' => 'blue',
			),
			'description' => '<p>' . implode( '</p><p>', array_map( 'esc_html', $lines ) ) . '</p>',
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( \MDIA\Admin\Menu::url() ), esc_html__( 'Open the alt text dashboard', 'geo-tool' ) ),
			'test'        => 'mdia_status',
		);
	}
}
