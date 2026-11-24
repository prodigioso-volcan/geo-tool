<?php
/**
 * Daily cap on provider calls, so the plugin never spends without limit.
 *
 * @package RevisorAlt
 */

namespace MDIA\AI;

use MDIA\Settings;

defined( 'ABSPATH' ) || exit;

final class Budget {

	private const USED_OPTION = 'mdia_budget_used';
	private const DATE_OPTION = 'mdia_budget_date';

	public static function limit(): int {
		return (int) Settings::get( 'daily_limit' );
	}

	public static function used_today(): int {
		if ( get_option( self::DATE_OPTION ) !== wp_date( 'Y-m-d' ) ) {
			return 0;
		}
		// Other processes change the counter directly in the database: never trust a cached copy.
		wp_cache_delete( self::USED_OPTION, 'options' );
		return max( 0, (int) get_option( self::USED_OPTION, 0 ) );
	}

	public static function remaining(): int {
		return max( 0, self::limit() - self::used_today() );
	}

	/**
	 * Reserves one call from today's budget.
	 *
	 * The check and the increment are a single SQL statement, so concurrent queue
	 * runners cannot both take the last call. Callers must act on the result.
	 *
	 * @return bool False when the daily limit has been reached.
	 */
	public static function consume(): bool {
		global $wpdb;

		self::start_day();

		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				self::USED_OPTION,
				self::limit()
			)
		);

		wp_cache_delete( self::USED_OPTION, 'options' );
		delete_transient( 'mdia_status' );

		return 1 === $rows;
	}

	private static function start_day(): void {
		$today = wp_date( 'Y-m-d' );

		if ( get_option( self::DATE_OPTION ) !== $today ) {
			update_option( self::DATE_OPTION, $today, false );
			update_option( self::USED_OPTION, '0', false );
		}
		wp_cache_delete( self::USED_OPTION, 'options' );
		if ( false === get_option( self::USED_OPTION ) ) {
			add_option( self::USED_OPTION, '0', '', false );
		}
	}

	/**
	 * Timestamp of 00:05 tomorrow in the site time zone, when the budget is available again.
	 */
	public static function resets_at(): int {
		$tomorrow = new \DateTimeImmutable( 'tomorrow 00:05', wp_timezone() );
		return $tomorrow->getTimestamp();
	}
}
