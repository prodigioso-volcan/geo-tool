<?php
/**
 * Decides the status of one image from its library alt and its usages.
 *
 * @package RevisorAlt
 */

namespace MDIA\Audit;

use MDIA\Data\Items;
use MDIA\Data\Usages;

defined( 'ABSPATH' ) || exit;

final class Auditor {

	/**
	 * Value of `provider` for a proposal that is the library text itself, not an AI answer.
	 */
	public const LIBRARY_PROPOSAL = 'library';

	public const MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

	/**
	 * Set while the plugin itself writes alt text, so its own writes are not re-audited.
	 */
	public static bool $suspended = false;

	private const ISSUE_ORDER = array( Rules::MISSING, Rules::FILENAME, Rules::GENERIC, Rules::TOO_SHORT, Rules::TOO_LONG, Rules::DUPLICATE );

	private Items $items;

	private Usages $usages;

	public function __construct() {
		$this->items  = new Items();
		$this->usages = new Usages();
	}

	public static function config(): array {
		/**
		 * Filters audit thresholds and generic terms. See Rules::default_config().
		 *
		 * @param array $config Audit configuration.
		 */
		return (array) apply_filters( 'mdia_audit_config', Rules::default_config() );
	}

	public static function is_supported( int $attachment_id ): bool {
		return 'attachment' === get_post_type( $attachment_id )
			&& in_array( get_post_mime_type( $attachment_id ), self::MIME_TYPES, true );
	}

	/**
	 * @return string Resulting status, or '' when the attachment is not audited.
	 */
	public function audit( int $attachment_id ): string {
		if ( ! self::is_supported( $attachment_id ) ) {
			$this->items->delete( $attachment_id );
			return '';
		}

		$row     = $this->items->get( $attachment_id );
		$alt     = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$changes = array(
			'library_alt' => $alt,
			'audited_at'  => $now,
		);

		if ( $row && 'applying' === $row['status'] ) {
			return 'applying';
		}

		if ( $row && $row['human_locked'] ) {
			if ( $row['library_alt'] === $alt ) {
				// A person approved, ignored or marked this image: their decision stands.
				$this->items->upsert( $attachment_id, array( 'audited_at' => $now ) );
				return $row['status'];
			}
			$changes['human_locked'] = 0;
		}

		$library_issues    = $this->library_issues( $attachment_id, $alt, $row );
		$issues            = $this->with_usage_issues( $attachment_id, $library_issues );
		$changes['issues'] = implode( ',', $issues );

		if ( ! $issues ) {
			$changes['status']       = 'ok';
			$changes['queued']       = 0;
			$changes['proposed_alt'] = null;
			$changes['provider']     = null;
			$changes['last_error']   = null;
		} elseif ( ! $library_issues ) {
			// The library text is good and only some page is poor: propose that same text
			// for the page. No AI, no cost, and the good text is never replaced.
			$changes['status']       = 'needs_review';
			$changes['proposed_alt'] = $alt;
			$changes['provider']     = self::LIBRARY_PROPOSAL;
			$changes['model']        = null;
			$changes['queued']       = 0;
			$changes['last_error']   = null;
		} elseif ( $row && self::LIBRARY_PROPOSAL === $row['provider'] && 'needs_review' === $row['status'] ) {
			// The library text it was going to copy is no longer good.
			$changes['status']       = 'needs_generation';
			$changes['proposed_alt'] = null;
			$changes['provider']     = null;
			$changes['queued']       = 0;
		} elseif ( $row && ! $row['human_locked'] && in_array( $row['status'], array( 'needs_generation', 'needs_review', 'error' ), true ) ) {
			$changes['status'] = $row['status'];
		} else {
			$changes['status'] = 'needs_generation';
			$changes['queued'] = 0;
		}

		$this->items->upsert( $attachment_id, $changes );

		return $changes['status'];
	}

	/**
	 * @return string[] Issues of the text stored in the media library.
	 */
	private function library_issues( int $attachment_id, string $alt, ?array $row ): array {
		$same = $this->items->count_alt( $alt );
		if ( ! $row || 0 !== strcasecmp( (string) $row['library_alt'], $alt ) ) {
			++$same; // This image is not counted yet under its current text.
		}

		$filename = wp_basename( (string) get_attached_file( $attachment_id ) );
		return Rules::classify( $alt, $filename, $same, self::config() );
	}

	/**
	 * @param string[] $issues Library issues.
	 * @return string[] Union with the issues of manageable usages, in canonical order.
	 */
	private function with_usage_issues( int $attachment_id, array $issues ): array {
		foreach ( $this->usages->for_attachment( $attachment_id ) as $usage ) {
			if ( (int) $usage['manageable'] && '' !== $usage['issues'] ) {
				$issues = array_merge( $issues, explode( ',', $usage['issues'] ) );
			}
		}

		return array_values( array_intersect( self::ISSUE_ORDER, $issues ) );
	}
}
