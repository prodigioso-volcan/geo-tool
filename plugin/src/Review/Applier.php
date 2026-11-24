<?php
/**
 * Writes an approved alt text to the library and to the content that uses the image.
 *
 * @package RevisorAlt
 */

namespace MDIA\Review;

use MDIA\Audit\Auditor;
use MDIA\Audit\Rules;
use MDIA\Audit\Usage_Indexer;
use MDIA\Content\Alt_Rewriter;
use MDIA\Data\Changes;
use MDIA\Data\Items;
use MDIA\Data\Usages;
use MDIA\Queue\Scheduler;

defined( 'ABSPATH' ) || exit;

final class Applier {

	/**
	 * Posts rewritten by one background action. An image used in hundreds of posts
	 * is applied in several actions so that none of them runs out of time.
	 */
	public const POSTS_PER_ACTION = 25;

	private const MAX_LOCK_RETRIES   = 6;
	private const LOCK_RETRY_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * Safe to run more than once for the same image: every step checks what is already done.
	 *
	 * @param int   $attempt  Grows each time posts that were being edited are retried.
	 * @param int[] $post_ids Posts left to update; empty means every post that uses the image.
	 */
	public static function apply( int $attachment_id, string $batch, int $attempt = 0, array $post_ids = array() ): void {
		$items = new Items();
		$row   = $items->get( $attachment_id );
		if ( ! $row || 'applying' !== $row['status'] ) {
			return;
		}

		$new_alt  = (string) $row['proposed_alt'];
		$user_id  = (int) $row['reviewed_by'];
		$changes  = new Changes();
		$usages   = new Usages();
		$pending  = $post_ids ? $post_ids : $usages->manageable_post_ids( $attachment_id );
		$chunk    = array_slice( $pending, 0, self::POSTS_PER_ACTION );
		$rest     = array_slice( $pending, self::POSTS_PER_ACTION );
		$deferred = array();

		Auditor::$suspended = true;
		try {
			if ( null === $row['previous_alt'] ) {
				$previous = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
				if ( $previous !== $new_alt ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $new_alt ) );
					$changes->record( $batch, $attachment_id, 0, $previous, $new_alt, $user_id );
				}
				// From here on this text is a person's decision: pages that already show it are fine.
				$items->upsert(
					$attachment_id,
					array(
						'previous_alt' => $previous,
						'library_alt'  => $new_alt,
						'human_locked' => 1,
					)
				);
			} else {
				$previous = (string) $row['previous_alt'];
			}

			$rewriter = new Alt_Rewriter();
			$indexer  = new Usage_Indexer();
			$urls     = Usage_Indexer::urls_for( $attachment_id );
			$file     = (string) get_attached_file( $attachment_id );
			$filename = wp_basename( $file );
			$config   = Auditor::config();

			$should_replace = static function ( string $current ) use ( $previous, $new_alt, $filename, $config ): bool {
				if ( '' === trim( $current ) ) {
					return '' !== $new_alt;
				}
				return ( '' !== $previous && $current === $previous ) || array() !== Rules::classify( $current, $filename, 1, $config );
			};

			foreach ( $chunk as $post_id ) {
				$post_id = (int) $post_id;

				// Always re-read: the post may have been edited since the approval.
				clean_post_cache( $post_id );
				$post = get_post( $post_id );
				if ( ! Usage_Indexer::is_indexable( $post ) || Usage_Indexer::is_builder_post( $post_id ) ) {
					continue;
				}
				// An approval never reaches content the reviewer could not edit by hand (templates, for an editor).
				if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
					continue;
				}
				if ( self::is_being_edited( $post_id ) ) {
					$deferred[] = $post_id;
					continue;
				}

				$result = $rewriter->replace( $post->post_content, $attachment_id, $new_alt, $should_replace, $urls, $file );
				if ( $result['replaced'] ) {
					if ( ! self::save_content( $post_id, $result['content'], $user_id ) ) {
						$deferred[] = $post_id;
						continue;
					}
					foreach ( array_unique( $result['replaced'] ) as $old_alt ) {
						$changes->record( $batch, $attachment_id, $post_id, $old_alt, $new_alt, $user_id );
					}
				}
				$indexer->index_post( $post_id );
			}
		} finally {
			Auditor::$suspended = false;
		}

		if ( $rest ) {
			as_enqueue_async_action( 'mdia_apply_item', array( $attachment_id, $batch, $attempt, array_merge( $rest, $deferred ) ), Scheduler::GROUP );
			return;
		}
		if ( $deferred && $attempt < self::MAX_LOCK_RETRIES ) {
			as_schedule_single_action( time() + self::LOCK_RETRY_SECONDS, 'mdia_apply_item', array( $attachment_id, $batch, $attempt + 1, $deferred ), Scheduler::GROUP );
			return;
		}

		// Posts still being edited after every retry are finished by the daily watchdog.
		$items->upsert(
			$attachment_id,
			array(
				'status'       => '' === $new_alt ? 'decorative' : 'applied',
				'library_alt'  => $new_alt,
				'issues'       => '',
				'proposed_alt' => null,
				'provider'     => Auditor::LIBRARY_PROPOSAL === $row['provider'] ? null : $row['provider'],
				'human_locked' => 1,
				'queued'       => 0,
				'applied_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Saves post content exactly as given, creating a revision attributed to the reviewer.
	 *
	 * The queue runs with no logged-in user. Without this, WordPress would run the
	 * content through KSES and strip markup (iframes, scripts) an administrator added.
	 *
	 * @return bool Whether the post was saved.
	 */
	public static function save_content( int $post_id, string $content, int $user_id ): bool {
		$current_user = get_current_user_id();
		$had_kses     = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		wp_set_current_user( $user_id );
		kses_remove_filters();

		try {
			$saved = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $content ),
				),
				true
			);
		} finally {
			wp_set_current_user( $current_user );
			// Put the content filters back exactly as they were. Switching user does not do it
			// when the reviewer is also the current user (undo from the screen, queue run by hand).
			if ( $had_kses ) {
				kses_init_filters();
			} else {
				kses_remove_filters();
			}
		}

		return ! is_wp_error( $saved ) && (int) $saved === $post_id;
	}

	/**
	 * Whether someone has the post open in the editor right now, whoever they are.
	 */
	private static function is_being_edited( int $post_id ): bool {
		$lock = get_post_meta( $post_id, '_edit_lock', true );
		if ( ! $lock ) {
			return false;
		}
		$time = (int) explode( ':', (string) $lock )[0];

		/** This filter is documented in wp-admin/includes/ajax-actions.php */
		$window = (int) apply_filters( 'wp_check_post_lock_window', 150 );

		return $time > time() - $window;
	}
}
