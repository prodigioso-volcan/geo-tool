<?php
/**
 * Which model writes the texts. WordPress, left alone, takes the first model the connector
 * lists, and that can be a tiny one that invents what is in a picture. This class knows which
 * models are a sensible choice and picks one of them unless the site says otherwise.
 *
 * @package GeoTool
 */

namespace MDIA\AI;

defined( 'ABSPATH' ) || exit;

final class Models {

	/** Small, cheap and good at describing images: what the plugin picks by itself. */
	public const RECOMMENDED = 'recommended';

	/** Larger models of the same makers: they work, and cost more. */
	public const KNOWN = 'known';

	/** Anything else. It may work; nobody here has seen it do so. */
	public const UNKNOWN = 'unknown';

	private const CACHE = 'mdia_default_models';

	/**
	 * Families the plugin picks from, in order of preference. Each pattern captures the version.
	 */
	private const FAMILIES = array(
		'/claude-haiku-(\d+)(?:-(\d{1,2}))?(?!\d)/',
		'/claude-(\d+)(?:-(\d{1,2}))?-haiku/',
		'/gpt-(\d+)(?:\.(\d+))?[a-z]?-mini(?![a-z-]*-(?:tts|audio|realtime|transcribe|search))/',
		'/gemini-(\d+)(?:\.(\d+))?-flash(?!-(?:image|tts|live|audio))/',
	);

	/** Variants of known families that do something else than writing about an image. */
	private const NOT_FOR_TEXT = '/-(image|tts|audio|realtime|transcribe|embedding|live|search)(-|$)/';

	public static function tier( string $model_id ): string {
		$id = strtolower( $model_id );
		if ( preg_match( self::NOT_FOR_TEXT, $id ) ) {
			return self::UNKNOWN;
		}
		foreach ( self::FAMILIES as $pattern ) {
			if ( preg_match( $pattern, $id ) ) {
				return self::RECOMMENDED;
			}
		}
		return preg_match( '/(^|[.\/])(claude-|gpt-\d|gemini-\d)/', $id ) ? self::KNOWN : self::UNKNOWN;
	}

	/**
	 * Models of the connected providers that can read an image and write text.
	 *
	 * @return array<int,array{provider: string, provider_name: string, id: string, name: string, tier: string}>
	 */
	public static function available(): array {
		$models = array();
		try {
			$requirements = new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements(
				array( \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration() ),
				array(
					new \WordPress\AiClient\Providers\Models\DTO\RequiredOption(
						\WordPress\AiClient\Providers\Models\Enums\OptionEnum::inputModalities(),
						array(
							\WordPress\AiClient\Messages\Enums\ModalityEnum::text(),
							\WordPress\AiClient\Messages\Enums\ModalityEnum::image(),
						)
					),
				)
			);
			foreach ( \WordPress\AiClient\AiClient::defaultRegistry()->findModelsMetadataForSupport( $requirements ) as $group ) {
				$provider = $group->getProvider();
				foreach ( $group->getModels() as $model ) {
					$models[] = array(
						'provider'      => (string) $provider->getId(),
						'provider_name' => (string) $provider->getName(),
						'id'            => (string) $model->getId(),
						'name'          => (string) $model->getName(),
						'tier'          => self::tier( (string) $model->getId() ),
					);
				}
			}
		} catch ( \Throwable $error ) {
			return array();
		}
		return $models;
	}

	/**
	 * The models to try, best first: the newest small model of each maker, in the region of the
	 * site when the provider has regions. Never a large model, never an unknown one.
	 *
	 * @param array<int,array{provider: string, id: string}> $models Available models.
	 * @param string                                         $region Region of the site, such as "eu-west-1". '' when it has none.
	 * @return array<int,array{0: string, 1: string}> Provider id and model id pairs.
	 */
	public static function default_preference( array $models, string $region ): array {
		$ranked = array();
		foreach ( $models as $model ) {
			$id = strtolower( (string) $model['id'] );
			if ( self::RECOMMENDED !== self::tier( $id ) ) {
				continue;
			}
			foreach ( self::FAMILIES as $index => $pattern ) {
				if ( ! preg_match( $pattern, $id, $match ) ) {
					continue;
				}
				$ranked[] = array(
					// The two ways Anthropic has named Haiku are one family.
					'family'  => max( 0, $index - 1 ),
					'version' => (int) $match[1] * 100 + (int) ( $match[2] ?? 0 ),
					'lite'    => str_contains( $id, '-lite' ) ? 1 : 0,
					'region'  => self::region_rank( $id, $region ),
					'pair'    => array( (string) $model['provider'], (string) $model['id'] ),
				);
				break;
			}
		}

		usort(
			$ranked,
			static fn( array $a, array $b ): int => array( $a['family'], -$a['version'], $a['lite'], $a['region'] ) <=> array( $b['family'], -$b['version'], $b['lite'], $b['region'] )
		);
		return array_column( $ranked, 'pair' );
	}

	/**
	 * What the plugin uses when nobody chose: computed from the connector and remembered for a
	 * while, because asking the provider for its list of models is a request of its own.
	 *
	 * @return array<int,array{0: string, 1: string}>
	 */
	public static function cached_default(): array {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$models     = self::available();
		$preference = self::default_preference( $models, self::region() );
		// An empty list may be a connector that did not answer: asked again soon.
		set_transient( self::CACHE, $preference, $models ? 6 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );

		return $preference;
	}

	public static function forget(): void {
		delete_transient( self::CACHE );
	}

	private static function region(): string {
		$region = defined( 'AWS_BEDROCK_REGION' ) ? (string) AWS_BEDROCK_REGION : (string) getenv( 'AWS_BEDROCK_REGION' );
		return strtolower( trim( $region ) );
	}

	/**
	 * Providers with regions list the same model once per region ("eu.", "us.", "global.").
	 * The one of the region of the site goes first, then the global one.
	 */
	private static function region_rank( string $model_id, string $region ): int {
		if ( ! preg_match( '/^(eu|us|apac|ap|global|au|jp|ca)\./', $model_id, $match ) ) {
			return 2;
		}
		if ( 'global' === $match[1] ) {
			return 1;
		}
		$area = substr( $region, 0, 2 );
		return '' !== $area && str_starts_with( $match[1], $area ) ? 0 : 3;
	}
}
