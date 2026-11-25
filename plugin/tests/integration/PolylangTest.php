<?php

use MDIA\Checks\Engine;
use MDIA\Checks\Text_Generator;
use MDIA\Data\Findings;
use MDIA\I18n\Languages;
use MDIA\Settings;

class PolylangTest extends WP_UnitTestCase {
	use MDIA_Test_Helpers;

	private const GOOD = 'Five walking routes along the coast of the Rías Baixas, with maps, times and practical advice to prepare each stage of the way.';

	private MDIA_Fake_Provider $fake;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WPSEO_Meta' ) ) {
			$this->markTestSkipped( 'Yoast SEO is not installed in the test WordPress.' );
		}
		if ( defined( 'POLYLANG_VERSION' ) ) {
			$this->markTestSkipped( 'The real Polylang is loaded in the test WordPress.' );
		}
		\MDIA\Content\Page_Text::flush();
		Settings::update( array( 'auto_generate_new' => false, 'wizard_done' => true, 'text_consent' => true ) );
		$this->fake = MDIA_Fake_Provider::install();
	}

	public function tear_down() {
		MDIA_Fake_Polylang::uninstall();
		parent::tear_down();
	}

	private function page( string $lang, array $args = array() ): int {
		$id = self::factory()->post->create( array_merge( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Routes', 'post_excerpt' => '', 'post_content' => '<p>We walk the Galician coast, from Baiona to Fisterra.</p>' ), $args ) );
		MDIA_Fake_Polylang::set_post( $id, $lang );
		Engine::evaluate( 'post', $id );
		return $id;
	}

	public function test_a_polylang_without_languages_is_a_site_with_one_language() {
		$this->assertFalse( Languages::is_multilingual() );
		$this->assertSame( array(), Languages::all() );
	}

	public function test_the_languages_of_polylang_are_known() {
		MDIA_Fake_Polylang::install();

		$this->assertTrue( Languages::is_multilingual() );
		$this->assertSame( array( 'es' => 'Español', 'en' => 'English', 'ca' => 'Català' ), Languages::all() );
		$this->assertSame( 'es', Languages::default_code() );
	}

	public function test_each_page_and_term_has_its_language() {
		MDIA_Fake_Polylang::install();
		$page  = $this->page( 'en' );
		$plain = self::factory()->post->create();
		$term  = self::factory()->category->create( array( 'name' => 'Rutes' ) );
		MDIA_Fake_Polylang::set_term( $term, 'ca' );

		$this->assertSame( 'en', Languages::of( 'post', $page ) );
		$this->assertSame( 'en_US', Languages::locale_of( 'post', $page ) );
		$this->assertSame( 'ca', Languages::of( 'term', $term ) );
		$this->assertSame( 'ca', Languages::locale_of( 'term', $term ) );
		$this->assertSame( '', Languages::of( 'post', $plain ), 'A page nobody gave a language to.' );
	}

	public function test_work_runs_in_a_language_and_the_previous_one_comes_back() {
		MDIA_Fake_Polylang::install();

		$seen = Languages::in( 'ca', static fn() => array( pll_current_language(), Languages::in( 'en', static fn() => pll_current_language() ), pll_current_language() ) );

		$this->assertSame( array( 'ca', 'en', 'ca' ), $seen );
		$this->assertNull( MDIA_Fake_Polylang::$instance->curlang, 'Exactly what Polylang had: nothing.' );
	}

	public function test_the_translated_strings_of_the_site_follow_the_language() {
		MDIA_Fake_Polylang::install();
		$locale = get_locale();

		Languages::in( 'ca', static fn() => null );

		// Site title, tagline and the options of the SEO plugin are translated strings in Polylang.
		$this->assertSame( array( 'ca', $locale ), MDIA_Fake_Polylang::$instance->loaded, 'Loaded for the language, then put back.' );
	}

	public function test_a_type_polylang_no_longer_translates_has_no_language() {
		MDIA_Fake_Polylang::install();
		$image = $this->make_image();
		MDIA_Fake_Polylang::set_post( $image, 'en' ); // Left over from when media was translated.
		MDIA_Fake_Polylang::$untranslated = array( 'attachment' );

		$this->assertSame( '', Languages::of( 'post', $image ) );
	}

	public function test_a_text_is_proposed_in_the_language_of_its_page() {
		MDIA_Fake_Polylang::install();
		$english              = $this->page( 'en' );
		$catalan              = $this->page( 'ca', array( 'post_title' => 'Rutes' ) );
		$this->fake->rewrites = array( self::GOOD, self::GOOD );
		$findings             = new Findings();

		$this->assertSame( 'en', $findings->find( 'post.metadesc', 'post', $english, '' )['lang'] );
		Text_Generator::run( $findings->find( 'post.metadesc', 'post', $english, '' )['id'] );
		Text_Generator::run( $findings->find( 'post.metadesc', 'post', $catalan, '' )['id'] );

		$this->assertStringContainsString( 'Write in English.', $this->fake->rewrite_calls[0]['system'] );
		$this->assertStringContainsString( 'Write in Catalan.', $this->fake->rewrite_calls[1]['system'] );
	}

	public function test_an_image_on_a_translated_page_belongs_to_its_copy_in_that_language() {
		MDIA_Fake_Polylang::install();
		$original = $this->make_image();
		$copy     = $this->make_image();
		$plain    = $this->make_image();
		MDIA_Fake_Polylang::set_post( $original, 'es', 77 );
		MDIA_Fake_Polylang::set_post( $copy, 'en', 77 );
		$english = $this->make_post( $this->image_block( $original ) . $this->image_block( $plain ) );
		$catalan = $this->make_post( $this->image_block( $original ) );
		MDIA_Fake_Polylang::set_post( $english, 'en' );
		MDIA_Fake_Polylang::set_post( $catalan, 'ca' );

		$this->assertSame( $copy, Languages::image_for_page( $original, $english ) );
		$this->assertNull( Languages::image_for_page( $original, $catalan ), 'No copy in Catalan.' );
		$this->assertNull( Languages::image_for_page( $plain, $english ), 'Media without a language holds a text in the default one.' );
		$this->assertSame( array( $copy ), Languages::translations_of( $original ) );
	}
}
