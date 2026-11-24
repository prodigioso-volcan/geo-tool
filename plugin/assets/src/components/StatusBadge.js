import { Icon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export const STATE_ICON = {
	green: 'yes-alt',
	amber: 'warning',
	red: 'dismiss',
};

const HEADLINE = {
	green: __( 'All green', 'revisor-alt' ),
	amber: __( 'Needs attention', 'revisor-alt' ),
	red: __( 'Action required', 'revisor-alt' ),
};

/**
 * Global state. Colour is never the only signal: icon and text always go with it.
 *
 * @param {Object} props
 * @param {string} props.state green, amber or red.
 */
export default function StatusBadge( { state } ) {
	return (
		<p className={ `mdia-badge mdia-state-${ state }` }>
			<Icon icon={ STATE_ICON[ state ] } size={ 28 } />
			<strong>{ HEADLINE[ state ] }</strong>
		</p>
	);
}
