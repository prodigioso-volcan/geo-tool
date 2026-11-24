<?php
/**
 * Shared fixtures for integration tests.
 */

trait MDIA_Test_Helpers {

	/**
	 * Suites about generating and reviewing work with images that some page shows:
	 * one that no page uses is left alone by the plugin.
	 */
	protected bool $images_in_use = false;

	protected function show_on_a_page( int $id ): int {
		return $this->make_post( $this->theme_block( 'library', $id ) );
	}

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
		if ( $this->images_in_use ) {
			$this->show_on_a_page( $id );
		}
		return $id;
	}

	protected function image_block( int $id, string $alt = '' ): string {
		$alt = esc_attr( $alt );
		$src = esc_url( (string) wp_get_attachment_url( $id ) );
		return "<!-- wp:image {\"id\":$id} -->\n<figure class=\"wp-block-image\"><img src=\"$src\" alt=\"$alt\" class=\"wp-image-$id\"/></figure>\n<!-- /wp:image -->";
	}

	/**
	 * Two blocks painted when the page is shown, like the ones a theme builds with ACF:
	 * one takes the alt text from the media library, the other always writes its own.
	 */
	protected function register_theme_blocks(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'mdia-test/library' ) ) {
			return;
		}
		register_block_type(
			'mdia-test/library',
			array(
				'attributes'      => array( 'id' => array( 'type' => 'integer' ) ),
				'render_callback' => static fn( array $attrs ): string => '<div class="hero">' . wp_get_attachment_image( (int) $attrs['id'], 'full' ) . '</div>',
			)
		);
		register_block_type(
			'mdia-test/fixed',
			array(
				'attributes'      => array(
					'id'  => array( 'type' => 'integer' ),
					'alt' => array( 'type' => 'string', 'default' => '' ),
				),
				'render_callback' => static fn( array $attrs ): string => sprintf( '<img src="%s" alt="%s">', esc_url( wp_get_attachment_url( (int) $attrs['id'] ) ), esc_attr( $attrs['alt'] ) ),
			)
		);
	}

	protected function theme_block( string $kind, int $id, string $alt = '' ): string {
		$this->register_theme_blocks();
		return sprintf( '<!-- wp:mdia-test/%s %s /-->', $kind, wp_json_encode( array( 'id' => $id, 'alt' => $alt ) ) );
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
		if ( $this->images_in_use ) {
			$this->show_on_a_page( $id );
		}
		return $id;
	}
}
