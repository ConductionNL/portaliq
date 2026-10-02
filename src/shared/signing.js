// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Signing and declining a document from its row
// (case-actions-sign-a-document, D4). Pure functions, no React and no fetch,
// tested on their own in tests/signing-dialog.spec.mjs.
//
// Nothing about the signature itself is decided here (D5): the receiving app
// checks the signer, the consent and the assurance level. These helpers only
// decide which dialog opens, how a returned document is shown, and which
// sentence an answer gets.

import { outcomeKey } from './rowAction.js'

/**
 * The largest base64 payload rendered inline. Above it the document is
 * offered as a download only, because a very large data URL can stall or
 * crash a phone's browser (design risk "Large documents").
 */
export const INLINE_LIMIT = 8 * 1024 * 1024

/** The kinds of document a browser renders inline without running anything. */
const INLINE_TYPES = [
	'application/pdf',
	'image/png',
	'image/jpeg',
	'image/gif',
	'image/webp',
]

/**
 * Which dialog a row's endpoint action opens.
 *
 * @param {object} action The resolved endpoint row action.
 * @return {'sign'|'decline'|'confirm'} The dialog.
 *
 * @spec openspec/specs/portal-document-signing/spec.md#requirement-the-resident-reads-the-document-before-signing-it-req-sgn-004
 */
export function dialogFor(action) {
	if (action && action.id === 'sign') {
		return 'sign'
	}

	if (action && action.id === 'decline') {
		return 'decline'
	}

	return 'confirm'
}

/**
 * The row actions the table shows as buttons. Viewing the document is part of
 * the sign dialog, not a button of its own.
 *
 * @param {Array<object>} actions The resolved row actions.
 * @return {Array<object>} The actions to show.
 *
 * @spec openspec/specs/portal-document-signing/spec.md#requirement-the-resident-reads-the-document-before-signing-it-req-sgn-004
 */
export function tableRowActions(actions) {
	return (Array.isArray(actions) ? actions : []).filter(
		(action) => action && action.id !== 'viewDocument',
	)
}

/**
 * How a document the receiver returned is shown.
 *
 * `shown` carries a data URL; `inline` says whether it is rendered in the page
 * (a PDF or an image under the size limit) or offered as a download only. Any
 * other answer is `unavailable`, and then there is nothing to sign.
 *
 * @param {{ok: boolean, status: number, body: object}} result The forward's answer.
 * @return {{state: string, name?: string, href?: string, inline?: boolean, mimeType?: string}} The view.
 *
 * @spec openspec/specs/portal-document-signing/spec.md#requirement-the-resident-reads-the-document-before-signing-it-req-sgn-004
 */
export function documentView(result) {
	const body = result && result.body
	const content =
		body && typeof body.contentBase64 === 'string' ? body.contentBase64 : ''
	if (!result || !result.ok || content === '') {
		return { state: 'unavailable' }
	}

	const declared =
		typeof body.mimeType === 'string' ? body.mimeType.toLowerCase() : ''
	const renderable = INLINE_TYPES.includes(declared)
	const mimeType = renderable ? declared : 'application/octet-stream'

	return {
		state: 'shown',
		name:
			typeof body.documentName === 'string' && body.documentName !== ''
				? body.documentName
				: 'document',
		mimeType,
		href: `data:${mimeType};base64,${content}`,
		inline: renderable && content.length <= INLINE_LIMIT,
	}
}

/**
 * The sentence an answer gets, and whether the dialog is done.
 *
 * @param {'sign'|'decline'} kind The act.
 * @param {{ok: boolean, status: number}} result The forward's answer.
 * @param {string} documentName The document's name.
 * @return {{done: boolean, key: string, vars?: object}} The outcome.
 *
 * @spec openspec/specs/portal-document-signing/spec.md#requirement-the-resident-reads-the-document-before-signing-it-req-sgn-004
 */
export function outcome(kind, result, documentName) {
	if (result && result.ok) {
		return {
			done: true,
			key:
				kind === 'decline'
					? 'You declined to sign {documentName}.'
					: 'You signed {documentName}.',
			vars: { documentName },
		}
	}

	return { done: false, key: outcomeKey(result) }
}
