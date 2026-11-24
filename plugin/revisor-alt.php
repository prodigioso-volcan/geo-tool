<?php
/**
 * Plugin Name:       Revisor ALT by JUNGLE
 * Description:       Audits image alt text in the media library and in published content, and proposes better alt text with AI for human review.
 * Version:           0.2.2
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            JUNGLE
 * Text Domain:       revisor-alt
 * Update URI:        https://github.com/prodigioso-volcan/revisor-alt
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * @package RevisorAlt
 */

defined( 'ABSPATH' ) || exit;

define( 'MDIA_VERSION', '0.2.2' );
define( 'MDIA_FILE', __FILE__ );
define( 'MDIA_DIR', plugin_dir_path( __FILE__ ) );
define( 'MDIA_MIN_WP', '7.0' );

if ( version_compare( $GLOBALS['wp_version'], MDIA_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Revisor ALT by JUNGLE requires WordPress 7.0 or later.', 'revisor-alt' );
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
