<?php

use MDIA\Seo\Seo;

class SeoAdapterTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WPSEO_Meta' ) ) {
			$this->markTestSkipped( 'Yoast SEO is not installed in the test WordPress.' );
		}
	}

	public function test_an_approved_description_is_never_read_as_a_template() {
		$seo = \MDIA\Seo\Seo::adapter();
		$id  = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Transparencia' ) );
		update_post_meta( $id, 'secreto', 'dato-interno' );

		$seo->set_post_description( $id, 'Visita %%sitename%% y %%cf_secreto%% hoy, con un 50% de descuento.' );

		$published = $seo->post_description( $id );
		$this->assertStringNotContainsString( 'dato-interno', $published );
		$this->assertStringNotContainsString( get_bloginfo( 'name' ), $published );
		$this->assertStringContainsString( '50%', $published );
	}

	public function test_yoast_is_detected() {
		$seo = Seo::adapter();

		$this->assertInstanceOf( \MDIA\Seo\Yoast::class, $seo );
		$this->assertTrue( $seo->is_active() );
		$this->assertSame( 'Yoast SEO', $seo->name() );
	}

	public function test_description_is_empty_until_written_and_read_back_in_the_same_request() {
		$seo  = Seo::adapter();
		$post = self::factory()->post->create( array( 'post_title' => 'Rutas por las Rías Baixas', 'post_status' => 'publish' ) );

		$this->assertSame( '', $seo->post_description( $post ) );

		$seo->set_post_description( $post, 'Cinco rutas a pie por la costa de las Rías Baixas, con mapas y consejos.' );

		$this->assertSame( 'Cinco rutas a pie por la costa de las Rías Baixas, con mapas y consejos.', $seo->post_description( $post ) );
		$this->assertSame( 'Cinco rutas a pie por la costa de las Rías Baixas, con mapas y consejos.', $seo->post_raw_description( $post ) );
	}

	public function test_description_supplied_by_a_yoast_template_counts() {
		$seo  = Seo::adapter();
		$post = self::factory()->post->create( array( 'post_title' => 'Rutas', 'post_excerpt' => 'Un extracto escrito a mano.', 'post_status' => 'publish' ) );
		WPSEO_Options::set( 'metadesc-post', '%%excerpt%%' );

		$this->assertSame( 'Un extracto escrito a mano.', $seo->post_description( $post ) );
		$this->assertSame( '', $seo->post_raw_description( $post ) );
	}

	public function test_title_is_the_composed_one_with_the_site_name() {
		$seo  = Seo::adapter();
		$site = get_bloginfo( 'name' ); // Yoast caches the site name for the whole run.
		$post = self::factory()->post->create( array( 'post_title' => 'Rutas por las Rías Baixas', 'post_status' => 'publish' ) );

		$this->assertStringContainsString( 'Rutas por las Rías Baixas', $seo->post_title( $post ) );
		$this->assertStringContainsString( $site, $seo->post_title( $post ) );
		$this->assertSame( '', $seo->post_raw_title( $post ) );

		$seo->set_post_title( $post, 'Rutas a pie' );
		$this->assertStringContainsString( 'Rutas a pie', $seo->post_title( $post ) );
		$this->assertStringContainsString( $site, $seo->post_title( $post ), 'The site name is kept.' );
		$this->assertStringNotContainsString( 'Rías Baixas', $seo->post_title( $post ) );

		$seo->set_post_title( $post, '' );
		$this->assertSame( '', $seo->post_raw_title( $post ) );
	}

	public function test_noindex_per_post_and_per_type() {
		$seo  = Seo::adapter();
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertFalse( $seo->is_post_noindex( $post ) );
		$this->assertFalse( $seo->is_type_noindex( 'post' ) );

		WPSEO_Meta::set_value( 'meta-robots-noindex', '1', $post );
		$this->assertTrue( $seo->is_post_noindex( $post ) );

		WPSEO_Options::set( 'noindex-page', true );
		$this->assertTrue( $seo->is_type_noindex( 'page' ) );
	}

	public function test_term_meta_description_round_trip() {
		$seo  = Seo::adapter();
		$term = self::factory()->category->create( array( 'name' => 'Senderismo', 'description' => '' ) );

		$this->assertSame( '', $seo->term_description_meta( $term, 'category' ) );
		$seo->set_term_description_meta( $term, 'category', 'Rutas de senderismo por Galicia.' );
		$this->assertSame( 'Rutas de senderismo por Galicia.', $seo->term_description_meta( $term, 'category' ) );
	}

	public function test_site_settings_are_exposed() {
		$seo = Seo::adapter();
		WPSEO_Options::set( 'enable_xml_sitemap', false );
		// Not switched on here: Yoast would write a real llms.txt into the WordPress folder.
		WPSEO_Options::set( 'company_or_person', 'company' );
		WPSEO_Options::set( 'company_name', 'Fundación Ejemplo' );

		$this->assertFalse( $seo->site( 'sitemap' ) );
		$this->assertFalse( $seo->site( 'llms_txt' ) );
		$this->assertSame( 'Fundación Ejemplo', $seo->site( 'organization_name' ) );
		$this->assertSame( '', $seo->site( 'organization_logo' ) );
		$this->assertSame( '', $seo->site( 'home_description' ) );
		$this->assertNull( $seo->site( 'no-existe' ) );
		$this->assertStringContainsString( 'page=wpseo_page_settings', $seo->settings_url( 'site-features' ) );
	}

	public function test_without_an_seo_plugin_everything_degrades_quietly() {
		add_filter( 'mdia_seo_adapter', static fn() => new \MDIA\Seo\None() );
		$seo  = Seo::adapter();
		$post = self::factory()->post->create( array( 'post_title' => 'Rutas', 'post_status' => 'publish' ) );

		$this->assertFalse( $seo->is_active() );
		$this->assertSame( '', $seo->post_description( $post ) );
		$this->assertStringContainsString( 'Rutas', $seo->post_title( $post ) );
		$this->assertFalse( $seo->set_post_description( $post, 'x' ) );
		$this->assertFalse( $seo->is_post_noindex( $post ) );
		$this->assertNull( $seo->site( 'sitemap' ) );
		$this->assertSame( '', $seo->settings_url( 'site-features' ) );
	}
}
