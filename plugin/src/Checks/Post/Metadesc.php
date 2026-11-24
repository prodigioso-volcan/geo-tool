<?php
namespace MDIA\Checks\Post;

use MDIA\Checks\Rules\Text_Length;
use MDIA\Seo\Seo;

defined( 'ABSPATH' ) || exit;

final class Metadesc extends Post_Check {

	public const MIN = 70;
	public const MAX = 160;

	private const FINGERPRINT = '_mdia_fp_desc';

	public function id(): string {
		return 'post.metadesc';
	}

	public function fix(): string {
		return 'ai';
	}

	public function severity(): string {
		return self::REQUIRED;
	}

	public function label(): string {
		return __( 'Meta description', 'geo-tool' );
	}

	public function why(): string {
		return __( 'It is the summary search engines and AI assistants show for the page. Without it they make one up.', 'geo-tool' );
	}

	protected function check( \WP_Post $post ): ?array {
		$seo = Seo::adapter();
		if ( ! $seo->is_active() ) {
			return null;
		}

		$description = $seo->post_description( $post->ID );
		$fingerprint = Text_Length::fingerprint( $description );
		update_post_meta( $post->ID, self::FINGERPRINT, $fingerprint );

		$issue = Text_Length::classify( $description, self::MIN, self::MAX );
		if ( '' === $issue && $fingerprint === Text_Length::fingerprint( get_the_title( $post ) ) ) {
			$issue = 'same_as_title';
		}
		if ( '' === $issue && $this->others_with( self::FINGERPRINT, $fingerprint, $post->ID ) > 0 ) {
			$issue = 'duplicate';
		}

		return '' === $issue ? array() : array( $this->fail( $issue, $description ) );
	}

	public function current( int $target_id ): string {
		return Seo::adapter()->post_raw_description( $target_id );
	}

	public function apply( int $target_id, string $text ): bool {
		return Seo::adapter()->set_post_description( $target_id, $text );
	}

	public function is_human_value( int $target_id ): bool {
		return '' !== Seo::adapter()->post_raw_description( $target_id );
	}

	/**
	 * Undo restores the stored field as it was, variables included.
	 */
	public function restore( int $target_id, string $value ): bool {
		return Seo::adapter()->restore_post_description( $target_id, $value );
	}

	public function limits( int $target_id ): array {
		return array(
			'min'  => 120,
			'max'  => 155,
			'unit' => 'chars',
		);
	}

	public function text_kind(): string {
		return 'meta_description';
	}
}
