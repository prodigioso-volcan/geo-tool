<?php

use MDIA\Data\Findings;

class FindingsTest extends WP_UnitTestCase {

	public function test_schema_is_current_and_has_the_findings_table() {
		global $wpdb;
		$this->assertSame( 6, \MDIA\Install\Schema::DB_VERSION );
		$this->assertSame( 6, (int) get_option( 'mdia_db_version' ) );
		$this->assertSame( $wpdb->prefix . 'mdia_findings', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'mdia_findings' ) ) );
		$this->assertContains( 'mdia_findings', \MDIA\Install\Schema::TABLES );
	}

	public function test_upgrade_from_version_1_keeps_existing_image_rows() {
		( new \MDIA\Data\Items() )->upsert( 77, array( 'status' => 'applied', 'library_alt' => 'Un faro' ) );
		update_option( 'mdia_db_version', 1 );

		\MDIA\Install\Schema::maybe_upgrade();

		$this->assertSame( 6, (int) get_option( 'mdia_db_version' ) );
		$this->assertSame( 'applied', ( new \MDIA\Data\Items() )->get( 77 )['status'] );
	}

	public function test_upgrade_gives_a_kind_to_the_usages_recorded_before() {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'mdia_usages', array( 'attachment_id' => 5, 'post_id' => 9, 'alt' => '', 'manageable' => 1 ) );
		$wpdb->insert( $wpdb->prefix . 'mdia_usages', array( 'attachment_id' => 6, 'post_id' => 9, 'alt' => '', 'manageable' => 0 ) );
		update_option( 'mdia_db_version', 2 );

		\MDIA\Install\Schema::maybe_upgrade();

		$usages = new \MDIA\Data\Usages();
		$this->assertSame( 'content', $usages->for_attachment( 5 )[0]['kind'] );
		$this->assertSame( 'builder', $usages->for_attachment( 6 )[0]['kind'] );
	}

	public function test_one_row_per_check_and_target() {
		$findings = new Findings();
		$a        = $findings->upsert( 'post.metadesc', 'post', 10, array( 'status' => 'open', 'severity' => 'required', 'current_value' => '' ) );
		$b        = $findings->upsert( 'post.metadesc', 'post', 10, array( 'status' => 'proposed', 'proposed_value' => 'Una descripción' ) );
		$c        = $findings->upsert( 'post.seo_title', 'post', 10, array( 'status' => 'open', 'severity' => 'recommended' ) );

		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, $c );
		$row = $findings->get( $a );
		$this->assertSame( 'proposed', $row['status'] );
		$this->assertSame( 'required', $row['severity'] );
		$this->assertSame( 'Una descripción', $row['proposed_value'] );
		$this->assertSame( 10, $row['target_id'] );
		$this->assertSame( $a, $findings->find( 'post.metadesc', 'post', 10 )['id'] );
		$this->assertNull( $findings->find( 'post.metadesc', 'post', 11 ) );
	}

	public function test_target_key_separates_findings_on_the_same_target() {
		$findings = new Findings();
		$a        = $findings->upsert( 'post.acf_empty', 'post', 10, array( 'status' => 'open' ), 'hero_title' );
		$b        = $findings->upsert( 'post.acf_empty', 'post', 10, array( 'status' => 'open' ), 'hero_text' );

		$this->assertNotSame( $a, $b );
		$this->assertSame( 'hero_text', $findings->get( $b )['target_key'] );
	}

	public function test_query_filters_and_counts() {
		$findings = new Findings();
		$findings->upsert( 'post.metadesc', 'post', 1, array( 'status' => 'open', 'severity' => 'required' ) );
		$findings->upsert( 'post.metadesc', 'post', 2, array( 'status' => 'proposed', 'severity' => 'required' ) );
		$findings->upsert( 'site.sitemap', 'site', 0, array( 'status' => 'waived', 'severity' => 'required' ) );
		$findings->upsert( 'term.description', 'term', 5, array( 'status' => 'open', 'severity' => 'recommended' ) );

		$this->assertSame( 2, $findings->query( array( 'check_id' => 'post.metadesc' ) )['total'] );
		$this->assertSame( 1, $findings->query( array( 'status' => 'proposed' ) )['total'] );
		$this->assertSame( 2, $findings->query( array( 'target_type' => array( 'site', 'term' ) ) )['total'] );
		$this->assertSame( 2, $findings->query( array( 'status' => array( 'open', 'proposed' ), 'severity' => 'required' ) )['total'] );
		$this->assertCount( 1, $findings->query( array( 'per_page' => 1, 'page' => 2, 'check_id' => 'post.metadesc' ) )['items'] );

		$counts = $findings->counts();
		$this->assertSame( 1, $counts['post']['required']['open'] );
		$this->assertSame( 1, $counts['post']['required']['proposed'] );
		$this->assertSame( 1, $counts['site']['required']['waived'] );
		$this->assertSame( 1, $counts['term']['recommended']['open'] );
	}

	public function test_delete_and_conditional_update() {
		$findings = new Findings();
		$id       = $findings->upsert( 'post.metadesc', 'post', 1, array( 'status' => 'open' ) );
		$findings->upsert( 'post.seo_title', 'post', 1, array( 'status' => 'open' ) );

		$this->assertFalse( $findings->update_if_status( $id, array( 'status' => 'proposed' ), array( 'error' ) ) );
		$this->assertTrue( $findings->update_if_status( $id, array( 'status' => 'proposed' ), array( 'open', 'error' ) ) );
		$this->assertSame( 'proposed', $findings->get( $id )['status'] );

		$findings->delete( $id );
		$this->assertNull( $findings->get( $id ) );
		$findings->delete_for_target( 'post', 1 );
		$this->assertSame( 0, $findings->query( array( 'target_type' => 'post' ) )['total'] );
	}

	public function test_targets_with_pending_findings_are_counted_once() {
		$findings = new Findings();
		$findings->upsert( 'post.metadesc', 'post', 1, array( 'status' => 'open' ) );
		$findings->upsert( 'post.seo_title', 'post', 1, array( 'status' => 'proposed' ) );
		$findings->upsert( 'post.metadesc', 'post', 2, array( 'status' => 'applied' ) );

		$this->assertSame( 1, $findings->count_pending_targets( 'post' ) );
	}
}
