<?php

use MDIA\AI\Core_Provider;
use MDIA\AI\Models;
use MDIA\Settings;

class ModelsTest extends WP_UnitTestCase {

	/** The list Amazon Bedrock really gives, in its order: a tiny model comes first. */
	private const BEDROCK = array(
		array( 'aws-bedrock', 'nvidia.nemotron-nano-12b-v2' ),
		array( 'aws-bedrock', 'google.gemma-3-12b-it' ),
		array( 'aws-bedrock', 'eu.amazon.nova-lite-v1:0' ),
		array( 'aws-bedrock', 'eu.anthropic.claude-opus-4-8' ),
		array( 'aws-bedrock', 'eu.anthropic.claude-haiku-4-5-20251001-v1:0' ),
		array( 'aws-bedrock', 'global.anthropic.claude-haiku-4-5-20251001-v1:0' ),
		array( 'aws-bedrock', 'eu.anthropic.claude-haiku-5-5' ),
		array( 'aws-bedrock', 'global.anthropic.claude-haiku-5-5' ),
		array( 'aws-bedrock', 'us.anthropic.claude-haiku-5-5' ),
		array( 'aws-bedrock', 'global.openai.gpt-6-sol' ),
	);

	private function models( array $pairs ): array {
		return array_map(
			static fn( array $pair ): array => array(
				'provider'      => $pair[0],
				'provider_name' => $pair[0],
				'id'            => $pair[1],
				'name'          => $pair[1],
			),
			$pairs
		);
	}

	public function set_up() {
		parent::set_up();
		remove_all_filters( 'mdia_model_preference' );
		delete_transient( 'mdia_default_models' );
	}

	public function test_small_reliable_models_are_the_recommended_ones() {
		foreach ( array( 'claude-haiku-5-5', 'eu.anthropic.claude-haiku-4-5-20251001-v1:0', 'gpt-5-mini', 'gpt-4o-mini', 'gpt-4.1-mini-2025-04-14', 'gemini-2.5-flash', 'gemini-3-flash-preview', 'gemini-2.5-flash-lite' ) as $id ) {
			$this->assertSame( 'recommended', Models::tier( $id ), $id );
		}
	}

	public function test_larger_models_of_the_same_makers_are_known_but_cost_more() {
		foreach ( array( 'claude-sonnet-5-5', 'eu.anthropic.claude-opus-4-8', 'gpt-5', 'global.openai.gpt-6-sol', 'gemini-2.5-pro' ) as $id ) {
			$this->assertSame( 'known', Models::tier( $id ), $id );
		}
	}

	public function test_everything_else_is_unknown() {
		foreach ( array( 'nvidia.nemotron-nano-12b-v2', 'google.gemma-3-12b-it', 'eu.amazon.nova-lite-v1:0', 'mistral.ministral-3-8b-instruct', 'gemini-2.5-flash-image', 'gpt-4o-mini-tts' ) as $id ) {
			$this->assertSame( 'unknown', Models::tier( $id ), $id );
		}
	}

	public function test_the_default_choice_is_the_newest_small_model_never_the_first_of_the_list() {
		$preference = Models::default_preference( $this->models( self::BEDROCK ), 'eu-west-1' );

		$this->assertSame( array( 'aws-bedrock', 'eu.anthropic.claude-haiku-5-5' ), $preference[0], 'Newest Haiku, in the region of the site.' );
		$this->assertSame( array( 'aws-bedrock', 'global.anthropic.claude-haiku-5-5' ), $preference[1] );
		$this->assertContains( array( 'aws-bedrock', 'eu.anthropic.claude-haiku-4-5-20251001-v1:0' ), $preference );
		$this->assertNotContains( array( 'aws-bedrock', 'nvidia.nemotron-nano-12b-v2' ), $preference );
		$this->assertNotContains( array( 'aws-bedrock', 'eu.anthropic.claude-opus-4-8' ), $preference, 'Never an expensive model by default.' );
	}

	public function test_the_region_of_the_site_decides_among_copies_of_the_same_model() {
		$preference = Models::default_preference( $this->models( self::BEDROCK ), 'us-east-1' );

		$this->assertSame( array( 'aws-bedrock', 'us.anthropic.claude-haiku-5-5' ), $preference[0] );
	}

	public function test_with_several_connectors_claude_goes_first_then_gpt_then_gemini() {
		$preference = Models::default_preference(
			$this->models(
				array(
					array( 'google', 'gemini-2.5-flash' ),
					array( 'openai', 'gpt-5-mini' ),
					array( 'openai', 'gpt-5' ),
					array( 'anthropic', 'claude-haiku-4-5' ),
				)
			),
			''
		);

		$this->assertSame( array( array( 'anthropic', 'claude-haiku-4-5' ), array( 'openai', 'gpt-5-mini' ), array( 'google', 'gemini-2.5-flash' ) ), $preference );
	}

	public function test_no_small_model_available_means_no_default() {
		$this->assertSame( array(), Models::default_preference( $this->models( array( array( 'aws-bedrock', 'nvidia.nemotron-nano-12b-v2' ) ) ), '' ) );
	}

	public function test_the_model_chosen_in_settings_goes_before_the_defaults() {
		set_transient( 'mdia_default_models', array( array( 'anthropic', 'claude-haiku-4-5' ) ), HOUR_IN_SECONDS );
		$this->assertSame( array( array( 'anthropic', 'claude-haiku-4-5' ) ), Core_Provider::model_preference() );

		Settings::update( array( 'model' => 'openai::gpt-5-mini' ) );

		$this->assertSame( array( array( 'openai', 'gpt-5-mini' ), array( 'anthropic', 'claude-haiku-4-5' ) ), Core_Provider::model_preference() );
		Settings::update( array( 'model' => '' ) );
	}

	public function test_a_site_can_still_decide_by_code() {
		set_transient( 'mdia_default_models', array( array( 'anthropic', 'claude-haiku-4-5' ) ), HOUR_IN_SECONDS );
		add_filter( 'mdia_model_preference', static fn( array $models ): array => array_merge( array( 'mi-modelo' ), $models ) );

		$this->assertSame( array( 'mi-modelo', array( 'anthropic', 'claude-haiku-4-5' ) ), Core_Provider::model_preference() );
	}

	public function test_only_a_well_formed_choice_is_stored() {
		Settings::update( array( 'model' => 'aws-bedrock::eu.anthropic.claude-haiku-4-5-20251001-v1:0' ) );
		$this->assertSame( 'aws-bedrock::eu.anthropic.claude-haiku-4-5-20251001-v1:0', Settings::get( 'model' ) );

		Settings::update( array( 'model' => '<script>alert(1)</script>' ) );
		$this->assertSame( '', Settings::get( 'model' ) );
	}

	public function test_the_list_of_models_is_for_managers() {
		$route = new WP_REST_Request( 'GET', '/mdia/v1/models' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $route )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$data = rest_get_server()->dispatch( $route )->get_data();
		$this->assertSame( array(), $data['models'], 'No connector in the test site.' );
		$this->assertSame( '', $data['selected'] );
		$this->assertNull( $data['automatic'] );
	}
}
