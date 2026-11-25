<?php
/**
 * What a reviewer can do with an image. Nothing is written without one of these.
 *
 * @package GeoTool
 */

namespace MDIA\Review;

use MDIA\Audit\Auditor;
use MDIA\Audit\Usage_Indexer;
use MDIA\Content\Alt_Rewriter;
use MDIA\Data\Changes;
use MDIA\Data\Items;
use MDIA\Queue\Scheduler;

defined( 'ABSPATH' ) || exit;

final class Actions {

	/**
	 * @return true|\WP_Error
	 */
	public static function approve( int $attachment_id, string $text, int $user_id ) {
		$row = self::reviewable( $attachment_id, $user_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$text = sanitize_text_field( $text );
		if ( '' === $text ) {
			return new \WP_Error( 'mdia_empty', __( 'The alt text cannot be empty. Mark the image as decorative instead.', 'geo-tool' ) );
		}

		return self::start_apply( $attachment_id, $text, $user_id );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function mark_decorative( int $attachment_id, int $user_id ) {
		$row = self::reviewable( $attachment_id, $user_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return self::start_apply( $attachment_id, '', $user_id );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function ignore( int $attachment_id, int $user_id ) {
		$row = self::reviewable( $attachment_id, $user_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		( new Items() )->upsert(
			$attachment_id,
			array(
				'status'       => 'ignored',
				'human_locked' => 1,
				'queued'       => 0,
				'reviewed_by'  => $user_id,
			)
		);
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function regenerate( int $attachment_id, int $user_id ) {
		$row = self::reviewable( $attachment_id, $user_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		// Applied and decorative images hold a decision that has "undo"; a paid request must not silently reset it.
		if ( ! in_array( $row['status'], array( 'needs_review', 'needs_generation', 'error', 'ignored', 'unused' ), true ) ) {
			return new \WP_Error( 'mdia_invalid_status', __( 'A new proposal cannot be requested for this image in its current state.', 'geo-tool' ) );
		}

		( new Items() )->upsert(
			$attachment_id,
			array(
				'status'       => 'needs_generation',
				'proposed_alt' => null,
				'last_error'   => null,
				'attempts'     => 0,
				'human_locked' => 0,
				'queued'       => 0,
				'provider'     => null,
			)
		);
		Scheduler::enqueue_generation( array( $attachment_id ), true );
		return true;
	}

	/**
	 * Reverts the last applied change of an image, in the library and in content.
	 *
	 * @return true|\WP_Error
	 */
	public static function undo( int $attachment_id, int $user_id ) {
		$row = self::reviewable( $attachment_id, $user_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$changes = new Changes();
		$rows    = $changes->latest_batch( $attachment_id );
		if ( ! $rows ) {
			return new \WP_Error( 'mdia_nothing_to_undo', __( 'There is no applied change to undo for this image.', 'geo-tool' ) );
		}

		$rewriter = new Alt_Rewriter();
		$indexer  = new Usage_Indexer();
		$urls     = Usage_Indexer::urls_for( $attachment_id );
		$file     = (string) get_attached_file( $attachment_id );
		$done     = array();

		Auditor::$suspended = true;
		try {
			foreach ( $rows as $change ) {
				$post_id = (int) $change['post_id'];

				if ( 0 === $post_id ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $change['old_alt'] ) );
					continue;
				}
				if ( isset( $done[ $post_id ] ) ) {
					continue;
				}
				$done[ $post_id ] = true;

				$post = get_post( $post_id );
				if ( ! Usage_Indexer::is_indexable( $post ) || Usage_Indexer::is_builder_post( $post_id ) || ! user_can( $user_id, 'edit_post', $post_id ) ) {
					continue;
				}
				// Only where the alt is still the one the plugin wrote: later human edits stand.
				$result = Applier::replace_any_copy( $rewriter, $post->post_content, $attachment_id, $change['old_alt'], static fn( string $current ): bool => $current === $change['new_alt'] );
				if ( $result['replaced'] ) {
					Applier::save_content( $post_id, $result['content'], $user_id );
				}
			}
		} finally {
			Auditor::$suspended = false;
		}

		$changes->mark_undone( array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
		( new Items() )->upsert(
			$attachment_id,
			array(
				'human_locked' => 0,
				'status'       => 'ok',
				'applied_at'   => null,
			)
		);
		foreach ( array_keys( $done ) as $post_id ) {
			$indexer->index_post( $post_id );
		}
		( new Auditor() )->audit( $attachment_id );

		return true;
	}

	/**
	 * Approves several images at once.
	 *
	 * Each entry may carry the text the reviewer sees on screen (possibly edited);
	 * without it, the stored proposal is used.
	 *
	 * @param array<int,array{id:int,text?:string}> $entries
	 * @return array{done: int, failed: int}
	 */
	public static function approve_many( array $entries, int $user_id ): array {
		$items  = new Items();
		$result = array(
			'done'   => 0,
			'failed' => 0,
		);
		$seen   = array();

		foreach ( $entries as $entry ) {
			$attachment_id = (int) ( $entry['id'] ?? 0 );
			if ( ! $attachment_id || isset( $seen[ $attachment_id ] ) ) {
				continue;
			}
			$seen[ $attachment_id ] = true;

			$row  = $items->get( $attachment_id );
			$text = trim( (string) ( $entry['text'] ?? ( $row['proposed_alt'] ?? '' ) ) );

			$approved = $row && 'needs_review' === $row['status'] && '' !== $text
				? self::approve( $attachment_id, $text, $user_id )
				: false;
			++$result[ true === $approved ? 'done' : 'failed' ];
		}
		return $result;
	}

	/**
	 * @return array|\WP_Error The item row when the user may act on it.
	 */
	private static function reviewable( int $attachment_id, int $user_id ) {
		if ( ! user_can( $user_id, 'mdia_review' ) ) {
			return new \WP_Error( 'mdia_forbidden', __( 'You are not allowed to review alt text.', 'geo-tool' ) );
		}

		$row = ( new Items() )->get( $attachment_id );
		if ( ! $row ) {
			return new \WP_Error( 'mdia_not_found', __( 'This image is not tracked by the plugin.', 'geo-tool' ) );
		}
		if ( 'applying' === $row['status'] ) {
			return new \WP_Error( 'mdia_busy', __( 'A change is still being applied to this image. Try again in a moment.', 'geo-tool' ) );
		}
		return $row;
	}

	private static function start_apply( int $attachment_id, string $text, int $user_id ): bool {
		( new Items() )->upsert(
			$attachment_id,
			array(
				'status'       => 'applying',
				'proposed_alt' => $text,
				'previous_alt' => null, // Tells the applier the library has not been written yet.
				'reviewed_by'  => $user_id,
				'queued'       => 0,
				'last_error'   => null,
			)
		);
		as_unschedule_all_actions( 'mdia_generate_item', array( $attachment_id ), Scheduler::GROUP );
		as_enqueue_async_action( 'mdia_apply_item', array( $attachment_id, wp_generate_uuid4(), 0, array() ), Scheduler::GROUP );
		return true;
	}
}
