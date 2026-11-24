<?php

use MDIA\Checks\Detail;

class DetailTest extends WP_UnitTestCase {

	public function test_every_code_a_check_emits_reads_as_a_sentence() {
		$codes = array( 'missing', 'too_short', 'too_long', 'same_as_title', 'duplicate', 'meta_missing', 'noindex', 'default_content', 'filler_text', 'several_h1', 'skipped_level', 'several_h1,skipped_level', 'blocks_all', 'long_site_name', 'test_title', 'maybe_test', 'fail' );
		foreach ( $codes as $code ) {
			$text = Detail::text( 'post.metadesc', $code, '' );
			$this->assertNotSame( '', $text, $code );
			$this->assertStringNotContainsString( '_', $text, $code );
		}
	}

	public function test_details_that_carry_data_show_it() {
		$this->assertStringContainsString( 'GPTBot, ClaudeBot', Detail::text( 'site.robots', 'blocks_ai:GPTBot, ClaudeBot', '' ) );
		$this->assertStringContainsString( 'Pie de foto', Detail::text( 'post.acf_empty', 'text', 'Pie de foto' ) );
		$this->assertStringContainsString( 'Convocatorias Lorem ipsum', Detail::text( 'post.placeholder', 'filler_text', 'Convocatorias Lorem ipsum dolor' ) );
	}

	public function test_an_unknown_code_says_nothing_rather_than_showing_the_code() {
		$this->assertSame( '', Detail::text( 'post.metadesc', 'whatever_new', '' ) );
	}

	public function test_the_rest_api_gives_the_sentence() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id  = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '<p>Texto de la página.</p>' ) );
		$fid = ( new \MDIA\Data\Findings() )->upsert( 'post.featured_image', 'post', $id, array( 'status' => 'open', 'severity' => 'recommended', 'detail' => 'missing' ) );
		$row = \MDIA\Rest\Api::prepare_finding( ( new \MDIA\Data\Findings() )->get( $fid ) );

		$this->assertSame( Detail::text( 'post.featured_image', 'missing', '' ), $row['problem'] );
		$this->assertArrayNotHasKey( 'detail', $row );
	}
}
