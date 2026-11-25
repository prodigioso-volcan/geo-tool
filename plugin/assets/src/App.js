import { useState } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import Dashboard from './tabs/Dashboard';
import Review from './tabs/Review';
import Findings from './tabs/Findings';
import Settings from './tabs/Settings';
import Wizard from './wizard/Wizard';

const { canManage, wizardDone } = window.mdiaData;

const readUrl = () => new URLSearchParams( window.location.search );

const writeUrl = ( values ) => {
	const url = new URL( window.location.href );
	[ 'tab', 'status', 'area', 'check_id', 'language' ].forEach( ( key ) =>
		values[ key ]
			? url.searchParams.set( key, values[ key ] )
			: url.searchParams.delete( key )
	);
	window.history.replaceState( null, '', url );
};

// The dashboard speaks of areas; each one lives in a tab.
const TAB_OF_AREA = {
	images: 'images',
	pages: 'texts',
	taxonomy: 'texts',
	site: 'site',
};

export default function App() {
	const query = readUrl();
	const [ showWizard, setShowWizard ] = useState( canManage && ! wizardDone );
	const [ tab, setTab ] = useState( query.get( 'tab' ) || 'dashboard' );
	// Bumped only when the app itself changes tab; a remount on every click would lose keyboard focus.
	const [ jump, setJump ] = useState( 0 );
	const [ reviewStatus, setReviewStatus ] = useState(
		( query.get( 'tab' ) === 'images' && query.get( 'status' ) ) ||
			'needs_review'
	);
	const [ filters, setFilters ] = useState(
		[ 'texts', 'site' ].includes( query.get( 'tab' ) )
			? {
					area: query.get( 'area' ) || '',
					status: query.get( 'status' ) ?? 'pending',
					check_id: query.get( 'check_id' ) || '',
					language: query.get( 'language' ) || '',
			  }
			: {}
	);

	if ( showWizard ) {
		return (
			<Wizard
				onDone={ ( target ) => {
					setShowWizard( false );
					setTab( target === 'review' ? 'images' : target || 'dashboard' );
					setJump( ( n ) => n + 1 );
				} }
			/>
		);
	}

	const tabs = [
		{ name: 'dashboard', title: __( 'Dashboard', 'geo-tool' ) },
		{ name: 'images', title: __( 'Images', 'geo-tool' ) },
		{ name: 'texts', title: __( 'Texts', 'geo-tool' ) },
		{ name: 'site', title: __( 'Site', 'geo-tool' ) },
	];
	if ( canManage ) {
		tabs.push( {
			name: 'settings',
			title: __( 'Settings', 'geo-tool' ),
		} );
	}

	const urlFor = ( name, values = filters, status = reviewStatus ) => {
		if ( name === 'images' ) {
			return { tab: name, status };
		}
		return name === 'texts' || name === 'site'
			? { tab: name, ...values }
			: { tab: name };
	};

	const jumpTo = ( name ) => {
		setTab( name );
		setJump( ( n ) => n + 1 );
	};

	const goToImages = ( status ) => {
		setReviewStatus( status );
		jumpTo( 'images' );
		writeUrl( urlFor( 'images', filters, status ) );
	};

	const goToFindings = ( area, checkId = '' ) => {
		if ( area === 'images' ) {
			goToImages( 'needs_review' );
			return;
		}
		const next = { area, status: 'pending', check_id: checkId };
		setFilters( next );
		jumpTo( TAB_OF_AREA[ area ] );
		writeUrl( urlFor( TAB_OF_AREA[ area ], next ) );
	};

	const findings = ( name, areas ) => (
		<Findings
			key={ name }
			areas={ areas }
			filters={ filters }
			onFilterChange={ ( next ) => {
				setFilters( next );
				writeUrl( urlFor( name, next ) );
			} }
			onOpenSettings={ () => {
				jumpTo( 'settings' );
				writeUrl( { tab: 'settings' } );
			} }
		/>
	);

	return (
		<TabPanel
			className="mdia-tabs"
			key={ jump }
			initialTabName={ tabs.some( ( t ) => t.name === tab ) ? tab : 'dashboard' }
			tabs={ tabs }
			onSelect={ ( name ) => {
				if ( name !== tab ) {
					// Filters belong to the tab they were chosen in.
					const reset = { status: 'pending' };
					setFilters( reset );
					writeUrl( urlFor( name, reset ) );
				}
				setTab( name );
			} }
		>
			{ ( current ) => (
				<div className="mdia-panel">
					{ current.name === 'dashboard' && (
						<Dashboard
							onOpenReview={ goToImages }
							onOpenArea={ goToFindings }
						/>
					) }
					{ current.name === 'images' && (
						<Review
							status={ reviewStatus }
							onStatusChange={ ( status ) => {
								setReviewStatus( status );
								writeUrl( urlFor( 'images', filters, status ) );
							} }
						/>
					) }
					{ current.name === 'texts' &&
						findings( 'texts', [ 'pages', 'taxonomy' ] ) }
					{ current.name === 'site' && findings( 'site', [ 'site' ] ) }
					{ current.name === 'settings' && (
						<Settings onRunWizard={ () => setShowWizard( true ) } />
					) }
				</div>
			) }
		</TabPanel>
	);
}
