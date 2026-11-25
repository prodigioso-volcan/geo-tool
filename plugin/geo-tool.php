<?php
/**
 * Plugin Name:       GEO Tool by JUNGLE
 * Description:       Checks that a site is ready for search engines and AI assistants: image alt text, meta descriptions, titles, empty fields and site settings. Proposes the missing texts with AI for human review.
 * Version:           0.5.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            JUNGLE
 * Text Domain:       geo-tool
 * Update URI:        https://github.com/prodigioso-volcan/geo-tool
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * @package GeoTool
 */

defined( 'ABSPATH' ) || exit;

define( 'MDIA_VERSION', '0.5.0' );
define( 'MDIA_FILE', __FILE__ );
define( 'MDIA_DIR', plugin_dir_path( __FILE__ ) );
define( 'MDIA_MIN_WP', '7.0' );

if ( version_compare( $GLOBALS['wp_version'], MDIA_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'GEO Tool by JUNGLE requires WordPress 7.0 or later.', 'geo-tool' );
			echo '</p></div>';
		}
	);
	return;
}

require_once MDIA_DIR . 'vendor/autoload.php';
require_once MDIA_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

// Registered here, not in Plugin::boot(): on the activation request `plugins_loaded` has already fired.
register_activation_hook( __FILE__, array( \MDIA\Admin\Activation::class, 'on_activate' ) );

add_action( 'plugins_loaded', array( \MDIA\Plugin::instance(), 'boot' ) );
