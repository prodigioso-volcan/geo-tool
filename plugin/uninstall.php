<?php
/**
 * Removes plugin tables, options, capabilities and scheduled actions, on every site
 * of a network. Applied alt text is kept.
 *
 * @package GeoTool
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mdia_site_id ) {
		switch_to_blog( (int) $mdia_site_id );
		\MDIA\Install\Schema::uninstall();
		restore_current_blog();
	}
} else {
	\MDIA\Install\Schema::uninstall();
}
