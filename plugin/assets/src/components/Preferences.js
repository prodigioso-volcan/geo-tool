import { TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * The preferences shared by the settings tab and the setup wizard.
 *
 * @param {Object}   props
 * @param {Object}   props.values   daily_limit and auto_generate_new.
 * @param {Function} props.onChange Receives the changed values.
 */
export default function Preferences( { values, onChange } ) {
	return (
		<>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Generate proposals automatically for new uploads',
					'geo-tool'
				) }
				help={ __(
					'Each new image without good alt text gets a proposal for you to review.',
					'geo-tool'
				) }
				checked={ values.auto_generate_new }
				onChange={ ( value ) => onChange( { auto_generate_new: value } ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="number"
				min={ 1 }
				max={ 5000 }
				label={ __( 'Daily limit of AI requests', 'geo-tool' ) }
				help={ __(
					'The plugin stops when it reaches this number and continues the next day. It protects you from unexpected costs.',
					'geo-tool'
				) }
				value={ values.daily_limit }
				onChange={ ( value ) =>
					onChange( { daily_limit: parseInt( value, 10 ) || 1 } )
				}
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Allow the AI to read page text to write meta descriptions and other texts',
					'geo-tool'
				) }
				help={ __(
					'To propose a meta description, an SEO title, an excerpt or a category description, the plugin sends the visible text of that page to the AI provider you connected. Without this permission those texts are only listed for you to write by hand.',
					'geo-tool'
				) }
				checked={ !! values.text_consent }
				onChange={ ( value ) => onChange( { text_consent: value } ) }
			/>
			<p className="mdia-muted">
				{ __(
					'Privacy: to describe an image, the plugin sends a reduced copy of it to the AI provider you connected. Nothing is sent to anyone else.',
					'geo-tool'
				) }
			</p>
		</>
	);
}
