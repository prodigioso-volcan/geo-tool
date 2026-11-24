<?php
/**
 * The single answer to "is everything green?".
 *
 * @package GeoTool
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
			// Images no page uses are listed apart: they are neither pending nor done.
			'total'            => array_sum( $by_status ) - $count( 'unused' ),
			'unused'           => $count( 'unused' ),
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

		// Site-level checks are cheap: always judged on the spot.
		\MDIA\Checks\Engine::evaluate_site();
		$seo = self::seo_summary();

		$images_pending = $counts['to_generate'] + $counts['to_review'] + $counts['applying'];
		$areas          = array(
			'images' => array(
				'total'   => $counts['total'],
				'pending' => $counts['total'] - $counts['correct'],
				'state'   => $counts['errors'] > 0 ? 'red' : ( $images_pending > 0 ? 'amber' : 'green' ),
			),
		) + $seo['areas'];

		$states = array_merge( wp_list_pluck( $checks, 'state' ), wp_list_pluck( $areas, 'state' ) );
		if ( in_array( 'red', $states, true ) ) {
			$state = 'red';
		} elseif ( in_array( 'amber', $states, true ) || $audit['running'] ) {
			$state = 'amber';
		} else {
			$state = 'green';
		}

		$status = array(
			'state'      => $state,
			'counts'     => $counts,
			'coverage'   => $counts['total'] ? (int) floor( 100 * $counts['correct'] / $counts['total'] ) : 100,
			'checks'     => $checks,
			'audit'      => $audit,
			'areas'      => $areas,
			'todo'       => $seo['todo'],
			'exceptions' => $seo['exceptions'],
			'texts'      => self::text_counts(),
			'seo_plugin' => \MDIA\Seo\Seo::adapter()->is_active(),
		);

		set_transient( self::TRANSIENT, $status, MINUTE_IN_SECONDS );

		return $status;
	}

	/**
	 * Target types shown together in each area of the dashboard.
	 */
	public const AREAS = array(
		'pages'    => array( 'post' ),
		'taxonomy' => array( 'term', 'user' ),
		'site'     => array( 'site' ),
	);

	/**
	 * @return array{areas: array, todo: array, exceptions: int}
	 */
	private static function seo_summary(): array {
		global $wpdb;

		$findings = new \MDIA\Data\Findings();
		$rows     = $wpdb->get_results( "SELECT check_id, target_type, severity, status, COUNT(*) AS total FROM {$wpdb->prefix}mdia_findings GROUP BY check_id, target_type, severity, status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$pending    = array(); // By target type and severity.
		$todo       = array();
		$exceptions = 0;
		foreach ( $rows as $row ) {
			$total = (int) $row['total'];
			if ( 'waived' === $row['status'] && 'required' === $row['severity'] ) {
				$exceptions += $total;
			}
			if ( ! in_array( $row['status'], \MDIA\Data\Findings::PENDING, true ) ) {
				continue;
			}
			$pending[ $row['target_type'] ][ $row['severity'] ] = ( $pending[ $row['target_type'] ][ $row['severity'] ] ?? 0 ) + $total;

			$key = $row['check_id'] . '|' . $row['severity'];
			$todo[ $key ]['check_id']  = $row['check_id'];
			$todo[ $key ]['severity']  = $row['severity'];
			$todo[ $key ]['count']     = ( $todo[ $key ]['count'] ?? 0 ) + $total;
			$todo[ $key ]['to_review'] = ( $todo[ $key ]['to_review'] ?? 0 ) + ( 'proposed' === $row['status'] ? $total : 0 );
		}

		$checks = \MDIA\Checks\Registry::all();
		$list   = array();
		foreach ( $todo as $item ) {
			$check = $checks[ $item['check_id'] ] ?? null;
			if ( ! $check ) {
				continue;
			}
			$list[] = $item + array(
				'label'   => $check->label(),
				'fix'     => $check->fix(),
				'area'    => self::area_of( $check->target_type() ),
				'fix_url' => 'site' === $check->target_type() ? $check->fix_url( 0 ) : '',
			);
		}
		usort( $list, static fn( array $a, array $b ): int => array( 'required' === $a['severity'] ? 0 : 1, -$a['count'] ) <=> array( 'required' === $b['severity'] ? 0 : 1, -$b['count'] ) );

		$totals = array(
			'pages'    => \MDIA\Checks\Targets::count_posts(),
			'taxonomy' => null,
			'site'     => count( \MDIA\Checks\Registry::for_type( 'site' ) ),
		);
		$areas  = array();
		foreach ( self::AREAS as $area => $types ) {
			$required    = 0;
			$recommended = 0;
			$targets     = 0;
			foreach ( $types as $type ) {
				$required    += (int) ( $pending[ $type ]['required'] ?? 0 );
				$recommended += (int) ( $pending[ $type ]['recommended'] ?? 0 );
				$targets     += 'site' === $type ? $required + $recommended : $findings->count_pending_targets( $type );
			}
			$areas[ $area ] = array(
				'total'   => $totals[ $area ],
				'pending' => $targets,
				'state'   => $required > 0 ? 'red' : ( $recommended > 0 ? 'amber' : 'green' ),
			);
		}

		return array(
			'areas'      => $areas,
			'todo'       => $list,
			'exceptions' => $exceptions,
		);
	}

	/**
	 * What is left for the checks that are fixed by writing a text.
	 *
	 * @return array{to_generate: int, to_review: int, queued: int}
	 */
	private static function text_counts(): array {
		global $wpdb;

		$counts = array(
			'to_generate' => 0,
			'to_review'   => 0,
			'queued'      => 0,
		);
		$ids    = array_keys( array_filter( \MDIA\Checks\Registry::all(), static fn( $check ): bool => 'ai' === $check->fix() ) );
		if ( ! $ids ) {
			return $counts;
		}

		$in   = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare( "SELECT status, COUNT(*) AS total, SUM(queued) AS queued FROM {$wpdb->prefix}mdia_findings WHERE check_id IN ($in) AND status IN ('open','error','proposed') GROUP BY status", $ids ),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$counts[ 'proposed' === $row['status'] ? 'to_review' : 'to_generate' ] += (int) $row['total'];
			$counts['queued'] += (int) $row['queued'];
		}
		return $counts;
	}

	public static function area_of( string $target_type ): string {
		foreach ( self::AREAS as $area => $types ) {
			if ( in_array( $target_type, $types, true ) ) {
				return $area;
			}
		}
		return 'images';
	}

	private static function connector_check(): array {
		$label        = __( 'AI connector', 'geo-tool' );
		$availability = Generator::provider()->availability();

		if ( empty( $availability['available'] ) ) {
			$detail = 'no_connector' === ( $availability['reason'] ?? '' )
				? __( 'No AI provider is connected. Add an API key in Settings > Connectors.', 'geo-tool' )
				: __( 'The connected AI provider cannot describe images. Connect one that accepts image input.', 'geo-tool' );
			return self::check( 'connector', 'red', $label, $detail, (string) ( $availability['reason'] ?? 'unsupported' ) );
		}

		$health = Generator::health();
		if ( $health && empty( $health['ok'] ) ) {
			$message = '' !== (string) ( $health['message'] ?? '' ) ? $health['message'] : __( 'The provider is not available.', 'geo-tool' );
			/* translators: %s: error message returned by the AI provider. */
			return self::check( 'connector', 'red', $label, sprintf( __( 'The last request to the AI provider failed: %s', 'geo-tool' ), $message ), 'failed' );
		}

		$detail = $health
			/* translators: %s: human-readable time difference, e.g. "2 hours". */
			? sprintf( __( 'Connected. Last successful answer %s ago.', 'geo-tool' ), human_time_diff( (int) $health['at'] ) )
			: __( 'Connected. Not used yet.', 'geo-tool' );

		return self::check( 'connector', 'green', $label, $detail );
	}

	private static function queue_check(): array {
		$label    = __( 'Background tasks', 'geo-tool' );
		$last_run = Scheduler::last_run_at();
		$stalled  = Scheduler::has_overdue_actions() && ( null === $last_run || $last_run < time() - DAY_IN_SECONDS );

		if ( $stalled ) {
			return self::check( 'queue', 'red', $label, __( 'Tasks are waiting but none has run in the last 24 hours. Check that WP-Cron works on this site.', 'geo-tool' ) );
		}

		$detail = null === $last_run
			? __( 'No task has needed to run yet.', 'geo-tool' )
			/* translators: %s: human-readable time difference, e.g. "4 mins". */
			: sprintf( __( 'Last run %s ago.', 'geo-tool' ), human_time_diff( $last_run ) );

		return self::check( 'queue', 'green', $label, $detail );
	}

	private static function content_check( int $deficient, array $audit ): array {
		$label = __( 'Published content', 'geo-tool' );

		// Until the first audit has finished, nobody knows the state of the site: that is not "green".
		if ( null === $audit['finished_at'] ) {
			return self::check( 'content', 'amber', $label, __( 'The site has not been fully audited yet.', 'geo-tool' ) );
		}

		if ( $deficient > 0 ) {
			/* translators: %d: number of pages. */
			$detail = sprintf( _n( '%d page still shows an image with missing or poor alt text.', '%d pages still show an image with missing or poor alt text.', $deficient, 'geo-tool' ), $deficient );
			return self::check( 'content', 'amber', $label, $detail );
		}
		return self::check( 'content', 'green', $label, __( 'In sync with the media library.', 'geo-tool' ) );
	}

	private static function budget_check(): array {
		$label = __( 'Daily AI usage', 'geo-tool' );
		/* translators: 1: calls used today, 2: daily limit. */
		$detail = sprintf( __( '%1$d of %2$d requests used today.', 'geo-tool' ), Budget::used_today(), Budget::limit() );

		if ( Budget::remaining() < 1 ) {
			return self::check( 'budget', 'amber', $label, $detail . ' ' . __( 'Generation resumes tomorrow.', 'geo-tool' ) );
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
