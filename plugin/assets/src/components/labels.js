import { __ } from '@wordpress/i18n';

export const ISSUE_LABELS = {
	missing: __( 'Missing', 'meta-descriptions-ia' ),
	filename: __( 'File name', 'meta-descriptions-ia' ),
	generic: __( 'Generic', 'meta-descriptions-ia' ),
	too_short: __( 'Too short', 'meta-descriptions-ia' ),
	too_long: __( 'Too long', 'meta-descriptions-ia' ),
	duplicate: __( 'Duplicate', 'meta-descriptions-ia' ),
};

export const STATUS_LABELS = {
	needs_review: __( 'To review', 'meta-descriptions-ia' ),
	needs_generation: __( 'To generate', 'meta-descriptions-ia' ),
	applying: __( 'Applying', 'meta-descriptions-ia' ),
	applied: __( 'Applied', 'meta-descriptions-ia' ),
	decorative: __( 'Decorative', 'meta-descriptions-ia' ),
	ignored: __( 'Ignored', 'meta-descriptions-ia' ),
	error: __( 'Errors', 'meta-descriptions-ia' ),
	ok: __( 'Correct', 'meta-descriptions-ia' ),
};
