<?php

use MDIA\Checks\Engine;
use MDIA\Checks\Text_Generator;
use MDIA\Data\Findings;
use MDIA\I18n\Languages;
use MDIA\Settings;

class WpmlTest extends WP_UnitTestCase {
	use MDIA_Test_Helpers;

	private const GOOD = 'Five walking routes along the coast of the Rías Baixas, with maps, times and practical advice to prepare each stage of the way.';

	private MDIA_Fake_Provider $fake;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WPSEO_Meta' ) ) {
			$this->markTestSkipped( 'Yoast SEO is not installed in the test WordPress.' );
		}
		\MDIA\Content\Page_Text::flush();
		Settings::update( array( 'auto_generate_new' => false, 'wizard_done' => true, 'text_consent' => true ) );
		$this->fake = MDIA_Fake_Provider::install();
	}

	private function page( string $lang, array $args = array() ): int {
		$id = self::factory()->post->create( array_merge( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Routes', 'post_excerpt' => '', 'post_content' => '<p>We walk the Galician coast, from Baiona to Fisterra.</p>' ), $args ) );
		MDIA_Fake_Wpml::set_post( $id, $lang );
		Engine::evaluate( 'post', $id );
		return $id;
	}

	private function finding( string $check, int $id, string $type = 'post' ): ?array {
		return ( new Findings() )->find( $check, $type, $id, '' );
	}

	public function test_without_wpml_the_site_has_one_language() {
		$id = self::factory()->post->create();

		$this->assertFalse( Languages::is_multilingual() );
		$this->assertSame( array(), Languages::all() );
		$this->assertSame( '', Languages::of( 'post', $id ) );
		$this->assertSame( get_locale(), Languages::locale_of( 'post', $id ) );
		$this->assertSame( 'x', Languages::in( 'en', static fn() => 'x' ), 'Work still runs.' );
	}

	public function test_the_languages_active_in_wpml_are_known() {
		MDIA_Fake_Wpml::install();

		$this->assertTrue( Languages::is_multilingual() );
		$this->assertSame( array( 'es' => 'Español', 'en' => 'English', 'ca' => 'Català' ), Languages::all() );
	}

	public function test_each_page_and_term_has_its_language() {
		MDIA_Fake_Wpml::install();
		$page = $this->page( 'en' );
		$term = self::factory()->category->create( array( 'name' => 'Rutes' ) );
		MDIA_Fake_Wpml::set_term( $term, 'ca' );

		$this->assertSame( 'en', Languages::of( 'post', $page ) );
		$this->assertSame( 'en_US', Languages::locale_of( 'post', $page ) );
		$this->assertSame( 'ca', Languages::of( 'term', $term ) );
		$this->assertSame( 'ca', Languages::locale_of( 'term', $term ) );
		$this->assertSame( '', Languages::of( 'site', 0 ) );
	}

	public function test_work_runs_in_a_language_and_the_previous_one_comes_back() {
		MDIA_Fake_Wpml::install();

		$seen = Languages::in( 'ca', static fn() => MDIA_Fake_Wpml::$current );

		$this->assertSame( 'ca', $seen );
		$this->assertSame( 'es', MDIA_Fake_Wpml::$current );

		try {
			Languages::in( 'en', static function () { throw new \RuntimeException( 'x' ); } );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'es', MDIA_Fake_Wpml::$current, 'Also after an error.' );
		}
	}

	public function test_a_text_is_proposed_in_the_language_of_its_page() {
		MDIA_Fake_Wpml::install();
		$english              = $this->page( 'en' );
		$catalan              = $this->page( 'ca', array( 'post_title' => 'Rutes' ) );
		$this->fake->rewrites = array( self::GOOD, self::GOOD );

		Text_Generator::run( $this->finding( 'post.metadesc', $english )['id'] );
		Text_Generator::run( $this->finding( 'post.metadesc', $catalan )['id'] );

		$this->assertStringContainsString( 'Write in English.', $this->fake->rewrite_calls[0]['system'] );
		$this->assertStringContainsString( 'Write in Catalan.', $this->fake->rewrite_calls[1]['system'] );
		$this->assertSame( 'es', MDIA_Fake_Wpml::$current );
	}

	public function test_a_page_is_read_and_checked_in_its_own_language() {
		MDIA_Fake_Wpml::install();
		add_shortcode( 'mdia_lang', static fn() => 'language:' . MDIA_Fake_Wpml::$current );
		$id = $this->page( 'en', array( 'post_content' => '<p>Text.</p>[mdia_lang]' ) );
		\MDIA\Content\Page_Text::flush();

		$this->assertStringContainsString( 'language:en', \MDIA\Content\Page_Text::text( $id ) );
		$this->assertSame( 'es', MDIA_Fake_Wpml::$current );
		remove_shortcode( 'mdia_lang' );
	}

	public function test_an_image_is_described_in_its_language() {
		MDIA_Fake_Wpml::install();
		$this->images_in_use = true;
		$image               = $this->make_real_image();
		MDIA_Fake_Wpml::set_post( $image, 'en' );
		$this->fake->responses = array( 'A white lighthouse on a cliff' );

		\MDIA\AI\Generator::run( $image );

		$this->assertStringContainsString( 'Write in English.', $this->fake->calls[0]['system'] );
	}

	public function test_a_translation_with_the_same_description_is_not_a_duplicate() {
		MDIA_Fake_Wpml::install();
		$seo  = \MDIA\Seo\Seo::adapter();
		$text = 'Cinco rutas a pie por la costa de las Rías Baixas, con mapas, tiempos y consejos prácticos para preparar cada tramo del camino.';
		$a    = $this->page( 'es' );
		$b    = $this->page( 'en' );
		$c    = $this->page( 'es', array( 'post_title' => 'Otra' ) );
		foreach ( array( $a, $b ) as $id ) {
			$seo->set_post_description( $id, $text );
			Engine::evaluate( 'post', $id );
		}
		$this->assertNull( $this->finding( 'post.metadesc', $b ), 'Another language is another page for search engines.' );

		$seo->set_post_description( $c, $text );
		Engine::evaluate( 'post', $c );
		$this->assertSame( 'duplicate', $this->finding( 'post.metadesc', $c )['detail'] );
	}

	public function test_copies_of_an_image_in_other_languages_do_not_make_its_alt_text_repeated() {
		MDIA_Fake_Wpml::install();
		$this->images_in_use = true;
		$ids                 = array();
		foreach ( array( 'es', 'es', 'en', 'en', 'ca', 'ca' ) as $lang ) {
			$id = $this->make_image();
			MDIA_Fake_Wpml::set_post( $id, $lang );
			update_post_meta( $id, '_wp_attachment_image_alt', 'Equipo reunido en la sala de juntas' );
			$ids[] = $id;
		}

		$this->assertSame( 'ok', ( new \MDIA\Audit\Auditor() )->audit( $ids[0] ), 'Two in Spanish: far from the limit of five.' );
	}

	public function test_findings_carry_their_language_and_can_be_listed_by_it() {
		MDIA_Fake_Wpml::install();
		$english = $this->page( 'en' );
		$spanish = $this->page( 'es' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 'en', $this->finding( 'post.metadesc', $english )['lang'] );

		$request = new WP_REST_Request( 'GET', '/mdia/v1/findings' );
		$request->set_query_params( array( 'check_id' => 'post.metadesc', 'language' => 'en' ) );
		$items = rest_get_server()->dispatch( $request )->get_data();

		$this->assertContains( $english, wp_list_pluck( $items, 'target_id' ) );
		$this->assertNotContains( $spanish, wp_list_pluck( $items, 'target_id' ) );
		$this->assertSame( 'en', $items[0]['lang'] );
		$this->assertSame( 'English', $items[0]['lang_name'] );
	}

	public function test_an_image_on_a_translated_page_belongs_to_its_copy_in_that_language() {
		MDIA_Fake_Wpml::install();
		$original = $this->make_image();
		$copy     = $this->make_image();
		$orphan   = $this->make_image();
		MDIA_Fake_Wpml::set_post( $original, 'es', 77 );
		MDIA_Fake_Wpml::set_post( $copy, 'en', 77 );
		MDIA_Fake_Wpml::set_post( $orphan, 'en', 78 );
		// WPML keeps the original image in the translated page.
		$page = $this->make_post( $this->image_block( $original ) );
		MDIA_Fake_Wpml::set_post( $page, 'en' );
		( new \MDIA\Audit\Usage_Indexer() )->index_post( $page );

		$this->assertSame( 'content', $this->usages( $copy )[0]['kind'] );
		$this->assertSame( array(), $this->usages( $original ), 'The Spanish image is not what an English page needs.' );

		$auditor = new \MDIA\Audit\Auditor();
		$this->assertSame( 'needs_generation', $auditor->audit( $copy ) );
		$this->assertSame( 'unused', $auditor->audit( $orphan ) );
		$this->assertSame( 'unused', $auditor->audit( $original ) );
	}

	public function test_approving_the_copy_writes_its_text_in_the_page_of_its_language_only() {
		MDIA_Fake_Wpml::install();
		\MDIA\Settings::update( array( 'wizard_done' => true ) );
		$editor   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$original = $this->make_image();
		$copy     = $this->make_image();
		MDIA_Fake_Wpml::set_post( $original, 'es', 91 );
		MDIA_Fake_Wpml::set_post( $copy, 'en', 91 );
		$spanish = $this->make_post( $this->image_block( $original ) );
		$english = $this->make_post( $this->image_block( $original ) );
		MDIA_Fake_Wpml::set_post( $spanish, 'es' );
		MDIA_Fake_Wpml::set_post( $english, 'en' );
		$indexer = new \MDIA\Audit\Usage_Indexer();
		$indexer->index_post( $spanish );
		$indexer->index_post( $english );
		( new \MDIA\Audit\Auditor() )->audit( $copy );
		( new \MDIA\Data\Items() )->upsert( $copy, array( 'status' => 'needs_review', 'proposed_alt' => 'A white lighthouse on a cliff' ) );

		$this->assertTrue( \MDIA\Review\Actions::approve( $copy, 'A white lighthouse on a cliff', $editor ) );
		$this->run_queue();

		$this->assertStringContainsString( 'alt="A white lighthouse on a cliff"', get_post( $english )->post_content );
		$this->assertStringContainsString( 'alt=""', get_post( $spanish )->post_content, 'English text never lands on the Spanish page.' );

		$this->assertTrue( \MDIA\Review\Actions::undo( $copy, $editor ) );
		$this->run_queue();
		$this->assertStringContainsString( 'alt=""', get_post( $english )->post_content );
	}

	public function test_a_page_in_a_language_the_image_has_no_copy_for_is_left_alone() {
		MDIA_Fake_Wpml::install();
		\MDIA\Settings::update( array( 'wizard_done' => true ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$image  = $this->make_image();
		$plain  = $this->make_image();
		MDIA_Fake_Wpml::set_post( $image, 'es', 95 );
		MDIA_Fake_Wpml::set_post( $plain, 'none' ); // Media is not translated on this site.
		$catalan = $this->make_post( $this->image_block( $image ) . $this->image_block( $plain ) );
		MDIA_Fake_Wpml::set_post( $catalan, 'ca' );
		$spanish = $this->make_post( $this->image_block( $image ) . $this->image_block( $plain ) );
		MDIA_Fake_Wpml::set_post( $spanish, 'es' );
		$indexer = new \MDIA\Audit\Usage_Indexer();
		$indexer->index_post( $catalan );
		$indexer->index_post( $spanish );

		$kinds = static fn( array $rows ): array => array_column( $rows, 'kind', 'post_id' );
		$this->assertSame( array( $catalan => 'foreign', $spanish => 'content' ), $kinds( $this->usages( $image ) ) );
		$this->assertSame( array( $catalan => 'foreign', $spanish => 'content' ), $kinds( $this->usages( $plain ) ), 'An image without a language belongs to the default one.' );

		( new \MDIA\Data\Items() )->upsert( $image, array( 'status' => 'needs_review', 'proposed_alt' => 'Un faro blanco' ) );
		\MDIA\Review\Actions::approve( $image, 'Un faro blanco', $editor );
		$this->run_queue();

		$this->assertStringContainsString( 'alt="Un faro blanco"', get_post( $spanish )->post_content );
		$this->assertStringNotContainsString( 'Un faro blanco', get_post( $catalan )->post_content );
	}

	public function test_a_hidden_language_is_still_the_language_of_its_pages() {
		MDIA_Fake_Wpml::install( array( 'es', 'en' ) ); // "de" exists but is hidden from this visitor.
		$id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		MDIA_Fake_Wpml::set_post( $id, 'de' );

		$this->assertSame( 'de', Languages::of( 'post', $id ) );
		$this->assertSame( 'de_XX', Languages::locale_of( 'post', $id ) );
	}

	public function test_nested_work_puts_each_language_back_and_ends_where_the_request_started() {
		MDIA_Fake_Wpml::install();
		MDIA_Fake_Wpml::start_in( 'all' );
		MDIA_Fake_Wpml::$switches = array();

		$seen = Languages::in( 'en', static fn() => array( MDIA_Fake_Wpml::$current, Languages::in( 'ca', static fn() => MDIA_Fake_Wpml::$current ), MDIA_Fake_Wpml::$current ) );

		$this->assertSame( array( 'en', 'ca', 'en' ), $seen );
		$this->assertSame( array( 'en', 'ca', 'en', 'null' ), MDIA_Fake_Wpml::$switches, 'The outermost call hands the request back to WPML.' );
	}

	public function test_the_language_wpml_saves_late_is_the_one_recorded() {
		MDIA_Fake_Wpml::install();
		// WPML stores the language of a new page on save_post, at priority 100.
		$late = static fn( $post_id ) => MDIA_Fake_Wpml::set_post( (int) $post_id, 'en' );
		add_action( 'save_post', $late, 100 );
		$id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Routes', 'post_content' => '<p>Text of the page.</p>' ) );
		remove_action( 'save_post', $late, 100 );

		$this->assertSame( 'en', $this->finding( 'post.metadesc', $id )['lang'] );
	}

	private function listed( string $route, array $params ): array {
		$request = new WP_REST_Request( 'GET', '/mdia/v1/' . $route );
		$request->set_query_params( $params + array( 'per_page' => 100 ) );
		return rest_get_server()->dispatch( $request )->get_data();
	}

	public function test_filtering_texts_by_language_shows_that_language_only() {
		MDIA_Fake_Wpml::install();
		$english = $this->page( 'en' );
		$spanish = $this->page( 'es' );
		// Recorded before the site had languages, about a Spanish and an English page.
		$old_es = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$old_en = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		MDIA_Fake_Wpml::set_post( $old_en, 'en' );
		$findings = new Findings();
		foreach ( array( $old_es, $old_en ) as $id ) {
			$findings->delete_for_target( 'post', $id );
			$findings->upsert( 'post.metadesc', 'post', $id, array( 'status' => 'waived', 'severity' => 'required', 'lang' => '' ) );
		}
		delete_option( 'mdia_lang_backfill' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$ids = wp_list_pluck( $this->listed( 'findings', array( 'check_id' => 'post.metadesc', 'language' => 'en' ) ), 'target_id' );

		$this->assertContains( $english, $ids );
		$this->assertContains( $old_en, $ids, 'Old findings get their language.' );
		$this->assertNotContains( $spanish, $ids );
		$this->assertNotContains( $old_es, $ids, 'Nothing in Spanish under the English filter.' );
	}

	public function test_what_has_no_language_of_its_own_is_listed_under_the_main_one() {
		MDIA_Fake_Wpml::install();
		$author = self::factory()->user->create( array( 'role' => 'author', 'description' => '' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $author ) );
		Engine::evaluate( 'user', $author );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertContains( $author, wp_list_pluck( $this->listed( 'findings', array( 'check_id' => 'user.bio', 'language' => 'es' ) ), 'target_id' ) );
		$this->assertNotContains( $author, wp_list_pluck( $this->listed( 'findings', array( 'check_id' => 'user.bio', 'language' => 'en' ) ), 'target_id' ) );
	}

	public function test_images_can_be_listed_by_language() {
		MDIA_Fake_Wpml::install();
		$spanish = $this->make_image();
		$english = $this->make_image();
		$plain   = $this->make_image();
		MDIA_Fake_Wpml::set_post( $spanish, 'es' );
		MDIA_Fake_Wpml::set_post( $english, 'en' );
		MDIA_Fake_Wpml::set_post( $plain, 'none' ); // Media without a language holds a text in the main one.
		$auditor = new \MDIA\Audit\Auditor();
		foreach ( array( $spanish, $english, $plain ) as $id ) {
			$auditor->audit( $id );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$in_english = wp_list_pluck( $this->listed( 'items', array( 'language' => 'en' ) ), 'attachment_id' );
		$in_spanish = wp_list_pluck( $this->listed( 'items', array( 'language' => 'es' ) ), 'attachment_id' );

		$this->assertSame( array( $english ), $in_english );
		$this->assertContains( $spanish, $in_spanish );
		$this->assertContains( $plain, $in_spanish );
		$this->assertNotContains( $english, $in_spanish );
	}

	public function test_images_audited_before_the_site_had_languages_get_theirs() {
		MDIA_Fake_Wpml::install();
		$english = $this->make_image();
		MDIA_Fake_Wpml::set_post( $english, 'en' );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'mdia_items', array( 'lang' => '' ), array( 'attachment_id' => $english ) );
		delete_option( 'mdia_lang_backfill' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( array( $english ), wp_list_pluck( $this->listed( 'items', array( 'language' => 'en' ) ), 'attachment_id' ) );
	}

	public function test_the_site_is_judged_in_its_default_language() {
		MDIA_Fake_Wpml::install();
		MDIA_Fake_Wpml::start_in( 'all' );
		$seen = '';
		add_filter( 'pre_option_blogdescription', static function ( $value ) use ( &$seen ) { $seen = MDIA_Fake_Wpml::$current; return $value; } );

		Engine::evaluate_site();

		$this->assertSame( 'es', $seen );
	}

	public function test_an_image_shown_only_on_pages_of_another_language_is_not_sent_to_the_ai() {
		MDIA_Fake_Wpml::install();
		$image = $this->make_image();
		MDIA_Fake_Wpml::set_post( $image, 'none' ); // Media is not translated: its text is in the default language.
		$page = $this->make_post( $this->image_block( $image ) );
		MDIA_Fake_Wpml::set_post( $page, 'en' );
		( new \MDIA\Audit\Usage_Indexer() )->index_post( $page );

		$this->assertSame( 'unused', ( new \MDIA\Audit\Auditor() )->audit( $image ), 'A Spanish text would reach no page.' );
	}

	public function test_what_has_no_language_is_written_in_the_main_one() {
		MDIA_Fake_Wpml::install();
		$image = $this->make_image();
		MDIA_Fake_Wpml::set_post( $image, 'none' );

		$this->assertNotSame( 'es_ES', get_locale(), 'The admin is in another language in this test.' );
		$this->assertSame( 'es_ES', Languages::locale_of( 'post', $image ) );
		$this->assertSame( 'es_ES', Languages::locale_of( 'site', 0 ) );
	}

	public function test_a_language_given_after_the_page_was_saved_is_picked_up() {
		MDIA_Fake_Wpml::install();
		$id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Routes', 'post_content' => '<p>Text of the page.</p>' ) );
		$this->assertSame( 'es', $this->finding( 'post.metadesc', $id )['lang'] );

		// An importer, WP-CLI or the translation editor sets the language later.
		MDIA_Fake_Wpml::set_post( $id, 'en' );
		do_action( 'wpml_translation_update', array( 'type' => 'update', 'element_id' => $id, 'element_type' => 'post_page', 'context' => 'post' ) );
		\MDIA\I18n\Hooks::run(); // What happens when the request ends.

		$this->assertSame( 'en', $this->finding( 'post.metadesc', $id )['lang'] );
	}

	public function test_the_translation_of_the_home_page_is_not_a_test_page() {
		MDIA_Fake_Wpml::install();
		$home    = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio' ) );
		$english = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Test page' ) );
		MDIA_Fake_Wpml::set_post( $home, 'es', 55 );
		MDIA_Fake_Wpml::set_post( $english, 'en', 55 );
		update_option( 'page_on_front', $home );
		Engine::evaluate( 'post', $english );

		$this->assertNull( $this->finding( 'post.test_page', $english ) );
		delete_option( 'page_on_front' );
	}

	public function test_a_featured_image_of_another_language_is_not_counted_as_use() {
		MDIA_Fake_Wpml::install();
		$image = $this->make_image();
		MDIA_Fake_Wpml::set_post( $image, 'es', 61 );
		$page = $this->make_post( '<p>Text.</p>' );
		MDIA_Fake_Wpml::set_post( $page, 'en' );
		set_post_thumbnail( $page, $image );
		( new \MDIA\Audit\Usage_Indexer() )->index_post( $page );

		$this->assertSame( 'foreign', $this->usages( $image )[0]['kind'] );
	}

	public function test_the_screen_knows_the_languages() {
		MDIA_Fake_Wpml::install();

		$this->assertSame( array( 'es' => 'Español', 'en' => 'English', 'ca' => 'Català' ), \MDIA\Health\Status::compute( true )['languages'] );
	}
}
