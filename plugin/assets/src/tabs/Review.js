import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import ReviewRow from '../components/ReviewRow';
import { ISSUE_LABELS, STATUS_LABELS } from '../components/labels';
import { approveMany, getItems } from '../api';

const STATUS_ORDER = [
	'needs_review',
	'needs_generation',
	'error',
	'applying',
	'applied',
	'decorative',
	'ignored',
	'ok',
];

export default function Review( { status, onStatusChange } ) {
	const [ issue, setIssue ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	// Text on screen for each proposal, including edits not yet approved.
	const [ texts, setTexts ] = useState( {} );
	const announcePage = useRef( false );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const list = useRef();

	const load = useCallback( () => {
		return getItems( { status, issue, search, page, per_page: 20 } )
			.then( ( result ) => {
				setData( result );
				setError( '' );
				// Keep what the reviewer is typing; add the proposals that are new on screen.
				setTexts( ( current ) => {
					const next = {};
					result.items.forEach( ( item ) => {
						next[ item.attachment_id ] =
							current[ item.attachment_id ] ?? item.proposed_alt;
					} );
					return next;
				} );
				if ( announcePage.current ) {
					announcePage.current = false;
					speak(
						sprintf(
							/* translators: 1: current page, 2: total pages. */
							__( 'Page %1$d of %2$d', 'meta-descriptions-ia' ),
							page,
							result.totalPages
						)
					);
					list.current?.querySelector( '.mdia-row' )?.focus();
				}
			} )
			.catch( ( e ) => setError( e.message ) );
	}, [ status, issue, search, page ] );

	useEffect( () => {
		setData( null );
		setSelected( [] );
		setTexts( {} );
		const timer = setTimeout( load, search ? 300 : 0 );
		return () => clearTimeout( timer );
	}, [ load, search ] );

	// Items in progress change on the server: refresh them without user action.
	useEffect( () => {
		const moving =
			data &&
			data.items.some(
				( item ) => item.status === 'applying' || item.queued
			);
		if ( ! moving ) {
			return undefined;
		}
		const timer = setInterval( load, 8000 );
		return () => clearInterval( timer );
	}, [ data, load ] );

	const filter = ( setter ) => ( value ) => {
		setter( value );
		setPage( 1 );
	};

	const onRowChange = ( updated, action ) => {
		setData( ( current ) => ( {
			...current,
			items: current.items.map( ( item ) =>
				item.attachment_id === updated.attachment_id ? updated : item
			),
		} ) );
		setSelected( ( ids ) =>
			ids.filter( ( id ) => id !== updated.attachment_id )
		);
		setTexts( ( current ) => ( {
			...current,
			[ updated.attachment_id ]: updated.proposed_alt,
		} ) );

		// The button just used may be gone: never leave keyboard focus nowhere.
		// After approving, continue with the next image; otherwise stay on this one.
		const row = list.current?.querySelector(
			`[data-id="${ updated.attachment_id }"]`
		);
		const next = action === 'approve' && row?.nextElementSibling;
		const field = next && next.querySelector( 'textarea, button' );
		( field || row )?.focus();
	};

	const changePage = ( target ) => {
		announcePage.current = true;
		setPage( target );
	};

	const approveSelected = async () => {
		setBusy( true );
		try {
			const result = await approveMany(
				selected.map( ( id ) => ( { id, text: texts[ id ] ?? '' } ) )
			);
			speak(
				sprintf(
					/* translators: %d: number of images. */
					_n(
						'%d image approved.',
						'%d images approved.',
						result.done,
						'meta-descriptions-ia'
					),
					result.done
				)
			);
			if ( result.failed ) {
				setError(
					sprintf(
						/* translators: %d: number of images. */
						_n(
							'%d image could not be approved.',
							'%d images could not be approved.',
							result.failed,
							'meta-descriptions-ia'
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

	const selectable = data
		? data.items
				.filter( ( item ) => item.status === 'needs_review' )
				.map( ( item ) => item.attachment_id )
		: [];

	return (
		<div className="mdia-review">
			<div className="mdia-filters">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Status', 'meta-descriptions-ia' ) }
					value={ status }
					options={ [
						{ value: '', label: __( 'All', 'meta-descriptions-ia' ) },
						...STATUS_ORDER.map( ( value ) => ( {
							value,
							label: STATUS_LABELS[ value ],
						} ) ),
					] }
					onChange={ ( value ) => {
						setPage( 1 );
						onStatusChange( value );
					} }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Problem detected', 'meta-descriptions-ia' ) }
					value={ issue }
					options={ [
						{ value: '', label: __( 'Any', 'meta-descriptions-ia' ) },
						...Object.entries( ISSUE_LABELS ).map(
							( [ value, label ] ) => ( { value, label } )
						),
					] }
					onChange={ filter( setIssue ) }
				/>
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search by text or title', 'meta-descriptions-ia' ) }
					hideLabelFromVision={ false }
					value={ search }
					onChange={ filter( setSearch ) }
				/>
			</div>

			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			{ ! data && <Spinner /> }

			{ data && data.items.length === 0 && (
				<p>{ __( 'No images here.', 'meta-descriptions-ia' ) }</p>
			) }

			{ data && data.items.length > 0 && (
				<>
					<div className="mdia-bulk">
						<span aria-live="polite">
							{ sprintf(
								/* translators: %d: number of images. */
								_n(
									'%d image',
									'%d images',
									data.total,
									'meta-descriptions-ia'
								),
								data.total
							) }
						</span>
						{ selectable.length > 0 && (
							<>
								<Button
									variant="tertiary"
									onClick={ () =>
										setSelected(
											selected.length === selectable.length
												? []
												: selectable
										)
									}
								>
									{ selected.length === selectable.length
										? __( 'Select none', 'meta-descriptions-ia' )
										: __( 'Select all on this page', 'meta-descriptions-ia' ) }
								</Button>
								<Button
									variant="primary"
									disabled={ ! selected.length || busy }
									isBusy={ busy }
									onClick={ approveSelected }
								>
									{ sprintf(
										/* translators: %d: number of selected images. */
										__( 'Approve selected (%d)', 'meta-descriptions-ia' ),
										selected.length
									) }
								</Button>
							</>
						) }
					</div>

					<ul className="mdia-rows" ref={ list }>
						{ data.items.map( ( item ) => (
							<ReviewRow
								key={ item.attachment_id }
								item={ item }
								text={ texts[ item.attachment_id ] ?? item.proposed_alt }
								onTextChange={ ( value ) =>
									setTexts( ( current ) => ( {
										...current,
										[ item.attachment_id ]: value,
									} ) )
								}
								selected={ selected.includes( item.attachment_id ) }
								onSelect={ ( checked ) =>
									setSelected( ( ids ) =>
										checked
											? [ ...ids, item.attachment_id ]
											: ids.filter(
													( id ) => id !== item.attachment_id
											  )
									)
								}
								onChange={ onRowChange }
							/>
						) ) }
					</ul>

					{ data.totalPages > 1 && (
						<nav
							className="mdia-pagination"
							aria-label={ __( 'Pages', 'meta-descriptions-ia' ) }
						>
							<Button
								variant="secondary"
								disabled={ page <= 1 }
								onClick={ () => changePage( page - 1 ) }
							>
								{ __( 'Previous', 'meta-descriptions-ia' ) }
							</Button>
							<span>
								{ sprintf(
									/* translators: 1: current page, 2: total pages. */
									__( 'Page %1$d of %2$d', 'meta-descriptions-ia' ),
									page,
									data.totalPages
								) }
							</span>
							<Button
								variant="secondary"
								disabled={ page >= data.totalPages }
								onClick={ () => changePage( page + 1 ) }
							>
								{ __( 'Next', 'meta-descriptions-ia' ) }
							</Button>
						</nav>
					) }
				</>
			) }
		</div>
	);
}
