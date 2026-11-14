/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The person the designed header's chip shows. Its own module, imported only
 * by the lazily loaded HeaderTools, so the site's entry does not carry it.
 */

/**
 * Whether a name is one to show: not empty, not the subject reference, not
 * only digits (a BSN or KvK number is no name).
 *
 * @param {string} name The trimmed name.
 * @param {object} session The portal session.
 * @return {boolean} True when the chip may show it.
 */
function isShownName(name, session) {
	return name !== '' && name !== String(session.subjectRef || '') && !/^\d+$/.test(name)
}

/**
 * The initials of a name: its first word's letter and its last word's.
 *
 * @param {string} name The trimmed name.
 * @return {string} The initials, in capitals.
 */
function initialsOf(name) {
	const words = name.split(/\s+/)
	const last = words.length > 1 ? words[words.length - 1][0] : ''
	return (words[0][0] + last).toUpperCase()
}

/**
 * The signed-in person as the header chip shows them: the name, its
 * initials, and a second line (the organisation they act for) when the
 * session carries one. Null when there is no name to show.
 *
 * @param {object|null} session The portal session.
 * @return {{name: string, initials: string, subline: string}|null} The person.
 *
 * @spec openspec/specs/site-chrome/spec.md#requirement-the-header-must-carry-the-search-box-and-one-way-to-the-own-area
 */
export function personOf(session) {
	const name = String(
		(session && (session.displayName || session.name)) || '',
	).trim()
	if (!isShownName(name, session)) {
		return null
	}
	return {
		name,
		initials: initialsOf(name),
		subline: String(session.organisationName || ''),
	}
}
