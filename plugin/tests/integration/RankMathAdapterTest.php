<?php

use MDIA\Seo\Rank_Math;

class RankMathAdapterTest extends WP_UnitTestCase {

	private Rank_Math $seo;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( Rank_Math::class ) || ! Rank_Math::available() ) {
			$this->markTestSkipped( 'Rank Math is not installed in the test WordPress.' );
		}
		$this->seo = new Rank_Math();
		delete_option( 'rank-math-options-titles' );
		delete_option( 'rank_math_modules' );
	}

	private function page( array $args = array() ): int {
		return self::factory()->post->create( array_merge( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Transparencia', 'post_excerpt' => '', 'post_content' => '<p>Cuentas anuales y órganos de gobierno de la fundación.</p>' ), $args ) );
	}

	private function titles( array $values ): void {
		update_option( 'rank-math-options-titles', array_merge( (array) get_option( 'rank-math-options-titles', array() ), $values ) );
	}

	public function test_it_identifies_itself() {
		$this->assertTrue( $this->seo->is_active() );
		$this->assertSame( 'Rank Math', $this->seo->name() );
	}

	public function test_a_description_nobody_wrote_is_missing_even_if_rank_math_would_cut_the_content() {
		$id = $this->page();

		$this->assertSame( '', $this->seo->post_raw_description( $id ) );
		$this->assertSame( '', $this->seo->post_description( $id ), 'The first lines of the content are not a written description.' );
	}

	public function test_written_description_is_read_and_saved_in_the_rank_math_field() {
		$id = $this->page();

		$this->assertTrue( $this->seo->set_post_description( $id, 'Cuentas, patronato y memoria de actividad.' ) );

		$this->assertSame( 'Cuentas, patronato y memoria de actividad.', get_post_meta( $id, 'rank_math_description', true ) );
		$this->assertSame( 'Cuentas, patronato y memoria de actividad.', $this->seo->post_raw_description( $id ) );
		$this->assertSame( 'Cuentas, patronato y memoria de actividad.', $this->seo->post_description( $id ) );
	}

	public function test_an_approved_text_is_never_read_as_a_template() {
		$id = $this->page();
		update_post_meta( $id, 'secreto', 'dato-interno' );

		$this->seo->set_post_description( $id, 'Visita %sitename% y %customfield(secreto)% hoy, con un 50% de descuento y %title%.' );

		$published = $this->seo->post_description( $id );
		$this->assertStringNotContainsString( 'dato-interno', $published );
		$this->assertStringNotContainsString( get_bloginfo( 'name' ), $published );
		$this->assertStringNotContainsString( 'Transparencia', $published );
		$this->assertStringContainsString( '50%', $published );
	}

	public function test_a_hand_written_excerpt_is_what_rank_math_publishes() {
		$id = $this->page( array( 'post_type' => 'post', 'post_excerpt' => 'Resumen escrito a mano.' ) );

		$this->assertSame( 'Resumen escrito a mano.', $this->seo->post_description( $id ) );
	}

	public function test_a_description_template_of_the_site_counts() {
		$this->titles( array( 'pt_page_description' => 'Información sobre %title% en %sitename%.' ) );
		$id = $this->page();

		$this->assertSame( 'Información sobre Transparencia en ' . get_bloginfo( 'name' ) . '.', $this->seo->post_description( $id ) );
	}

	public function test_title_has_the_site_name_by_default_and_keeps_it_when_set() {
		$id = $this->page();
		$this->assertStringContainsString( 'Transparencia', $this->seo->post_title( $id ) );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $this->seo->post_title( $id ) );
		$this->assertSame( '', $this->seo->post_raw_title( $id ) );

		$this->seo->set_post_title( $id, 'Cuentas y gobierno' );
		$this->assertSame( 'Cuentas y gobierno %sep% %sitename%', get_post_meta( $id, 'rank_math_title', true ) );
		$this->assertStringStartsWith( 'Cuentas y gobierno', $this->seo->post_title( $id ) );
		$this->assertStringEndsWith( get_bloginfo( 'name' ), $this->seo->post_title( $id ) );

		$this->seo->set_post_title( $id, 'Solo esto', false );
		$this->assertSame( 'Solo esto', $this->seo->post_title( $id ) );

		$this->seo->restore_post_title( $id, '' );
		$this->assertSame( '', $this->seo->post_raw_title( $id ) );
	}

	public function test_noindex_of_a_page_and_of_a_whole_type() {
		$id = $this->page();
		$this->assertFalse( $this->seo->is_post_noindex( $id ) );
		$this->assertFalse( $this->seo->is_type_noindex( 'page' ) );

		update_post_meta( $id, 'rank_math_robots', array( 'noindex', 'follow' ) );
		$this->assertTrue( $this->seo->is_post_noindex( $id ) );

		$this->titles( array( 'pt_page_custom_robots' => 'on', 'pt_page_robots' => array( 'noindex' ) ) );
		$this->assertTrue( $this->seo->is_type_noindex( 'page' ) );
	}

	public function test_term_description() {
		$term = self::factory()->category->create( array( 'name' => 'Clínica', 'description' => '' ) );
		$this->assertSame( '', $this->seo->term_description_meta( $term, 'category' ) );

		$this->seo->set_term_description_meta( $term, 'category', 'Ensayos y estudios clínicos de la fundación.' );

		$this->assertSame( 'Ensayos y estudios clínicos de la fundación.', get_term_meta( $term, 'rank_math_description', true ) );
		$this->assertSame( 'Ensayos y estudios clínicos de la fundación.', $this->seo->term_description_meta( $term, 'category' ) );
	}

	public function test_with_the_default_template_the_description_of_the_term_is_what_is_published() {
		$this->titles( array( 'tax_category_description' => '%term_description%' ) );
		$term = self::factory()->category->create( array( 'name' => 'Clínica', 'description' => 'Ensayos y estudios clínicos de la fundación.' ) );

		$this->assertSame( 'Ensayos y estudios clínicos de la fundación.', $this->seo->term_description_meta( $term, 'category' ) );
	}

	public function test_custom_robots_switched_off_do_not_hide_a_type() {
		$this->titles( array( 'pt_page_custom_robots' => 'off', 'pt_page_robots' => array( 'noindex' ) ) );

		$this->assertFalse( $this->seo->is_type_noindex( 'page' ) );
	}

	public function test_restoring_a_description_keeps_its_variables() {
		$id = $this->page();

		$this->seo->restore_post_description( $id, 'Guía de %title%' );

		$this->assertSame( 'Guía de %title%', get_post_meta( $id, 'rank_math_description', true ) );
		$this->assertSame( 'Guía de Transparencia', $this->seo->post_description( $id ) );
	}

	public function test_site_settings() {
		$this->assertFalse( $this->seo->site( 'sitemap' ) );
		$this->assertSame( '', $this->seo->site( 'organization_name' ) );
		$this->assertSame( get_bloginfo( 'name' ), $this->seo->site( 'title_site_name' ) );
		$this->assertNull( $this->seo->site( 'whatever' ) );

		update_option( 'rank_math_modules', array( 'sitemap', 'rich-snippet', 'llms-txt' ) );
		$this->titles(
			array(
				'knowledgegraph_name'  => 'Fundación',
				'knowledgegraph_logo'  => 'https://example.org/logo.png',
				'open_graph_image'     => 'https://example.org/og.png',
				'homepage_description' => 'Investigación biomédica en tres hospitales.',
			)
		);

		$this->assertTrue( $this->seo->site( 'sitemap' ) );
		$this->assertTrue( $this->seo->site( 'schema' ) );
		$this->assertTrue( $this->seo->site( 'llms_txt' ) );
		$this->assertSame( 'Fundación', $this->seo->site( 'organization_name' ) );
		$this->assertSame( 'https://example.org/logo.png', $this->seo->site( 'organization_logo' ) );
		$this->assertSame( 'https://example.org/og.png', $this->seo->site( 'social_image' ) );
		$this->assertSame( 'Investigación biomédica en tres hospitales.', $this->seo->site( 'home_description' ) );
	}

	public function test_settings_links_go_to_rank_math_screens() {
		foreach ( array( 'homepage', 'site-representation', 'site-features', 'site-basics', 'robots', '' ) as $section ) {
			$this->assertStringContainsString( 'page=rank-math', $this->seo->settings_url( $section ), $section );
		}
	}

	public function test_saving_its_fields_is_watched_so_checks_run_again() {
		$this->assertContains( 'rank_math_description', $this->seo->watched_meta() );
		$this->assertContains( 'rank_math_title', $this->seo->watched_meta() );
		$this->assertContains( '_yoast_wpseo_metadesc', ( new \MDIA\Seo\Yoast() )->watched_meta() );
	}

	public function test_the_checks_work_through_it() {
		add_filter( 'mdia_seo_adapter', fn() => $this->seo );
		$id = $this->page();
		\MDIA\Checks\Engine::evaluate( 'post', $id );

		$findings = new \MDIA\Data\Findings();
		$row      = $findings->find( 'post.metadesc', 'post', $id, '' );
		$this->assertSame( 'missing', $row['detail'] );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$text  = 'Cuentas anuales, patronato, memoria de actividad y portal de transparencia de la fundación, con los documentos de cada ejercicio.';
		$this->assertTrue( \MDIA\Checks\Finding_Actions::approve( (int) $row['id'], $text, $admin ) );
		$this->assertSame( $text, get_post_meta( $id, 'rank_math_description', true ) );

		$this->assertTrue( \MDIA\Checks\Finding_Actions::undo( (int) $row['id'], $admin ) );
		$this->assertSame( '', (string) get_post_meta( $id, 'rank_math_description', true ) );
	}
}
