<?php
/**
 * Instructions sent to the model.
 *
 * @package MetaDescriptionsIA
 */

namespace MDIA\AI;

defined( 'ABSPATH' ) || exit;

final class Prompt {

	private const LANGUAGES = array(
		'es' => 'Spanish',
		'ca' => 'Catalan',
		'gl' => 'Galician',
		'eu' => 'Basque',
		'en' => 'English',
		'pt' => 'Portuguese',
		'fr' => 'French',
		'it' => 'Italian',
		'de' => 'German',
	);

	public static function system( string $locale ): string {
		$code     = strtolower( substr( $locale, 0, 2 ) );
		$language = self::LANGUAGES[ $code ] ?? sprintf( 'the language with locale code %s', $locale );

		$system = implode(
			"\n",
			array(
				'You write alt text for images on a website so that people using screen readers know what the image shows.',
				sprintf( 'Write in %s.', $language ),
				sprintf( 'At most %1$d words. It must never exceed %2$d characters: longer text is cut off. One sentence. No quotation marks around it.', self::MAX_WORDS, Response_Sanitizer::MAX_LENGTH ),
				'Say only the essential. Leave out secondary details rather than going over the limit.',
				'Describe what is visible, concretely: who or what, doing what, where. Mention readable text in the image if it matters.',
				'Do not start with "Image of", "Photo of", "Imagen de", "Foto de" or similar.',
				'Do not invent names of people, places or brands that are not visible or given in the context.',
				'The context (file name, current text, page title) may help you identify the subject; ignore it if it does not match what you see.',
				'Answer with the alt text only.',
			)
		);

		/**
		 * Filters the system instruction sent to the AI provider.
		 *
		 * @param string $system System instruction.
		 * @param string $locale Site locale.
		 */
		return (string) apply_filters( 'mdia_system_prompt', $system, $locale );
	}

	/**
	 * Limits are given to the model in words: models count words far better than characters.
	 * Fifteen words stay under the 125-character maximum in Spanish and English.
	 */
	public const MAX_WORDS = 15;

	/**
	 * Stricter limit when a first answer came back too long.
	 */
	public const SHORTEN_WORDS = 12;

	public static function shorten_system(): string {
		return implode(
			"\n",
			array(
				'You are given the alt text of an image. It is too long.',
				sprintf( 'Rewrite it in at most %d words, in the same language.', self::SHORTEN_WORDS ),
				'Keep what matters most to understand the image and drop secondary details. Do not add anything.',
				'It must be one complete sentence, not a cut one. No quotation marks around it.',
				'Answer with the alt text only.',
			)
		);
	}

	/**
	 * @param array{filename?:string,title?:string,current_alt?:string,post_title?:string} $context
	 */
	public static function user( array $context ): string {
		$labels = array(
			'filename'    => 'File name',
			'title'       => 'Title in the media library',
			'current_alt' => 'Current alt text (considered poor)',
			'post_title'  => 'Used on a page titled',
		);

		$lines = array( 'Write the alt text for this image.' );
		foreach ( $labels as $key => $label ) {
			$value = trim( (string) ( $context[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$lines[] = $label . ': ' . mb_substr( $value, 0, 200 );
			}
		}
		return implode( "\n", $lines );
	}
}
