<?php
namespace MDIA\Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Checks on people who sign published content. Configured, not subclassed.
 */
final class Author_Check extends Check {

	private string $id;
	private string $label;
	private string $why;
	/** @var callable fn( \WP_User ): bool True when the author passes. */
	private $passes;

	public function __construct( string $id, string $label, string $why, callable $passes ) {
		$this->id     = $id;
		$this->label  = $label;
		$this->why    = $why;
		$this->passes = $passes;
	}

	public function id(): string {
		return $this->id;
	}

	public function target_type(): string {
		return 'user';
	}

	public function fix(): string {
		return 'manual';
	}

	public function label(): string {
		return $this->label;
	}

	public function why(): string {
		return $this->why;
	}

	public function evaluate( int $target_id ): ?array {
		$user = get_userdata( $target_id );
		if ( ! $user || ! Targets::is_author( $target_id ) ) {
			return null;
		}
		return ( $this->passes )( $user ) ? array() : array( array( 'detail' => 'missing' ) );
	}
}
