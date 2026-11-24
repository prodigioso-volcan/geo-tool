<?php

use MDIA\AI\Core_Provider;

class CoreProviderTest extends WP_UnitTestCase {

	private const PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	public function set_up() {
		parent::set_up();
		// The local environment (docker/local-model.php) sets its own preference: start clean.
		remove_all_filters( 'mdia_model_preference' );
	}

	public function test_prevented_prompt_is_unavailable_and_returns_an_error() {
		add_filter( 'wp_ai_client_prevent_prompt', '__return_true' );
		$provider = new Core_Provider();

		$this->assertFalse( $provider->availability()['available'] );
		$this->assertWPError( $provider->describe( self::PIXEL, 'system', 'prompt' ) );
	}

	public function test_without_any_configured_connector_reason_is_no_connector() {
		$availability = ( new Core_Provider() )->availability();

		$this->assertFalse( $availability['available'] );
		$this->assertSame( 'no_connector', $availability['reason'] );
	}

	public function test_default_provider_is_the_core_one_and_is_filterable() {
		$this->assertInstanceOf( Core_Provider::class, \MDIA\AI\Generator::provider() );

		$fake = MDIA_Fake_Provider::install();
		$this->assertSame( $fake, \MDIA\AI\Generator::provider() );
	}

	public function test_no_model_is_preferred_by_default() {
		$this->assertSame( array(), Core_Provider::model_preference() );
	}

	public function test_sites_can_prefer_models_and_bad_entries_are_dropped() {
		add_filter(
			'mdia_model_preference',
			static fn() => array( 'claude-haiku-5-5', array( 'aws-bedrock', 'eu.anthropic.claude-haiku-5-5' ), '', 42, array( 'solo-uno' ), array( 'a', 'b', 'c' ) )
		);

		$this->assertSame(
			array( 'claude-haiku-5-5', array( 'aws-bedrock', 'eu.anthropic.claude-haiku-5-5' ) ),
			Core_Provider::model_preference()
		);
	}

	public function test_answers_have_room_for_models_that_think_before_writing() {
		// A model that reasons first spends output tokens on it: with 200 it never got to the text.
		$this->assertSame( 2000, Core_Provider::max_tokens() );

		add_filter( 'mdia_max_tokens', static fn() => 4000 );
		$this->assertSame( 4000, Core_Provider::max_tokens() );
	}

	public function test_a_broken_token_limit_stays_within_bounds() {
		add_filter( 'mdia_max_tokens', static fn() => 'muchos' );
		$this->assertSame( 200, Core_Provider::max_tokens() );

		remove_all_filters( 'mdia_max_tokens' );
		add_filter( 'mdia_max_tokens', static fn() => 900000 );
		$this->assertSame( 8000, Core_Provider::max_tokens() );
	}

	public function test_a_broken_preference_filter_does_not_break_the_provider() {
		add_filter( 'mdia_model_preference', static fn() => 'no es una lista' );
		add_filter( 'wp_ai_client_prevent_prompt', '__return_true' );

		$this->assertSame( array(), Core_Provider::model_preference() );
		$this->assertWPError( ( new Core_Provider() )->describe( self::PIXEL, 'system', 'prompt' ) );
	}
}
