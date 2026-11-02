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
 * The icon path for the channel a contact came in by: a phone, a message,
 * a desk or a letter, else a speech bubble. The words stay beside it, so the
 * icon is never the only signal.
 *
 * @param {string} channel The channel, in any words ("Telefoon", "portal").
 * @return {string} An SVG path for a 24px box.
 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
 */
export function channelIcon(channel) {
	const words = String(channel || '').toLowerCase()
	if (/telefo|phone|bel/.test(words)) {
		return 'M6.62,10.79C8.06,13.62 10.38,15.94 13.21,17.38L15.41,15.18C15.69,14.9 16.08,14.82 16.43,14.93C17.55,15.3 18.75,15.5 20,15.5A1,1 0 0,1 21,16.5V20A1,1 0 0,1 20,21A17,17 0 0,1 3,4A1,1 0 0,1 4,3H7.5A1,1 0 0,1 8.5,4C8.5,5.25 8.7,6.45 9.07,7.57C9.18,7.92 9.1,8.31 8.82,8.59L6.62,10.79Z'
	}
	if (/balie|desk|loket|counter/.test(words)) {
		return 'M20,6H16V4A2,2 0 0,0 14,2H10A2,2 0 0,0 8,4V6H4A2,2 0 0,0 2,8V19A2,2 0 0,0 4,21H20A2,2 0 0,0 22,19V8A2,2 0 0,0 20,6M10,4H14V6H10V4Z'
	}
	if (/mail|brief|letter|post/.test(words)) {
		return 'M20,8L12,13L4,8V6L12,11L20,6M20,4H4C2.89,4 2,4.89 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V6C22,4.89 21.1,4 20,4Z'
	}
	return 'M20,2H4A2,2 0 0,0 2,4V22L6,18H20A2,2 0 0,0 22,16V4A2,2 0 0,0 20,2M6,9H18V11H6M14,14H6V12H14M18,8H6V6H18'
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
