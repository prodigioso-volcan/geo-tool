<?php
/**
 * Opens the setup wizard right after the plugin is activated.
 *
 * @package MetaDescriptionsIA
 */

namespace MDIA\Admin;

use MDIA\Settings;

defined( 'ABSPATH' ) || exit;

final class Activation {

	private const TRANSIENT = 'mdia_activation_redirect';

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_redirect' ) );
	}

	/**
	 * Activation callback: creates tables and capabilities, and remembers to open the wizard.
	 */
	public static function on_activate(): void {
		\MDIA\Install\Schema::install();
		set_transient( self::TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * @return string|null Where to send the user, once. Null when no redirect applies.
	 */
	public static function redirect_target(): ?string {
		if ( ! get_transient( self::TRANSIENT ) ) {
			return null;
		}
		if ( ! current_user_can( 'mdia_manage' ) ) {
			return null; // Keep it for the administrator who activated the plugin.
		}
		delete_transient( self::TRANSIENT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag set by core on bulk activation.
		if ( isset( $_GET['activate-multi'] ) || Settings::get( 'wizard_done' ) ) {
			return null;
		}
		return Menu::url();
	}

	public static function maybe_redirect(): void {
		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$target = self::redirect_target();
		if ( $target ) {
			wp_safe_redirect( $target );
			exit;
		}
	}
}
