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
import { AREA_LABELS, SEVERITY_LABELS } from '../components/labels';
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

function Area( { area, data, onOpen } ) {
	const { state, pending, total } = data;
	// Share of the area that needs nothing; unknown totals show only their state.
	let done = pending > 0 ? 0 : 100;
	if ( total ) {
		done = Math.round( ( 100 * Math.max( 0, total - pending ) ) / total );
	}
	return (
		<li className={ `mdia-area mdia-area--${ state }` }>
			<button
				type="button"
				className="mdia-area__button"
				onClick={ () => onOpen( area ) }
			>
				<span className="mdia-area__name">{ AREA_LABELS[ area ] }</span>
				<span className="mdia-area__meter" aria-hidden="true">
					<span style={ { width: `${ done }%` } } />
				</span>
				<span className={ `mdia-area__state mdia-state-${ state }` }>
					<Icon icon={ STATE_ICON[ state ] } size={ 18 } />
					{ STATE_WORD[ state ] }
				</span>
				<span className="mdia-area__count">
					{ total === null
						? sprintf(
								/* translators: %d: number of items with something to fix. */
								_n( '%d pending', '%d pending', pending, 'geo-tool' ),
								pending
						  )
						: sprintf(
								/* translators: 1: number of items with something to fix, 2: total. */
								__( '%1$d pending of %2$d', 'geo-tool' ),
								pending,
								total
						  ) }
				</span>
				<span className="screen-reader-text">
					{ __( 'Open the list.', 'geo-tool' ) }
				</span>
			</button>
		</li>
	);
}

function TodoGroup( { severity, items, onOpen } ) {
	if ( ! items.length ) {
		return null;
	}
	return (
		<section className={ `mdia-todo mdia-todo--${ severity }` }>
			<h3 className="mdia-todo__title">{ SEVERITY_LABELS[ severity ] }</h3>
			<ul className="mdia-todo__list">
				{ items.map( ( item ) => (
					<li key={ item.check_id }>
						<button
							type="button"
							className="mdia-todo__item"
							onClick={ () => onOpen( item.area, item.check_id ) }
						>
							<span className="mdia-todo__label">{ item.label }</span>
							{ item.to_review > 0 && (
								<span className="mdia-todo__review">
									{ sprintf(
										/* translators: %d: number of proposals. */
										_n(
											'%d proposal to review',
											'%d proposals to review',
											item.to_review,
											'geo-tool'
										),
										item.to_review
									) }
								</span>
							) }
							<span className="mdia-todo__count">
								{ item.area === 'site' ? (
									AREA_LABELS.site
								) : (
									<>
										<span aria-hidden="true">{ item.count }</span>
										<span className="screen-reader-text">
											{ sprintf(
												/* translators: %d: number of pages or items. */
												_n(
													'%d item',
													'%d items',
													item.count,
													'geo-tool'
												),
												item.count
											) }
										</span>
									</>
								) }
							</span>
						</button>
					</li>
				) ) }
			</ul>
		</section>
	);
}

export default function Dashboard( { onOpenReview, onOpenArea } ) {
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

	const { counts, checks, coverage, audit, state, areas, todo, exceptions } =
		status;
	const notLaunched = counts.to_generate - counts.queued;
	const left = ( severity ) =>
		todo
			.filter( ( item ) => item.severity === severity )
			.reduce( ( sum, item ) => sum + item.count, 0 );
	const required = left( 'required' );
	const imagesLeft = areas.images.pending;
	const recommended = left( 'recommended' );
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

			<section className={ `mdia-hero mdia-hero--${ state }` }>
				<div className="mdia-hero__text">
					<StatusBadge state={ state } />
					<p className="mdia-hero__summary">
						{ required + recommended + imagesLeft === 0
							? __(
									'Nothing is pending: the site is ready to deliver.',
									'geo-tool'
							  )
							: [
									required > 0 &&
										sprintf(
											/* translators: %d: number of things to fix. */
											_n(
												'%d required item left before delivery.',
												'%d required items left before delivery.',
												required,
												'geo-tool'
											),
											required
										),
									recommended > 0 &&
										sprintf(
											/* translators: %d: number of things to fix. */
											_n(
												'%d recommended item left.',
												'%d recommended items left.',
												recommended,
												'geo-tool'
											),
											recommended
										),
									imagesLeft > 0 &&
										sprintf(
											/* translators: %d: number of images. */
											_n(
												'%d image without a correct alt text.',
												'%d images without a correct alt text.',
												imagesLeft,
												'geo-tool'
											),
											imagesLeft
										),
							  ]
									.filter( Boolean )
									.join( ' ' ) }
					</p>
					{ ! status.seo_plugin && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'No SEO plugin is active. Meta descriptions, SEO titles and most site checks need Yoast SEO or Rank Math; until one is active they are not checked.',
								'geo-tool'
							) }
						</Notice>
					) }
					{ exceptions > 0 && (
						<p className="mdia-muted">
							{ sprintf(
								/* translators: %d: number of exceptions. */
								_n(
									'%d required check was accepted as it is by an administrator.',
									'%d required checks were accepted as they are by an administrator.',
									exceptions,
									'geo-tool'
								),
								exceptions
							) }
						</p>
					) }
				</div>
				<ul className="mdia-areas">
					{ Object.entries( areas ).map( ( [ area, data ] ) => (
						<Area
							key={ area }
							area={ area }
							data={ data }
							onOpen={ onOpenArea }
						/>
					) ) }
				</ul>
			</section>

			<div className="mdia-columns">
				<div className="mdia-columns__main">
					<Card>
						<CardHeader>
							<h2>{ __( 'What is left to do', 'geo-tool' ) }</h2>
						</CardHeader>
						<CardBody>
							{ todo.length === 0 ? (
								<p className="mdia-empty">
									<Icon icon="yes-alt" size={ 24 } />
									{ __(
										'No page, category, author or site check is pending.',
										'geo-tool'
									) }
								</p>
							) : (
								<>
									<TodoGroup
										severity="required"
										items={ todo.filter(
											( item ) => item.severity === 'required'
										) }
										onOpen={ onOpenArea }
									/>
									<TodoGroup
										severity="recommended"
										items={ todo.filter(
											( item ) => item.severity === 'recommended'
										) }
										onOpen={ onOpenArea }
									/>
								</>
							) }
						</CardBody>
					</Card>
				</div>
				<div className="mdia-columns__side">
			<Card>
				<CardHeader>
					<h2>{ AREA_LABELS.images }</h2>
				</CardHeader>
				<CardBody>
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
					<h2>{ __( 'System', 'geo-tool' ) }</h2>
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

				</div>
			</div>

			{ counts.unused > 0 && (
				<p className="mdia-muted">
					{ sprintf(
						/* translators: %d: number of images. */
						_n(
							'%d image in the media library is not shown on any published page. It is not counted.',
							'%d images in the media library are not shown on any published page. They are not counted.',
							counts.unused,
							'geo-tool'
						),
						counts.unused
					) }{ ' ' }
					<Button variant="link" onClick={ () => onOpenReview( 'unused' ) }>
						{ __( 'See them', 'geo-tool' ) }
					</Button>
				</p>
			) }

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
