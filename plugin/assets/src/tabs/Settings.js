import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import Preferences from '../components/Preferences';
import { getSettings, saveSettings, startAudit } from '../api';

const { connectorsUrl, siteHealthUrl } = window.mdiaData;

export default function Settings( { onRunWizard } ) {
	const [ values, setValues ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		getSettings()
			.then( setValues )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	}, [] );

	const run = async ( task, done ) => {
		setBusy( true );
		try {
			await task();
			setNotice( { status: 'success', text: done } );
		} catch ( e ) {
			setNotice( { status: 'error', text: e.message } );
		}
		setBusy( false );
	};

	if ( ! values ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.text }
			</Notice>
		) : (
			<Spinner />
		);
	}

	return (
		<div className="mdia-settings">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<h2>{ __( 'Preferences', 'geo-tool' ) }</h2>
				</CardHeader>
				<CardBody className="mdia-settings">
					<Preferences
						values={ values }
						onChange={ ( changed ) =>
							setValues( { ...values, ...changed } )
						}
					/>
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ () =>
							run(
								async () =>
									setValues(
										await saveSettings( {
											daily_limit: values.daily_limit,
											auto_generate_new: values.auto_generate_new,
										} )
									),
								__( 'Settings saved.', 'geo-tool' )
							)
						}
					>
						{ __( 'Save settings', 'geo-tool' ) }
					</Button>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'AI provider', 'geo-tool' ) }</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'The plugin uses the AI provider connected in WordPress. API keys are managed there, never by this plugin.',
							'geo-tool'
						) }
					</p>
					<Button variant="secondary" href={ connectorsUrl }>
						{ __( 'Open Connectors', 'geo-tool' ) }
					</Button>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'Maintenance', 'geo-tool' ) }</h2>
				</CardHeader>
				<CardBody className="mdia-generate">
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ () =>
							run(
								startAudit,
								__(
									'Audit started. It runs in the background; the dashboard shows the result.',
									'geo-tool'
								)
							)
						}
					>
						{ __( 'Run full audit now', 'geo-tool' ) }
					</Button>
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ async () => {
							await saveSettings( { wizard_done: false } );
							onRunWizard();
						} }
					>
						{ __( 'Run setup wizard again', 'geo-tool' ) }
					</Button>
					<Button variant="link" href={ siteHealthUrl }>
						{ __( 'Site Health', 'geo-tool' ) }
					</Button>
				</CardBody>
			</Card>
		</div>
	);
}
