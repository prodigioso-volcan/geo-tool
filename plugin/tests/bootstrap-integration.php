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
require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require __DIR__ . '/integration/Metadata_Keeping_Editor.php';
