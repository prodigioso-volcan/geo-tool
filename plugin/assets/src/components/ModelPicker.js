import { useEffect, useState } from '@wordpress/element';
import { Notice, SelectControl, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { getModels } from '../api';

const GROUPS = [
	[ 'recommended', __( 'Recommended: small, cheap and reliable', 'geo-tool' ) ],
	[ 'known', __( 'Larger models: they work and cost more', 'geo-tool' ) ],
	[ 'unknown', __( 'Not checked with this plugin', 'geo-tool' ) ],
];

/**
 * Lets a manager choose which model writes the texts, among the ones the connector offers.
 *
 * @param {Object}   props
 * @param {string}   props.value    Chosen model as "provider::model", or '' for automatic.
 * @param {Function} props.onChange Receives the new choice.
 */
export default function ModelPicker( { value, onChange } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		getModels()
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! data ) {
		return <Spinner />;
	}
	if ( ! data.models.length ) {
		return (
			<p className="mdia-muted">
				{ __(
					'No connected AI provider offers a model that can describe images.',
					'geo-tool'
				) }
			</p>
		);
	}

	const key = ( model ) => `${ model.provider }::${ model.id }`;
	const several =
		new Set( data.models.map( ( model ) => model.provider ) ).size > 1;
	// The same model can be listed once per region: the id tells them apart.
	const label = ( model ) =>
		`${ model.name } (${ model.id })` +
		( several ? ` · ${ model.provider_name }` : '' );
	const chosen = data.models.find( ( model ) => key( model ) === value );

	return (
		<>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Model that writes the texts', 'geo-tool' ) }
				help={ __(
					'Left on automatic, the plugin uses the newest small model of the connected provider. Larger models cost more per text and rarely write better alt text or meta descriptions.',
					'geo-tool'
				) }
				value={ chosen ? value : '' }
				onChange={ onChange }
			>
				<option value="">
					{ data.automatic
						? sprintf(
								/* translators: %s: name of an AI model. */
								__( 'Automatic: %s', 'geo-tool' ),
								data.automatic.name
						  )
						: __( 'Automatic', 'geo-tool' ) }
				</option>
				{ GROUPS.map( ( [ tier, title ] ) => {
					const models = data.models.filter(
						( model ) => model.tier === tier
					);
					return models.length ? (
						<optgroup key={ tier } label={ title }>
							{ models.map( ( model ) => (
								<option key={ key( model ) } value={ key( model ) }>
									{ label( model ) }
								</option>
							) ) }
						</optgroup>
					) : null;
				} ) }
			</SelectControl>
			{ ! chosen && ! data.automatic && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The connected provider offers none of the recommended models (Claude Haiku, GPT mini, Gemini Flash). On automatic, WordPress uses the first model of its list, which may describe images badly. Choose one here and check the result.',
						'geo-tool'
					) }
				</Notice>
			) }
			{ chosen && chosen.tier === 'unknown' && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This model has not been checked with the plugin. Some small models invent what is in a picture: review a few proposals before approving many.',
						'geo-tool'
					) }
				</Notice>
			) }
		</>
	);
}
