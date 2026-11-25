<?php
/**
 * Proposes the text for one finding. Never writes the field itself.
 *
 * @package GeoTool
 */

namespace MDIA\Checks;

use MDIA\AI\Budget;
use MDIA\AI\Generator;
use MDIA\Data\Findings;
use MDIA\Queue\Scheduler;
use MDIA\Settings;

defined( 'ABSPATH' ) || exit;

final class Text_Generator {

	private const PENDING = array( 'open', 'error' );

	private const BACKOFF_MINUTES = array(
		1 => 5,
		2 => 30,
	);

	/**
	 * @return string Status of the finding after the run, '' if it does not exist.
	 */
	public static function run( int $finding_id ): string {
		$findings = new Findings();
		$row      = $findings->get( $finding_id );
		if ( ! $row ) {
			return '';
		}

		$check = Registry::get( $row['check_id'] );
		if ( ! $check || 'ai' !== $check->fix() || ! in_array( $row['status'], self::PENDING, true ) ) {
			return $row['status'];
		}
		// Page text only leaves the site after setup and after a manager accepted it.
		if ( ! Settings::get( 'wizard_done' ) || ! Settings::get( 'text_consent' ) ) {
			return $row['status'];
		}

		$provider     = Generator::provider();
		$availability = $provider->availability();
		if ( empty( $availability['available'] ) ) {
			Scheduler::pause_generation();
			Generator::set_health( false, (string) ( $availability['reason'] ?? 'unsupported' ) );
			return $row['status'];
		}

		if ( ! Budget::consume() ) {
			$findings->update_if_status( $finding_id, array( 'queued' => 1 ), self::PENDING );
			Scheduler::schedule_resume();
			return $row['status'];
		}

		$limits = $check->limits( (int) $row['target_id'] );

		// The page is read as a visitor sees it: what only members or editors see never leaves the site.
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			\MDIA\Content\Page_Text::flush();
			$prompt = (string) \MDIA\I18n\Languages::in( \MDIA\I18n\Languages::of_row( $row ), static fn() => Text_Prompt::user( $check, $row ) );
		} finally {
			\MDIA\Content\Page_Text::flush();
			wp_set_current_user( $user );
		}

		$answer = $provider->rewrite( Text_Prompt::system( $check->text_kind(), $limits, \MDIA\I18n\Languages::locale_of( $row['target_type'], (int) $row['target_id'] ) ), $prompt );
		$text   = is_wp_error( $answer ) ? null : Text_Sanitizer::clean( (string) ( $answer['text'] ?? '' ) );

		if ( null === $text ) {
			return self::failed( $findings, $row, $answer );
		}
		Generator::register_success();

		if ( ! Text_Sanitizer::fits( $text, $limits ) ) {
			// One more request to get the size right; cutting is the last resort.
			if ( Budget::consume() ) {
				$second   = $provider->rewrite( Text_Prompt::adjust_system( $limits ), $text );
				$adjusted = is_wp_error( $second ) ? null : Text_Sanitizer::clean( (string) ( $second['text'] ?? '' ) );
				if ( null !== $adjusted ) {
					$text = $adjusted;
				}
			}
			$text = Text_Sanitizer::cut( $text, $limits );
		}

		$findings->update_if_status(
			$finding_id,
			array(
				'status'         => 'proposed',
				'proposed_value' => $text,
				'provider'       => (string) ( $answer['provider'] ?? '' ),
				'model'          => (string) ( $answer['model'] ?? '' ),
				'generated_at'   => gmdate( 'Y-m-d H:i:s' ),
				'queued'         => 0,
				'attempts'       => 0,
				'last_error'     => null,
			),
			self::PENDING
		);

		return (string) $findings->get( $finding_id )['status'];
	}

	/**
	 * @param array|\WP_Error $answer
	 */
	private static function failed( Findings $findings, array $row, $answer ): string {
		$attempts = 'error' === $row['status'] ? 1 : (int) $row['attempts'] + 1;
		$message  = is_wp_error( $answer )
			? mb_substr( wp_strip_all_tags( $answer->get_error_message() ), 0, 300 )
			: __( 'The AI provider did not return a usable text.', 'geo-tool' );

		$paused = is_wp_error( $answer ) && Generator::register_failure( $message );

		if ( $attempts >= Generator::MAX_ATTEMPTS ) {
			Generator::set_health( false, 'failed', $message );
			$findings->update_if_status(
				$row['id'],
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

		$written = $findings->update_if_status(
			$row['id'],
			array(
				'status'     => 'open',
				'attempts'   => $attempts,
				'last_error' => $message,
				'queued'     => $paused ? 0 : 1,
			),
			self::PENDING
		);
		if ( $written && ! $paused ) {
			as_schedule_single_action( time() + self::BACKOFF_MINUTES[ $attempts ] * MINUTE_IN_SECONDS, 'mdia_generate_finding', array( $row['id'] ), Scheduler::GROUP );
		}

		return (string) ( $findings->get( $row['id'] )['status'] ?? 'open' );
	}
}
