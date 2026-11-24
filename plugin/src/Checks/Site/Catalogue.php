<?php
/**
 * The site-level checks.
 *
 * @package GeoTool
 */

namespace MDIA\Checks\Site;

use MDIA\Checks\Check;
use MDIA\Checks\Rules\Placeholder;
use MDIA\Checks\Rules\Robots_Txt;
use MDIA\Seo\Seo;

defined( 'ABSPATH' ) || exit;

final class Catalogue {

	/**
	 * Development copies are not judged on things that only matter in production.
	 */
	private static function is_local(): bool {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return 'local' === wp_get_environment_type()
			|| in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true )
			|| (bool) preg_match( '/\.(localhost|test|local)$/', $host );
	}

	/**
	 * @return Site_Check[]
	 */
	public static function checks(): array {
		$seo = Seo::adapter();

		// A setting owned by the SEO plugin cannot be judged without it.
		$with_seo = static fn( callable $test ): callable => static fn() => $seo->is_active() ? $test() : null;
		$seo_url  = static fn( string $section ): callable => static fn(): string => $seo->settings_url( $section );
		$admin    = static fn( string $path ): callable => static fn(): string => admin_url( $path );

		return array(
			new Site_Check(
				'site.seo_plugin',
				Check::REQUIRED,
				__( 'SEO plugin', 'geo-tool' ),
				__( 'GEO Tool fills in the fields of the SEO plugin (Yoast SEO or Rank Math), which is what publishes them. Without one, titles and descriptions cannot be checked or written.', 'geo-tool' ),
				static fn(): bool => $seo->is_active(),
				$admin( 'plugin-install.php?s=yoast+seo&tab=search&type=term' )
			),
			new Site_Check(
				'site.visible',
				Check::REQUIRED,
				__( 'Visible to search engines', 'geo-tool' ),
				__( 'While "Discourage search engines" is ticked, the whole site asks not to be indexed.', 'geo-tool' ),
				static fn(): bool => '1' === (string) get_option( 'blog_public' ),
				$admin( 'options-reading.php' )
			),
			new Site_Check(
				'site.identity',
				Check::REQUIRED,
				__( 'Site title and tagline', 'geo-tool' ),
				__( 'They appear in every page title and tell search engines and AI assistants what the site is.', 'geo-tool' ),
				static fn(): bool => '' !== trim( (string) get_option( 'blogname' ) ) && ! Placeholder::is_default_tagline( (string) get_option( 'blogdescription' ) ),
				$admin( 'options-general.php' )
			),
			new Site_Check(
				'site.home_metadesc',
				Check::REQUIRED,
				__( 'Home page meta description', 'geo-tool' ),
				__( 'It is the summary of the whole site in search results.', 'geo-tool' ),
				// When the home page is a normal page, its own meta description check covers it.
				$with_seo( static fn() => 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ? null : '' !== $seo->site( 'home_description' ) ),
				$seo_url( 'homepage' )
			),
			new Site_Check(
				'site.organization',
				Check::REQUIRED,
				__( 'Who is behind the site', 'geo-tool' ),
				__( 'The organisation name and logo go into the structured data that search engines and AI assistants use to identify the publisher.', 'geo-tool' ),
				$with_seo( static fn(): bool => '' !== $seo->site( 'organization_name' ) && '' !== $seo->site( 'organization_logo' ) ),
				$seo_url( 'site-representation' )
			),
			new Site_Check(
				'site.sitemap',
				Check::REQUIRED,
				__( 'XML sitemap', 'geo-tool' ),
				__( 'The sitemap is how crawlers find every page.', 'geo-tool' ),
				$with_seo( static fn(): bool => true === $seo->site( 'sitemap' ) ),
				$seo_url( 'site-features' )
			),
			new Site_Check(
				'site.robots',
				Check::REQUIRED,
				__( 'robots.txt', 'geo-tool' ),
				__( 'A robots.txt that keeps crawlers out makes the site invisible, in search results or in AI answers.', 'geo-tool' ),
				static function () {
					$robots = Robots_Source::content();
					if ( Robots_Txt::blocks_all( $robots ) ) {
						return 'blocks_all';
					}
					/**
					 * Filters the AI crawlers that must be able to read the site.
					 *
					 * @param string[] $agents User-agent names.
					 */
					$blocked = Robots_Txt::blocked_agents( $robots, (array) apply_filters( 'mdia_ai_agents', Robots_Txt::AI_AGENTS ) );
					return $blocked ? 'blocks_ai:' . implode( ', ', $blocked ) : true;
				},
				$seo->is_active() ? $seo_url( 'robots' ) : $admin( 'options-reading.php' )
			),
			new Site_Check(
				'site.schema',
				Check::REQUIRED,
				__( 'Structured data', 'geo-tool' ),
				__( 'Structured data tells machines what each page is: an article, an organisation, who wrote it and when.', 'geo-tool' ),
				$with_seo( static fn(): bool => true === $seo->site( 'schema' ) ),
				$seo_url( 'site-features' )
			),
			new Site_Check(
				'site.llms_txt',
				Check::RECOMMENDED,
				__( 'llms.txt', 'geo-tool' ),
				__( 'An llms.txt file gives AI assistants a short guide to the most important pages of the site.', 'geo-tool' ),
				/**
				 * Filters where a hand-made llms.txt would be.
				 *
				 * @param string $path Absolute path.
				 */
				static fn(): bool => true === $seo->site( 'llms_txt' ) || is_readable( (string) apply_filters( 'mdia_llms_txt_path', ABSPATH . 'llms.txt' ) ),
				$seo_url( 'site-features' )
			),
			new Site_Check(
				'site.social_image',
				Check::RECOMMENDED,
				__( 'Default image for sharing', 'geo-tool' ),
				__( 'It is the image shown when a page without its own image is shared.', 'geo-tool' ),
				$with_seo( static fn(): bool => '' !== $seo->site( 'social_image' ) ),
				$seo_url( 'site-basics' )
			),
			new Site_Check(
				'site.permalinks',
				Check::REQUIRED,
				__( 'Permalinks', 'geo-tool' ),
				__( 'Addresses like "?p=123" say nothing about the page. Use readable ones.', 'geo-tool' ),
				static fn(): bool => '' !== (string) get_option( 'permalink_structure' ),
				$admin( 'options-permalink.php' )
			),
			new Site_Check(
				'site.https',
				Check::REQUIRED,
				__( 'HTTPS', 'geo-tool' ),
				__( 'Browsers and search engines mark sites without HTTPS as not secure.', 'geo-tool' ),
				static fn() => self::is_local() ? null : str_starts_with( home_url(), 'https://' ),
				$admin( 'options-general.php' )
			),
			new Site_Check(
				'site.icon',
				Check::RECOMMENDED,
				__( 'Site icon', 'geo-tool' ),
				__( 'It is the icon in browser tabs, bookmarks and some search results.', 'geo-tool' ),
				static fn(): bool => has_site_icon(),
				$admin( 'options-general.php' )
			),
			new Site_Check(
				'site.title_site_name',
				Check::RECOMMENDED,
				__( 'Site name in page titles', 'geo-tool' ),
				__( 'The site name is added to the title of every page. Search engines cut titles longer than about 60 characters.', 'geo-tool' ),
				$with_seo( static fn() => \MDIA\Checks\Post\Seo_Title::has_long_site_name() ? 'long_site_name' : true ),
				$seo_url( '' )
			),
		);
	}
}
