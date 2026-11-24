<?php
/**
 * Provider backed by the WordPress AI Client. Never touches API keys.
 *
 * @package RevisorAlt
 */

namespace MDIA\AI;

defined( 'ABSPATH' ) || exit;

final class Core_Provider implements Provider {

	private const PROBE_IMAGE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	public function describe( string $data_uri, string $system, string $prompt ) {
		return $this->generate( $this->builder( $system, $prompt, $data_uri ) );
	}

	public function rewrite( string $system, string $prompt ) {
		return $this->generate( $this->builder( $system, $prompt ) );
	}

	/**
	 * @param \WP_AI_Client_Prompt_Builder $builder
	 * @return array{text: string, provider: string, model: string}|\WP_Error
	 */
	private function generate( $builder ) {
		try {

			if ( ! $builder->is_supported_for_text_generation() ) {
				return new \WP_Error( 'mdia_unsupported', __( 'The configured AI provider cannot describe images.', 'revisor-alt' ) );
			}

			$result = $builder->generate_text_result();
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'text'     => (string) $result->toText(),
				'provider' => (string) $result->getProviderMetadata()->getName(),
				'model'    => (string) $result->getModelMetadata()->getId(),
			);
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'mdia_provider_exception', $error->getMessage() );
		}
	}

	public function availability(): array {
		// WordPress knows whether some connected provider can take this request, however that
		// connector authenticates (API key, sign-in, local model...). Ask it first.
		try {
			$supported = (bool) $this->builder( 'probe', 'probe', self::PROBE_IMAGE )->is_supported_for_text_generation();
		} catch ( \Throwable $error ) {
			$supported = false;
		}

		if ( $supported ) {
			return array(
				'available' => true,
				'reason'    => '',
			);
		}

		return array(
			'available' => false,
			'reason'    => self::has_configured_connector() ? 'unsupported' : 'no_connector',
		);
	}

	/**
	 * Whether any AI connector has a credential source set. Only checks presence:
	 * the key itself is never returned, stored or logged.
	 */
	public static function has_configured_connector(): bool {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return false;
		}

		foreach ( wp_get_connectors() as $connector ) {
			if ( 'ai_provider' !== ( $connector['type'] ?? '' ) ) {
				continue;
			}
			$auth = $connector['authentication'] ?? array();
			if ( 'none' === ( $auth['method'] ?? '' ) ) {
				return true;
			}

			$env = (string) ( $auth['env_var_name'] ?? '' );
			if ( '' !== $env && ! in_array( getenv( $env ), array( false, '' ), true ) ) {
				return true;
			}
			$constant = (string) ( $auth['constant_name'] ?? '' );
			if ( '' !== $constant && defined( $constant ) && '' !== (string) constant( $constant ) ) {
				return true;
			}
			$setting = (string) ( $auth['setting_name'] ?? '' );
			if ( '' !== $setting && '' !== (string) get_option( $setting, '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Models the site prefers, in order. Empty lets WordPress choose among the connected providers.
	 *
	 * @return array<int, string|array{0:string,1:string}> Model ids or [provider id, model id] pairs.
	 */
	public static function model_preference(): array {
		/**
		 * Filters the models used to describe images, in order of preference.
		 *
		 * Entries are model ids ('claude-haiku-5-5') or [provider id, model id] pairs.
		 * A preferred model that is not available is skipped.
		 *
		 * @param array $models Preferred models. Default none.
		 */
		$models = apply_filters( 'mdia_model_preference', array() );
		if ( ! is_array( $models ) ) {
			return array();
		}

		$valid = array();
		foreach ( $models as $model ) {
			if ( is_string( $model ) && '' !== $model ) {
				$valid[] = $model;
			} elseif ( is_array( $model ) && 2 === count( $model ) && array_is_list( $model ) && is_string( $model[0] ) && is_string( $model[1] ) && '' !== $model[0] && '' !== $model[1] ) {
				$valid[] = $model;
			}
		}
		return $valid;
	}

	private function builder( string $system, string $prompt, string $data_uri = '' ) {
		$builder = wp_ai_client_prompt()
			->using_system_instruction( $system )
			->with_text( $prompt )
			->using_max_tokens( 200 );

		if ( '' !== $data_uri ) {
			$builder = $builder->with_file( $data_uri );
		}

		$preference = self::model_preference();

		return $preference ? $builder->using_model_preference( ...$preference ) : $builder;
	}
}
