<?php
/**
 * Plugin bootstrap: registers every hook.
 *
 * @package GeoTool
 */

namespace MDIA;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Install\Schema::maybe_upgrade();
		Audit\Hooks::register();
		Checks\Hooks::register();
		Queue\Scheduler::register();
		Health\Site_Health::register();
		Admin\Dashboard_Widget::register();
		Rest\Api::register();
		Admin\Menu::register();
		Admin\Activation::register();
		Update\Updater::register();
		add_action( 'admin_init', array( Install\Schema::class, 'ensure_capabilities' ) );

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'geo-tool', false, dirname( plugin_basename( MDIA_FILE ) ) . '/languages' );
			}
		);
	}
}
