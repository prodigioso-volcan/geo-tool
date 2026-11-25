<?php
/**
 * One row per audited image.
 *
 * @package GeoTool
 */

namespace MDIA\Data;

defined( 'ABSPATH' ) || exit;

final class Items {

	private const COLUMNS = array(
		'status', 'lang', 'issues', 'library_alt', 'proposed_alt', 'previous_alt', 'human_locked', 'queued', 'attempts',
		'last_error', 'provider', 'model', 'reviewed_by', 'audited_at', 'generated_at', 'applied_at',
	);

	private const INT_COLUMNS = array( 'human_locked', 'queued', 'attempts', 'reviewed_by' );

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mdia_items';
	}

	public function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE attachment_id = %d", $id ), ARRAY_A );
		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Inserts the row or updates only the given fields. A null value stores NULL.
	 */
	public function upsert( int $id, array $fields ): void {
		global $wpdb;

		$fields = array_intersect_key( $fields, array_flip( self::COLUMNS ) );
		$exists = null !== $this->get( $id );

		if ( ! $exists ) {
			$fields += array( 'status' => 'ok', 'issues' => '', 'library_alt' => '' );
		}
		if ( ! $fields ) {
			return;
		}

		$sets   = array();
		$values = array();
		foreach ( $fields as $column => $value ) {
			if ( null === $value ) {
				$sets[] = "$column = NULL";
				continue;
			}
			$sets[]   = "$column = " . ( in_array( $column, self::INT_COLUMNS, true ) ? '%d' : '%s' );
			$values[] = $value;
		}

		if ( $exists ) {
			$values[] = $id;
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET " . implode( ', ', $sets ) . ' WHERE attachment_id = %d', $values ) );
		} else {
			array_unshift( $values, $id );
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$this->table()} SET attachment_id = %d, " . implode( ', ', $sets ), $values ) );
		}

		delete_transient( 'mdia_status' );
	}

	/**
	 * Updates the row only if it is still in one of the given statuses, in one statement.
	 * Used by background work so it cannot overwrite a decision a person took meanwhile.
	 *
	 * @param string[] $statuses
	 * @return bool Whether the row was updated.
	 */
	public function update_if_status( int $id, array $fields, array $statuses ): bool {
		global $wpdb;

		$fields = array_intersect_key( $fields, array_flip( self::COLUMNS ) );
		if ( ! $fields || ! $statuses ) {
			return false;
		}

		$sets   = array();
		$values = array();
		foreach ( $fields as $column => $value ) {
			if ( null === $value ) {
				$sets[] = "$column = NULL";
				continue;
			}
			$sets[]   = "$column = " . ( in_array( $column, self::INT_COLUMNS, true ) ? '%d' : '%s' );
			$values[] = $value;
		}
		$values[] = $id;
		$values   = array_merge( $values, array_values( $statuses ) );
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET " . implode( ', ', $sets ) . " WHERE attachment_id = %d AND status IN ($in)", $values ) );
		delete_transient( 'mdia_status' );

		return (bool) $rows;
	}

	/**
	 * Applied images that some page still shows with missing or poor alt text.
	 *
	 * @return int[]
	 */
	public function applied_with_deficient_usages( int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT i.attachment_id FROM {$this->table()} i INNER JOIN {$wpdb->prefix}mdia_usages u ON u.attachment_id = i.attachment_id WHERE i.status = 'applied' AND u.manageable = 1 AND u.issues <> '' ORDER BY i.attachment_id ASC LIMIT %d", $limit ) );
		return array_map( 'intval', $ids );
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'attachment_id' => $id ), array( '%d' ) );
		delete_transient( 'mdia_status' );
	}

	/**
	 * @return array<string,int>
	 */
	public function count_by_status(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$this->table()} GROUP BY status ORDER BY status", ARRAY_A );
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * @param array{status?:string,issue?:string,search?:string,page?:int,per_page?:int} $args
	 * @return array{items: array<int,array>, total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'i.status = %s';
			$values[] = $args['status'];
		}
		if ( ! empty( $args['lang'] ) ) {
			$where[]  = 'i.lang = %s';
			$values[] = $args['lang'];
		}
		if ( ! empty( $args['issue'] ) ) {
			$where[]  = 'FIND_IN_SET(%s, i.issues) > 0';
			$values[] = $args['issue'];
		}
		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$where[]  = '(i.library_alt LIKE %s OR i.proposed_alt LIKE %s OR p.post_title LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$from     = "FROM {$this->table()} i LEFT JOIN {$wpdb->posts} p ON p.ID = i.attachment_id WHERE " . implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		$total_sql = "SELECT COUNT(*) $from";
		$total     = (int) $wpdb->get_var( $values ? $wpdb->prepare( $total_sql, $values ) : $total_sql );

		$values[] = $per_page;
		$values[] = ( $page - 1 ) * $per_page;
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT i.* $from ORDER BY i.attachment_id DESC LIMIT %d OFFSET %d", $values ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => array_map( array( $this, 'cast' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * @return int[]
	 */
	public function ids_by_status( string $status, int $limit, bool $queued_only = false ): array {
		global $wpdb;
		$queued = $queued_only ? ' AND queued = 1' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM {$this->table()} WHERE status = %s{$queued} ORDER BY attachment_id ASC LIMIT %d", $status, $limit ) );
		return array_map( 'intval', $ids );
	}

	/**
	 * How many images share this library alt text (case-insensitive).
	 */
	/**
	 * @return int[] Images with this alt text in the media library.
	 */
	public function ids_with_alt( string $alt, int $limit = 200 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM {$this->table()} WHERE LOWER(library_alt) = LOWER(%s) LIMIT %d", $alt, $limit ) ) );
	}

	public function count_alt( string $alt ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE LOWER(library_alt) = LOWER(%s)", $alt ) );
	}

	/**
	 * Images waiting for a proposal whose generation nobody has launched yet.
	 *
	 * @return int[]
	 */
	public function ids_to_generate( int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM {$this->table()} WHERE status = 'needs_generation' AND queued = 0 ORDER BY attachment_id ASC LIMIT %d", $limit ) );
		return array_map( 'intval', $ids );
	}

	public function count_to_generate(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE status = 'needs_generation' AND queued = 0" );
	}

	public function count_queued(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE queued = 1" );
	}

	/**
	 * @return int Rows that were queued.
	 */
	public function clear_queued(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (int) $wpdb->query( "UPDATE {$this->table()} SET queued = 0 WHERE queued = 1" );
		delete_transient( 'mdia_status' );
		return $rows;
	}

	private function cast( array $row ): array {
		$row['attachment_id'] = (int) $row['attachment_id'];
		foreach ( self::INT_COLUMNS as $column ) {
			if ( null !== $row[ $column ] ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}
		return $row;
	}
}
