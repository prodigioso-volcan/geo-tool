<?php
/**
 * Shared fixtures for integration tests.
 */

trait MDIA_Test_Helpers {

	protected function make_image( string $alt = '', string $file = '', string $mime = 'image/jpeg' ): int {
		static $n = 0;
		++$n;
		$file = $file ?: "2026/11/foto-$n.jpg";
		$id   = self::factory()->attachment->create(
			array(
				'post_mime_type' => $mime,
				'post_title'     => "Foto $n",
				'file'           => $file,
			)
		);
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		return $id;
	}

	protected function image_block( int $id, string $alt = '' ): string {
		$alt = esc_attr( $alt );
		$src = esc_url( (string) wp_get_attachment_url( $id ) );
		return "<!-- wp:image {\"id\":$id} -->\n<figure class=\"wp-block-image\"><img src=\"$src\" alt=\"$alt\" class=\"wp-image-$id\"/></figure>\n<!-- /wp:image -->";
	}

	protected function make_post( string $content, array $args = array() ): int {
		return self::factory()->post->create( array_merge( array( 'post_content' => $content, 'post_status' => 'publish' ), $args ) );
	}

	protected function item( int $id ): ?array {
		return ( new \MDIA\Data\Items() )->get( $id );
	}

	protected function usages( int $id ): array {
		return ( new \MDIA\Data\Usages() )->for_attachment( $id );
	}

	/**
	 * Runs due Action Scheduler actions of the plugin until none is left.
	 */
	protected function run_queue(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			$due = as_get_scheduled_actions(
				array(
					'group'        => 'mdia',
					'status'       => \ActionScheduler_Store::STATUS_PENDING,
					'date'         => as_get_datetime_object(),
					'date_compare' => '<=',
					'per_page'     => 1,
				),
				'ids'
			);
			if ( ! $due ) {
				return;
			}
			\ActionScheduler_QueueRunner::instance()->run();
		}
		$this->fail( 'The queue did not drain.' );
	}

	protected function pending_actions( string $hook ): array {
		return as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => 'mdia',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 500,
			),
			'ids'
		);
	}

	/**
	 * Creates an attachment backed by a real image file generated with GD.
	 */
	protected function make_real_image( int $width = 300, int $height = 200, string $type = 'jpeg', string $alt = '' ): int {
		static $n = 0;
		++$n;
		$ext    = 'jpeg' === $type ? 'jpg' : $type;
		$upload = wp_upload_dir();
		$path   = $upload['path'] . "/real-$n.$ext";
		wp_mkdir_p( $upload['path'] );

		$image = imagecreatetruecolor( $width, $height );
		if ( 'png' === $type ) {
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
			imagefill( $image, 0, 0, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );
			imagepng( $image, $path );
		} elseif ( 'gif' === $type ) {
			imagegif( $image, $path );
		} else {
			imagefill( $image, 0, 0, imagecolorallocate( $image, 200, 120, 40 ) );
			imagejpeg( $image, $path );
		}
		imagedestroy( $image );

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => "image/$type",
				'post_title'     => "Real $n",
				'post_status'    => 'inherit',
			),
			$path
		);
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		return $id;
	}
}
