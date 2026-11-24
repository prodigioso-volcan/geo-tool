<?php
/**
 * Background work on Action Scheduler.
 *
 * @package GeoTool
 */

namespace MDIA\Queue;

use MDIA\Audit\Auditor;
use MDIA\Audit\Usage_Indexer;
use MDIA\Data\Items;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	public const GROUP = 'mdia';

	private const RESUME_CHUNK     = 500;
	private const POSTS_PER_BATCH  = 50;
	private const IMAGES_PER_BATCH = 100;
	private const AUDIT_OPTION     = 'mdia_full_audit';
	private const LAST_RUN_OPTION  = 'mdia_last_run';

	public static function register(): void {
		add_action( 'mdia_index_batch', array( self::class, 'handle_index_batch' ) );
		add_action( 'mdia_audit_batch', array( self::class, 'handle_audit_batch' ) );
		add_action( 'mdia_checks_batch', array( self::class, 'handle_checks_batch' ), 10, 2 );
		add_action( 'mdia_generate_item', array( self::class, 'handle_generate' ) );
		add_action( 'mdia_generate_finding', array( self::class, 'handle_generate_finding' ) );
		add_action( 'mdia_apply_item', array( self::class, 'handle_apply' ), 10, 4 );
		add_action( 'mdia_resume_generation', array( self::class, 'handle_resume_generation' ) );
		add_action( 'mdia_daily', array( self::class, 'handle_daily' ) );
		add_action( 'init', array( self::class, 'ensure_daily' ), 20 );
		add_action( 'mdia_attachment_audited_on_upload', array( self::class, 'maybe_generate_for_upload' ), 10, 2 );
	}

	public static function ensure_daily(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'mdia_daily', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'mdia_daily', array(), self::GROUP, true );
		}
	}

	public static function start_full_audit(): void {
		if ( self::full_audit_state()['running'] ) {
			return;
		}
		update_option(
			self::AUDIT_OPTION,
			array(
				'running'     => true,
				'started_at'  => gmdate( 'c' ),
				'finished_at' => null,
			),
			false
		);
		delete_transient( 'mdia_status' );
		as_enqueue_async_action( 'mdia_index_batch', array( 0 ), self::GROUP );
	}

	/**
	 * @return array{running: bool, started_at: ?string, finished_at: ?string}
	 */
	public static function full_audit_state(): array {
		$state = get_option( self::AUDIT_OPTION, array() );
		$state = array_merge(
			array(
				'running'     => false,
				'started_at'  => null,
				'finished_at' => null,
			),
			is_array( $state ) ? $state : array()
		);

		// A run whose actions vanished (cancelled, cleaned) must not block the next one forever.
		if ( $state['running'] && ! self::has_work( 'mdia_index_batch' ) && ! self::has_work( 'mdia_audit_batch' ) && ! self::has_work( 'mdia_checks_batch' ) ) {
			$state['running'] = false;
		}
		return $state;
	}

	/**
	 * @param int[] $attachment_ids
	 * @param bool  $requested Someone asked for these images in particular: ones no page uses are included.
	 * @return int How many generations were enqueued.
	 */
	public static function enqueue_generation( array $attachment_ids, bool $requested = false ): int {
		$items    = new Items();
		$enqueued = 0;

		// Nothing is sent to the provider until setup, with its privacy notice, has been completed.
		if ( ! \MDIA\Settings::get( 'wizard_done' ) ) {
			return 0;
		}

		foreach ( array_unique( array_map( 'intval', $attachment_ids ) ) as $attachment_id ) {
			$row = $items->get( $attachment_id );
			if ( ! $row || ! in_array( $row['status'], $requested ? array( 'needs_generation', 'error', 'unused' ) : array( 'needs_generation', 'error' ), true ) ) {
				continue;
			}
			if ( as_has_scheduled_action( 'mdia_generate_item', array( $attachment_id ), self::GROUP ) ) {
				continue;
			}
			$items->upsert(
				$attachment_id,
				array(
					'queued' => 1,
					'status' => 'unused' === $row['status'] ? 'needs_generation' : $row['status'],
				)
			);
			as_enqueue_async_action( 'mdia_generate_item', array( $attachment_id ), self::GROUP );
			++$enqueued;
		}
		return $enqueued;
	}

	/**
	 * Queues text proposals for findings. Only for checks fixed with AI text, and only once
	 * a manager accepted that page text is sent to the provider.
	 *
	 * @param int[] $finding_ids
	 * @return int How many were enqueued.
	 */
	public static function enqueue_text_generation( array $finding_ids ): int {
		if ( ! \MDIA\Settings::get( 'wizard_done' ) || ! \MDIA\Settings::get( 'text_consent' ) ) {
			return 0;
		}

		$findings = new \MDIA\Data\Findings();
		$enqueued = 0;
		foreach ( array_unique( array_map( 'intval', $finding_ids ) ) as $finding_id ) {
			$row   = $findings->get( $finding_id );
			$check = $row ? \MDIA\Checks\Registry::get( $row['check_id'] ) : null;
			if ( ! $check || 'ai' !== $check->fix() || ! in_array( $row['status'], array( 'open', 'error' ), true ) ) {
				continue;
			}
			if ( as_has_scheduled_action( 'mdia_generate_finding', array( $finding_id ), self::GROUP ) ) {
				continue;
			}
			$findings->update_if_status( $finding_id, array( 'queued' => 1 ), array( 'open', 'error' ) );
			as_enqueue_async_action( 'mdia_generate_finding', array( $finding_id ), self::GROUP );
			++$enqueued;
		}
		return $enqueued;
	}

	public static function handle_generate_finding( $finding_id = 0 ): void {
		\MDIA\Checks\Text_Generator::run( (int) $finding_id );
		self::touch();
	}

	/**
	 * @return int How many queued generations were cancelled.
	 */
	public static function pause_generation(): int {
		// With a hook and no args or group, Action Scheduler cancels every pending action of the hook.
		as_unschedule_all_actions( 'mdia_generate_item' );
		as_unschedule_all_actions( 'mdia_generate_finding' );
		as_unschedule_all_actions( 'mdia_resume_generation' );
		return ( new Items() )->clear_queued() + ( new \MDIA\Data\Findings() )->clear_queued();
	}

	/**
	 * Makes sure the waiting images are picked up again when the daily budget resets.
	 */
	public static function schedule_resume(): void {
		// Pending only: as_has_scheduled_action() also matches the action that is running right now.
		if ( ! self::has_pending( 'mdia_resume_generation' ) ) {
			as_schedule_single_action( \MDIA\AI\Budget::resets_at(), 'mdia_resume_generation', array(), self::GROUP );
		}
	}

	/**
	 * Re-enqueues images that are marked as queued but have no action, up to today's budget.
	 */
	public static function handle_resume_generation(): void {
		$items     = new Items();
		$remaining = min( \MDIA\AI\Budget::remaining(), self::RESUME_CHUNK );
		$enqueued  = 0;

		foreach ( $items->ids_by_status( 'needs_generation', self::RESUME_CHUNK, true ) as $attachment_id ) {
			if ( as_has_scheduled_action( 'mdia_generate_item', array( $attachment_id ), self::GROUP ) ) {
				continue;
			}
			if ( $enqueued >= $remaining ) {
				self::schedule_resume();
				break;
			}
			as_enqueue_async_action( 'mdia_generate_item', array( $attachment_id ), self::GROUP );
			++$enqueued;
		}

		// Text proposals that were waiting for budget too.
		$waiting = ( new \MDIA\Data\Findings() )->query(
			array(
				'queued'   => true,
				'status'   => array( 'open', 'error' ),
				'per_page' => self::RESUME_CHUNK,
			)
		)['items'];
		foreach ( $waiting as $row ) {
			if ( as_has_scheduled_action( 'mdia_generate_finding', array( $row['id'] ), self::GROUP ) ) {
				continue;
			}
			if ( $enqueued >= $remaining ) {
				self::schedule_resume();
				break;
			}
			as_enqueue_async_action( 'mdia_generate_finding', array( $row['id'] ), self::GROUP );
			++$enqueued;
		}
		self::touch();
	}

	public static function maybe_generate_for_upload( $attachment_id, $status ): void {
		// Only uploads by people trusted to review start paid requests on their own: otherwise
		// anyone able to upload (authors, front-end forms) could spend the site's budget.
		$uploader = (int) get_post_field( 'post_author', (int) $attachment_id );

		// A fresh upload is not on any page yet; the setting is the request to describe it anyway.
		if ( in_array( $status, array( 'needs_generation', 'unused' ), true ) && \MDIA\Settings::get( 'auto_generate_new' ) && $uploader && user_can( $uploader, 'mdia_review' ) ) {
			self::enqueue_generation( array( (int) $attachment_id ), true );
		}
	}

	public static function last_run_at(): ?int {
		$time = (int) get_option( self::LAST_RUN_OPTION, 0 );
		return $time ? $time : null;
	}

	/**
	 * Whether plugin actions are waiting past their due time.
	 */
	public static function has_overdue_actions(): bool {
		return (bool) as_get_scheduled_actions(
			array(
				'group'        => self::GROUP,
				'status'       => \ActionScheduler_Store::STATUS_PENDING,
				'date'         => as_get_datetime_object( '-1 hour' ),
				'date_compare' => '<=',
				'per_page'     => 1,
			),
			'ids'
		);
	}

	public static function handle_index_batch( $after_post_id = 0 ): void {
		global $wpdb;

		$types    = Usage_Indexer::post_types();
		$statuses = Usage_Indexer::statuses();
		$in_types = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$in_stats = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($in_types) AND post_status IN ($in_stats) ORDER BY ID ASC LIMIT %d", array_merge( array( (int) $after_post_id ), $types, $statuses, array( self::POSTS_PER_BATCH ) ) ) );

		$indexer = new Usage_Indexer();
		foreach ( $ids as $post_id ) {
			$indexer->index_post( (int) $post_id );
		}

		if ( count( $ids ) === self::POSTS_PER_BATCH ) {
			as_enqueue_async_action( 'mdia_index_batch', array( (int) end( $ids ) ), self::GROUP );
		} else {
			as_enqueue_async_action( 'mdia_audit_batch', array( 0 ), self::GROUP );
		}
		self::touch();
	}

	public static function handle_audit_batch( $after_attachment_id = 0 ): void {
		global $wpdb;

		$mimes    = Auditor::MIME_TYPES;
		$in_mimes = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type = 'attachment' AND post_mime_type IN ($in_mimes) ORDER BY ID ASC LIMIT %d", array_merge( array( (int) $after_attachment_id ), $mimes, array( self::IMAGES_PER_BATCH ) ) ) );

		$auditor = new Auditor();
		foreach ( $ids as $attachment_id ) {
			$auditor->audit( (int) $attachment_id );
		}

		if ( count( $ids ) === self::IMAGES_PER_BATCH ) {
			as_enqueue_async_action( 'mdia_audit_batch', array( (int) end( $ids ) ), self::GROUP );
		} else {
			// Images done: now the SEO/GEO checks on pages, terms, authors and the site.
			as_enqueue_async_action( 'mdia_checks_batch', array( 'post', 0 ), self::GROUP );
		}
		self::touch();
	}

	/**
	 * Third part of the full audit. Stages run in order: post, term, user, site.
	 */
	public static function handle_checks_batch( $stage = 'post', $after = 0 ): void {
		$after  = (int) $after;
		$stages = array(
			'post' => array( 'next' => 'term', 'size' => self::POSTS_PER_BATCH ),
			'term' => array( 'next' => 'user', 'size' => self::IMAGES_PER_BATCH ),
			'user' => array( 'next' => 'site', 'size' => self::IMAGES_PER_BATCH ),
		);

		if ( isset( $stages[ $stage ] ) ) {
			$size = $stages[ $stage ]['size'];
			switch ( $stage ) {
				case 'post':
					$ids = \MDIA\Checks\Targets::post_ids_after( $after, $size );
					break;
				case 'term':
					$ids = \MDIA\Checks\Targets::term_ids_after( $after, $size );
					break;
				default:
					$ids = \MDIA\Checks\Targets::author_ids_after( $after, $size );
			}
			foreach ( $ids as $id ) {
				\MDIA\Content\Page_Text::flush();
				\MDIA\Checks\Engine::evaluate( $stage, $id );
			}

			$more = count( $ids ) === $size;
			as_enqueue_async_action( 'mdia_checks_batch', $more ? array( $stage, (int) end( $ids ) ) : array( $stages[ $stage ]['next'], 0 ), self::GROUP );
		} else {
			\MDIA\Checks\Engine::evaluate_site();

			$state                = get_option( self::AUDIT_OPTION, array() );
			$state                = is_array( $state ) ? $state : array();
			$state['running']     = false;
			$state['finished_at'] = gmdate( 'c' );
			update_option( self::AUDIT_OPTION, $state, false );
			delete_transient( 'mdia_status' );
		}
		self::touch();
	}

	public static function handle_generate( $attachment_id = 0 ): void {
		\MDIA\AI\Generator::run( (int) $attachment_id );
		self::touch();
	}

	public static function handle_apply( $attachment_id = 0, $batch = '', $attempt = 0, $post_ids = array() ): void {
		\MDIA\Review\Applier::apply( (int) $attachment_id, (string) $batch, (int) $attempt, array_map( 'intval', (array) $post_ids ) );
		self::touch();
	}

	public static function handle_daily(): void {
		self::start_full_audit();
		Watchdog::run();
		self::touch();
	}

	public static function has_pending( string $hook ): bool {
		return (bool) as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
	}

	public static function touch(): void {
		update_option( self::LAST_RUN_OPTION, time(), false );
	}

	private static function has_work( string $hook ): bool {
		foreach ( array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
			$found = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'group'    => self::GROUP,
					'status'   => $status,
					'per_page' => 1,
				),
				'ids'
			);
			if ( $found ) {
				return true;
			}
		}
		return false;
	}
}
