import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Icon,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import StatusBadge, { STATE_ICON } from '../components/StatusBadge';
import { getStatus, pauseGeneration, startGeneration } from '../api';

const { canManage } = window.mdiaData;

const STATE_WORD = {
	green: __( 'OK', 'geo-tool' ),
	amber: __( 'Attention', 'geo-tool' ),
	red: __( 'Problem', 'geo-tool' ),
};

function Tile( { label, value, status, onOpenReview } ) {
	return (
		<li className="mdia-tile">
			<button
				type="button"
				className="mdia-tile__button"
				onClick={ () => onOpenReview( status ) }
				aria-label={ sprintf(
					/* translators: 1: number of images, 2: name of the group, e.g. "To review". */
					__( '%1$d images: %2$s. Open the list.', 'geo-tool' ),
					value,
					label
				) }
			>
				<span className="mdia-tile__label">{ label }</span>
				<span className="mdia-tile__value">{ value }</span>
			</button>
		</li>
	);
}

export default function Dashboard( { onOpenReview } ) {
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( '' );

	const load = useCallback(
		() =>
			getStatus()
				.then( ( data ) => {
					setStatus( data );
					setError( '' );
				} )
				.catch( ( e ) => setError( e.message ) ),
		[]
	);

	useEffect( () => {
		load();
	}, [ load ] );

	// While work is in progress, keep the figures fresh.
	useEffect( () => {
		if ( ! status || status.state !== 'amber' ) {
			return undefined;
		}
		const timer = setInterval( load, 15000 );
		return () => clearInterval( timer );
	}, [ status, load ] );

	if ( error && ! status ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! status ) {
		return <Spinner />;
	}

	const { counts, checks, coverage, audit, state } = status;
	const notLaunched = counts.to_generate - counts.queued;
	const budget = checks.find( ( check ) => check.id === 'budget' );
	const connector = checks.find( ( check ) => check.id === 'connector' );
	// After a failed request the person may retry; with no usable connector there is nothing to try.
	const connectorDown =
		connector?.state === 'red' && connector?.code !== 'failed';

	const generate = async () => {
		const question = sprintf(
			/* translators: 1: number of images, 2: sentence with today's AI usage. */
			__(
				'Generate proposals for %1$d images? Each one is a paid request to your AI provider. %2$s Nothing is published until you review it.',
				'geo-tool'
			),
			notLaunched,
			budget ? budget.detail : ''
		);
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( question ) ) {
			return;
		}
		setBusy( true );
		try {
			const queued = await startGeneration();
			setNotice(
				sprintf(
					/* translators: %d: number of images. */
					_n(
						'%d image queued for generation.',
						'%d images queued for generation.',
						queued,
						'geo-tool'
					),
					queued
				)
			);
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( false );
		load();
	};

	const pause = async () => {
		setBusy( true );
		await pauseGeneration().catch( ( e ) => setError( e.message ) );
		setBusy( false );
		load();
	};

	return (
		<div className="mdia-dashboard">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ notice && (
				<Notice status="success" onRemove={ () => setNotice( '' ) }>
					{ notice }
				</Notice>
			) }

			<Card>
				<CardBody>
					<StatusBadge state={ state } />
					<label htmlFor="mdia-coverage" className="mdia-coverage__label">
						{ sprintf(
							/* translators: 1: images with correct alt text, 2: total images, 3: percentage. */
							__(
								'%1$d of %2$d images have correct alt text (%3$d%%)',
								'geo-tool'
							),
							counts.correct,
							counts.total,
							coverage
						) }
					</label>
					<progress
						id="mdia-coverage"
						className="mdia-coverage"
						max="100"
						value={ coverage }
					/>
					{ audit.running && (
						<p className="mdia-muted">
							<Spinner />{ ' ' }
							{ __(
								'Auditing the media library and your content…',
								'geo-tool'
							) }
						</p>
					) }
				</CardBody>
			</Card>

			<ul className="mdia-tiles">
				<Tile
					label={ __( 'To generate', 'geo-tool' ) }
					value={ counts.to_generate }
					status="needs_generation"
					onOpenReview={ onOpenReview }
				/>
				<Tile
					label={ __( 'To review', 'geo-tool' ) }
					value={ counts.to_review }
					status="needs_review"
					onOpenReview={ onOpenReview }
				/>
				<Tile
					label={ __( 'Applied', 'geo-tool' ) }
					value={ counts.applied }
					status="applied"
					onOpenReview={ onOpenReview }
				/>
				<Tile
					label={ __( 'Errors', 'geo-tool' ) }
					value={ counts.errors }
					status="error"
					onOpenReview={ onOpenReview }
				/>
			</ul>

			{ canManage && ( notLaunched > 0 || counts.queued > 0 ) && (
				<Card>
					<CardBody className="mdia-generate">
						{ notLaunched > 0 && (
							<Button
								variant="primary"
								onClick={ generate }
								isBusy={ busy }
								disabled={ busy || connectorDown }
							>
								{ sprintf(
									/* translators: %d: number of images. */
									_n(
										'Generate proposal for %d image',
										'Generate proposals for %d images',
										notLaunched,
										'geo-tool'
									),
									notLaunched
								) }
							</Button>
						) }
						{ notLaunched > 0 && connectorDown && (
							<span className="mdia-muted">
								{ __(
									'Fix the AI connector first.',
									'geo-tool'
								) }
							</span>
						) }
						{ counts.queued > 0 && (
							<>
								<span>
									{ sprintf(
										/* translators: %d: number of images. */
										_n(
											'%d image in the generation queue.',
											'%d images in the generation queue.',
											counts.queued,
											'geo-tool'
										),
										counts.queued
									) }
								</span>
								<Button
									variant="secondary"
									onClick={ pause }
									disabled={ busy }
								>
									{ __( 'Pause', 'geo-tool' ) }
								</Button>
							</>
						) }
					</CardBody>
				</Card>
			) }

			<Card>
				<CardHeader>
					<h2>{ __( 'Checks', 'geo-tool' ) }</h2>
				</CardHeader>
				<CardBody>
					<ul className="mdia-checks">
						{ checks.map( ( check ) => (
							<li
								key={ check.id }
								className={ `mdia-check mdia-state-${ check.state }` }
							>
								<Icon icon={ STATE_ICON[ check.state ] } />
								<span>
									<strong>{ check.label }</strong>
									<span className="screen-reader-text">
										{ ` (${ STATE_WORD[ check.state ] })` }
									</span>
									{ ': ' }
									{ check.detail }
								</span>
							</li>
						) ) }
					</ul>
				</CardBody>
			</Card>

			{ counts.unmanaged_usages > 0 && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: %d: number of uses. */
						_n(
							'%d use of an image is inside a page builder. The plugin updates the media library but cannot rewrite that page.',
							'%d uses of images are inside a page builder. The plugin updates the media library but cannot rewrite those pages.',
							counts.unmanaged_usages,
							'geo-tool'
						),
						counts.unmanaged_usages
					) }
				</Notice>
			) }
		</div>
	);
}
