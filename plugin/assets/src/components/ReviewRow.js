import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	TextareaControl,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { itemAction } from '../api';
import { ISSUE_LABELS, STATUS_LABELS } from './labels';

const { maxLength } = window.mdiaData;

const DONE_MESSAGE = {
	approve: __( 'Approved. The text is being applied.', 'geo-tool' ),
	decorative: __( 'Marked as decorative.', 'geo-tool' ),
	ignore: __( 'Ignored.', 'geo-tool' ),
	regenerate: __( 'A new proposal has been requested.', 'geo-tool' ),
	undo: __( 'Change undone.', 'geo-tool' ),
};

export default function ReviewRow( {
	item,
	text,
	onTextChange,
	selected,
	onSelect,
	onChange,
} ) {
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );

	const name = item.title || item.filename;
	const status = item.status;
	const length = [ ...text ].length;
	// The theme always shows it without alt text: the proposal is to mark it as decorative.
	const themeProposal = status === 'needs_review' && item.provider === 'theme';
	const hasProposal = status === 'needs_review' && ! themeProposal;

	const run = async ( action, data ) => {
		setBusy( action );
		setError( '' );
		try {
			const updated = await itemAction( item.attachment_id, action, data );
			speak( DONE_MESSAGE[ action ] );
			onChange( updated, action );
		} catch ( e ) {
			setError( e.message );
			speak( e.message, 'assertive' );
		}
		setBusy( '' );
	};

	const action = ( key, label, variant = 'secondary', data ) => (
		<Button
			variant={ variant }
			isBusy={ busy === key }
			disabled={ !! busy }
			onClick={ () => run( key, data ) }
			aria-label={ `${ label }: ${ name }` }
		>
			{ label }
		</Button>
	);

	return (
		<li
			className={ `mdia-row mdia-row--${
				[ 'ok', 'applied', 'decorative', 'ignored' ].includes( status )
					? 'done'
					: ( status === 'unused' && 'idle' ) || 'required'
			}` }
			data-id={ item.attachment_id }
			tabIndex="-1"
		>
			<div className="mdia-row__select">
				{ hasProposal && (
					<input
						type="checkbox"
						aria-label={ sprintf(
							/* translators: %s: image name. */
							__( 'Select %s', 'geo-tool' ),
							name
						) }
						checked={ selected }
						onChange={ ( event ) => onSelect( event.target.checked ) }
					/>
				) }
			</div>

			<a className="mdia-row__thumb" href={ item.edit_url }>
				<img src={ item.thumbnail_url } alt="" />
				<span className="screen-reader-text">
					{ sprintf(
						/* translators: %s: image name. */
						__( 'Open %s in the media library', 'geo-tool' ),
						name
					) }
				</span>
			</a>

			<div className="mdia-row__body">
				<p className="mdia-row__meta">
					<strong>{ item.filename }</strong>
					<span>{ STATUS_LABELS[ status ] }</span>
					<span>
						{ sprintf(
							/* translators: %d: number of pages. */
							_n(
								'Used on %d page',
								'Used on %d pages',
								item.usage_count,
								'geo-tool'
							),
							item.usage_count
						) }
					</span>
					{ item.fixed_count > 0 && (
						<span>
							{ sprintf(
								/* translators: %d: number of pages. */
								_n(
									'%d with a text set by the theme',
									'%d with a text set by the theme',
									item.fixed_count,
									'geo-tool'
								),
								item.fixed_count
							) }
						</span>
					) }
					{ item.unmanaged_count > 0 && (
						<span>
							{ sprintf(
								/* translators: %d: number of pages. */
								_n(
									'%d in a page builder (not updated)',
									'%d in page builders (not updated)',
									item.unmanaged_count,
									'geo-tool'
								),
								item.unmanaged_count
							) }
						</span>
					) }
				</p>

				<p className="mdia-row__current">
					<strong>
						{ __( 'Current alt text:', 'geo-tool' ) }
					</strong>{ ' ' }
					{ item.library_alt || (
						<em>{ __( '(empty)', 'geo-tool' ) }</em>
					) }{ ' ' }
						{ item.issues.map( ( issue ) => (
						<span key={ issue } className="mdia-tag">
							{ ISSUE_LABELS[ issue ] }
						</span>
					) ) }
				</p>

				{ item.provider === 'library' && status === 'needs_review' && (
					<p className="mdia-muted">
						{ __(
							'The media library text is good; only a page is missing it. Approving copies it there. No AI is used.',
							'geo-tool'
						) }
					</p>
				) }
				{ themeProposal && (
					<p className="mdia-muted">
						{ __(
							'The theme always shows this image without alt text, as decoration. A description would not reach the page, so the proposal is to mark it as decorative. No AI is used.',
							'geo-tool'
						) }
					</p>
				) }
				{ status === 'unused' && (
					<p className="mdia-muted">
						{ __(
							'No published page shows this image, so it is not counted as pending. You can still ask for a description.',
							'geo-tool'
						) }
					</p>
				) }
				{ status === 'error' && (
					<Notice status="error" isDismissible={ false }>
						{ item.last_error }
					</Notice>
				) }
				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }

				{ hasProposal && (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ sprintf(
							/* translators: %s: image name. */
							__( 'Proposed alt text for %s', 'geo-tool' ),
							name
						) }
						rows={ 2 }
						value={ text }
						onChange={ onTextChange }
						help={
							<span
								className={
									length > maxLength
										? 'mdia-row__counter--over'
										: undefined
								}
							>
								{ sprintf(
									/* translators: 1: current length, 2: recommended maximum. */
									__( '%1$d of %2$d recommended characters', 'geo-tool' ),
									length,
									maxLength
								) }
							</span>
						}
					/>
				) }

				<div className="mdia-row__actions">
					{ themeProposal && (
						<>
							{ action(
								'decorative',
								__( 'Mark as decorative', 'geo-tool' ),
								'primary'
							) }
							{ action(
								'regenerate',
								__( 'Describe it anyway', 'geo-tool' )
							) }
							{ action( 'ignore', __( 'Ignore', 'geo-tool' ) ) }
						</>
					) }
					{ status === 'unused' &&
						action(
							'regenerate',
							__( 'Generate proposal', 'geo-tool' )
						) }
					{ hasProposal && (
						<>
							{ action(
								'approve',
								__( 'Approve', 'geo-tool' ),
								'primary',
								{ text }
							) }
							{ action(
								'regenerate',
								__( 'Regenerate', 'geo-tool' )
							) }
						</>
					) }
					{ status === 'needs_generation' &&
						! item.queued &&
						action(
							'regenerate',
							__( 'Generate now', 'geo-tool' ),
							'primary'
						) }
					{ status === 'needs_generation' && item.queued && (
						<span className="mdia-muted">
							{ __( 'In the generation queue…', 'geo-tool' ) }
						</span>
					) }
					{ status === 'error' &&
						action(
							'regenerate',
							__( 'Retry', 'geo-tool' ),
							'primary'
						) }
					{ ! themeProposal &&
						[ 'needs_review', 'needs_generation', 'error' ].includes(
							status
						) && (
						<>
							{ action(
								'decorative',
								__( 'Decorative', 'geo-tool' )
							) }
							{ action( 'ignore', __( 'Ignore', 'geo-tool' ) ) }
						</>
					) }
					{ status === 'ignored' &&
						action(
							'regenerate',
							__( 'Generate proposal', 'geo-tool' )
						) }
					{ item.can_undo &&
						action( 'undo', __( 'Undo', 'geo-tool' ) ) }
					{ status === 'applying' && (
						<span className="mdia-muted">
							{ __(
								'Applying to the library and your pages…',
								'geo-tool'
							) }
						</span>
					) }
				</div>
			</div>
		</li>
	);
}
