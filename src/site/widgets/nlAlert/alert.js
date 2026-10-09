// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The plain logic of the melding (site-callouts-steps-and-tables-follow-the-boards):
// its kind, its words with bold parts, and its button. No Vue, so node tests it.

import { authoredLink } from '../../components/mijn/links.js'

/** The kinds a melding may be; `plain` is a white card with a line. */
export const KINDS = ['info', 'ok', 'warning', 'error', 'plain']

/**
 * The kind, or `info` for anything unknown.
 *
 * @param {string} kind The declared kind.
 * @return {string} One of KINDS.
 * @spec openspec/changes/site-callouts-steps-and-tables-follow-the-boards/specs/site-look/spec.md#requirement-a-melding-may-carry-a-button-bold-words-and-a-plain-look
 */
export function alertKind(kind) {
	return KINDS.includes(kind) ? kind : 'info'
}

/**
 * The text in parts, the ones between `**` bold: "Bel **[telefoonnummer]**"
 * shows the number in bold. Never HTML: each part renders as text.
 *
 * @param {string} text The text.
 * @return {Array<{text: string, strong: boolean}>}
 * @spec openspec/changes/site-callouts-steps-and-tables-follow-the-boards/specs/site-look/spec.md#requirement-a-melding-may-carry-a-button-bold-words-and-a-plain-look
 */
export function boldParts(text) {
	const source = String(text ?? '')
	const pieces = source.split('**')
	// An odd number of markers is not markup: show the text as written.
	if (pieces.length % 2 === 0) {
		return source === '' ? [] : [{ text: source, strong: false }]
	}
	return pieces
		.map((piece, index) => ({ text: piece, strong: index % 2 === 1 }))
		.filter((part) => part.text !== '')
}

/**
 * The melding's button, `{label, href}`, as a link the site can follow, or
 * null when either is missing or the address is not a safe one.
 *
 * @param {object} action The declared button.
 * @return {{label: string, link: object}|null}
 * @spec openspec/changes/site-callouts-steps-and-tables-follow-the-boards/specs/site-look/spec.md#requirement-a-melding-may-carry-a-button-bold-words-and-a-plain-look
 */
export function alertAction(action) {
	const label = String(action?.label ?? '').trim()
	const link = authoredLink(action?.href)
	return label !== '' && link ? { label, link } : null
}
