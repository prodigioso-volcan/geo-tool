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
	approve: __( 'Approved. The text is being applied.', 'meta-descriptions-ia' ),
	decorative: __( 'Marked as decorative.', 'meta-descriptions-ia' ),
	ignore: __( 'Ignored.', 'meta-descriptions-ia' ),
	regenerate: __( 'A new proposal has been requested.', 'meta-descriptions-ia' ),
	undo: __( 'Change undone.', 'meta-descriptions-ia' ),
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
		<li className="mdia-row" data-id={ item.attachment_id } tabIndex="-1">
			<div className="mdia-row__select">
				{ status === 'needs_review' && (
					<input
						type="checkbox"
						aria-label={ sprintf(
							/* translators: %s: image name. */
							__( 'Select %s', 'meta-descriptions-ia' ),
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
						__( 'Open %s in the media library', 'meta-descriptions-ia' ),
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
								'meta-descriptions-ia'
							),
							item.usage_count
						) }
					</span>
					{ item.unmanaged_count > 0 && (
						<span>
							{ sprintf(
								/* translators: %d: number of pages. */
								_n(
									'%d in a page builder (not updated)',
									'%d in page builders (not updated)',
									item.unmanaged_count,
									'meta-descriptions-ia'
								),
								item.unmanaged_count
							) }
						</span>
					) }
				</p>

				<p className="mdia-row__current">
					<strong>
						{ __( 'Current alt text:', 'meta-descriptions-ia' ) }
					</strong>{ ' ' }
					{ item.library_alt || (
						<em>{ __( '(empty)', 'meta-descriptions-ia' ) }</em>
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
							'meta-descriptions-ia'
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

				{ status === 'needs_review' && (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ sprintf(
							/* translators: %s: image name. */
							__( 'Proposed alt text for %s', 'meta-descriptions-ia' ),
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
									__( '%1$d of %2$d recommended characters', 'meta-descriptions-ia' ),
									length,
									maxLength
								) }
							</span>
						}
					/>
				) }

				<div className="mdia-row__actions">
					{ status === 'needs_review' && (
						<>
							{ action(
								'approve',
								__( 'Approve', 'meta-descriptions-ia' ),
								'primary',
								{ text }
							) }
							{ action(
								'regenerate',
								__( 'Regenerate', 'meta-descriptions-ia' )
							) }
						</>
					) }
					{ status === 'needs_generation' &&
						! item.queued &&
						action(
							'regenerate',
							__( 'Generate now', 'meta-descriptions-ia' ),
							'primary'
						) }
					{ status === 'needs_generation' && item.queued && (
						<span className="mdia-muted">
							{ __( 'In the generation queue…', 'meta-descriptions-ia' ) }
						</span>
					) }
					{ status === 'error' &&
						action(
							'regenerate',
							__( 'Retry', 'meta-descriptions-ia' ),
							'primary'
						) }
					{ [ 'needs_review', 'needs_generation', 'error' ].includes(
						status
					) && (
						<>
							{ action(
								'decorative',
								__( 'Decorative', 'meta-descriptions-ia' )
							) }
							{ action( 'ignore', __( 'Ignore', 'meta-descriptions-ia' ) ) }
						</>
					) }
					{ status === 'ignored' &&
						action(
							'regenerate',
							__( 'Generate proposal', 'meta-descriptions-ia' )
						) }
					{ item.can_undo &&
						action( 'undo', __( 'Undo', 'meta-descriptions-ia' ) ) }
					{ status === 'applying' && (
						<span className="mdia-muted">
							{ __(
								'Applying to the library and your pages…',
								'meta-descriptions-ia'
							) }
						</span>
					) }
				</div>
			</div>
		</li>
	);
}
