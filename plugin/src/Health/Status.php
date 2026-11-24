<?php
/**
 * The single answer to "is everything green?".
 *
 * @package RevisorAlt
 */

namespace MDIA\Health;

use MDIA\AI\Budget;
use MDIA\AI\Generator;
use MDIA\Data\Items;
use MDIA\Data\Usages;
use MDIA\Queue\Scheduler;

defined( 'ABSPATH' ) || exit;

final class Status {

	private const TRANSIENT = 'mdia_status';

	/**
	 * @param bool $fresh Skip the 60-second cache.
	 * @return array{state: string, counts: array<string,int>, coverage: int, checks: array<int,array>, audit: array}
	 */
	public static function compute( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$by_status = ( new Items() )->count_by_status();
		$usages    = new Usages();
		$count     = static fn( string $status ): int => (int) ( $by_status[ $status ] ?? 0 );

		$counts = array(
			'total'            => array_sum( $by_status ),
			'correct'          => $count( 'ok' ) + $count( 'applied' ) + $count( 'decorative' ) + $count( 'ignored' ),
			'to_generate'      => $count( 'needs_generation' ),
			'to_review'        => $count( 'needs_review' ),
			'applying'         => $count( 'applying' ),
			'applied'          => $count( 'applied' ),
			'errors'           => $count( 'error' ),
			'ignored'          => $count( 'ignored' ),
			'decorative'       => $count( 'decorative' ),
			'queued'           => ( new Items() )->count_queued(),
			'unmanaged_usages' => $usages->count_unmanaged(),
		);

		$audit  = Scheduler::full_audit_state();
		$checks = array(
			self::connector_check(),
			self::queue_check(),
			self::content_check( $usages->count_deficient( true ), $audit ),
			self::budget_check(),
		);

		$states = wp_list_pluck( $checks, 'state' );
		if ( in_array( 'red', $states, true ) || $counts['errors'] > 0 ) {
			$state = 'red';
		} elseif ( in_array( 'amber', $states, true ) || $audit['running'] || $counts['to_generate'] + $counts['to_review'] + $counts['applying'] > 0 ) {
			$state = 'amber';
		} else {
			$state = 'green';
		}

		$status = array(
			'state'    => $state,
			'counts'   => $counts,
			'coverage' => $counts['total'] ? (int) floor( 100 * $counts['correct'] / $counts['total'] ) : 100,
			'checks'   => $checks,
			'audit'    => $audit,
		);

		set_transient( self::TRANSIENT, $status, MINUTE_IN_SECONDS );

		return $status;
	}

	private static function connector_check(): array {
		$label        = __( 'AI connector', 'revisor-alt' );
		$availability = Generator::provider()->availability();

		if ( empty( $availability['available'] ) ) {
			$detail = 'no_connector' === ( $availability['reason'] ?? '' )
				? __( 'No AI provider is connected. Add an API key in Settings > Connectors.', 'revisor-alt' )
				: __( 'The connected AI provider cannot describe images. Connect one that accepts image input.', 'revisor-alt' );
			return self::check( 'connector', 'red', $label, $detail, (string) ( $availability['reason'] ?? 'unsupported' ) );
		}

		$health = Generator::health();
		if ( $health && empty( $health['ok'] ) ) {
			$message = '' !== (string) ( $health['message'] ?? '' ) ? $health['message'] : __( 'The provider is not available.', 'revisor-alt' );
			/* translators: %s: error message returned by the AI provider. */
			return self::check( 'connector', 'red', $label, sprintf( __( 'The last request to the AI provider failed: %s', 'revisor-alt' ), $message ), 'failed' );
		}

		$detail = $health
			/* translators: %s: human-readable time difference, e.g. "2 hours". */
			? sprintf( __( 'Connected. Last successful answer %s ago.', 'revisor-alt' ), human_time_diff( (int) $health['at'] ) )
			: __( 'Connected. Not used yet.', 'revisor-alt' );

		return self::check( 'connector', 'green', $label, $detail );
	}

	private static function queue_check(): array {
		$label    = __( 'Background tasks', 'revisor-alt' );
		$last_run = Scheduler::last_run_at();
		$stalled  = Scheduler::has_overdue_actions() && ( null === $last_run || $last_run < time() - DAY_IN_SECONDS );

		if ( $stalled ) {
			return self::check( 'queue', 'red', $label, __( 'Tasks are waiting but none has run in the last 24 hours. Check that WP-Cron works on this site.', 'revisor-alt' ) );
		}

		$detail = null === $last_run
			? __( 'No task has needed to run yet.', 'revisor-alt' )
			/* translators: %s: human-readable time difference, e.g. "4 mins". */
			: sprintf( __( 'Last run %s ago.', 'revisor-alt' ), human_time_diff( $last_run ) );

		return self::check( 'queue', 'green', $label, $detail );
	}

	private static function content_check( int $deficient, array $audit ): array {
		$label = __( 'Published content', 'revisor-alt' );

		// Until the first audit has finished, nobody knows the state of the site: that is not "green".
		if ( null === $audit['finished_at'] ) {
			return self::check( 'content', 'amber', $label, __( 'The site has not been fully audited yet.', 'revisor-alt' ) );
		}

		if ( $deficient > 0 ) {
			/* translators: %d: number of pages. */
			$detail = sprintf( _n( '%d page still shows an image with missing or poor alt text.', '%d pages still show an image with missing or poor alt text.', $deficient, 'revisor-alt' ), $deficient );
			return self::check( 'content', 'amber', $label, $detail );
		}
		return self::check( 'content', 'green', $label, __( 'In sync with the media library.', 'revisor-alt' ) );
	}

	private static function budget_check(): array {
		$label = __( 'Daily AI usage', 'revisor-alt' );
		/* translators: 1: calls used today, 2: daily limit. */
		$detail = sprintf( __( '%1$d of %2$d requests used today.', 'revisor-alt' ), Budget::used_today(), Budget::limit() );

		if ( Budget::remaining() < 1 ) {
			return self::check( 'budget', 'amber', $label, $detail . ' ' . __( 'Generation resumes tomorrow.', 'revisor-alt' ) );
		}
		return self::check( 'budget', 'green', $label, $detail );
	}

	/**
	 * @param string $code Machine-readable reason, when the interface needs to react to it.
	 */
	private static function check( string $id, string $state, string $label, string $detail, string $code = '' ): array {
		return compact( 'id', 'state', 'label', 'detail', 'code' );
	}
}
