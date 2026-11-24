<?php
/**
 * Summary on the WordPress dashboard home.
 *
 * @package RevisorAlt
 */

namespace MDIA\Admin;

use MDIA\Health\Status;

defined( 'ABSPATH' ) || exit;

final class Dashboard_Widget {

	public static function register(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'add' ) );
	}

	public static function add(): void {
		if ( current_user_can( 'mdia_review' ) ) {
			wp_add_dashboard_widget( 'mdia_status', __( 'Image alt text', 'revisor-alt' ), array( self::class, 'render' ) );
		}
	}

	public static function render(): void {
		$status = Status::compute();
		$counts = $status['counts'];

		$headlines = array(
			'green' => __( 'All green', 'revisor-alt' ),
			'amber' => __( 'Needs attention', 'revisor-alt' ),
			'red'   => __( 'Action required', 'revisor-alt' ),
		);
		$icons     = array(
			'green' => 'yes-alt',
			'amber' => 'warning',
			'red'   => 'dismiss',
		);
		$colors    = array(
			'green' => '#008a20',
			'amber' => '#996800',
			'red'   => '#d63638',
		);

		printf(
			'<p style="font-size:14px;font-weight:600;color:%1$s"><span class="dashicons dashicons-%2$s" aria-hidden="true"></span> %3$s</p>',
			esc_attr( $colors[ $status['state'] ] ),
			esc_attr( $icons[ $status['state'] ] ),
			esc_html( $headlines[ $status['state'] ] )
		);

		echo '<p>';
		/* translators: 1: images with correct alt text, 2: total images, 3: percentage. */
		echo esc_html( sprintf( __( '%1$d of %2$d images have correct alt text (%3$d%%).', 'revisor-alt' ), $counts['correct'], $counts['total'], $status['coverage'] ) );
		echo '</p>';

		if ( $counts['to_review'] > 0 ) {
			echo '<p>';
			/* translators: %d: number of proposals. */
			echo esc_html( sprintf( _n( '%d proposal is waiting for your review.', '%d proposals are waiting for your review.', $counts['to_review'], 'revisor-alt' ), $counts['to_review'] ) );
			echo '</p>';
		}

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( Menu::url() ),
			esc_html__( 'Open the alt text dashboard', 'revisor-alt' )
		);
	}
}
