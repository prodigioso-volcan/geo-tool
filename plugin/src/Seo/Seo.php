<?php
/**
 * Entry point to the site's SEO plugin: Yoast SEO or Rank Math.
 *
 * @package GeoTool
 */

namespace MDIA\Seo;

defined( 'ABSPATH' ) || exit;

final class Seo {

	public static function adapter(): Adapter {
		if ( Yoast::available() ) {
			$adapter = new Yoast();
		} elseif ( Rank_Math::available() ) {
			$adapter = new Rank_Math();
		} else {
			$adapter = new None();
		}

		/**
		 * Filters the SEO plugin adapter. Lets other SEO plugins be supported.
		 *
		 * @param Adapter $adapter Detected adapter.
		 */
		$filtered = apply_filters( 'mdia_seo_adapter', $adapter );

		return $filtered instanceof Adapter ? $filtered : $adapter;
	}
}
