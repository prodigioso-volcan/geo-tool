<?php
/**
 * What the plugin needs from an AI provider. Lets tests swap the real one.
 *
 * @package MetaDescriptionsIA
 */

namespace MDIA\AI;

interface Provider {

	/**
	 * Asks the model to describe an image.
	 *
	 * @param string $data_uri Image as a base64 data URI.
	 * @param string $system   System instruction.
	 * @param string $prompt   User prompt with the image context.
	 * @return array{text: string, provider: string, model: string}|\WP_Error
	 */
	public function describe( string $data_uri, string $system, string $prompt );

	/**
	 * Asks the model to rewrite a text. No image is sent.
	 *
	 * @param string $system System instruction.
	 * @param string $prompt The text to rewrite.
	 * @return array{text: string, provider: string, model: string}|\WP_Error
	 */
	public function rewrite( string $system, string $prompt );

	/**
	 * Whether a provider that accepts images is configured. Makes no API call.
	 *
	 * @return array{available: bool, reason: string} Reason is '', 'no_connector' or 'unsupported'.
	 */
	public function availability(): array;
}
