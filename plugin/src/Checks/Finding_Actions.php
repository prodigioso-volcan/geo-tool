<?php
/**
 * What a reviewer can do with a finding. No field is written without one of these.
 *
 * @package GeoTool
 */

namespace MDIA\Checks;

use MDIA\Data\Findings;
use MDIA\Queue\Scheduler;

defined( 'ABSPATH' ) || exit;

final class Finding_Actions {

	/**
	 * Applies a text to the field the check is about.
	 *
	 * @return true|\WP_Error
	 */
	public static function approve( int $finding_id, string $text, int $user_id ) {
		list( $row, $check ) = self::load( $finding_id, $user_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( 'ai' !== $check->fix() ) {
			return new \WP_Error( 'mdia_not_text', __( 'This check is not fixed by writing a text here.', 'geo-tool' ) );
		}
		if ( ! $check->can_edit( $user_id, (int) $row['target_id'] ) ) {
			return self::forbidden();
		}
		// An applied text has its own undo: writing over it would lose the original for good.
		if ( ! in_array( $row['status'], array( 'open', 'proposed', 'error' ), true ) ) {
			return new \WP_Error( 'mdia_invalid_status', __( 'This item has already been decided. Undo it or review it again first.', 'geo-tool' ) );
		}

		$text = sanitize_text_field( $text );
		if ( '' === $text ) {
			return new \WP_Error( 'mdia_empty', __( 'The text cannot be empty.', 'geo-tool' ) );
		}

		$target_id = (int) $row['target_id'];
		$previous  = $check->current( $target_id );
		$applied   = false;
		$remaining = array();

		// The check writes the field; the decision is recorded here, not by the hooks half-way.
		Hooks::run(
			static function () use ( $check, $target_id, $text, &$applied, &$remaining ): void {
				\MDIA\Content\Page_Text::flush();
				$applied   = $check->apply( $target_id, $text );
				$remaining = $applied ? (array) $check->evaluate( $target_id ) : array();
			}
		);
		if ( ! $applied ) {
			return new \WP_Error( 'mdia_apply_failed', __( 'The text could not be saved.', 'geo-tool' ) );
		}

		// If the check still objects (a long site name, for instance), the person's choice stands anyway.
		$still = $remaining ? (string) ( $remaining[0]['value'] ?? $text ) : $text;

		( new Findings() )->upsert(
			$row['check_id'],
			$row['target_type'],
			$target_id,
			array(
				'status'         => 'applied',
				'current_value'  => $still,
				// What the check still objects to, if anything; a different objection later is news.
				'detail'         => $remaining ? (string) ( $remaining[0]['detail'] ?? '' ) : '',
				'previous_value' => $previous,
				'applied_value'  => $check->written( $target_id ),
				'proposed_value' => null,
				'queued'         => 0,
				'last_error'     => null,
				'reviewed_by'    => $user_id,
				'resolved_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			$row['target_key']
		);
		as_unschedule_all_actions( 'mdia_generate_finding', array( $finding_id ), Scheduler::GROUP );

		return true;
	}

	/**
	 * @param array<int,array{id:int,text?:string}> $entries Findings with the text shown on screen.
	 * @return array{done: int, failed: int}
	 */
	public static function approve_many( array $entries, int $user_id ): array {
		$findings = new Findings();
		$result   = array(
			'done'   => 0,
			'failed' => 0,
		);
		$seen     = array();

		foreach ( $entries as $entry ) {
			$finding_id = (int) ( $entry['id'] ?? 0 );
			if ( ! $finding_id || isset( $seen[ $finding_id ] ) ) {
				continue;
			}
			$seen[ $finding_id ] = true;

			$row  = $findings->get( $finding_id );
			$text = trim( (string) ( $entry['text'] ?? ( $row['proposed_value'] ?? '' ) ) );

			$approved = $row && 'proposed' === $row['status'] && '' !== $text ? self::approve( $finding_id, $text, $user_id ) : false;
			++$result[ true === $approved ? 'done' : 'failed' ];
		}
		return $result;
	}

	/**
	 * Accepts a finding as it is ("does not apply here"). Required checks need a manager,
	 * and the dashboard keeps showing them as exceptions.
	 *
	 * @return true|\WP_Error
	 */
	public static function waive( int $finding_id, int $user_id ) {
		list( $row, $check ) = self::load( $finding_id, $user_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! $check->can_edit( $user_id, (int) $row['target_id'] ) ) {
			return self::forbidden();
		}
		if ( Check::REQUIRED === $row['severity'] && ! user_can( $user_id, 'mdia_manage' ) ) {
			return self::forbidden();
		}

		( new Findings() )->upsert(
			$row['check_id'],
			$row['target_type'],
			(int) $row['target_id'],
			array(
				'status'         => 'waived',
				'proposed_value' => null,
				'queued'         => 0,
				'reviewed_by'    => $user_id,
				'resolved_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			$row['target_key']
		);
		// Said to be real content: from now on it is checked like any other page.
		if ( Post\Test_Page::ID === $row['check_id'] ) {
			Hooks::run( static fn() => Engine::evaluate( 'post', (int) $row['target_id'] ) );
		}
		return true;
	}

	/**
	 * Puts a waived finding back under review.
	 *
	 * @return true|\WP_Error
	 */
	public static function reopen( int $finding_id, int $user_id ) {
		list( $row, $check ) = self::load( $finding_id, $user_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! $check->can_edit( $user_id, (int) $row['target_id'] ) ) {
			return self::forbidden();
		}
		// An applied text has its own undo; reopening it would throw the original away.
		if ( 'waived' !== $row['status'] ) {
			return new \WP_Error( 'mdia_invalid_status', __( 'Only an item accepted as it is can be reviewed again.', 'geo-tool' ) );
		}
		// An exception to a required check was a manager's decision: only a manager takes it back.
		if ( 'waived' === $row['status'] && Check::REQUIRED === $row['severity'] && ! user_can( $user_id, 'mdia_manage' ) ) {
			return self::forbidden();
		}

		( new Findings() )->upsert(
			$row['check_id'],
			$row['target_type'],
			(int) $row['target_id'],
			array(
				'status'      => 'open',
				'reviewed_by' => null,
				'resolved_at' => null,
			),
			$row['target_key']
		);
		Engine::evaluate( $row['target_type'], (int) $row['target_id'] );
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function regenerate( int $finding_id, int $user_id ) {
		list( $row, $check ) = self::load( $finding_id, $user_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! $check->can_edit( $user_id, (int) $row['target_id'] ) ) {
			return self::forbidden();
		}
		if ( 'ai' !== $check->fix() || ! in_array( $row['status'], array( 'open', 'proposed', 'error' ), true ) ) {
			return new \WP_Error( 'mdia_invalid_status', __( 'A new proposal cannot be requested for this item in its current state.', 'geo-tool' ) );
		}
		// Checked before touching anything: a proposal is not thrown away for a request that will not be made.
		if ( ! \MDIA\Settings::get( 'wizard_done' ) || ! \MDIA\Settings::get( 'text_consent' ) ) {
			return new \WP_Error( 'mdia_no_consent', __( 'The AI is not allowed to read page text. An administrator can allow it in the settings.', 'geo-tool' ) );
		}

		( new Findings() )->upsert(
			$row['check_id'],
			$row['target_type'],
			(int) $row['target_id'],
			array(
				'status'         => 'open',
				'proposed_value' => null,
				'last_error'     => null,
				'attempts'       => 0,
				'queued'         => 0,
			),
			$row['target_key']
		);
		Scheduler::enqueue_text_generation( array( $finding_id ) );
		return true;
	}

	/**
	 * Sends a test page to the trash, where it can be recovered. Nothing else is deleted from here.
	 *
	 * @return true|\WP_Error
	 */
	public static function trash( int $finding_id, int $user_id ) {
		list( $row ) = self::load( $finding_id, $user_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$post = Post\Test_Page::ID === $row['check_id'] ? get_post( (int) $row['target_id'] ) : null;
		if ( ! $post || 'waived' === $row['status'] || '' === Post\Test_Page::detect( $post ) ) {
			return new \WP_Error( 'mdia_not_test_page', __( 'Only test pages can be deleted from here.', 'geo-tool' ) );
		}
		if ( ! user_can( $user_id, 'delete_post', $post->ID ) ) {
			return self::forbidden();
		}
		if ( in_array( (int) $post->ID, array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ), true ) ) {
			return new \WP_Error( 'mdia_in_use', __( 'This page is the home page or the posts page of the site. Choose another one in Settings > Reading first.', 'geo-tool' ) );
		}
		if ( ! wp_trash_post( $post->ID ) ) {
			return new \WP_Error( 'mdia_apply_failed', __( 'The page could not be sent to the trash.', 'geo-tool' ) );
		}
		( new Findings() )->delete_for_target( 'post', (int) $post->ID );
		return true;
	}

	/**
	 * Puts back the value the field had before the approval.
	 *
	 * @return true|\WP_Error
	 */
	public static function undo( int $finding_id, int $user_id ) {
		list( $row, $check ) = self::load( $finding_id, $user_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( 'applied' !== $row['status'] || null === $row['previous_value'] ) {
			return new \WP_Error( 'mdia_nothing_to_undo', __( 'There is no applied change to undo here.', 'geo-tool' ) );
		}
		if ( ! $check->can_edit( $user_id, (int) $row['target_id'] ) ) {
			return self::forbidden();
		}
		if ( ! self::is_untouched( $row, $check ) ) {
			return new \WP_Error( 'mdia_changed_since', __( 'The text was changed after it was approved. Undoing would overwrite that change.', 'geo-tool' ) );
		}

		$target_id = (int) $row['target_id'];
		$previous  = (string) $row['previous_value'];
		Hooks::run( static fn() => $check->restore( $target_id, $previous ) );

		( new Findings() )->upsert(
			$row['check_id'],
			$row['target_type'],
			$target_id,
			array(
				'status'         => 'open',
				'previous_value' => null,
				'applied_value'  => null,
				'reviewed_by'    => null,
				'resolved_at'    => null,
			),
			$row['target_key']
		);
		\MDIA\Content\Page_Text::flush();
		Engine::evaluate( $row['target_type'], $target_id );
		return true;
	}

	/**
	 * Declares an ACF field optional for the whole site: it stops being reported on every page.
	 *
	 * @return true|\WP_Error
	 */
	public static function waive_acf_field( string $field_name, int $user_id ) {
		if ( ! user_can( $user_id, 'mdia_manage' ) ) {
			return self::forbidden();
		}
		// ACF field names keep their case.
		$field_name = trim( sanitize_text_field( $field_name ) );
		if ( '' === $field_name ) {
			return new \WP_Error( 'mdia_not_found', __( 'Unknown field.', 'geo-tool' ) );
		}

		$waived = array_values( array_unique( array_merge( (array) get_option( Post\Acf_Empty::WAIVED_OPTION, array() ), array( $field_name ) ) ) );
		update_option( Post\Acf_Empty::WAIVED_OPTION, $waived, false );

		( new Findings() )->delete_by_key( 'post.acf_empty', $field_name );
		return true;
	}

	/**
	 * Whether the field still holds the text that was approved. Rows applied before this
	 * was recorded are given the benefit of the doubt.
	 */
	public static function is_untouched( array $row, Check $check ): bool {
		return null === ( $row['applied_value'] ?? null ) || (string) $row['applied_value'] === $check->written( (int) $row['target_id'] );
	}

	/**
	 * @return array{0: array|\WP_Error, 1: ?Check}
	 */
	private static function load( int $finding_id, int $user_id ): array {
		if ( ! user_can( $user_id, 'mdia_review' ) ) {
			return array( self::forbidden(), null );
		}
		$row   = ( new Findings() )->get( $finding_id );
		$check = $row ? Registry::get( $row['check_id'] ) : null;
		if ( ! $row || ! $check ) {
			return array( new \WP_Error( 'mdia_not_found', __( 'This item no longer exists.', 'geo-tool' ) ), null );
		}
		return array( $row, $check );
	}

	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'mdia_forbidden', __( 'You are not allowed to do this.', 'geo-tool' ) );
	}
}
