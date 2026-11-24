import { __ } from '@wordpress/i18n';

export const ISSUE_LABELS = {
	missing: __( 'Missing', 'revisor-alt' ),
	filename: __( 'File name', 'revisor-alt' ),
	generic: __( 'Generic', 'revisor-alt' ),
	too_short: __( 'Too short', 'revisor-alt' ),
	too_long: __( 'Too long', 'revisor-alt' ),
	duplicate: __( 'Duplicate', 'revisor-alt' ),
};

export const STATUS_LABELS = {
	needs_review: __( 'To review', 'revisor-alt' ),
	needs_generation: __( 'To generate', 'revisor-alt' ),
	applying: __( 'Applying', 'revisor-alt' ),
	applied: __( 'Applied', 'revisor-alt' ),
	decorative: __( 'Decorative', 'revisor-alt' ),
	ignored: __( 'Ignored', 'revisor-alt' ),
	error: __( 'Errors', 'revisor-alt' ),
	ok: __( 'Correct', 'revisor-alt' ),
};
