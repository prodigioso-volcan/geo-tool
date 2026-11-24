<?php
/**
 * Index of where each image is used in content.
 *
 * @package GeoTool
 */

namespace MDIA\Data;

defined( 'ABSPATH' ) || exit;

final class Usages {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mdia_usages';
	}

	/**
	 * @param array<int,array{attachment_id:int,alt:string,issues:string,occurrences:int,manageable:int}> $rows
	 */
	public function replace_for_post( int $post_id, array $rows ): void {
		global $wpdb;

		$this->delete_for_post( $post_id );

		foreach ( $rows as $row ) {
			$wpdb->insert(
				$this->table(),
				array(
					'attachment_id' => (int) $row['attachment_id'],
					'post_id'       => $post_id,
					'alt'           => (string) $row['alt'],
					'issues'        => (string) $row['issues'],
					'occurrences'   => (int) $row['occurrences'],
					'manageable'    => (int) $row['manageable'],
				),
				array( '%d', '%d', '%s', '%s', '%d', '%d' )
			);
		}

		delete_transient( 'mdia_status' );
	}

	public function for_attachment( int $id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE attachment_id = %d ORDER BY post_id ASC", $id ), ARRAY_A );
	}

	/**
	 * @return int[]
	 */
	public function attachment_ids_for_post( int $post_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT attachment_id FROM {$this->table()} WHERE post_id = %d", $post_id ) ) );
	}

	public function delete_for_post( int $post_id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'post_id' => $post_id ), array( '%d' ) );
		delete_transient( 'mdia_status' );
	}

	public function delete_for_attachment( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'attachment_id' => $id ), array( '%d' ) );
		delete_transient( 'mdia_status' );
	}

	/**
	 * Usages with missing or poor alt text. Images a person marked as decorative or
	 * chose to ignore are left out: that is a decision, not something pending.
	 */
	public function count_deficient( bool $manageable = true ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} u LEFT JOIN {$wpdb->prefix}mdia_items i ON i.attachment_id = u.attachment_id WHERE u.issues <> '' AND u.manageable = %d AND ( i.status IS NULL OR i.status NOT IN ('decorative','ignored') )", $manageable ? 1 : 0 ) );
	}

	/**
	 * @return int[] Posts where the plugin can rewrite this image.
	 */
	public function manageable_post_ids( int $attachment_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$this->table()} WHERE attachment_id = %d AND manageable = 1 ORDER BY post_id ASC", $attachment_id ) ) );
	}

	public function count_unmanaged(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE manageable = 0" );
	}
}
