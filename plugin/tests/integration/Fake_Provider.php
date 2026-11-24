<?php
/**
 * Test double for the AI provider: returns queued responses and records calls.
 */

class MDIA_Fake_Provider implements \MDIA\AI\Provider {

	/** @var array<int,mixed> */
	public array $responses = array();

	public array $calls = array();

	public array $availability = array(
		'available' => true,
		'reason'    => '',
	);

	/** @var callable|null Runs while the "request" is in flight. */
	public $on_describe = null;

	public function describe( string $data_uri, string $system, string $prompt ) {
		$this->calls[] = compact( 'data_uri', 'system', 'prompt' );
		if ( $this->on_describe ) {
			call_user_func( $this->on_describe );
		}
		$next          = $this->responses ? array_shift( $this->responses ) : 'Un faro blanco sobre un acantilado';
		if ( is_wp_error( $next ) ) {
			return $next;
		}
		return array(
			'text'     => $next,
			'provider' => 'Fake',
			'model'    => 'fake-1',
		);
	}

	/** @var array<int,mixed> Answers to text-only rewrite requests. */
	public array $rewrites = array();

	public array $rewrite_calls = array();

	public function rewrite( string $system, string $prompt ) {
		$this->rewrite_calls[] = compact( 'system', 'prompt' );
		$next                  = $this->rewrites ? array_shift( $this->rewrites ) : 'Un faro blanco';
		if ( is_wp_error( $next ) ) {
			return $next;
		}
		return array(
			'text'     => $next,
			'provider' => 'Fake',
			'model'    => 'fake-1',
		);
	}

	public function availability(): array {
		return $this->availability;
	}

	public static function install(): self {
		$fake = new self();
		add_filter( 'mdia_provider', static fn() => $fake );
		return $fake;
	}
}
