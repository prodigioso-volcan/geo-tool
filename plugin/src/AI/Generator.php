<?php
/**
 * Generates the proposal for one image. Never writes the alt text itself.
 *
 * @package GeoTool
 */

namespace MDIA\AI;

use MDIA\Data\Items;
use MDIA\Data\Usages;
use MDIA\Queue\Scheduler;

defined( 'ABSPATH' ) || exit;

final class Generator {

	public const MAX_ATTEMPTS = 3;

	/**
	 * Provider errors in a row, across images, after which the whole queue stops:
	 * an expired key or an exhausted quota fails every image, not one.
	 */
	public const MAX_CONSECUTIVE_FAILURES = 3;

	private const MAX_ERROR_LENGTH = 300;

	private const HEALTH_OPTION   = 'mdia_provider_health';
	private const FAILURES_OPTION = 'mdia_provider_failures';

	private const PENDING = array( 'needs_generation', 'error' );

	/**
	 * Minutes to wait before attempt N+1.
	 */
	private const BACKOFF_MINUTES = array(
		1 => 5,
		2 => 30,
	);

	public static function provider(): Provider {
		/**
		 * Filters the AI provider used to describe images.
		 *
		 * @param Provider $provider Provider instance.
		 */
		$provider = apply_filters( 'mdia_provider', new Core_Provider() );
		return $provider instanceof Provider ? $provider : new Core_Provider();
	}

	/**
	 * @return string Status of the image after the run, '' if it is not tracked.
	 */
	public static function run( int $attachment_id ): string {
		$items = new Items();
		$row   = $items->get( $attachment_id );

		if ( ! $row ) {
			return '';
		}
		if ( ! in_array( $row['status'], self::PENDING, true ) ) {
			return $row['status'];
		}

		if ( ! \MDIA\Settings::get( 'wizard_done' ) ) {
			return $row['status'];
		}

		$provider     = self::provider();
		$availability = $provider->availability();
		if ( empty( $availability['available'] ) ) {
			// Stop the whole queue: retrying each image would not help and must not spend budget.
			Scheduler::pause_generation();
			self::set_health( false, (string) ( $availability['reason'] ?? 'unsupported' ) );
			return $row['status'];
		}

		// Cheap early exit: avoids loading and encoding the image when today's budget is gone.
		if ( Budget::remaining() < 1 ) {
			return self::wait_for_budget( $items, $attachment_id, $row['status'] );
		}

		$data_uri = Image_Encoder::encode( $attachment_id );
		if ( is_wp_error( $data_uri ) ) {
			return self::fail_for_good( $items, $attachment_id, $data_uri->get_error_message(), (int) $row['attempts'] );
		}

		$attempts = 'error' === $row['status'] ? 1 : (int) $row['attempts'] + 1;

		// The reservation is atomic; a concurrent runner may have taken the last call since the check above.
		if ( ! Budget::consume() ) {
			return self::wait_for_budget( $items, $attachment_id, $row['status'] );
		}

		$answer = $provider->describe( $data_uri, Prompt::system( get_locale() ), Prompt::user( self::context( $attachment_id, $row ) ) );
		$text   = is_wp_error( $answer ) ? null : self::usable_text( $provider, (string) ( $answer['text'] ?? '' ) );

		if ( null === $text ) {
			$message = is_wp_error( $answer )
				? mb_substr( wp_strip_all_tags( $answer->get_error_message() ), 0, self::MAX_ERROR_LENGTH )
				: __( 'The AI provider did not return a usable description.', 'geo-tool' );

			$failures = 0;
			if ( is_wp_error( $answer ) ) {
				$failures = (int) get_option( self::FAILURES_OPTION, 0 ) + 1;
				update_option( self::FAILURES_OPTION, $failures, false );
			}

			if ( $attempts >= self::MAX_ATTEMPTS ) {
				self::set_health( false, 'failed', $message );
				$status = self::fail_for_good( $items, $attachment_id, $message, $attempts );
			} else {
				$status  = 'needs_generation';
				$written = $items->update_if_status(
					$attachment_id,
					array(
						'status'     => 'needs_generation',
						'attempts'   => $attempts,
						'last_error' => $message,
						'queued'     => 1,
					),
					self::PENDING
				);
				if ( $written ) {
					as_schedule_single_action( time() + self::BACKOFF_MINUTES[ $attempts ] * MINUTE_IN_SECONDS, 'mdia_generate_item', array( $attachment_id ), Scheduler::GROUP );
				}
			}

			if ( $failures >= self::MAX_CONSECUTIVE_FAILURES ) {
				// Every image is failing: stop before the rest of the queue burns today's budget.
				Scheduler::pause_generation();
				self::set_health( false, 'failed', $message );
			}

			return (string) ( $items->get( $attachment_id )['status'] ?? $status );
		}

		delete_option( self::FAILURES_OPTION );
		self::set_health( true );

		// A reviewer may have ignored, approved or marked the image while the request was in flight.
		$items->update_if_status(
			$attachment_id,
			array(
				'status'       => 'needs_review',
				'proposed_alt' => $text,
				'provider'     => (string) ( $answer['provider'] ?? '' ),
				'model'        => (string) ( $answer['model'] ?? '' ),
				'generated_at' => gmdate( 'Y-m-d H:i:s' ),
				'queued'       => 0,
				'attempts'     => 0,
				'last_error'   => null,
			),
			self::PENDING
		);

		return (string) $items->get( $attachment_id )['status'];
	}

	/**
	 * Turns a raw answer into alt text that fits the maximum length.
	 *
	 * A text that is too long is sent back once to be rewritten shorter, so that it stays
	 * a complete sentence. It is only cut when that is not possible: no budget left, the
	 * rewrite failed, or it came back too long again.
	 *
	 * @return string|null Null when the answer cannot be used.
	 */
	public static function usable_text( Provider $provider, string $raw ): ?string {
		$text = Response_Sanitizer::normalize( $raw );
		if ( null === $text || Response_Sanitizer::fits( $text ) ) {
			return $text;
		}

		if ( Budget::consume() ) {
			$answer  = $provider->rewrite( Prompt::shorten_system(), $text );
			$shorter = is_wp_error( $answer ) ? null : Response_Sanitizer::normalize( (string) ( $answer['text'] ?? '' ) );
			if ( null !== $shorter && Response_Sanitizer::fits( $shorter ) ) {
				return $shorter;
			}
		}

		return Response_Sanitizer::clean( $text );
	}

	/**
	 * Counts a provider error. Three in a row, whatever is being generated, stop the queue.
	 *
	 * @return bool Whether the queue was paused.
	 */
	public static function register_failure( string $message ): bool {
		$failures = (int) get_option( self::FAILURES_OPTION, 0 ) + 1;
		update_option( self::FAILURES_OPTION, $failures, false );

		if ( $failures >= self::MAX_CONSECUTIVE_FAILURES ) {
			Scheduler::pause_generation();
			self::set_health( false, 'failed', $message );
			return true;
		}
		return false;
	}

	public static function register_success(): void {
		delete_option( self::FAILURES_OPTION );
		self::set_health( true );
	}

	/**
	 * Forgets a previous provider failure. Called when a person starts generation again.
	 */
	public static function clear_health(): void {
		delete_option( self::HEALTH_OPTION );
		delete_option( self::FAILURES_OPTION );
		delete_transient( 'mdia_status' );
	}

	/**
	 * @return array{ok: bool, reason: string, message: string, at: int}|null Null until the provider has been used.
	 */
	public static function health(): ?array {
		$health = get_option( self::HEALTH_OPTION );
		return is_array( $health ) ? $health : null;
	}

	/**
	 * What people who cannot manage the plugin see instead of a raw provider error,
	 * which may carry account identifiers or internal endpoints.
	 */
	public static function public_error(): string {
		return __( 'The AI provider returned an error. An administrator can see the details.', 'geo-tool' );
	}

	public static function set_health( bool $ok, string $reason = '', string $message = '' ): void {
		$message = mb_substr( wp_strip_all_tags( $message ), 0, self::MAX_ERROR_LENGTH );

		update_option(
			self::HEALTH_OPTION,
			array(
				'ok'      => $ok,
				'reason'  => $reason,
				'message' => $message,
				'at'      => time(),
			),
			false
		);
		delete_transient( 'mdia_status' );
	}

	/**
	 * Leaves the image marked as queued; one shared action re-enqueues the waiting
	 * images when tomorrow's budget is available.
	 */
	private static function wait_for_budget( Items $items, int $attachment_id, string $status ): string {
		$items->update_if_status( $attachment_id, array( 'queued' => 1 ), self::PENDING );
		Scheduler::schedule_resume();
		return $status;
	}

	private static function fail_for_good( Items $items, int $attachment_id, string $message, int $attempts ): string {
		$items->update_if_status(
			$attachment_id,
			array(
				'status'     => 'error',
				'attempts'   => $attempts,
				'last_error' => $message,
				'queued'     => 0,
			),
			self::PENDING
		);
		return 'error';
	}

	private static function context( int $attachment_id, array $row ): array {
		$post_title = '';
		foreach ( ( new Usages() )->for_attachment( $attachment_id ) as $usage ) {
			if ( 'publish' === get_post_status( (int) $usage['post_id'] ) ) {
				$post_title = get_the_title( (int) $usage['post_id'] );
				break;
			}
		}

		return array(
			'filename'    => wp_basename( (string) get_attached_file( $attachment_id ) ),
			'title'       => get_the_title( $attachment_id ),
			'current_alt' => (string) $row['library_alt'],
			'post_title'  => $post_title,
		);
	}
}
