import { useState } from '@wordpress/element';
import { Button, Notice, TextareaControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { findingAction } from '../api';
import { FINDING_STATUS_LABELS, SEVERITY_LABELS } from './labels';

const DONE_MESSAGE = {
	approve: __( 'Approved and saved.', 'geo-tool' ),
	waive: __( 'Accepted as it is.', 'geo-tool' ),
	reopen: __( 'Back under review.', 'geo-tool' ),
	regenerate: __( 'A new proposal has been requested.', 'geo-tool' ),
	undo: __( 'Change undone.', 'geo-tool' ),
	trash: __( 'Sent to the trash.', 'geo-tool' ),
};

const size = ( text, unit ) =>
	unit === 'words'
		? text.trim().split( /\s+/ ).filter( Boolean ).length
		: [ ...text ].length;

/**
 * One finding: what is wrong, where, and what can be done about it.
 *
 * @param {Object}   props
 * @param {Object}   props.item         Finding as the REST API returns it.
 * @param {string}   props.text         Text on screen for a proposal.
 * @param {Function} props.onTextChange Receives the edited text.
 * @param {boolean}  props.selected     Whether it is ticked for bulk approval.
 * @param {Function} props.onSelect     Receives the new tick state; omit to hide the checkbox.
 * @param {Function} props.onChange     Receives the updated finding and the action.
 * @param {Function} props.onWaiveField Declares an ACF field optional site-wide; omit to hide.
 */
export default function FindingRow( {
	item,
	text = '',
	onTextChange,
	selected,
	onSelect,
	onChange,
	onWaiveField,
} ) {
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ writing, setWriting ] = useState( false );

	const { status, limits } = item;
	const pending = [ 'open', 'proposed', 'error' ].includes( status );
	const isText = item.fix === 'ai';
	const editing = isText && item.can_edit && ( status === 'proposed' || writing );
	const length = limits ? size( text, limits.unit ) : 0;
	const outside = limits && ( length > limits.max || length < limits.min );

	const run = async ( action, data ) => {
		setBusy( action );
		setError( '' );
		try {
			const updated = await findingAction( item.id, action, data );
			speak( DONE_MESSAGE[ action ] );
			setWriting( false );
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
			disabled={ !! busy || ( key === 'approve' && ! text.trim() ) }
			onClick={ () => run( key, data ) }
			aria-label={ `${ label }: ${ item.label }, ${ item.target_label }` }
		>
			{ label }
		</Button>
	);

	return (
		<li
			className={ `mdia-row mdia-row--finding mdia-row--${
				pending ? item.severity : 'done'
			}` }
			data-id={ item.id }
			tabIndex="-1"
		>
			<div className="mdia-row__select">
				{ onSelect && status === 'proposed' && item.can_edit && (
					<input
						type="checkbox"
						aria-label={ sprintf(
							/* translators: 1: name of the check, 2: page or item it is about. */
							__( 'Select %1$s: %2$s', 'geo-tool' ),
							item.label,
							item.target_label
						) }
						checked={ selected }
						onChange={ ( event ) => onSelect( event.target.checked ) }
					/>
				) }
			</div>

			<div className="mdia-row__body">
				{ item.area !== 'site' && (
					<p className="mdia-row__target">
						{ item.view_url ? (
							<a href={ item.view_url }>{ item.target_label }</a>
						) : (
							item.target_label
						) }
					</p>
				) }

				<p className="mdia-row__meta">
					<strong>{ item.label }</strong>
					<span
						className={ `mdia-tag mdia-tag--${ item.severity }` }
					>
						{ SEVERITY_LABELS[ item.severity ] }
					</span>
					<span>
						{ status === 'open' && ! isText
							? __( 'To fix', 'geo-tool' )
							: FINDING_STATUS_LABELS[ status ] }
					</span>
				</p>

				{ item.problem && pending && (
					<p className="mdia-row__problem">{ item.problem }</p>
				) }
				<p className="mdia-row__why">{ item.why }</p>

				{ isText && item.current_value && (
					<p className="mdia-row__current">
						<strong>{ __( 'Current text:', 'geo-tool' ) }</strong>{ ' ' }
						{ item.current_value }
					</p>
				) }

				{ status === 'error' && item.last_error && (
					<Notice status="error" isDismissible={ false }>
						{ item.last_error }
					</Notice>
				) }
				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }

				{ editing && (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ sprintf(
							/* translators: 1: name of the text, e.g. "Meta description", 2: page it belongs to. */
							__( 'Text for %1$s: %2$s', 'geo-tool' ),
							item.label,
							item.target_label
						) }
						rows={ 3 }
						value={ text }
						onChange={ onTextChange }
						help={
							limits && (
								<span
									className={
										outside
											? 'mdia-row__counter--over'
											: undefined
									}
								>
									{ limits.unit === 'words'
										? sprintf(
												/* translators: 1: current number of words, 2: minimum, 3: maximum. */
												__(
													'%1$d words (recommended: %2$d to %3$d)',
													'geo-tool'
												),
												length,
												limits.min,
												limits.max
										  )
										: sprintf(
												/* translators: 1: current number of characters, 2: minimum, 3: maximum. */
												__(
													'%1$d characters (recommended: %2$d to %3$d)',
													'geo-tool'
												),
												length,
												limits.min,
												limits.max
										  ) }
								</span>
							)
						}
					/>
				) }

				<div className="mdia-row__actions">
					{ editing &&
						action( 'approve', __( 'Approve', 'geo-tool' ), 'primary', {
							text,
						} ) }
					{ isText && pending && item.can_edit && ! item.queued && (
						<>
							{ window.mdiaData.textConsent &&
								action(
									'regenerate',
									status === 'proposed'
										? __( 'Regenerate', 'geo-tool' )
										: __( 'Propose with AI', 'geo-tool' ),
									status === 'open' && ! writing
										? 'primary'
										: 'secondary'
								) }
							{ status !== 'proposed' && ! writing && (
								<Button
									variant="secondary"
									onClick={ () => setWriting( true ) }
									aria-label={ `${ __(
										'Write it myself',
										'geo-tool'
									) }: ${ item.label }, ${ item.target_label }` }
								>
									{ __( 'Write it myself', 'geo-tool' ) }
								</Button>
							) }
						</>
					) }
					{ item.queued && (
						<span className="mdia-muted">
							{ __( 'In the generation queue…', 'geo-tool' ) }
						</span>
					) }
					{ pending && item.can_trash && (
						<Button
							variant="primary"
							isBusy={ busy === 'trash' }
							disabled={ !! busy }
							onClick={ () => {
								const question = sprintf(
									/* translators: %s: title of a page. */
									__(
										'Send "%s" to the trash? It stops being public. You can recover it from the trash of WordPress.',
										'geo-tool'
									),
									item.target_label
								);
								// eslint-disable-next-line no-alert
								if ( window.confirm( question ) ) {
									run( 'trash' );
								}
							} }
							aria-label={ `${ __(
								'Send to the trash',
								'geo-tool'
							) }: ${ item.target_label }` }
						>
							{ __( 'Send to the trash', 'geo-tool' ) }
						</Button>
					) }
					{ ! isText && pending && item.fix_url && (
						<Button
							variant={ item.can_trash ? 'secondary' : 'primary' }
							href={ item.fix_url }
						>
							{ item.fix === 'setting'
								? __( 'Open the setting', 'geo-tool' )
								: __( 'Edit', 'geo-tool' ) }
							<span className="screen-reader-text">
								{ `: ${ item.label }, ${ item.target_label }` }
							</span>
						</Button>
					) }
					{ pending &&
						item.can_waive &&
						action( 'waive', __( 'Accept as it is', 'geo-tool' ) ) }
					{ pending &&
						onWaiveField &&
						item.check_id === 'post.acf_empty' &&
						item.target_key && (
							<Button
								variant="tertiary"
								disabled={ !! busy }
								onClick={ () => onWaiveField( item.target_key ) }
							>
								{ __(
									'This field is optional on every page',
									'geo-tool'
								) }
							</Button>
						) }
					{ status === 'waived' &&
						item.can_waive &&
						action( 'reopen', __( 'Review again', 'geo-tool' ) ) }
					{ item.can_undo &&
						item.can_edit &&
						action( 'undo', __( 'Undo', 'geo-tool' ) ) }
				</div>
			</div>
		</li>
	);
}
