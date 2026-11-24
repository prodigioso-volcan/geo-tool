<?php
/**
 * What GEO Tool needs from the site's SEO plugin. The SEO plugin publishes the tags;
 * GEO Tool only reads and fills its fields.
 *
 * @package GeoTool
 */

namespace MDIA\Seo;

interface Adapter {

	public function is_active(): bool;

	public function name(): string;

	/**
	 * Meta description as it would be published, templates included. '' when there is none.
	 */
	public function post_description( int $post_id ): string;

	/**
	 * Meta description typed for this post, before templates. '' when nobody wrote one.
	 */
	public function post_raw_description( int $post_id ): string;

	public function set_post_description( int $post_id, string $text ): bool;

	/**
	 * Title as search engines see it, site name included.
	 */
	public function post_title( int $post_id ): string;

	public function post_raw_title( int $post_id ): string;

	/**
	 * Sets the page's own part of the title, keeping the site name. '' restores the default.
	 */
	public function set_post_title( int $post_id, string $text, bool $with_site_name = true ): bool;

	/**
	 * Puts back the stored description field exactly as it was, variables included.
	 */
	public function restore_post_description( int $post_id, string $raw ): bool;

	/**
	 * Puts back the stored title field exactly as it was, variables included.
	 */
	public function restore_post_title( int $post_id, string $raw ): bool;

	/**
	 * @return string[] Meta keys of the SEO plugin whose change makes a page worth checking again.
	 */
	public function watched_meta(): array;

	public function is_post_noindex( int $post_id ): bool;

	public function is_type_noindex( string $post_type ): bool;

	public function term_description_meta( int $term_id, string $taxonomy ): string;

	/**
	 * Description typed for the term in the SEO plugin, before templates. '' when nobody wrote one.
	 */
	public function term_raw_description_meta( int $term_id, string $taxonomy ): string;

	public function set_term_description_meta( int $term_id, string $taxonomy, string $text ): bool;

	/**
	 * Site-level setting: sitemap, schema, llms_txt (bool), organization_name, organization_logo,
	 * social_image, home_description (string). Null when unknown.
	 *
	 * @return bool|string|null
	 */
	public function site( string $key );

	/**
	 * Admin URL of the settings screen where a site-level problem is fixed: homepage, site-representation,
	 * site-basics, site-features, robots or '' for the general one. '' when there is none.
	 */
	public function settings_url( string $section ): string;
}
