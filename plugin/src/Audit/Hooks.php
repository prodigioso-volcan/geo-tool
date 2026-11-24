<?php
/**
 * Keeps the usage index and the audit in sync with what editors do.
 *
 * @package RevisorAlt
 */

namespace MDIA\Audit;

use MDIA\Data\Items;
use MDIA\Data\Usages;

defined( 'ABSPATH' ) || exit;

final class Hooks {

	private const BUILDER_META = array( '_elementor_data', '_elementor_edit_mode' );

	public static function register(): void {
		add_action( 'save_post', array( self::class, 'on_save_post' ), 20, 2 );
		add_action( 'deleted_post', array( self::class, 'on_deleted_post' ), 20, 2 );
		add_action( 'add_attachment', array( self::class, 'on_add_attachment' ), 20 );
		add_action( 'delete_attachment', array( self::class, 'on_delete_attachment' ), 20 );
		add_action( 'added_post_meta', array( self::class, 'on_meta_change' ), 20, 3 );
		add_action( 'updated_post_meta', array( self::class, 'on_meta_change' ), 20, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'on_meta_change' ), 20, 3 );
	}

	public static function on_save_post( $post_id, $post ): void {
		if ( Auditor::$suspended || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'attachment' === $post->post_type ) {
			return;
		}
		self::reindex( (int) $post_id );
	}

	public static function on_deleted_post( $post_id, $post ): void {
		if ( $post && in_array( $post->post_type, array( 'attachment', 'revision' ), true ) ) {
			return;
		}
		$usages   = new Usages();
		$affected = $usages->attachment_ids_for_post( (int) $post_id );
		$usages->delete_for_post( (int) $post_id );
		self::audit_many( $affected );
	}

	public static function on_add_attachment( $attachment_id ): void {
		$status = ( new Auditor() )->audit( (int) $attachment_id );

		/**
		 * Fires after a newly uploaded image has been audited.
		 *
		 * @param int    $attachment_id Attachment id.
		 * @param string $status        Resulting status, '' if not audited.
		 */
		do_action( 'mdia_attachment_audited_on_upload', (int) $attachment_id, $status );
	}

	public static function on_delete_attachment( $attachment_id ): void {
		( new Items() )->delete( (int) $attachment_id );
		( new Usages() )->delete_for_attachment( (int) $attachment_id );
	}

	public static function on_meta_change( $meta_id, $object_id, $meta_key ): void {
		if ( Auditor::$suspended ) {
			return;
		}
		if ( '_wp_attachment_image_alt' === $meta_key ) {
			( new Auditor() )->audit( (int) $object_id );
		} elseif ( in_array( $meta_key, self::BUILDER_META, true ) ) {
			self::reindex( (int) $object_id );
		}
	}

	private static function reindex( int $post_id ): void {
		self::audit_many( ( new Usage_Indexer() )->index_post( $post_id ) );
	}

	/**
	 * @param int[] $attachment_ids
	 */
	private static function audit_many( array $attachment_ids ): void {
		$auditor = new Auditor();
		foreach ( $attachment_ids as $attachment_id ) {
			$auditor->audit( (int) $attachment_id );
		}
	}
}
