import { TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * The two preferences shared by the settings tab and the setup wizard.
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
					'meta-descriptions-ia'
				) }
				help={ __(
					'Each new image without good alt text gets a proposal for you to review.',
					'meta-descriptions-ia'
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
				label={ __( 'Daily limit of AI requests', 'meta-descriptions-ia' ) }
				help={ __(
					'The plugin stops when it reaches this number and continues the next day. It protects you from unexpected costs.',
					'meta-descriptions-ia'
				) }
				value={ values.daily_limit }
				onChange={ ( value ) =>
					onChange( { daily_limit: parseInt( value, 10 ) || 1 } )
				}
			/>
			<p className="mdia-muted">
				{ __(
					'Privacy: to describe an image, the plugin sends a reduced copy of it to the AI provider you connected. Nothing is sent to anyone else.',
					'meta-descriptions-ia'
				) }
			</p>
		</>
	);
}
