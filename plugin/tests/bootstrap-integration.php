<?php
/**
 * Integration suite bootstrap: loads WordPress from the wpcli container.
 */

$mdia_root = dirname( __DIR__ );

require $mdia_root . '/vendor/autoload.php';

putenv( 'WP_TESTS_CONFIG_FILE=' . __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $mdia_root . '/vendor/yoast/phpunit-polyfills' );

$mdia_tests_dir = $mdia_root . '/vendor/wp-phpunit/wp-phpunit';

require_once $mdia_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $mdia_root ) {
		// Rank Math does nothing until its setup screen is completed or skipped.
		define( 'RANK_MATH_REGISTRATION_SKIP', true );
		// Third-party plugins the SEO/GEO checks integrate with, when present in the test WordPress.
		foreach ( array( 'wordpress-seo/wp-seo.php', 'seo-by-rank-math/rank-math.php', 'secure-custom-fields/secure-custom-fields.php' ) as $mdia_dependency ) {
			if ( is_readable( WP_PLUGIN_DIR . '/' . $mdia_dependency ) ) {
				require WP_PLUGIN_DIR . '/' . $mdia_dependency;
			}
		}
		require $mdia_root . '/geo-tool.php';
	}
);

require $mdia_tests_dir . '/includes/bootstrap.php';

// Real (non-temporary) plugin tables, created once for the whole run.
if ( class_exists( \MDIA\Install\Schema::class ) ) {
	\MDIA\Install\Schema::install();
}

require __DIR__ . '/integration/Helpers.php';
require __DIR__ . '/integration/Fake_Provider.php';
require __DIR__ . '/integration/Fake_Wpml.php';
require __DIR__ . '/integration/Fake_Polylang.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require __DIR__ . '/integration/Metadata_Keeping_Editor.php';
