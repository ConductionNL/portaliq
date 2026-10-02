// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The history of one object, as its contributing app declared it through a
// collection's `timeline` (portaliq#723). The app decided what is public, so
// nothing here filters or adds: it only orders the entries newest first.
// The same rules as the React portal's TimelineList.jsx, without React.
//
// Imports nothing, so tests/case-timeline.spec.mjs runs it as node.
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-records-timeline-must-show-as-its-app-returned-it-req-srp-019

/**
 * The moment of an entry, for sorting; entries without one sort last.
 *
 * @param {object} entry A timeline entry.
 * @return {number} Milliseconds since the epoch, or -Infinity.
 */
export function momentOf(entry) {
	const time = Date.parse(entry?.occurredAt || entry?.date || '')
	return Number.isNaN(time) ? -Infinity : time
}

/**
 * The entries newest first, without changing the list it was given.
 *
 * @param {Array<object>} entries The entries as returned.
 * @return {Array<object>} A newest-first copy.
 */
export function newestFirst(entries) {
	return (Array.isArray(entries) ? entries : [])
		.map((entry, index) => ({ entry, index }))
		.sort((a, b) => momentOf(b.entry) - momentOf(a.entry) || a.index - b.index)
		.map(({ entry }) => entry)
}

/**
 * The words of one entry: its message, else its label or title.
 *
 * @param {object} entry A timeline entry.
 * @return {string} What happened.
 */
export function textOf(entry) {
	return String(entry?.message || entry?.label || entry?.title || '')
}
