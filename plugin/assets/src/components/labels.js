import { __ } from '@wordpress/i18n';

export const ISSUE_LABELS = {
	missing: __( 'Missing', 'geo-tool' ),
	filename: __( 'File name', 'geo-tool' ),
	generic: __( 'Generic', 'geo-tool' ),
	too_short: __( 'Too short', 'geo-tool' ),
	too_long: __( 'Too long', 'geo-tool' ),
	duplicate: __( 'Duplicate', 'geo-tool' ),
};

export const STATUS_LABELS = {
	needs_review: __( 'To review', 'geo-tool' ),
	needs_generation: __( 'To generate', 'geo-tool' ),
	applying: __( 'Applying', 'geo-tool' ),
	applied: __( 'Applied', 'geo-tool' ),
	decorative: __( 'Decorative', 'geo-tool' ),
	ignored: __( 'Ignored', 'geo-tool' ),
	error: __( 'Errors', 'geo-tool' ),
	ok: __( 'Correct', 'geo-tool' ),
};
