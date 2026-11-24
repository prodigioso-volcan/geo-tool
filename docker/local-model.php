<?php
/**
 * Solo entorno local: hace que el plugin use Claude Haiku para describir imágenes.
 *
 * Sin esto WordPress elige el primer modelo disponible del proveedor conectado, que
 * en Amazon Bedrock puede ser uno mucho más caro. Si el modelo preferido no está
 * disponible, se pasa al siguiente y, si ninguno lo está, WordPress elige.
 */

add_filter(
	'mdia_model_preference',
	static function () {
		return array(
			array( 'aws-bedrock', 'eu.anthropic.claude-haiku-5-5' ),
			array( 'aws-bedrock', 'eu.anthropic.claude-haiku-4-5-20251001-v1:0' ),
			'claude-haiku-5-5',
			'claude-haiku-4-5',
		);
	}
);
