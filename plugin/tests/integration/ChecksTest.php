<?php

use MDIA\Checks\Engine;
use MDIA\Checks\Registry;
use MDIA\Data\Findings;

class ChecksTest extends WP_UnitTestCase {

	private const GOOD_DESC = 'Cinco rutas a pie por la costa de las Rías Baixas, con mapas, tiempos y consejos para cada tramo.';

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WPSEO_Meta' ) ) {
			$this->markTestSkipped( 'Yoast SEO is not installed in the test WordPress.' );
		}
		\MDIA\Content\Page_Text::flush();
	}

	public function tear_down() {
		// ACF keeps locally registered groups for the whole run: do not leak them into other tests.
		if ( function_exists( 'acf_remove_local_field_group' ) ) {
			acf_remove_local_field_group( 'group_mdia_checks' );
		}
		parent::tear_down();
	}

	private function finding( string $check, string $type, int $id, string $key = '' ): ?array {
		return ( new Findings() )->find( $check, $type, $id, $key );
	}

	private function page( array $args = array() ): int {
		return self::factory()->post->create( array_merge( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'La Fundación', 'post_content' => "<!-- wp:paragraph -->\n<p>Promovemos la investigación biomédica.</p>\n<!-- /wp:paragraph -->" ), $args ) );
	}

	public function test_catalogue_has_the_designed_checks() {
		$ids = array_keys( Registry::all() );

		foreach ( array( 'post.metadesc', 'post.seo_title', 'post.excerpt', 'post.headings', 'post.placeholder', 'post.acf_empty', 'post.featured_image', 'post.noindex', 'term.description', 'user.bio', 'user.display_name', 'site.seo_plugin', 'site.visible', 'site.identity', 'site.home_metadesc', 'site.organization', 'site.sitemap', 'site.robots', 'site.schema', 'site.llms_txt', 'site.social_image', 'site.permalinks', 'site.https', 'site.icon', 'site.title_site_name', 'post.test_page' ) as $id ) {
			$this->assertContains( $id, $ids );
		}
		$this->assertCount( 26, $ids );
		$this->assertSame( 'required', Registry::get( 'post.metadesc' )->severity() );
		$this->assertSame( 'ai', Registry::get( 'post.metadesc' )->fix() );
		$this->assertSame( 'setting', Registry::get( 'site.sitemap' )->fix() );
		$this->assertNotSame( '', Registry::get( 'site.robots' )->why() );
	}

	public function test_other_plugins_can_add_checks() {
		add_filter( 'mdia_checks', static function ( $checks ) { $checks['x'] = 'not a check'; return $checks; } );

		$this->assertArrayNotHasKey( 'x', Registry::all(), 'Anything that is not a Check is ignored.' );
	}

	public function test_published_page_without_meta_description_gets_a_required_finding() {
		$id = $this->page();

		$row = $this->finding( 'post.metadesc', 'post', $id );
		$this->assertSame( 'open', $row['status'] );
		$this->assertSame( 'required', $row['severity'] );
		$this->assertSame( 'missing', $row['detail'] );
		$this->assertSame( '', (string) $row['current_value'] );
	}

	public function test_good_meta_description_passes_and_the_finding_goes_away() {
		$id = $this->page();
		\MDIA\Seo\Seo::adapter()->set_post_description( $id, self::GOOD_DESC );

		Engine::evaluate( 'post', $id );

		$this->assertNull( $this->finding( 'post.metadesc', 'post', $id ) );
	}

	public function test_meta_description_length_title_and_duplicates() {
		$seo   = \MDIA\Seo\Seo::adapter();
		$short = $this->page();
		$seo->set_post_description( $short, 'Demasiado corta.' );
		$long = $this->page();
		$seo->set_post_description( $long, str_repeat( 'palabra ', 30 ) );
		$same = $this->page( array( 'post_title' => 'Una página con un título bastante largo para que supere el mínimo de caracteres exigido' ) );
		$seo->set_post_description( $same, 'Una página con un título bastante largo para que supere el mínimo de caracteres exigido' );
		$a = $this->page();
		$b = $this->page();
		$seo->set_post_description( $a, self::GOOD_DESC );
		$seo->set_post_description( $b, self::GOOD_DESC );
		foreach ( array( $short, $long, $same, $a, $b ) as $id ) {
			Engine::evaluate( 'post', $id );
		}

		$this->assertSame( 'too_short', $this->finding( 'post.metadesc', 'post', $short )['detail'] );
		$this->assertSame( 'too_long', $this->finding( 'post.metadesc', 'post', $long )['detail'] );
		$this->assertSame( 'same_as_title', $this->finding( 'post.metadesc', 'post', $same )['detail'] );
		$this->assertSame( 'duplicate', $this->finding( 'post.metadesc', 'post', $b )['detail'] );
		$this->assertSame( self::GOOD_DESC, $this->finding( 'post.metadesc', 'post', $b )['current_value'] );
	}

	public function test_title_too_long_once_the_site_name_is_added() {
		$ok   = $this->page( array( 'post_title' => 'Contacto' ) );
		$long = $this->page( array( 'post_title' => 'Convocatoria de ayudas a la investigación biomédica traslacional 2026' ) );

		$this->assertNull( $this->finding( 'post.seo_title', 'post', $ok ) );
		$row = $this->finding( 'post.seo_title', 'post', $long );
		$this->assertSame( 'too_long', $row['detail'] );
		$this->assertSame( 'recommended', $row['severity'] );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $row['current_value'] );
	}

	public function test_with_a_long_site_name_each_page_is_judged_on_its_own_part_and_the_site_gets_one_finding() {
		// A site name that takes the whole title is one problem of the site, not one per page.
		$long_name = 'Fundación para la Investigación e Innovación Biomédica';
		add_filter(
			'wpseo_replacements',
			static function ( $replacements ) use ( $long_name ) {
				$replacements['%%sitename%%'] = $long_name;
				return $replacements;
			}
		);

		$ok   = $this->page( array( 'post_title' => 'Transparencia' ) );
		$long = $this->page( array( 'post_title' => 'Convocatoria de ayudas a la investigación biomédica traslacional de 2026' ) );

		$this->assertNull( $this->finding( 'post.seo_title', 'post', $ok ) );
		$this->assertSame( 'too_long', $this->finding( 'post.seo_title', 'post', $long )['detail'] );
		$this->assertSame( 60, \MDIA\Checks\Registry::get( 'post.seo_title' )->limits( $ok )['max'] );

		Engine::evaluate_site();
		$site = $this->finding( 'site.title_site_name', 'site', 0 );
		$this->assertSame( 'long_site_name', $site['detail'] );
		$this->assertSame( 'recommended', $site['severity'] );
	}

	public function test_a_short_site_name_is_fine_in_titles() {
		Engine::evaluate_site();
		$this->assertNull( $this->finding( 'site.title_site_name', 'site', 0 ) );
	}

	public function test_excerpt_only_for_types_that_have_one() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_excerpt' => '' ) );
		$page = $this->page();

		$this->assertSame( 'open', $this->finding( 'post.excerpt', 'post', $post )['status'] );
		$this->assertNull( $this->finding( 'post.excerpt', 'post', $page ), 'Pages have no excerpt.' );
	}

	public function test_headings_and_filler_are_read_from_the_composed_page() {
		$id = $this->page( array( 'post_content' => "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Otro título</h1>\n<!-- /wp:heading -->\n<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Y otro</h1>\n<!-- /wp:heading -->\n<!-- wp:heading {\"level\":4} -->\n<h4 class=\"wp-block-heading\">Salto</h4>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Lorem ipsum dolor sit amet.</p>\n<!-- /wp:paragraph -->" ) );

		$this->assertSame( 'several_h1,skipped_level', $this->finding( 'post.headings', 'post', $id )['detail'] );
		$this->assertStringContainsString( 'Lorem ipsum dolor', $this->finding( 'post.placeholder', 'post', $id )['current_value'], 'The reviewer sees where the filler is.' );
		$this->assertSame( 'filler_text', $this->finding( 'post.placeholder', 'post', $id )['detail'] );
		$this->assertSame( 'required', $this->finding( 'post.placeholder', 'post', $id )['severity'] );
	}

	public function test_page_with_no_readable_text_is_not_evaluable_instead_of_failing() {
		$id = $this->page( array( 'post_content' => '' ) );

		$this->assertNull( $this->finding( 'post.headings', 'post', $id ) );
		$this->assertNull( $this->finding( 'post.placeholder', 'post', $id ) );
		$this->assertNotNull( $this->finding( 'post.metadesc', 'post', $id ), 'Fields are still checked.' );
	}

	public function test_wordpress_sample_content_is_a_test_page_to_delete_not_content_to_complete() {
		$id = $this->page( array( 'post_title' => 'Sample Page', 'post_name' => 'sample-page' ) );

		$row = $this->finding( 'post.test_page', 'post', $id );
		$this->assertSame( 'default_content', $row['detail'] );
		$this->assertSame( 'required', $row['severity'] );
		$this->assertNull( $this->finding( 'post.metadesc', 'post', $id ), 'Nobody is asked to write texts for a page that has to go.' );
		$this->assertNull( $this->finding( 'post.featured_image', 'post', $id ) );
		$this->assertNull( $this->finding( 'post.placeholder', 'post', $id ) );
	}

	public function test_a_page_named_as_a_test_is_a_test_page() {
		$id = $this->page( array( 'post_title' => 'Prueba 2' ) );

		$this->assertSame( 'test_title', $this->finding( 'post.test_page', 'post', $id )['detail'] );
		$this->assertNull( $this->finding( 'post.test_page', 'post', $this->page() ) );
	}

	public function test_a_test_page_someone_says_is_real_is_checked_like_any_other() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$id    = $this->page( array( 'post_title' => 'Test' ) );
		$fid   = $this->finding( 'post.test_page', 'post', $id )['id'];

		$this->assertTrue( \MDIA\Checks\Finding_Actions::waive( (int) $fid, $admin ) );

		$this->assertSame( 'missing', $this->finding( 'post.metadesc', 'post', $id )['detail'] );
		$this->assertSame( 'waived', $this->finding( 'post.test_page', 'post', $id )['status'] );
	}

	public function test_a_test_page_is_sent_to_the_trash_by_someone_who_could_delete_it() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $author )->add_cap( 'mdia_review' );
		$id    = $this->page( array( 'post_title' => 'Página de prueba' ) );
		$fid   = (int) $this->finding( 'post.test_page', 'post', $id )['id'];
		$other = (int) ( new Findings() )->upsert( 'post.featured_image', 'post', $this->page(), array( 'status' => 'open' ) );

		$this->assertSame( 'mdia_forbidden', \MDIA\Checks\Finding_Actions::trash( $fid, $author )->get_error_code() );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( 'mdia_not_test_page', \MDIA\Checks\Finding_Actions::trash( $other, $editor )->get_error_code(), 'Only test pages are deleted from here.' );

		$kept = $this->page( array( 'post_title' => 'Test' ) );
		$real = (int) $this->finding( 'post.test_page', 'post', $kept )['id'];
		\MDIA\Checks\Finding_Actions::waive( $real, self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 'mdia_not_test_page', \MDIA\Checks\Finding_Actions::trash( $real, $editor )->get_error_code(), 'A page someone said is real is not deleted from here.' );
		$this->assertSame( 'publish', get_post_status( $kept ) );

		$this->assertTrue( \MDIA\Checks\Finding_Actions::trash( $fid, $editor ) );

		$this->assertSame( 'trash', get_post_status( $id ), 'In the trash, where it can be recovered.' );
		$this->assertNull( ( new Findings() )->get( $fid ) );
	}

	public function test_pages_hidden_from_search_engines_are_left_out_of_the_other_checks() {
		$id = $this->page();
		$this->assertNotNull( $this->finding( 'post.metadesc', 'post', $id ) );

		WPSEO_Meta::set_value( 'meta-robots-noindex', '1', $id );
		Engine::evaluate( 'post', $id );

		$this->assertNull( $this->finding( 'post.metadesc', 'post', $id ) );
		$this->assertNull( $this->finding( 'post.seo_title', 'post', $id ) );
		$this->assertSame( 'open', $this->finding( 'post.noindex', 'post', $id )['status'] );
	}

	public function test_post_types_hidden_as_a_whole_are_out_of_scope() {
		WPSEO_Options::set( 'noindex-page', true );
		$id = $this->page();

		$this->assertSame( 0, ( new Findings() )->query( array( 'target_type' => 'post', 'target_id' => $id ) )['total'] );
	}

	public function test_drafts_and_unpublishing_leave_no_findings() {
		$draft = $this->page( array( 'post_status' => 'draft' ) );
		$this->assertSame( 0, ( new Findings() )->query( array( 'target_id' => $draft ) )['total'] );

		$id = $this->page();
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$this->assertSame( 0, ( new Findings() )->query( array( 'target_id' => $id ) )['total'] );
	}

	public function test_deleting_a_post_removes_its_findings() {
		$id = $this->page();
		wp_delete_post( $id, true );

		$this->assertSame( 0, ( new Findings() )->query( array( 'target_id' => $id ) )['total'] );
	}

	public function test_human_decisions_survive_re_evaluation() {
		$id       = $this->page();
		$findings = new Findings();
		$row      = $this->finding( 'post.featured_image', 'post', $id );
		$findings->upsert( 'post.featured_image', 'post', $id, array( 'status' => 'waived', 'reviewed_by' => 1 ) );

		Engine::evaluate( 'post', $id );

		$this->assertSame( 'waived', $findings->get( $row['id'] )['status'] );
	}

	public function test_pending_proposal_is_kept_while_the_value_does_not_change_and_dropped_when_it_does() {
		$id       = $this->page();
		$findings = new Findings();
		$fid      = $this->finding( 'post.metadesc', 'post', $id )['id'];
		$findings->upsert( 'post.metadesc', 'post', $id, array( 'status' => 'proposed', 'proposed_value' => self::GOOD_DESC ) );

		Engine::evaluate( 'post', $id );
		$this->assertSame( 'proposed', $findings->get( $fid )['status'] );

		\MDIA\Seo\Seo::adapter()->set_post_description( $id, 'Otra corta.' );
		Engine::evaluate( 'post', $id );
		$row = $findings->get( $fid );
		$this->assertSame( 'open', $row['status'] );
		$this->assertNull( $row['proposed_value'] );
		$this->assertSame( 'too_short', $row['detail'] );
	}

	public function test_empty_acf_fields_are_reported_one_by_one_and_can_be_waived_site_wide() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			$this->markTestSkipped( 'ACF is not installed.' );
		}
		acf_add_local_field_group(
			array(
				'key'      => 'group_mdia_checks',
				'title'    => 'Prueba',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
				'fields'   => array(
					array( 'key' => 'field_mdia_c1', 'name' => 'antetitulo', 'label' => 'Antetítulo', 'type' => 'text', 'required' => 1 ),
					array( 'key' => 'field_mdia_c2', 'name' => 'pie', 'label' => 'Pie', 'type' => 'textarea' ),
				),
			)
		);
		$id = $this->page();

		$this->assertSame( 'required', $this->finding( 'post.acf_empty', 'post', $id, 'antetitulo' )['severity'] );
		$this->assertSame( 'recommended', $this->finding( 'post.acf_empty', 'post', $id, 'pie' )['severity'] );
		$this->assertSame( 'Pie', $this->finding( 'post.acf_empty', 'post', $id, 'pie' )['current_value'] );

		update_option( 'mdia_waived_acf_fields', array( 'pie' ) );
		Engine::evaluate( 'post', $id );
		$this->assertNull( $this->finding( 'post.acf_empty', 'post', $id, 'pie' ) );
		$this->assertNotNull( $this->finding( 'post.acf_empty', 'post', $id, 'antetitulo' ) );
	}

	public function test_term_description_only_for_terms_with_content() {
		$used   = self::factory()->category->create( array( 'name' => 'Senderismo', 'description' => '' ) );
		$unused = self::factory()->category->create( array( 'name' => 'Vacía', 'description' => '' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $used ) ) );
		Engine::evaluate( 'term', $used );
		Engine::evaluate( 'term', $unused );

		$this->assertSame( 'missing', $this->finding( 'term.description', 'term', $used )['detail'] );
		$this->assertNull( $this->finding( 'term.description', 'term', $unused ) );

		wp_update_term( $used, 'category', array( 'description' => 'Rutas de senderismo por Galicia para todos los niveles.' ) );
		$this->assertSame( 'meta_missing', $this->finding( 'term.description', 'term', $used )['detail'], 'Yoast would still publish no description.' );

		\MDIA\Seo\Seo::adapter()->set_term_description_meta( $used, 'category', 'Rutas de senderismo por Galicia para todos los niveles.' );
		Engine::evaluate( 'term', $used );
		$this->assertNull( $this->finding( 'term.description', 'term', $used ) );
	}

	public function test_author_checks_apply_to_people_who_publish() {
		$author = self::factory()->user->create( array( 'role' => 'author', 'user_login' => 'mgarcia', 'display_name' => 'mgarcia', 'description' => '' ) );
		$silent = self::factory()->user->create( array( 'role' => 'author', 'description' => '' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $author ) );
		Engine::evaluate( 'user', $author );
		Engine::evaluate( 'user', $silent );

		$this->assertSame( 'open', $this->finding( 'user.bio', 'user', $author )['status'] );
		$this->assertSame( 'open', $this->finding( 'user.display_name', 'user', $author )['status'] );
		$this->assertNull( $this->finding( 'user.bio', 'user', $silent ) );

		wp_update_user( array( 'ID' => $author, 'display_name' => 'Marta García', 'description' => 'Investigadora en biomedicina.' ) );
		$this->assertNull( $this->finding( 'user.bio', 'user', $author ) );
		$this->assertNull( $this->finding( 'user.display_name', 'user', $author ) );
	}

	public function test_site_checks() {
		update_option( 'blog_public', '0' );
		update_option( 'blogdescription', 'Otro sitio realizado con WordPress' );
		update_option( 'permalink_structure', '' );
		WPSEO_Options::set( 'enable_xml_sitemap', false );
		WPSEO_Options::set( 'company_name', '' );
		WPSEO_Options::set( 'enable_llms_txt', false );
		add_filter( 'mdia_llms_txt_path', static fn() => '/no/existe/llms.txt' );
		add_filter( 'robots_txt', static fn() => "User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nDisallow: /wp-admin/\n" );

		Engine::evaluate_site();

		foreach ( array( 'site.visible', 'site.identity', 'site.permalinks', 'site.sitemap', 'site.organization', 'site.robots' ) as $check ) {
			$row = $this->finding( $check, 'site', 0 );
			$this->assertNotNull( $row, $check );
			$this->assertSame( 'required', $row['severity'], $check );
		}
		$this->assertStringContainsString( 'GPTBot', $this->finding( 'site.robots', 'site', 0 )['detail'] );
		$this->assertSame( 'recommended', $this->finding( 'site.llms_txt', 'site', 0 )['severity'] );
		$this->assertNull( $this->finding( 'site.seo_plugin', 'site', 0 ) );
		$this->assertNull( $this->finding( 'site.schema', 'site', 0 ) );
		$this->assertSame( 'required', $this->finding( 'site.https', 'site', 0 )['severity'], 'The test site is served over plain HTTP.' );

		add_filter( 'home_url', static fn() => 'http://localhost:8787' );
		Engine::evaluate_site();
		$this->assertNull( $this->finding( 'site.https', 'site', 0 ), 'Not judged on a local copy.' );
		remove_all_filters( 'home_url' );
		$this->assertStringContainsString( 'options-reading.php', Registry::get( 'site.visible' )->fix_url( 0 ) );
		$this->assertStringContainsString( 'wpseo_page_settings', Registry::get( 'site.sitemap' )->fix_url( 0 ) );
	}

	public function test_site_checks_pass_once_fixed() {
		update_option( 'blog_public', '1' );
		update_option( 'blogdescription', 'Investigación e innovación biomédica' );
		update_option( 'permalink_structure', '/%postname%/' );
		WPSEO_Options::set( 'enable_xml_sitemap', true );
		WPSEO_Options::set( 'company_or_person', 'company' );
		WPSEO_Options::set( 'company_name', 'Fundación Ejemplo' );
		WPSEO_Options::set( 'company_logo', 'https://example.org/logo.png' );

		Engine::evaluate_site();

		foreach ( array( 'site.visible', 'site.identity', 'site.permalinks', 'site.sitemap', 'site.organization', 'site.robots' ) as $check ) {
			$this->assertNull( $this->finding( $check, 'site', 0 ), $check );
		}
	}

	public function test_without_yoast_the_dependent_checks_are_not_evaluable_and_the_site_check_says_why() {
		add_filter( 'mdia_seo_adapter', static fn() => new \MDIA\Seo\None() );
		$id = $this->page();
		Engine::evaluate_site();

		$this->assertNull( $this->finding( 'post.metadesc', 'post', $id ) );
		$this->assertNull( $this->finding( 'site.sitemap', 'site', 0 ) );
		$this->assertSame( 'required', $this->finding( 'site.seo_plugin', 'site', 0 )['severity'] );
		$this->assertNotNull( $this->finding( 'post.featured_image', 'post', $id ), 'Checks that do not need Yoast still run.' );
	}

	public function test_a_check_that_throws_does_not_stop_the_others() {
		add_filter(
			'mdia_checks',
			static function ( $checks ) {
				$checks['post.boom'] = new class() extends \MDIA\Checks\Check {
					public function id(): string { return 'post.boom'; }
					public function target_type(): string { return 'post'; }
					public function fix(): string { return 'manual'; }
					public function label(): string { return 'Boom'; }
					public function why(): string { return 'Test.'; }
					public function evaluate( int $target_id ): ?array { throw new \RuntimeException( 'boom' ); }
				};
				return $checks;
			}
		);
		$id = $this->page();

		$this->assertNotNull( $this->finding( 'post.metadesc', 'post', $id ) );
		$this->assertNull( $this->finding( 'post.boom', 'post', $id ) );
	}

	public function test_full_audit_evaluates_pages_terms_authors_and_the_site() {
		global $wpdb;
		\MDIA\Settings::update( array( 'auto_generate_new' => false, 'wizard_done' => true ) );
		$cat = self::factory()->category->create( array( 'name' => 'Senderismo', 'description' => '' ) );
		for ( $i = 0; $i < 60; $i++ ) {
			self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $cat ), 'post_title' => "Entrada $i" ) );
		}
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mdia_findings" );

		\MDIA\Queue\Scheduler::start_full_audit();
		for ( $i = 0; $i < 60 && as_get_scheduled_actions( array( 'group' => 'mdia', 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ), 'ids' ); $i++ ) {
			\ActionScheduler_QueueRunner::instance()->run();
		}

		$findings = new Findings();
		$this->assertGreaterThanOrEqual( 60, $findings->query( array( 'check_id' => 'post.metadesc' ) )['total'] );
		$this->assertSame( 1, $findings->query( array( 'check_id' => 'term.description', 'target_id' => $cat ) )['total'] );
		$this->assertGreaterThan( 0, $findings->query( array( 'target_type' => 'site' ) )['total'] );
		$this->assertFalse( \MDIA\Queue\Scheduler::full_audit_state()['running'] );
	}
}
