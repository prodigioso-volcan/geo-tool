<?php
/**
 * Log of every alt text written by the plugin, used for undo.
 *
 * @package RevisorAlt
 */

namespace MDIA\Data;

defined( 'ABSPATH' ) || exit;

final class Changes {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mdia_changes';
	}

	public function record( string $batch, int $attachment_id, int $post_id, string $old, string $new, int $user_id ): int {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'batch'         => $batch,
				'attachment_id' => $attachment_id,
				'post_id'       => $post_id,
				'old_alt'       => $old,
				'new_alt'       => $new,
				'user_id'       => $user_id,
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Rows of the most recent batch that has not been undone.
	 */
	public function latest_batch( int $attachment_id ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$batch = $wpdb->get_var( $wpdb->prepare( "SELECT batch FROM {$this->table()} WHERE attachment_id = %d AND undone_at IS NULL ORDER BY id DESC LIMIT 1", $attachment_id ) );
		if ( ! $batch ) {
			return array();
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE attachment_id = %d AND batch = %s AND undone_at IS NULL ORDER BY id ASC", $attachment_id, $batch ), ARRAY_A );
		// phpcs:enable
	}

	/**
	 * @param int[] $ids
	 */
	public function mark_undone( array $ids ): void {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET undone_at = %s WHERE id IN ($placeholders)", array_merge( array( gmdate( 'Y-m-d H:i:s' ) ), $ids ) ) );
	}
}
