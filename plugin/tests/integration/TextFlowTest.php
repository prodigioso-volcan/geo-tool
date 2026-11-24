<?php

use MDIA\AI\Budget;
use MDIA\Checks\Engine;
use MDIA\Checks\Finding_Actions;
use MDIA\Checks\Text_Generator;
use MDIA\Data\Findings;
use MDIA\Settings;

class TextFlowTest extends WP_UnitTestCase {
	use MDIA_Test_Helpers;

	private const GOOD = 'Cinco rutas a pie por la costa de las Rías Baixas, con mapas, tiempos y consejos prácticos para preparar cada tramo del camino.';

	private MDIA_Fake_Provider $fake;
	private int $editor;
	private int $admin;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WPSEO_Meta' ) ) {
			$this->markTestSkipped( 'Yoast SEO is not installed in the test WordPress.' );
		}
		\MDIA\Content\Page_Text::flush();
		Settings::update( array( 'auto_generate_new' => false, 'wizard_done' => true, 'text_consent' => true ) );
		$this->fake   = MDIA_Fake_Provider::install();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function page( array $args = array() ): int {
		return self::factory()->post->create( array_merge( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rutas por las Rías Baixas', 'post_content' => "<!-- wp:paragraph -->\n<p>Recorremos la costa gallega a pie, de Baiona a Fisterra.</p>\n<!-- /wp:paragraph -->" ), $args ) );
	}

	private function finding( string $check, int $id, string $type = 'post', string $key = '' ): ?array {
		return ( new Findings() )->find( $check, $type, $id, $key );
	}

	// ---- generation

	public function test_proposal_is_generated_from_the_published_text_and_waits_for_review() {
		$id                   = $this->page();
		$fid                  = $this->finding( 'post.metadesc', $id )['id'];
		$this->fake->rewrites = array( '"' . self::GOOD . '"' );

		$this->assertSame( 'proposed', Text_Generator::run( $fid ) );

		$row = ( new Findings() )->get( $fid );
		$this->assertSame( self::GOOD, $row['proposed_value'] );
		$this->assertSame( 'Fake', $row['provider'] );
		$this->assertSame( '', \MDIA\Seo\Seo::adapter()->post_raw_description( $id ), 'Nothing is written before review.' );
		$call = $this->fake->rewrite_calls[0];
		$this->assertStringContainsString( 'Recorremos la costa gallega', $call['prompt'] );
		$this->assertStringContainsString( 'Rutas por las Rías Baixas', $call['prompt'] );
		$this->assertStringContainsString( '155', $call['system'] );
		$this->assertStringContainsString( 'meta description', strtolower( $call['system'] ) );
		$this->assertSame( 1, Budget::used_today() );
	}

	public function test_nothing_is_sent_without_the_extended_consent() {
		Settings::update( array( 'text_consent' => false ) );
		$fid = $this->finding( 'post.metadesc', $this->page() )['id'];

		$this->assertSame( 0, \MDIA\Queue\Scheduler::enqueue_text_generation( array( $fid ) ) );
		$this->assertSame( 'open', Text_Generator::run( $fid ) );
		$this->assertCount( 0, $this->fake->rewrite_calls );
	}

	public function test_answer_outside_the_range_is_asked_again_once_then_cut() {
		$fid                  = $this->finding( 'post.metadesc', $this->page() )['id'];
		$this->fake->rewrites = array( str_repeat( 'palabra ', 40 ), str_repeat( 'término ', 40 ) );

		Text_Generator::run( $fid );

		$proposal = ( new Findings() )->get( $fid )['proposed_value'];
		$this->assertCount( 2, $this->fake->rewrite_calls );
		$this->assertLessThanOrEqual( 160, mb_strlen( $proposal ) );
		$this->assertSame( 2, Budget::used_today() );
	}

	public function test_title_proposal_leaves_room_for_the_site_name() {
		$id                   = $this->page( array( 'post_title' => 'Convocatoria de ayudas a la investigación biomédica traslacional 2026' ) );
		$fid                  = $this->finding( 'post.seo_title', $id )['id'];
		$this->fake->rewrites = array( 'Ayudas a la investigación biomédica 2026' );

		Text_Generator::run( $fid );

		$room = 60 - mb_strlen( ' - ' . get_bloginfo( 'name' ) );
		$this->assertStringContainsString( (string) $room, $this->fake->rewrite_calls[0]['system'] );
		$this->assertSame( 'Ayudas a la investigación biomédica 2026', ( new Findings() )->get( $fid )['proposed_value'] );
	}

	public function test_provider_errors_retry_and_end_in_error() {
		$fid                  = $this->finding( 'post.metadesc', $this->page() )['id'];
		$this->fake->rewrites = array_fill( 0, 3, new WP_Error( 'http', 'Timeout' ) );

		$this->assertSame( 'open', Text_Generator::run( $fid ) );
		$this->assertSame( 'open', Text_Generator::run( $fid ) );
		$this->assertSame( 'error', Text_Generator::run( $fid ) );
		$this->assertSame( 'Timeout', ( new Findings() )->get( $fid )['last_error'] );
	}

	public function test_only_text_checks_are_generated() {
		$id  = $this->page();
		$fid = $this->finding( 'post.featured_image', $id )['id'];

		$this->assertSame( 0, \MDIA\Queue\Scheduler::enqueue_text_generation( array( $fid ) ) );
		$this->assertSame( 'open', Text_Generator::run( $fid ) );
		$this->assertCount( 0, $this->fake->rewrite_calls );
	}

	public function test_queue_runs_text_generation() {
		$fid = $this->finding( 'post.metadesc', $this->page() )['id'];
		$this->fake->rewrites = array( self::GOOD );

		$this->assertSame( 1, \MDIA\Queue\Scheduler::enqueue_text_generation( array( $fid ) ) );
		$this->assertSame( 1, ( new Findings() )->get( $fid )['queued'] );
		$this->run_queue();

		$this->assertSame( 'proposed', ( new Findings() )->get( $fid )['status'] );
		$this->assertSame( 0, ( new Findings() )->get( $fid )['queued'] );
	}

	public function test_term_description_is_proposed_from_its_posts() {
		$cat = self::factory()->category->create( array( 'name' => 'Senderismo', 'description' => '' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $cat ), 'post_title' => 'La ruta del Cares' ) );
		Engine::evaluate( 'term', $cat );
		$fid                  = $this->finding( 'term.description', $cat, 'term' )['id'];
		$this->fake->rewrites = array( 'Rutas de senderismo por el norte de España, con mapas y consejos para cada nivel de experiencia.' );

		Text_Generator::run( $fid );

		$this->assertStringContainsString( 'Senderismo', $this->fake->rewrite_calls[0]['prompt'] );
		$this->assertStringContainsString( 'La ruta del Cares', $this->fake->rewrite_calls[0]['prompt'] );
		$this->assertSame( 'proposed', ( new Findings() )->get( $fid )['status'] );
	}

	// ---- review

	public function test_approve_writes_the_field_and_the_check_passes() {
		$id  = $this->page();
		$fid = $this->finding( 'post.metadesc', $id )['id'];

		$this->assertTrue( Finding_Actions::approve( $fid, self::GOOD, $this->editor ) );

		$this->assertSame( self::GOOD, \MDIA\Seo\Seo::adapter()->post_description( $id ) );
		$row = ( new Findings() )->get( $fid );
		$this->assertSame( 'applied', $row['status'] );
		$this->assertSame( $this->editor, $row['reviewed_by'] );
		$this->assertSame( '', (string) $row['previous_value'] );

		Engine::evaluate( 'post', $id );
		$this->assertSame( 'applied', ( new Findings() )->get( $fid )['status'], 'A later audit keeps the decision.' );
	}

	public function test_undo_restores_the_previous_value_and_reopens() {
		$id  = $this->page();
		\MDIA\Seo\Seo::adapter()->set_post_description( $id, 'Corta escrita a mano.' );
		Engine::evaluate( 'post', $id );
		$fid = $this->finding( 'post.metadesc', $id )['id'];
		Finding_Actions::approve( $fid, self::GOOD, $this->editor );

		$this->assertTrue( Finding_Actions::undo( $fid, $this->editor ) );

		$this->assertSame( 'Corta escrita a mano.', \MDIA\Seo\Seo::adapter()->post_raw_description( $id ) );
		$this->assertSame( 'open', $this->finding( 'post.metadesc', $id )['status'] );
		$this->assertSame( 'too_short', $this->finding( 'post.metadesc', $id )['detail'] );
	}

	public function test_undo_refuses_to_overwrite_a_text_someone_wrote_after_the_approval() {
		$id  = $this->page();
		$fid = $this->finding( 'post.metadesc', $id )['id'];
		Finding_Actions::approve( $fid, self::GOOD, $this->editor );

		$later = 'Texto que un editor escribió después, a mano, en la caja del plugin de SEO y que también cumple con el tamaño recomendado.';
		\MDIA\Seo\Seo::adapter()->set_post_description( $id, $later );

		$this->assertSame( 'mdia_changed_since', Finding_Actions::undo( $fid, $this->editor )->get_error_code() );
		$this->assertSame( $later, \MDIA\Seo\Seo::adapter()->post_raw_description( $id ) );
		wp_set_current_user( $this->editor );
		$this->assertFalse( \MDIA\Rest\Api::prepare_finding( ( new Findings() )->get( $fid ) )['can_undo'] );
	}

	public function test_undo_of_a_term_description_keeps_a_seo_description_written_by_hand() {
		$cat = self::factory()->category->create( array( 'name' => 'Senderismo', 'description' => '' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $cat ) ) );
		$hand = 'Descripción para buscadores escrita a mano por el equipo, con la longitud suficiente para pasar la comprobación.';
		\MDIA\Seo\Seo::adapter()->set_term_description_meta( $cat, 'category', $hand );
		Engine::evaluate( 'term', $cat );
		$fid = $this->finding( 'term.description', $cat, 'term' )['id'];

		Finding_Actions::approve( $fid, 'Rutas de senderismo por el norte de España, con mapas y consejos para cada nivel de experiencia.', $this->editor );
		$this->assertTrue( Finding_Actions::undo( $fid, $this->editor ) );

		$this->assertSame( '', get_term( $cat )->description );
		$this->assertSame( $hand, \MDIA\Seo\Seo::adapter()->term_description_meta( $cat, 'category' ) );
	}

	public function test_undo_of_a_term_description_also_removes_the_seo_description_it_wrote() {
		$cat = self::factory()->category->create( array( 'name' => 'Ciclismo', 'description' => '' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $cat ) ) );
		Engine::evaluate( 'term', $cat );
		$fid = $this->finding( 'term.description', $cat, 'term' )['id'];

		Finding_Actions::approve( $fid, 'Rutas en bicicleta por el norte de España, con mapas y consejos para cada nivel de experiencia.', $this->editor );
		$this->assertTrue( Finding_Actions::undo( $fid, $this->editor ) );

		$this->assertSame( '', \MDIA\Seo\Seo::adapter()->term_description_meta( $cat, 'category' ) );
	}

	public function test_an_applied_text_that_fails_later_for_another_reason_is_reported_again() {
		$cat = self::factory()->category->create( array( 'name' => 'Montaña', 'description' => '' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $cat ) ) );
		Engine::evaluate( 'term', $cat );
		$fid = $this->finding( 'term.description', $cat, 'term' )['id'];
		Finding_Actions::approve( $fid, 'Rutas de montaña por el norte de España, con mapas y consejos para cada nivel de experiencia.', $this->editor );

		// The field of the SEO plugin is emptied later (another SEO plugin, an import...).
		\MDIA\Seo\Seo::adapter()->set_term_description_meta( $cat, 'category', '' );
		Engine::evaluate( 'term', $cat );

		$row = $this->finding( 'term.description', $cat, 'term' );
		$this->assertSame( 'open', $row['status'] );
		$this->assertSame( 'meta_missing', $row['detail'] );
	}

	public function test_undo_puts_back_a_description_with_variables_exactly_as_it_was() {
		$id = $this->page();
		update_post_meta( $id, '_yoast_wpseo_metadesc', 'Guía de %%title%%' );
		Engine::evaluate( 'post', $id );
		$fid = $this->finding( 'post.metadesc', $id )['id'];
		$this->assertSame( 'too_short', $this->finding( 'post.metadesc', $id )['detail'] );
		Finding_Actions::approve( $fid, self::GOOD, $this->editor );

		$this->assertTrue( Finding_Actions::undo( $fid, $this->editor ) );

		$this->assertSame( 'Guía de %%title%%', get_post_meta( $id, '_yoast_wpseo_metadesc', true ) );
		$this->assertSame( 'Guía de Rutas por las Rías Baixas', \MDIA\Seo\Seo::adapter()->post_description( $id ) );
	}

	public function test_undo_of_a_term_refuses_when_the_seo_description_was_rewritten_afterwards() {
		$cat = self::factory()->category->create( array( 'name' => 'Kayak', 'description' => 'Rutas en kayak por las rías gallegas, con mapas, mareas y consejos para cada nivel de experiencia.' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $cat ) ) );
		Engine::evaluate( 'term', $cat );
		$row = $this->finding( 'term.description', $cat, 'term' );
		$this->assertSame( 'meta_missing', $row['detail'] );
		Finding_Actions::approve( $row['id'], 'Rutas en kayak por las rías gallegas, con mapas y mareas, pensadas para quien busca en la web.', $this->editor );

		$later = 'Texto que el equipo de SEO escribió después, a mano, para los resultados de búsqueda de esta categoría.';
		\MDIA\Seo\Seo::adapter()->set_term_description_meta( $cat, 'category', $later );

		$this->assertSame( 'mdia_changed_since', Finding_Actions::undo( $row['id'], $this->editor )->get_error_code() );
		$this->assertSame( $later, \MDIA\Seo\Seo::adapter()->term_description_meta( $cat, 'category' ) );
	}

	public function test_undo_of_a_term_is_not_blocked_by_how_the_seo_plugin_renders_its_field() {
		$cat = self::factory()->category->create( array( 'name' => 'Vela', 'description' => 'Rutas en velero por las rías gallegas, con mapas, mareas y consejos para cada nivel de experiencia.' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $cat ) ) );
		Engine::evaluate( 'term', $cat );
		$row = $this->finding( 'term.description', $cat, 'term' );
		Finding_Actions::approve( $row['id'], 'Rutas en velero por las rías gallegas, con mapas y mareas, pensadas para quien busca en la web.', $this->editor );

		$applied = ( new Findings() )->get( $row['id'] )['applied_value'];
		$this->assertStringContainsString( 'pensadas para quien busca', $applied );

		// The same text, shown differently (a variable of the SEO plugin resolving to something else).
		add_filter( 'wpseo_replacements', static fn( $replacements ) => $replacements + array( 'busca' => 'BUSCA' ) );
		$this->assertTrue( Finding_Actions::undo( $row['id'], $this->editor ) );
	}

	public function test_only_an_accepted_finding_can_be_put_back_under_review() {
		$id  = $this->page();
		$fid = $this->finding( 'post.metadesc', $id )['id'];
		Finding_Actions::approve( $fid, self::GOOD, $this->editor );

		$this->assertSame( 'mdia_invalid_status', Finding_Actions::reopen( $fid, $this->admin )->get_error_code() );
		$this->assertSame( 'applied', ( new Findings() )->get( $fid )['status'], 'The applied text keeps its undo.' );
	}

	public function test_the_ai_reads_the_page_as_a_visitor_not_as_whoever_started_the_queue() {
		add_shortcode( 'mdia_members', static fn() => is_user_logged_in() ? 'Solo para socios: clave interna.' : 'Hazte socio.' );
		$id                   = $this->page( array( 'post_content' => '<p>Texto público de la página.</p>[mdia_members]' ) );
		$fid                  = $this->finding( 'post.metadesc', $id )['id'];
		$this->fake->rewrites = array( self::GOOD );
		wp_set_current_user( $this->admin );
		\MDIA\Content\Page_Text::flush();

		Text_Generator::run( $fid );

		$this->assertStringNotContainsString( 'clave interna', $this->fake->rewrite_calls[0]['prompt'] );
		$this->assertStringContainsString( 'Hazte socio', $this->fake->rewrite_calls[0]['prompt'] );
		$this->assertSame( $this->admin, get_current_user_id(), 'The current user is put back.' );
		remove_shortcode( 'mdia_members' );
	}

	public function test_decisions_survive_while_a_page_is_out_of_scope() {
		$id       = $this->page();
		$required = $this->finding( 'post.metadesc', $id )['id'];
		Finding_Actions::waive( $required, $this->admin );
		$this->assertNotNull( $this->finding( 'post.featured_image', $id ), 'There is a pending finding to clean up.' );

		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$this->assertNull( $this->finding( 'post.featured_image', $id ), 'Pending findings of a page that is not public go away.' );
		$this->assertSame( 'waived', ( new Findings() )->get( $required )['status'] );

		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$this->assertSame( 'waived', ( new Findings() )->get( $required )['status'], 'Publishing again does not ask the same question twice.' );
	}

	public function test_a_finding_already_decided_cannot_be_approved_again() {
		$id  = $this->page();
		\MDIA\Seo\Seo::adapter()->set_post_description( $id, 'Corta escrita a mano.' );
		Engine::evaluate( 'post', $id );
		$fid = $this->finding( 'post.metadesc', $id )['id'];
		Finding_Actions::approve( $fid, self::GOOD, $this->editor );

		$again = Finding_Actions::approve( $fid, 'Otro texto distinto enviado directamente a la API, saltándose la pantalla, con longitud suficiente para pasar.', $this->editor );

		$this->assertSame( 'mdia_invalid_status', $again->get_error_code() );
		$this->assertSame( 'Corta escrita a mano.', ( new Findings() )->get( $fid )['previous_value'], 'The original text can still be restored.' );
	}

	public function test_regenerate_without_consent_keeps_the_proposal_and_says_why() {
		$id  = $this->page();
		$fid = $this->finding( 'post.metadesc', $id )['id'];
		( new Findings() )->upsert( 'post.metadesc', 'post', $id, array( 'status' => 'proposed', 'proposed_value' => self::GOOD ) );
		Settings::update( array( 'text_consent' => false ) );

		$this->assertSame( 'mdia_no_consent', Finding_Actions::regenerate( $fid, $this->editor )->get_error_code() );
		$this->assertSame( self::GOOD, ( new Findings() )->get( $fid )['proposed_value'] );
	}

	public function test_acf_field_names_are_matched_exactly() {
		$findings = new Findings();
		$a        = $findings->upsert( 'post.acf_empty', 'post', 601, array( 'status' => 'open' ), 'Hero_Title' );
		$b        = $findings->upsert( 'post.acf_empty', 'post', 602, array( 'status' => 'open' ), 'hero_title' );

		$this->assertTrue( Finding_Actions::waive_acf_field( 'Hero_Title', $this->admin ) );

		$this->assertContains( 'Hero_Title', get_option( 'mdia_waived_acf_fields' ) );
		$this->assertNull( $findings->get( $a ) );
		$this->assertNotNull( $findings->get( $b ) );
	}

	public function test_findings_of_pages_the_reader_cannot_read_are_not_listed() {
		$private = $this->page( array( 'post_status' => 'private', 'post_author' => $this->admin ) );
		$fid     = ( new Findings() )->upsert( 'post.metadesc', 'post', $private, array( 'status' => 'open', 'severity' => 'required' ) );
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $author )->add_cap( 'mdia_review' );

		wp_set_current_user( $author );
		$this->assertNull( \MDIA\Rest\Api::prepare_finding( ( new Findings() )->get( $fid ) ) );
		wp_set_current_user( $this->admin );
		$this->assertNotNull( \MDIA\Rest\Api::prepare_finding( ( new Findings() )->get( $fid ) ) );
	}

	public function test_a_type_whose_titles_do_not_carry_the_site_name_gets_none_added() {
		\WPSEO_Options::set( 'title-page', '%%title%%' );
		$id = $this->page( array( 'post_title' => 'Convocatoria de ayudas a la investigación biomédica traslacional y clínica de 2026' ) );
		Engine::evaluate( 'post', $id );
		$fid = $this->finding( 'post.seo_title', $id )['id'];

		Finding_Actions::approve( $fid, 'Ayudas a la investigación 2026', $this->editor );

		$this->assertSame( 'Ayudas a la investigación 2026', \MDIA\Seo\Seo::adapter()->post_title( $id ) );
		\WPSEO_Options::set( 'title-page', '%%title%% %%page%% %%sep%% %%sitename%%' );
	}

	public function test_title_is_saved_with_the_site_name_or_alone_when_it_does_not_fit() {
		$id  = $this->page( array( 'post_title' => 'Convocatoria de ayudas a la investigación biomédica traslacional 2026' ) );
		$fid = $this->finding( 'post.seo_title', $id )['id'];
		Finding_Actions::approve( $fid, 'Ayudas a la investigación 2026', $this->editor );
		$this->assertSame( 'Ayudas a la investigación 2026 - ' . get_bloginfo( 'name' ), \MDIA\Seo\Seo::adapter()->post_title( $id ) );

		$other = $this->page( array( 'post_title' => 'Otra convocatoria de ayudas a la investigación biomédica traslacional' ) );
		$fid2  = $this->finding( 'post.seo_title', $other )['id'];
		Finding_Actions::approve( $fid2, 'Un título propio de cincuenta y cinco caracteres en total', $this->editor );
		$this->assertSame( 'Un título propio de cincuenta y cinco caracteres en total', \MDIA\Seo\Seo::adapter()->post_title( $other ), 'Kept alone: with the site name it would not fit.' );
	}

	public function test_excerpt_and_term_description_are_applied_where_they_belong() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_excerpt' => '' ) );
		Finding_Actions::approve( $this->finding( 'post.excerpt', $post )['id'], 'Un extracto breve.', $this->editor );
		$this->assertSame( 'Un extracto breve.', get_post( $post )->post_excerpt );

		$cat = self::factory()->category->create( array( 'name' => 'Senderismo', 'description' => '' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $cat ) ) );
		Engine::evaluate( 'term', $cat );
		$text = 'Rutas de senderismo por el norte de España, con mapas y consejos para cada nivel de experiencia.';
		Finding_Actions::approve( $this->finding( 'term.description', $cat, 'term' )['id'], $text, $this->editor );

		$this->assertSame( $text, get_term( $cat )->description );
		$this->assertSame( $text, \MDIA\Seo\Seo::adapter()->term_description_meta( $cat, 'category' ) );
		$this->assertSame( 'applied', $this->finding( 'term.description', $cat, 'term' )['status'] );
	}

	public function test_permissions() {
		$id         = $this->page();
		$fid        = $this->finding( 'post.metadesc', $id )['id'];
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$author     = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $author )->add_cap( 'mdia_review' );

		$this->assertSame( 'mdia_forbidden', Finding_Actions::approve( $fid, self::GOOD, $subscriber )->get_error_code() );
		$this->assertSame( 'mdia_forbidden', Finding_Actions::approve( $fid, self::GOOD, $author )->get_error_code(), 'Authors cannot edit other people\'s pages.' );
		$this->assertSame( 'mdia_empty', Finding_Actions::approve( $fid, '   ', $this->editor )->get_error_code() );
		$this->assertSame( 'mdia_not_found', Finding_Actions::approve( 999999, self::GOOD, $this->editor )->get_error_code() );
		$this->assertSame( 'mdia_not_text', Finding_Actions::approve( $this->finding( 'post.featured_image', $id )['id'], 'x', $this->editor )->get_error_code() );
		$this->assertSame( '', \MDIA\Seo\Seo::adapter()->post_raw_description( $id ) );
	}

	public function test_waiving_a_recommended_check_is_for_reviewers_and_a_required_one_for_managers() {
		$id          = $this->page();
		$recommended = $this->finding( 'post.featured_image', $id )['id'];
		$required    = $this->finding( 'post.metadesc', $id )['id'];

		$this->assertTrue( Finding_Actions::waive( $recommended, $this->editor ) );
		$this->assertSame( 'waived', ( new Findings() )->get( $recommended )['status'] );
		$this->assertSame( 'mdia_forbidden', Finding_Actions::waive( $required, $this->editor )->get_error_code() );
		$this->assertTrue( Finding_Actions::waive( $required, $this->admin ) );
		$this->assertSame( $this->admin, ( new Findings() )->get( $required )['reviewed_by'] );

		$this->assertTrue( Finding_Actions::reopen( $required, $this->admin ) );
		$this->assertSame( 'open', $this->finding( 'post.metadesc', $id )['status'] );
	}

	public function test_only_who_can_edit_the_target_can_waive_reopen_or_regenerate() {
		$id     = $this->page();
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $author )->add_cap( 'mdia_review' );
		$recommended = $this->finding( 'post.featured_image', $id )['id'];
		$text        = $this->finding( 'post.metadesc', $id )['id'];

		$this->assertSame( 'mdia_forbidden', Finding_Actions::waive( $recommended, $author )->get_error_code() );
		$this->assertSame( 'open', ( new Findings() )->get( $recommended )['status'] );
		$this->assertSame( 'mdia_forbidden', Finding_Actions::regenerate( $text, $author )->get_error_code() );

		Finding_Actions::waive( $recommended, $this->editor );
		$this->assertSame( 'mdia_forbidden', Finding_Actions::reopen( $recommended, $author )->get_error_code() );
		$this->assertSame( 'waived', ( new Findings() )->get( $recommended )['status'] );
	}

	public function test_reopening_a_waived_required_check_is_for_managers() {
		$required = $this->finding( 'post.metadesc', $this->page() )['id'];
		Finding_Actions::waive( $required, $this->admin );

		$this->assertSame( 'mdia_forbidden', Finding_Actions::reopen( $required, $this->editor )->get_error_code() );
		$this->assertSame( 'waived', ( new Findings() )->get( $required )['status'] );
	}

	public function test_regenerate_clears_the_proposal_and_queues() {
		$fid = $this->finding( 'post.metadesc', $this->page() )['id'];
		( new Findings() )->upsert( 'post.metadesc', 'post', ( new Findings() )->get( $fid )['target_id'], array( 'status' => 'proposed', 'proposed_value' => 'Algo' ) );

		$this->assertTrue( Finding_Actions::regenerate( $fid, $this->editor ) );

		$row = ( new Findings() )->get( $fid );
		$this->assertSame( 'open', $row['status'] );
		$this->assertNull( $row['proposed_value'] );
		$this->assertSame( 1, $row['queued'] );
	}

	public function test_bulk_approve_uses_the_text_on_screen() {
		$a   = $this->page( array( 'post_title' => 'Página A' ) );
		$b   = $this->page( array( 'post_title' => 'Página B' ) );
		$fa  = $this->finding( 'post.metadesc', $a )['id'];
		$fb  = $this->finding( 'post.metadesc', $b )['id'];
		$alt = 'Guía práctica de la ruta del Cares, con horarios, desniveles, transporte y recomendaciones para hacerla en un solo día.';
		( new Findings() )->upsert( 'post.metadesc', 'post', $a, array( 'status' => 'proposed', 'proposed_value' => self::GOOD ) );
		( new Findings() )->upsert( 'post.metadesc', 'post', $b, array( 'status' => 'proposed', 'proposed_value' => 'Propuesta sin tocar' ) );

		$result = Finding_Actions::approve_many( array( array( 'id' => $fa ), array( 'id' => $fb, 'text' => $alt ), array( 'id' => 999999 ) ), $this->editor );

		$this->assertSame( array( 'done' => 2, 'failed' => 1 ), $result );
		$this->assertSame( self::GOOD, \MDIA\Seo\Seo::adapter()->post_description( $a ) );
		$this->assertSame( $alt, \MDIA\Seo\Seo::adapter()->post_description( $b ) );
	}

	public function test_an_acf_field_can_be_declared_optional_for_the_whole_site() {
		$findings = new Findings();
		$a        = $findings->upsert( 'post.acf_empty', 'post', 501, array( 'status' => 'open' ), 'pie' );
		$b        = $findings->upsert( 'post.acf_empty', 'post', 502, array( 'status' => 'open' ), 'pie' );
		$c        = $findings->upsert( 'post.acf_empty', 'post', 502, array( 'status' => 'open' ), 'antetitulo' );

		$this->assertSame( 'mdia_forbidden', Finding_Actions::waive_acf_field( 'pie', $this->editor )->get_error_code() );
		$this->assertTrue( Finding_Actions::waive_acf_field( 'pie', $this->admin ) );

		$this->assertContains( 'pie', get_option( 'mdia_waived_acf_fields' ) );
		$this->assertNull( $findings->get( $a ) );
		$this->assertNull( $findings->get( $b ) );
		$this->assertNotNull( $findings->get( $c ) );
	}
}
