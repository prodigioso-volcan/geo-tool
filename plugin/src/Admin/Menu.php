<?php
/**
 * Admin screen under Media and its assets.
 *
 * @package RevisorAlt
 */

namespace MDIA\Admin;

use MDIA\Rest\Api;
use MDIA\Settings;

defined( 'ABSPATH' ) || exit;

final class Menu {

	public const SLUG = 'mdia';

	private const HOOK = 'media_page_mdia';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url( string $tab = '' ): string {
		$url = admin_url( 'upload.php?page=' . self::SLUG );
		return '' === $tab ? $url : add_query_arg( 'tab', $tab, $url );
	}

	public static function add_page(): void {
		add_media_page(
			'Revisor ALT',
			'Revisor ALT',
			'mdia_review',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		echo '<div class="wrap"><h1>' . esc_html( 'Revisor ALT' ) . '</h1>';

		if ( ! is_readable( MDIA_DIR . 'build/index.asset.php' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The plugin interface has not been built. Run the build step and reload.', 'revisor-alt' ) . '</p></div></div>';
			return;
		}

		echo '<div id="mdia-root"><p>' . esc_html__( 'Loading…', 'revisor-alt' ) . '</p></div>';
		echo '<noscript><p>' . esc_html__( 'This screen needs JavaScript.', 'revisor-alt' ) . '</p></noscript></div>';
	}

	public static function enqueue( $hook ): void {
		$asset_file = MDIA_DIR . 'build/index.asset.php';
		if ( self::HOOK !== $hook || ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;
		$base  = plugin_dir_url( MDIA_FILE ) . 'build/';

		wp_enqueue_script( 'mdia-admin', $base . 'index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'mdia-admin', 'revisor-alt', MDIA_DIR . 'languages' );
		wp_enqueue_style( 'mdia-admin', $base . 'style-index.css', array( 'wp-components' ), $asset['version'] );

		$data = array(
			'restNamespace' => Api::NAMESPACE,
			'canManage'     => current_user_can( 'mdia_manage' ),
			'wizardDone'    => (bool) Settings::get( 'wizard_done' ),
			'connectorsUrl' => admin_url( 'options-connectors.php' ),
			'siteHealthUrl' => admin_url( 'site-health.php' ),
			'maxLength'     => \MDIA\AI\Response_Sanitizer::MAX_LENGTH,
		);
		wp_add_inline_script( 'mdia-admin', 'window.mdiaData = ' . wp_json_encode( $data ) . ';', 'before' );
	}
}
