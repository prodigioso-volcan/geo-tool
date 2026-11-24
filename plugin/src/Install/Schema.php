<?php
/**
 * Database tables, capabilities and migrations.
 *
 * @package MetaDescriptionsIA
 */

namespace MDIA\Install;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const DB_VERSION = 1;

	public const TABLES = array( 'mdia_items', 'mdia_usages', 'mdia_changes' );

	private const CAPS = array(
		'administrator' => array( 'mdia_manage', 'mdia_review' ),
		'editor'        => array( 'mdia_review' ),
	);

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mdia_items (
				attachment_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'ok',
				issues varchar(190) NOT NULL DEFAULT '',
				library_alt text NOT NULL,
				proposed_alt text NULL,
				previous_alt text NULL,
				human_locked tinyint(1) NOT NULL DEFAULT 0,
				queued tinyint(1) NOT NULL DEFAULT 0,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				last_error text NULL,
				provider varchar(50) NULL,
				model varchar(100) NULL,
				reviewed_by bigint(20) unsigned NULL,
				audited_at datetime NULL,
				generated_at datetime NULL,
				applied_at datetime NULL,
				PRIMARY KEY  (attachment_id),
				KEY status (status)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mdia_usages (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				alt text NOT NULL,
				issues varchar(190) NOT NULL DEFAULT '',
				occurrences smallint(5) unsigned NOT NULL DEFAULT 1,
				manageable tinyint(1) NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				UNIQUE KEY usage_key (attachment_id,post_id,manageable),
				KEY post_id (post_id)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mdia_changes (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				batch char(36) NOT NULL,
				attachment_id bigint(20) unsigned NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				old_alt text NOT NULL,
				new_alt text NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				undone_at datetime NULL,
				PRIMARY KEY  (id),
				KEY batch (batch),
				KEY attachment_id (attachment_id)
			) $charset;"
		);

		foreach ( self::CAPS as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( $caps as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}

		update_option( 'mdia_db_version', self::DB_VERSION, false );
	}

	/**
	 * Runs pending migrations after a plugin update on a delivered site.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( 'mdia_db_version', 0 ) < self::DB_VERSION ) {
			self::install();
		}
	}

	public static function uninstall(): void {
		global $wpdb;

		foreach ( self::TABLES as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table names.
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
		}

		$options = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'mdia_' ) . '%', $wpdb->esc_like( '_transient_mdia_' ) . '%' )
		);
		foreach ( $options as $option ) {
			delete_option( $option );
		}

		foreach ( self::CAPS as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( $caps as $cap ) {
					$role->remove_cap( $cap );
				}
			}
		}

		self::delete_scheduled_actions();
	}

	/**
	 * Removes this plugin's Action Scheduler actions straight from its tables: at uninstall
	 * time the library may not be initialised, and other plugins may share those tables.
	 */
	private static function delete_scheduled_actions(): void {
		global $wpdb;

		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';

		foreach ( array( $actions, $groups ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return;
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names built from the prefix.
		$wpdb->query( $wpdb->prepare( "DELETE a FROM {$actions} a INNER JOIN {$groups} g ON g.group_id = a.group_id WHERE g.slug = %s", 'mdia' ) );
	}
}
