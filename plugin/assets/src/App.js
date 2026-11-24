import { useState } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import Dashboard from './tabs/Dashboard';
import Review from './tabs/Review';
import Settings from './tabs/Settings';
import Wizard from './wizard/Wizard';

const { canManage, wizardDone } = window.mdiaData;

const readUrl = () => new URLSearchParams( window.location.search );

const writeUrl = ( values ) => {
	const url = new URL( window.location.href );
	Object.entries( values ).forEach( ( [ key, value ] ) =>
		value ? url.searchParams.set( key, value ) : url.searchParams.delete( key )
	);
	window.history.replaceState( null, '', url );
};

export default function App() {
	const [ showWizard, setShowWizard ] = useState( canManage && ! wizardDone );
	const [ tab, setTab ] = useState( readUrl().get( 'tab' ) || 'dashboard' );
	// Bumped only when the app itself changes tab; a remount on every click would lose keyboard focus.
	const [ jump, setJump ] = useState( 0 );
	const [ reviewStatus, setReviewStatus ] = useState(
		readUrl().get( 'status' ) || 'needs_review'
	);

	if ( showWizard ) {
		return (
			<Wizard
				onDone={ ( target ) => {
					setShowWizard( false );
					setTab( target || 'dashboard' );
					setJump( ( n ) => n + 1 );
				} }
			/>
		);
	}

	const tabs = [
		{ name: 'dashboard', title: __( 'Dashboard', 'geo-tool' ) },
		{ name: 'review', title: __( 'Review', 'geo-tool' ) },
	];
	if ( canManage ) {
		tabs.push( {
			name: 'settings',
			title: __( 'Settings', 'geo-tool' ),
		} );
	}

	const goToReview = ( status ) => {
		setReviewStatus( status );
		setTab( 'review' );
		setJump( ( n ) => n + 1 );
		writeUrl( { tab: 'review', status } );
	};

	return (
		<TabPanel
			className="mdia-tabs"
			key={ jump }
			initialTabName={ tabs.some( ( t ) => t.name === tab ) ? tab : 'dashboard' }
			tabs={ tabs }
			onSelect={ ( name ) => {
				setTab( name );
				writeUrl( { tab: name, status: name === 'review' ? reviewStatus : '' } );
			} }
		>
			{ ( current ) => (
				<div className="mdia-panel">
					{ current.name === 'dashboard' && (
						<Dashboard onOpenReview={ goToReview } />
					) }
					{ current.name === 'review' && (
						<Review
							status={ reviewStatus }
							onStatusChange={ ( status ) => {
								setReviewStatus( status );
								writeUrl( { tab: 'review', status } );
							} }
						/>
					) }
					{ current.name === 'settings' && (
						<Settings onRunWizard={ () => setShowWizard( true ) } />
					) }
				</div>
			) }
		</TabPanel>
	);
}
