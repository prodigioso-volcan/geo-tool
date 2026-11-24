import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import Preferences from '../components/Preferences';
import {
	getSettings,
	getStatus,
	saveSettings,
	startAudit,
	startGeneration,
	wizardRequirements,
	wizardTest,
} from '../api';

const STEPS = [
	__( 'Requirements', 'geo-tool' ),
	__( 'Test', 'geo-tool' ),
	__( 'Preferences', 'geo-tool' ),
	__( 'First audit', 'geo-tool' ),
	__( 'Done', 'geo-tool' ),
];

function Requirements( { onNext } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );

	const check = useCallback( () => {
		setData( null );
		wizardRequirements()
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	useEffect( check, [ check ] );

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

	const ready = data.wp_ok && data.provider.available;

	return (
		<>
			{ ready ? (
				<Notice status="success" isDismissible={ false }>
					{ __(
						'An AI provider that can describe images is connected.',
						'geo-tool'
					) }
				</Notice>
			) : (
				<>
					<Notice status="warning" isDismissible={ false }>
						{ data.provider.reason === 'unsupported'
							? __(
									'The connected AI provider cannot describe images. Connect one that accepts image input.',
									'geo-tool'
							  )
							: __(
									'No AI provider is connected yet.',
									'geo-tool'
							  ) }
					</Notice>
					<p>
						{ __(
							'To write alt text, the plugin needs an AI provider: OpenAI (ChatGPT), Anthropic (Claude) or Google (Gemini). You connect it with an API key, a code you create in your account with that provider.',
							'geo-tool'
						) }
					</p>
					<p>
						{ __(
							'An API key is billed by use and is separate from a ChatGPT, Claude or Gemini subscription. Describing an image usually costs a fraction of a cent. Other connectors exist, including one that uses a ChatGPT plan without a key.',
							'geo-tool'
						) }
					</p>
				</>
			) }
			{ ! data.seo.active && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'No SEO plugin is active. Image alt text works without one, but meta descriptions, SEO titles and most site checks need Yoast SEO or Rank Math.',
						'geo-tool'
					) }{ ' ' }
					<a href={ data.seo.install_url } target="_blank" rel="noreferrer">
						{ __( 'Install one (new tab)', 'geo-tool' ) }
					</a>
				</Notice>
			) }
			<div className="mdia-wizard__actions">
				{ ! ready && (
					<>
						<Button
							variant="primary"
							href={ data.connectors_url }
							target="_blank"
							rel="noreferrer"
						>
							{ __( 'Open Connectors (new tab)', 'geo-tool' ) }
						</Button>
						<Button variant="secondary" onClick={ check }>
							{ __( 'Check again', 'geo-tool' ) }
						</Button>
					</>
				) }
				{ ready && (
					<Button variant="primary" onClick={ () => onNext( data ) }>
						{ __( 'Continue', 'geo-tool' ) }
					</Button>
				) }
			</div>
		</>
	);
}

function Test( { hasImages, onNext } ) {
	// Nothing is sent until the person asks for it, after reading what will be sent.
	const [ state, setState ] = useState( {} );

	const run = useCallback( () => {
		setState( { loading: true } );
		wizardTest()
			.then( ( result ) => setState( { result } ) )
			.catch( ( e ) => setState( { error: e.message } ) );
	}, [] );

	const idle = hasImages && ! state.loading && ! state.result && ! state.error;

	return (
		<>
			{ idle && (
				<>
					<p>
						{ __(
							'The plugin will ask the AI to describe one image from your media library, to check that everything works.',
							'geo-tool'
						) }
					</p>
					<p className="mdia-muted">
						{ __(
							'Privacy: to describe an image, the plugin sends a reduced copy of it to the AI provider you connected. Nothing is sent to anyone else.',
							'geo-tool'
						) }
					</p>
				</>
			) }
			{ ! hasImages && (
				<p>
					{ __(
						'The media library has no images yet, so there is nothing to test with. You can continue.',
						'geo-tool'
					) }
				</p>
			) }
			{ state.loading && (
				<p>
					<Spinner />{ ' ' }
					{ __( 'Asking the AI to describe one of your images…', 'geo-tool' ) }
				</p>
			) }
			{ state.error && (
				<Notice status="error" isDismissible={ false }>
					{ state.error }
				</Notice>
			) }
			{ state.result && (
				<div className="mdia-wizard__sample">
					<img src={ state.result.thumbnail_url } alt="" />
					<div>
						<p>
							<strong>
								{ __( 'Generated alt text:', 'geo-tool' ) }
							</strong>
						</p>
						<p>{ state.result.text }</p>
						<p className="mdia-muted">
							{ `${ state.result.provider } · ${ state.result.model }` }
						</p>
						<p className="mdia-muted">
							{ __(
								'This was only a test: nothing has been saved.',
								'geo-tool'
							) }
						</p>
					</div>
				</div>
			) }
			<div className="mdia-wizard__actions">
				{ state.error && (
					<Button variant="secondary" onClick={ run }>
						{ __( 'Try again', 'geo-tool' ) }
					</Button>
				) }
				{ idle && (
					<Button variant="primary" onClick={ run }>
						{ __( 'Run the test', 'geo-tool' ) }
					</Button>
				) }
				{ ! idle && (
					<Button
						variant="primary"
						disabled={ !! state.loading || !! state.error }
						onClick={ onNext }
					>
						{ __( 'Continue', 'geo-tool' ) }
					</Button>
				) }
			</div>
		</>
	);
}

function PreferencesStep( { onNext } ) {
	const [ values, setValues ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		getSettings()
			.then( setValues )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	if ( ! values ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}

	return (
		<>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<Preferences
				values={ values }
				onChange={ ( changed ) => setValues( { ...values, ...changed } ) }
			/>
			<div className="mdia-wizard__actions">
				<Button
					variant="primary"
					onClick={ () =>
						saveSettings( {
							daily_limit: values.daily_limit,
							auto_generate_new: values.auto_generate_new,
							text_consent: !! values.text_consent,
						} )
							.then( ( saved ) => {
								window.mdiaData.textConsent = !! saved.text_consent;
								onNext( saved );
							} )
							.catch( ( e ) => setError( e.message ) )
					}
				>
					{ __( 'Save and continue', 'geo-tool' ) }
				</Button>
			</div>
		</>
	);
}

function Audit( { onNext } ) {
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( '' );
	const started = useRef( false );

	useEffect( () => {
		let timer;
		const poll = () =>
			getStatus()
				.then( ( data ) => {
					setStatus( data );
					if ( data.audit.running ) {
						timer = setTimeout( poll, 3000 );
					}
				} )
				.catch( ( e ) => setError( e.message ) );

		if ( ! started.current ) {
			started.current = true;
			startAudit()
				.then( poll )
				.catch( ( e ) => setError( e.message ) );
		}
		return () => clearTimeout( timer );
	}, [] );

	const running = ! status || status.audit.running;
	const pending = status ? status.counts.total - status.counts.correct : 0;

	return (
		<>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ running && ! error && (
				<p role="status">
					<Spinner />{ ' ' }
					{ __(
						'Checking every image in the library and in your pages. This uses no AI and costs nothing.',
						'geo-tool'
					) }
				</p>
			) }
			{ ! running && (
				<p role="status">
					<strong>
						{ sprintf(
							/* translators: 1: images that need alt text, 2: total images. */
							__(
								'%1$d of %2$d images need alt text.',
								'geo-tool'
							),
							pending,
							status.counts.total
						) }
					</strong>
				</p>
			) }
			<div className="mdia-wizard__actions">
				<Button
					variant="primary"
					disabled={ running }
					onClick={ () => onNext( status ) }
				>
					{ __( 'Continue', 'geo-tool' ) }
				</Button>
			</div>
		</>
	);
}

function Done( { status, onDone } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const toGenerate = status ? status.counts.to_generate - status.counts.queued : 0;

	useEffect( () => {
		saveSettings( { wizard_done: true } ).catch( ( e ) =>
			setError( e.message )
		);
		window.mdiaData.wizardDone = true;
	}, [] );

	return (
		<>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<p>
				{ toGenerate > 0
					? __(
							'Everything is ready. You can ask the AI for proposals now; you will review each one before anything changes on your site.',
							'geo-tool'
					  )
					: __(
							'Everything is ready, and no image needs alt text right now.',
							'geo-tool'
					  ) }
			</p>
			<div className="mdia-wizard__actions">
				{ toGenerate > 0 && (
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ async () => {
							setBusy( true );
							try {
								await startGeneration();
								onDone( 'dashboard' );
							} catch ( e ) {
								setError( e.message );
								setBusy( false );
							}
						} }
					>
						{ sprintf(
							/* translators: %d: number of images. */
							_n(
								'Generate proposal for %d image',
								'Generate proposals for %d images',
								toGenerate,
								'geo-tool'
							),
							toGenerate
						) }
					</Button>
				) }
				<Button
					variant={ toGenerate > 0 ? 'secondary' : 'primary' }
					disabled={ busy }
					onClick={ () => onDone( 'dashboard' ) }
				>
					{ __( 'Go to dashboard', 'geo-tool' ) }
				</Button>
			</div>
		</>
	);
}

export default function Wizard( { onDone } ) {
	const [ step, setStep ] = useState( 0 );
	const [ requirements, setRequirements ] = useState( null );
	const [ status, setStatus ] = useState( null );
	const heading = useRef();

	// Move focus to the new step so screen reader and keyboard users follow along.
	useEffect( () => {
		if ( step > 0 ) {
			heading.current?.focus();
		}
	}, [ step ] );

	return (
		<div className="mdia-wizard">
			<ol className="mdia-wizard__steps">
				{ STEPS.map( ( label, index ) => (
					<li
						key={ label }
						aria-current={ index === step ? 'step' : undefined }
					>
						{ `${ index + 1 }. ${ label }` }
					</li>
				) ) }
			</ol>
			<Card>
				<CardBody>
					<h2 tabIndex="-1" ref={ heading }>
						{ sprintf(
							/* translators: 1: step number, 2: total steps, 3: step name. */
							__( 'Step %1$d of %2$d: %3$s', 'geo-tool' ),
							step + 1,
							STEPS.length,
							STEPS[ step ]
						) }
					</h2>
					{ step === 0 && (
						<Requirements
							onNext={ ( data ) => {
								setRequirements( data );
								setStep( 1 );
							} }
						/>
					) }
					{ step === 1 && (
						<Test
							hasImages={ requirements.image_count > 0 }
							onNext={ () => setStep( 2 ) }
						/>
					) }
					{ step === 2 && <PreferencesStep onNext={ () => setStep( 3 ) } /> }
					{ step === 3 && (
						<Audit
							onNext={ ( data ) => {
								setStatus( data );
								setStep( 4 );
							} }
						/>
					) }
					{ step === 4 && <Done status={ status } onDone={ onDone } /> }
				</CardBody>
			</Card>
		</div>
	);
}
