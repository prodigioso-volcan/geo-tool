<?php
/**
 * Daily safety net: picks up work whose background action was lost.
 *
 * @package MetaDescriptionsIA
 */

namespace MDIA\Queue;

use MDIA\Data\Changes;
use MDIA\Data\Items;

defined( 'ABSPATH' ) || exit;

final class Watchdog {

	private const LIMIT = 200;

	public static function run(): void {
		Scheduler::handle_resume_generation();
		self::recover_stuck_applies();
		self::finish_incomplete_applies();
	}

	/**
	 * An image left in "applying" by an action that died (timeout, deploy) would
	 * otherwise be blocked forever: nothing else can act on it.
	 */
	private static function recover_stuck_applies(): void {
		$active = self::attachments_with_apply_action();

		foreach ( ( new Items() )->ids_by_status( 'applying', self::LIMIT ) as $attachment_id ) {
			if ( ! isset( $active[ $attachment_id ] ) ) {
				self::enqueue_apply( $attachment_id );
			}
		}
	}

	/**
	 * Applied images that a page still shows with poor alt text, typically because
	 * someone had the page open in the editor for longer than the retries lasted.
	 * The text was already approved for the image: this only finishes applying it.
	 */
	private static function finish_incomplete_applies(): void {
		$items = new Items();

		foreach ( $items->applied_with_deficient_usages( self::LIMIT ) as $attachment_id ) {
			$row = $items->get( $attachment_id );
			$items->upsert(
				$attachment_id,
				array(
					'status'       => 'applying',
					'proposed_alt' => (string) $row['library_alt'],
				)
			);
			self::enqueue_apply( $attachment_id );
		}
	}

	private static function enqueue_apply( int $attachment_id ): void {
		// Same batch as the original apply, so that one "undo" still reverts everything.
		$latest = ( new Changes() )->latest_batch( $attachment_id );
		$batch  = $latest ? $latest[0]['batch'] : wp_generate_uuid4();

		as_enqueue_async_action( 'mdia_apply_item', array( $attachment_id, $batch, 0, array() ), Scheduler::GROUP );
	}

	/**
	 * @return array<int,true> Attachment ids that have an apply action pending or running.
	 */
	private static function attachments_with_apply_action(): array {
		$active = array();
		foreach ( array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => 'mdia_apply_item',
					'group'    => Scheduler::GROUP,
					'status'   => $status,
					'per_page' => 1000,
				)
			);
			foreach ( $actions as $action ) {
				$args = $action->get_args();
				if ( isset( $args[0] ) ) {
					$active[ (int) $args[0] ] = true;
				}
			}
		}
		return $active;
	}
}
