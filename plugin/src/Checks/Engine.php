<?php
/**
 * Runs the checks on a target and keeps the findings table in step.
 *
 * @package GeoTool
 */

namespace MDIA\Checks;

use MDIA\Data\Findings;

defined( 'ABSPATH' ) || exit;

final class Engine {

	/**
	 * Statuses that belong to the machine: they follow the latest evaluation.
	 */
	private const AUTOMATIC = array( 'open', 'proposed', 'error' );

	public static function evaluate_site(): void {
		self::evaluate( 'site', 0 );
	}

	/**
	 * Judges a page, term or person with every check that applies, as the site shows it in its language.
	 */
	public static function evaluate( string $target_type, int $target_id ): void {
		$lang = \MDIA\I18n\Languages::of( $target_type, $target_id );
		// The site and its people have no language of their own: they are judged in the main one,
		// whoever asks and whatever language their screen is in.
		$view = '' !== $lang ? $lang : \MDIA\I18n\Languages::default_code();
		\MDIA\I18n\Languages::in( $view, static fn() => self::evaluate_in_place( $target_type, $target_id, $lang ) );
	}

	private static function evaluate_in_place( string $target_type, int $target_id, string $lang ): void {
		$findings = new Findings();

		foreach ( Registry::for_type( $target_type ) as $check ) {
			try {
				$failures = $check->evaluate( $target_id );
			} catch ( \Throwable $error ) {
				continue; // One broken check never stops the others.
			}

			$existing = $findings->query(
				array(
					'check_id'    => $check->id(),
					'target_type' => $target_type,
					'target_id'   => $target_id,
					'per_page'    => 500,
				)
			)['items'];

			if ( null === $failures ) {
				// Does not apply right now: nothing pending to show. What a person decided is kept,
				// so a page that is unpublished for a moment does not ask the same question again.
				foreach ( $existing as $row ) {
					if ( in_array( $row['status'], self::AUTOMATIC, true ) ) {
						$findings->delete( $row['id'] );
					}
				}
				continue;
			}

			$seen = array();
			foreach ( $failures as $failure ) {
				$key          = (string) ( $failure['key'] ?? '' );
				$seen[ $key ] = true;
				self::record( $findings, $check, $target_type, $target_id, $key, $failure, $lang );
			}

			foreach ( $existing as $row ) {
				// What now passes disappears, except what a person applied: that is kept for undo.
				if ( ! isset( $seen[ $row['target_key'] ] ) && 'applied' !== $row['status'] ) {
					$findings->delete( $row['id'] );
				}
			}
		}
	}

	private static function record( Findings $findings, Check $check, string $target_type, int $target_id, string $key, array $failure, string $lang = '' ): void {
		$value    = (string) ( $failure['value'] ?? '' );
		$detail   = (string) ( $failure['detail'] ?? '' );
		$severity = (string) ( $failure['severity'] ?? $check->severity() );
		$row      = $findings->find( $check->id(), $target_type, $target_id, $key );

		if ( $row && 'waived' === $row['status'] ) {
			return; // A person accepted it as it is.
		}
		if ( $row && 'applied' === $row['status'] && (string) $row['current_value'] === $value && (string) $row['detail'] === $detail ) {
			return; // A person approved exactly this text knowing this objection: their decision stands.
		}

		$fields = array(
			'lang'          => $lang,
			'severity'      => $severity,
			'current_value' => $value,
			'detail'        => $detail,
		);

		$unchanged = $row && (string) $row['current_value'] === $value && in_array( $row['status'], self::AUTOMATIC, true );
		if ( ! $unchanged ) {
			// New failure, or the value changed under a pending proposal: start again.
			$fields += array(
				'status'         => 'open',
				'proposed_value' => null,
				'queued'         => 0,
				'attempts'       => 0,
				'last_error'     => null,
				'resolved_at'    => null,
			);
		}

		$findings->upsert( $check->id(), $target_type, $target_id, $fields, $key );
	}
}
