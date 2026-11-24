<?php
/**
 * One row per SEO/GEO check that fails, or was decided by a person, on one target.
 *
 * @package GeoTool
 */

namespace MDIA\Data;

defined( 'ABSPATH' ) || exit;

final class Findings {

	/**
	 * Statuses that still need someone to do something.
	 */
	public const PENDING = array( 'open', 'proposed', 'error' );

	private const COLUMNS = array(
		'status', 'severity', 'current_value', 'proposed_value', 'previous_value', 'applied_value', 'detail', 'provider', 'model',
		'attempts', 'queued', 'last_error', 'reviewed_by', 'generated_at', 'resolved_at',
	);

	private const INT_COLUMNS = array( 'attempts', 'queued', 'reviewed_by' );

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mdia_findings';
	}

	public function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->cast( $row ) : null;
	}

	public function find( string $check_id, string $target_type, int $target_id, string $target_key = '' ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE check_id = %s AND target_type = %s AND target_id = %d AND target_key = %s", $check_id, $target_type, $target_id, $target_key ), ARRAY_A );
		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Creates the finding or updates only the given fields. A null value stores NULL.
	 *
	 * @return int Finding id.
	 */
	public function upsert( string $check_id, string $target_type, int $target_id, array $fields, string $target_key = '' ): int {
		global $wpdb;

		$existing = $this->find( $check_id, $target_type, $target_id, $target_key );
		$fields   = array_intersect_key( $fields, array_flip( self::COLUMNS ) );

		if ( ! $existing ) {
			$wpdb->insert(
				$this->table(),
				array(
					'check_id'    => $check_id,
					'target_type' => $target_type,
					'target_id'   => $target_id,
					'target_key'  => $target_key,
					'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%s', '%d', '%s', '%s' )
			);
			$id = (int) $wpdb->insert_id;
		} else {
			$id = (int) $existing['id'];
		}

		$this->write( $id, $fields );
		return $id;
	}

	/**
	 * Updates the row only if it is still in one of the given statuses, in one statement,
	 * so background work never overwrites a decision a person took meanwhile.
	 *
	 * @param string[] $statuses
	 */
	public function update_if_status( int $id, array $fields, array $statuses ): bool {
		$fields = array_intersect_key( $fields, array_flip( self::COLUMNS ) );
		return $fields && $statuses ? $this->write( $id, $fields, $statuses ) : false;
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		delete_transient( 'mdia_status' );
	}

	/**
	 * Deletes every finding of a check about one key (an ACF field name, for instance), on any target.
	 */
	public function delete_by_key( string $check_id, string $target_key ): void {
		global $wpdb;
		// BINARY: keys that differ only in case are different keys.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE check_id = %s AND BINARY target_key = %s", $check_id, $target_key ) );
		delete_transient( 'mdia_status' );
	}

	public function delete_for_target( string $target_type, int $target_id ): void {
		global $wpdb;
		$wpdb->delete(
			$this->table(),
			array(
				'target_type' => $target_type,
				'target_id'   => $target_id,
			),
			array( '%s', '%d' )
		);
		delete_transient( 'mdia_status' );
	}

	/**
	 * @param array $args check_id, target_type, status, severity (string or string[]), target_id, queued, page, per_page.
	 * @return array{items: array<int,array>, total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();

		foreach ( array( 'check_id', 'target_type', 'status', 'severity' ) as $column ) {
			if ( empty( $args[ $column ] ) ) {
				continue;
			}
			$list     = array_values( (array) $args[ $column ] );
			$where[]  = "$column IN (" . implode( ',', array_fill( 0, count( $list ), '%s' ) ) . ')';
			$values   = array_merge( $values, $list );
		}
		if ( isset( $args['target_id'] ) ) {
			$where[]  = 'target_id = %d';
			$values[] = (int) $args['target_id'];
		}
		if ( isset( $args['queued'] ) ) {
			$where[]  = 'queued = %d';
			$values[] = $args['queued'] ? 1 : 0;
		}

		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$from     = "FROM {$this->table()} WHERE " . implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		$total_sql = "SELECT COUNT(*) $from";
		$total     = (int) $wpdb->get_var( $values ? $wpdb->prepare( $total_sql, $values ) : $total_sql );

		$values[] = $per_page;
		$values[] = ( $page - 1 ) * $per_page;
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * $from ORDER BY id ASC LIMIT %d OFFSET %d", $values ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => array_map( array( $this, 'cast' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * @return array<string, array<string, array<string,int>>> Rows by target type, severity and status.
	 */
	public function counts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results( "SELECT target_type, severity, status, COUNT(*) AS total FROM {$this->table()} GROUP BY target_type, severity, status", ARRAY_A );
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row['target_type'] ][ $row['severity'] ][ $row['status'] ] = (int) $row['total'];
		}
		return $counts;
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

	/**
	 * How many distinct targets of a type still have something pending.
	 */
	public function count_pending_targets( string $target_type ): int {
		global $wpdb;
		$in = "'" . implode( "','", self::PENDING ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constant list.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT target_id) FROM {$this->table()} WHERE target_type = %s AND status IN ($in)", $target_type ) );
	}

	/**
	 * @param string[] $statuses When given, the row is only written if it is in one of them.
	 */
	private function write( int $id, array $fields, array $statuses = array() ): bool {
		global $wpdb;

		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$sets                 = array();
		$values               = array();
		foreach ( $fields as $column => $value ) {
			if ( null === $value ) {
				$sets[] = "$column = NULL";
				continue;
			}
			$sets[]   = "$column = " . ( in_array( $column, self::INT_COLUMNS, true ) ? '%d' : '%s' );
			$values[] = $value;
		}
		$values[] = $id;

		$where = 'id = %d';
		if ( $statuses ) {
			$where .= ' AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$values = array_merge( $values, array_values( $statuses ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET " . implode( ', ', $sets ) . " WHERE $where", $values ) );
		delete_transient( 'mdia_status' );

		return (bool) $rows;
	}

	private function cast( array $row ): array {
		foreach ( array( 'id', 'target_id', 'attempts', 'queued' ) as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}
		if ( null !== $row['reviewed_by'] ) {
			$row['reviewed_by'] = (int) $row['reviewed_by'];
		}
		return $row;
	}
}
