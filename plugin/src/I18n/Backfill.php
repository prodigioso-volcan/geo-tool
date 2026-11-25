<?php
/**
 * Gives a language to what was recorded before the site had languages (or before the plugin
 * knew about them), a few hundred rows at a time, so lists can be filtered by language.
 *
 * @package GeoTool
 */

namespace MDIA\I18n;

defined( 'ABSPATH' ) || exit;

final class Backfill {

	private const OPTION = 'mdia_lang_backfill';

	private const CHUNK = 300;

	/**
	 * Does one stretch of the work. Cheap when there is nothing left to do.
	 *
	 * @param int $chunks How many chunks at most in this call.
	 */
	public static function step( int $chunks = 1 ): void {
		if ( ! Languages::is_multilingual() ) {
			return;
		}

		// A different set of languages (or another language plugin) starts the work again.
		$stamp = md5( wp_json_encode( array_keys( Languages::all() ) ) . Languages::default_code() );
		$state = get_option( self::OPTION );
		if ( ! is_array( $state ) || ( $state['stamp'] ?? '' ) !== $stamp ) {
			$state = array(
				'stamp'    => $stamp,
				'findings' => 0,
				'items'    => 0,
				'done'     => false,
			);
		}
		if ( ! empty( $state['done'] ) ) {
			return;
		}

		global $wpdb;
		for ( $i = 0; $i < $chunks && empty( $state['done'] ); $i++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$findings = $wpdb->get_results( $wpdb->prepare( "SELECT id, target_type, target_id, lang FROM {$wpdb->prefix}mdia_findings WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $state['findings'], self::CHUNK ), ARRAY_A );
			foreach ( $findings as $row ) {
				$lang = Languages::effective( (string) $row['target_type'], (int) $row['target_id'] );
				if ( $lang !== $row['lang'] ) {
					$wpdb->update( $wpdb->prefix . 'mdia_findings', array( 'lang' => $lang ), array( 'id' => (int) $row['id'] ) );
				}
				$state['findings'] = (int) $row['id'];
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT attachment_id, lang FROM {$wpdb->prefix}mdia_items WHERE attachment_id > %d ORDER BY attachment_id ASC LIMIT %d", (int) $state['items'], self::CHUNK ), ARRAY_A );
			foreach ( $items as $row ) {
				$lang = Languages::effective( 'post', (int) $row['attachment_id'] );
				if ( $lang !== $row['lang'] ) {
					$wpdb->update( $wpdb->prefix . 'mdia_items', array( 'lang' => $lang ), array( 'attachment_id' => (int) $row['attachment_id'] ) );
				}
				$state['items'] = (int) $row['attachment_id'];
			}

			$state['done'] = count( $findings ) < self::CHUNK && count( $items ) < self::CHUNK;
		}

		update_option( self::OPTION, $state, false );
	}
}
