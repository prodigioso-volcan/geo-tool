<?php
/**
 * Database tables, capabilities and migrations.
 *
 * @package GeoTool
 */

namespace MDIA\Install;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const DB_VERSION = 5;

	public const TABLES = array( 'mdia_items', 'mdia_usages', 'mdia_changes', 'mdia_findings' );

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
				kind varchar(10) NOT NULL DEFAULT '',
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

		// Version 2: one row per failed (or decided) SEO/GEO check and target.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mdia_findings (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				check_id varchar(40) NOT NULL,
				target_type varchar(10) NOT NULL,
				target_id bigint(20) unsigned NOT NULL DEFAULT 0,
				target_key varchar(100) NOT NULL DEFAULT '',
				lang varchar(12) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'open',
				severity varchar(12) NOT NULL DEFAULT 'recommended',
				current_value text NULL,
				proposed_value text NULL,
				previous_value text NULL,
				applied_value text NULL,
				detail text NULL,
				provider varchar(50) NULL,
				model varchar(100) NULL,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				queued tinyint(1) NOT NULL DEFAULT 0,
				last_error text NULL,
				reviewed_by bigint(20) unsigned NULL,
				updated_at datetime NULL,
				generated_at datetime NULL,
				resolved_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY target (check_id,target_type,target_id,target_key),
				KEY status (status),
				KEY lang (lang),
				KEY target_lookup (target_type,target_id)
			) $charset;"
		);

		self::grant_capabilities();

		// Rows written before usages had a kind: what the plugin can rewrite is content, the rest a page builder.
		$wpdb->query( "UPDATE {$wpdb->prefix}mdia_usages SET kind = IF( manageable = 1, 'content', 'builder' ) WHERE kind = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		update_option( 'mdia_db_version', self::DB_VERSION, false );
	}

	/**
	 * Gives the capabilities back after the site's roles have been reset (by a role editor,
	 * a migration or a restore). Administrators without theirs is the sign of a reset; a
	 * site that only took reviewing away from editors keeps that choice.
	 */
	public static function ensure_capabilities(): void {
		$administrator = get_role( 'administrator' );
		if ( $administrator && ! $administrator->has_cap( 'mdia_manage' ) ) {
			self::grant_capabilities();
		}
	}

	private static function grant_capabilities(): void {
		foreach ( self::CAPS as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( $caps as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
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
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_mdia_' ) . '%' ) );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_mdia_fp_desc','_mdia_fp_title')" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_mdia_wrote_seo_desc'" );
		wp_cache_flush();

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
