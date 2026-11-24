import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const base = `/${ window.mdiaData.restNamespace }`;

const post = ( path, data = {} ) =>
	apiFetch( { path: base + path, method: 'POST', data } );

export const getStatus = () => apiFetch( { path: `${ base }/status` } );

/**
 * @param {Object} params status, issue, search, page, per_page.
 * @return {Promise<{items: Array, total: number, totalPages: number}>} Page of items.
 */
export const getItems = async ( params ) => {
	const clean = Object.fromEntries(
		Object.entries( params ).filter( ( [ , value ] ) => value !== '' )
	);
	const response = await apiFetch( {
		path: addQueryArgs( `${ base }/items`, clean ),
		parse: false,
	} );
	return {
		items: await response.json(),
		total: Number( response.headers.get( 'X-WP-Total' ) ),
		totalPages: Number( response.headers.get( 'X-WP-TotalPages' ) ),
	};
};

export const itemAction = ( id, action, data ) =>
	post( `/items/${ id }/${ action }`, data );

/**
 * @param {Array<{id: number, text: string}>} items Images with the text shown on screen.
 */
export const approveMany = ( items ) => post( '/items/approve', { items } );

/**
 * Launches generation for every pending image, in server-sized chunks.
 *
 * @return {Promise<number>} How many images were queued.
 */
export const startGeneration = async () => {
	let total = 0;
	for ( let guard = 0; guard < 200; guard++ ) {
		const { queued, remaining } = await post( '/generation' );
		total += queued;
		if ( ! remaining || ! queued ) {
			break;
		}
	}
	return total;
};

export const pauseGeneration = () =>
	apiFetch( { path: `${ base }/generation`, method: 'DELETE' } );

export const startAudit = () => post( '/audit' );
export const getSettings = () => apiFetch( { path: `${ base }/settings` } );
export const saveSettings = ( values ) => post( '/settings', values );
export const wizardRequirements = () =>
	apiFetch( { path: `${ base }/wizard/requirements` } );
export const wizardTest = () => post( '/wizard/test' );

/**
 * @param {Object} params area, fix, status, check_id, page, per_page.
 * @return {Promise<{items: Array, total: number, totalPages: number}>} Page of findings.
 */
export const getFindings = async ( params ) => {
	const clean = Object.fromEntries(
		Object.entries( params ).filter( ( [ , value ] ) => value !== '' )
	);
	const response = await apiFetch( {
		path: addQueryArgs( `${ base }/findings`, clean ),
		parse: false,
	} );
	return {
		items: await response.json(),
		total: Number( response.headers.get( 'X-WP-Total' ) ),
		totalPages: Number( response.headers.get( 'X-WP-TotalPages' ) ),
	};
};

export const findingAction = ( id, action, data ) =>
	post( `/findings/${ id }/${ action }`, data );

/**
 * @param {Array<{id: number, text: string}>} items Findings with the text shown on screen.
 */
export const approveFindings = ( items ) =>
	post( '/findings/approve', { items } );

/**
 * Launches text proposals for every open finding of an area, in server-sized chunks.
 *
 * @param {string} area pages, taxonomy or empty for all.
 * @return {Promise<number>} How many texts were queued.
 */
export const startTextGeneration = async ( area ) => {
	let total = 0;
	for ( let guard = 0; guard < 200; guard++ ) {
		const { queued, remaining } = await post(
			'/findings/generation',
			area ? { area } : {}
		);
		total += queued;
		if ( ! remaining || ! queued ) {
			break;
		}
	}
	return total;
};

export const waiveAcfField = ( name ) => post( '/findings/acf-field', { name } );
