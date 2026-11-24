<?php
namespace MDIA\Checks\Site;

use MDIA\Checks\Check;

defined( 'ABSPATH' ) || exit;

/**
 * A site-level check. Configured, not subclassed: see Catalogue.
 */
final class Site_Check extends Check {

	private string $id;
	private string $severity;
	private string $label;
	private string $why;
	/** @var callable fn(): bool|string|null True passes; false fails; a string fails with that detail; null not evaluable. */
	private $test;
	/** @var callable fn(): string */
	private $url;

	public function __construct( string $id, string $severity, string $label, string $why, callable $test, callable $url ) {
		$this->id       = $id;
		$this->severity = $severity;
		$this->label    = $label;
		$this->why      = $why;
		$this->test     = $test;
		$this->url      = $url;
	}

	public function id(): string {
		return $this->id;
	}

	public function target_type(): string {
		return 'site';
	}

	public function fix(): string {
		return 'setting';
	}

	public function severity(): string {
		return $this->severity;
	}

	public function label(): string {
		return $this->label;
	}

	public function why(): string {
		return $this->why;
	}

	public function evaluate( int $target_id ): ?array {
		$result = ( $this->test )();
		if ( null === $result ) {
			return null;
		}
		if ( true === $result ) {
			return array();
		}
		return array( array( 'detail' => is_string( $result ) ? $result : 'fail' ) );
	}

	public function fix_url( int $target_id ): string {
		return (string) ( $this->url )();
	}
}
