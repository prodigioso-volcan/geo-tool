<?php

use MDIA\Data\Changes;
use MDIA\Data\Items;
use MDIA\Data\Usages;

class RepositoriesTest extends WP_UnitTestCase {

	public function test_upsert_twice_keeps_one_row_with_latest_values() {
		$items = new Items();
		$items->upsert( 10, array( 'status' => 'needs_generation', 'issues' => 'missing', 'library_alt' => '' ) );
		$items->upsert( 10, array( 'status' => 'needs_review', 'proposed_alt' => 'Un faro' ) );

		$row = $items->get( 10 );
		$this->assertSame( 'needs_review', $row['status'] );
		$this->assertSame( 'missing', $row['issues'] );
		$this->assertSame( 'Un faro', $row['proposed_alt'] );
		$this->assertSame( 1, array_sum( $items->count_by_status() ) );
	}

	public function test_get_returns_null_for_unknown_and_delete_removes() {
		$items = new Items();
		$this->assertNull( $items->get( 999 ) );
		$items->upsert( 11, array( 'status' => 'ok' ) );
		$items->delete( 11 );
		$this->assertNull( $items->get( 11 ) );
	}

	public function test_count_by_status_groups_rows() {
		$items = new Items();
		$items->upsert( 1, array( 'status' => 'ok' ) );
		$items->upsert( 2, array( 'status' => 'ok' ) );
		$items->upsert( 3, array( 'status' => 'needs_review' ) );

		$this->assertSame( array( 'needs_review' => 1, 'ok' => 2 ), $items->count_by_status() );
	}

	public function test_count_alt_is_case_insensitive() {
		$items = new Items();
		$items->upsert( 1, array( 'status' => 'ok', 'library_alt' => 'Logo' ) );
		$items->upsert( 2, array( 'status' => 'ok', 'library_alt' => 'logo' ) );
		$items->upsert( 3, array( 'status' => 'ok', 'library_alt' => 'LOGO' ) );
		$items->upsert( 4, array( 'status' => 'ok', 'library_alt' => 'Logotipo' ) );

		$this->assertSame( 3, $items->count_alt( 'Logo' ) );
	}

	public function test_query_filters_paginates_and_counts() {
		$items = new Items();
		for ( $i = 1; $i <= 5; $i++ ) {
			$items->upsert( $i, array( 'status' => 'needs_review', 'issues' => 'missing', 'proposed_alt' => "Propuesta $i" ) );
		}
		$items->upsert( 6, array( 'status' => 'ok', 'issues' => '' ) );
		$items->upsert( 7, array( 'status' => 'needs_review', 'issues' => 'filename,too_short', 'proposed_alt' => 'Otra' ) );

		$page = $items->query( array( 'status' => 'needs_review', 'per_page' => 2, 'page' => 2 ) );
		$this->assertSame( 6, $page['total'] );
		$this->assertCount( 2, $page['items'] );

		$by_issue = $items->query( array( 'issue' => 'too_short' ) );
		$this->assertSame( array( 7 ), array_map( 'intval', wp_list_pluck( $by_issue['items'], 'attachment_id' ) ) );

		$search = $items->query( array( 'search' => 'Propuesta 3' ) );
		$this->assertSame( 1, $search['total'] );
	}

	public function test_ids_by_status_respects_limit_and_queued_flag() {
		$items = new Items();
		$items->upsert( 1, array( 'status' => 'needs_generation', 'queued' => 1 ) );
		$items->upsert( 2, array( 'status' => 'needs_generation', 'queued' => 0 ) );
		$items->upsert( 3, array( 'status' => 'needs_generation', 'queued' => 0 ) );

		$this->assertSame( array( 1, 2 ), $items->ids_by_status( 'needs_generation', 2 ) );
		$this->assertSame( array( 1 ), $items->ids_by_status( 'needs_generation', 10, true ) );
	}

	public function test_usages_replace_for_post_only_touches_that_post() {
		$usages = new Usages();
		$usages->replace_for_post( 100, array( array( 'attachment_id' => 12, 'alt' => '', 'issues' => 'missing', 'occurrences' => 1, 'manageable' => 1 ) ) );
		$usages->replace_for_post( 200, array( array( 'attachment_id' => 12, 'alt' => 'Bien', 'issues' => '', 'occurrences' => 2, 'manageable' => 1 ) ) );
		$usages->replace_for_post( 100, array( array( 'attachment_id' => 13, 'alt' => 'x', 'issues' => 'too_short', 'occurrences' => 1, 'manageable' => 0 ) ) );

		$for_12 = $usages->for_attachment( 12 );
		$this->assertCount( 1, $for_12 );
		$this->assertSame( 200, (int) $for_12[0]['post_id'] );
		$this->assertSame( 0, $usages->count_deficient( true ) );
		$this->assertSame( 1, $usages->count_deficient( false ) );

		$usages->delete_for_post( 200 );
		$this->assertCount( 0, $usages->for_attachment( 12 ) );
		$usages->delete_for_attachment( 13 );
		$this->assertCount( 0, $usages->for_attachment( 13 ) );
	}

	public function test_changes_latest_batch_returns_only_newest_not_undone() {
		$changes = new Changes();
		$changes->record( 'batch-a', 12, 0, 'viejo', 'medio', 1 );
		$id1 = $changes->record( 'batch-b', 12, 0, 'medio', 'nuevo', 1 );
		$id2 = $changes->record( 'batch-b', 12, 55, '', 'nuevo', 1 );

		$latest = $changes->latest_batch( 12 );
		$this->assertSame( array( $id1, $id2 ), array_map( 'intval', wp_list_pluck( $latest, 'id' ) ) );

		$changes->mark_undone( array( $id1, $id2 ) );
		$latest = $changes->latest_batch( 12 );
		$this->assertCount( 1, $latest );
		$this->assertSame( 'batch-a', $latest[0]['batch'] );
	}
}
