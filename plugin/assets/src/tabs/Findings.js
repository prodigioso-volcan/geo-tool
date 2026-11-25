import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import FindingRow from '../components/FindingRow';
import { AREA_LABELS, FINDING_STATUS_LABELS } from '../components/labels';
import {
	approveFindings,
	getFindings,
	getStatus,
	pauseGeneration,
	startTextGeneration,
	waiveAcfField,
} from '../api';

const { canManage, checks } = window.mdiaData;
// Languages of the site when it has more than one (WPML); none otherwise.
const languages = Object.entries( window.mdiaData.languages || {} );

const STATUS_ORDER = [ 'pending', 'proposed', 'open', 'error', 'applied', 'waived' ];

/**
 * List of findings for one or more areas, with filters, bulk approval and AI proposals.
 *
 * @param {Object}   props
 * @param {string[]} props.areas          Areas offered in this tab; the first is the default.
 * @param {Object}   props.filters        area, status and check_id currently chosen.
 * @param {Function} props.onFilterChange Receives the changed filters.
 * @param {Function} props.onOpenSettings Opens the settings tab.
 */
export default function Findings( { areas, filters, onFilterChange, onOpenSettings } ) {
	const area = areas.includes( filters.area ) ? filters.area : areas[ 0 ];
	const status = filters.status ?? 'pending';
	const checkId = filters.check_id || '';
	const withTexts = area !== 'site';
	const withLanguages = withTexts && languages.length > 1;
	// Never "lang" in the address: WPML reads it to switch the language of the admin.
	// And a filter nobody can see must not empty the list.
	const lang = withLanguages ? filters.language || '' : '';

	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ counts, setCounts ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	// Only what the reviewer typed and has not approved yet; everything else is read from the finding.
	const [ texts, setTexts ] = useState( {} );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const announcePage = useRef( false );
	const list = useRef();
	const selectAll = useRef();

	const load = useCallback( () => {
		if ( withTexts ) {
			getStatus()
				.then( ( result ) => setCounts( result.texts ) )
				.catch( () => {} );
		}
		return getFindings( {
			area,
			status,
			check_id: checkId,
			language: lang,
			page,
			per_page: 20,
		} )
			.then( ( result ) => {
				setData( result );
				setError( '' );
				// A proposal that arrives from the queue must show: only typed edits are kept.
				setTexts( ( current ) => {
					const next = {};
					result.items.forEach( ( item ) => {
						if ( item.id in current ) {
							next[ item.id ] = current[ item.id ];
						}
					} );
					return next;
				} );
				if ( announcePage.current ) {
					announcePage.current = false;
					speak(
						sprintf(
							/* translators: 1: current page, 2: total pages. */
							__( 'Page %1$d of %2$d', 'geo-tool' ),
							page,
							result.totalPages
						)
					);
					list.current?.querySelector( '.mdia-row' )?.focus();
				}
			} )
			.catch( ( e ) => setError( e.message ) );
	}, [ area, status, checkId, lang, page, withTexts ] );

	useEffect( () => {
		setData( null );
		setSelected( [] );
		setTexts( {} );
		load();
	}, [ load ] );

	// Proposals arrive from the background queue: refresh without user action.
	useEffect( () => {
		const moving = data && data.items.some( ( item ) => item.queued );
		if ( ! moving ) {
			return undefined;
		}
		const timer = setInterval( load, 8000 );
		return () => clearInterval( timer );
	}, [ data, load ] );

	const filter = ( changed ) => {
		setPage( 1 );
		onFilterChange( {
			area,
			status,
			check_id: checkId,
			language: lang,
			...changed,
		} );
	};

	const onRowChange = ( updated, action ) => {
		// A finding that is solved disappears from the list.
		const gone = updated.status === 'resolved';
		setData( ( current ) => ( {
			...current,
			total: gone ? current.total - 1 : current.total,
			items: gone
				? current.items.filter( ( item ) => item.id !== updated.id )
				: current.items.map( ( item ) =>
						item.id === updated.id ? updated : item
				  ),
		} ) );
		setSelected( ( ids ) => ids.filter( ( id ) => id !== updated.id ) );
		setTexts( ( current ) => {
			const { [ updated.id ]: done, ...rest } = current;
			return rest;
		} );

		// The button just used may be gone: never leave keyboard focus nowhere.
		const row = list.current?.querySelector( `[data-id="${ updated.id }"]` );
		const next =
			( action === 'approve' || gone ) && row?.nextElementSibling;
		const field = next && next.querySelector( 'textarea, button, a' );
		( field || ( gone ? list.current : row ) )?.focus();
	};

	const changePage = ( target ) => {
		announcePage.current = true;
		setPage( target );
	};

	const approveSelected = async () => {
		setBusy( true );
		try {
			const result = await approveFindings(
				selected.map( ( id ) => ( {
					id,
					text:
						texts[ id ] ??
						data.items.find( ( item ) => item.id === id )
							?.proposed_value ??
						'',
				} ) )
			);
			speak(
				sprintf(
					/* translators: %d: number of texts. */
					_n( '%d text approved.', '%d texts approved.', result.done, 'geo-tool' ),
					result.done
				)
			);
			if ( result.failed ) {
				setError(
					sprintf(
						/* translators: %d: number of texts. */
						_n(
							'%d text could not be approved.',
							'%d texts could not be approved.',
							result.failed,
							'geo-tool'
						),
						result.failed
					)
				);
			}
		} catch ( e ) {
			setError( e.message );
		}
		setSelected( [] );
		setBusy( false );
		load();
	};

	const notLaunched = counts ? counts.to_generate - counts.queued : 0;

	const generate = async () => {
		const question = sprintf(
			/* translators: %d: number of texts. */
			__(
				'Generate proposals for %d texts? Each one is a paid request to your AI provider, which receives the visible text of the page. Nothing is published until you review it.',
				'geo-tool'
			),
			notLaunched
		);
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( question ) ) {
			return;
		}
		setBusy( true );
		try {
			const queued = await startTextGeneration();
			setNotice(
				sprintf(
					/* translators: %d: number of texts. */
					_n(
						'%d text queued for generation.',
						'%d texts queued for generation.',
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

	const waiveField = async ( name ) => {
		const question = sprintf(
			/* translators: %s: internal name of a custom field. */
			__(
				'Stop reporting the field "%s" when it is empty, on every page?',
				'geo-tool'
			),
			name
		);
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( question ) ) {
			return;
		}
		await waiveAcfField( name ).catch( ( e ) => setError( e.message ) );
		load();
	};

	const selectable = data
		? data.items
				.filter( ( item ) => item.status === 'proposed' && item.can_edit )
				.map( ( item ) => item.id )
		: [];
	const allSelected =
		selectable.length > 0 && selected.length === selectable.length;

	useEffect( () => {
		if ( selectAll.current ) {
			selectAll.current.indeterminate =
				selected.length > 0 && ! allSelected;
		}
	}, [ selected, allSelected, data ] );

	const areaChecks = checks.filter( ( check ) => check.area === area );

	return (
		<div className="mdia-review">
			{ withTexts &&
				canManage &&
				counts &&
				( notLaunched > 0 || counts.queued > 0 ) && (
					<Card>
						<CardBody className="mdia-generate">
							{ notLaunched > 0 && window.mdiaData.textConsent && (
								<Button
									variant="primary"
									onClick={ generate }
									isBusy={ busy }
									disabled={ busy }
								>
									{ sprintf(
										/* translators: %d: number of texts. */
										_n(
											'Propose %d text with AI',
											'Propose %d texts with AI',
											notLaunched,
											'geo-tool'
										),
										notLaunched
									) }
								</Button>
							) }
							{ notLaunched > 0 && ! window.mdiaData.textConsent && (
								<>
									<span>
										{ sprintf(
											/* translators: %d: number of texts. */
											_n(
												'%d text is waiting to be written. The AI can propose it if you allow it to read the page text.',
												'%d texts are waiting to be written. The AI can propose them if you allow it to read the page text.',
												notLaunched,
												'geo-tool'
											),
											notLaunched
										) }
									</span>
									<Button variant="secondary" onClick={ onOpenSettings }>
										{ __( 'Open settings', 'geo-tool' ) }
									</Button>
								</>
							) }
							{ counts.queued > 0 && (
								<>
									<span>
										{ sprintf(
											/* translators: %d: number of texts. */
											_n(
												'%d text in the generation queue.',
												'%d texts in the generation queue.',
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

			<div className="mdia-filters">
				{ areas.length > 1 && (
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Where', 'geo-tool' ) }
						value={ area }
						options={ areas.map( ( value ) => ( {
							value,
							label: AREA_LABELS[ value ],
						} ) ) }
						onChange={ ( value ) =>
							filter( { area: value, check_id: '' } )
						}
					/>
				) }
				<fieldset className="mdia-chips">
					<legend>{ __( 'Status', 'geo-tool' ) }</legend>
					{ [
						...STATUS_ORDER.map( ( value ) => ( {
							value,
							label: FINDING_STATUS_LABELS[ value ],
						} ) ),
						{ value: '', label: __( 'All', 'geo-tool' ) },
					].map( ( option ) => (
						<label key={ option.value || 'all' }>
							<input
								type="radio"
								name="mdia-finding-status"
								value={ option.value }
								checked={ status === option.value }
								onChange={ () => filter( { status: option.value } ) }
							/>
							<span>{ option.label }</span>
						</label>
					) ) }
				</fieldset>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Check', 'geo-tool' ) }
					value={ checkId }
					options={ [
						{ value: '', label: __( 'Any', 'geo-tool' ) },
						...areaChecks.map( ( check ) => ( {
							value: check.id,
							label: check.label,
						} ) ),
					] }
					onChange={ ( value ) => filter( { check_id: value } ) }
				/>
				{ withLanguages && (
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Language', 'geo-tool' ) }
						value={ lang }
						options={ [
							{ value: '', label: __( 'All', 'geo-tool' ) },
							...languages.map( ( [ value, label ] ) => ( {
								value,
								label,
							} ) ),
						] }
						onChange={ ( value ) => filter( { language: value } ) }
					/>
				) }
			</div>

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

			{ ! data && <Spinner /> }

			{ data && data.items.length === 0 && (
				<p>
					{ status === 'pending'
						? __( 'Nothing pending here.', 'geo-tool' )
						: __( 'Nothing here.', 'geo-tool' ) }
				</p>
			) }

			{ data && data.items.length > 0 && (
				<>
					<div className="mdia-bulk">
						{ selectable.length > 0 && (
							<label className="mdia-bulk__all">
								<input
									type="checkbox"
									ref={ selectAll }
									checked={ allSelected }
									onChange={ () =>
										setSelected( allSelected ? [] : selectable )
									}
								/>
								{ __( 'Select all on this page', 'geo-tool' ) }
							</label>
						) }
						<span className="mdia-bulk__count" aria-live="polite">
							{ sprintf(
								/* translators: %d: number of items. */
								_n( '%d item', '%d items', data.total, 'geo-tool' ),
								data.total
							) }
							{ selected.length > 0 &&
								' · ' +
									sprintf(
										/* translators: %d: number of selected items. */
										_n(
											'%d selected',
											'%d selected',
											selected.length,
											'geo-tool'
										),
										selected.length
									) }
						</span>
						{ selectable.length > 0 && (
							<Button
								className="mdia-bulk__approve"
								variant="primary"
								disabled={ ! selected.length || busy }
								isBusy={ busy }
								onClick={ approveSelected }
							>
								{ selected.length
									? sprintf(
											/* translators: %d: number of selected items. */
											__( 'Approve selected (%d)', 'geo-tool' ),
											selected.length
									  )
									: __( 'Approve selected', 'geo-tool' ) }
							</Button>
						) }
					</div>

					<ul className="mdia-rows" ref={ list } tabIndex="-1">
						{ data.items.map( ( item ) => (
							<FindingRow
								key={ item.id }
								item={ item }
								text={ texts[ item.id ] ?? item.proposed_value }
								onTextChange={ ( value ) =>
									setTexts( ( current ) => ( {
										...current,
										[ item.id ]: value,
									} ) )
								}
								selected={ selected.includes( item.id ) }
								onSelect={
									withTexts
										? ( checked ) =>
												setSelected( ( ids ) =>
													checked
														? [ ...ids, item.id ]
														: ids.filter(
																( id ) => id !== item.id
														  )
												)
										: undefined
								}
								onChange={ onRowChange }
								onWaiveField={ canManage ? waiveField : undefined }
							/>
						) ) }
					</ul>

					{ data.totalPages > 1 && (
						<nav
							className="mdia-pagination"
							aria-label={ __( 'Pages', 'geo-tool' ) }
						>
							<Button
								variant="secondary"
								disabled={ page <= 1 }
								onClick={ () => changePage( page - 1 ) }
							>
								{ __( 'Previous', 'geo-tool' ) }
							</Button>
							<span>
								{ sprintf(
									/* translators: 1: current page, 2: total pages. */
									__( 'Page %1$d of %2$d', 'geo-tool' ),
									page,
									data.totalPages
								) }
							</span>
							<Button
								variant="secondary"
								disabled={ page >= data.totalPages }
								onClick={ () => changePage( page + 1 ) }
							>
								{ __( 'Next', 'geo-tool' ) }
							</Button>
						</nav>
					) }
				</>
			) }
		</div>
	);
}
