/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The person the designed header's chip shows. Its own module, imported only
 * by the lazily loaded HeaderTools, so the site's entry does not carry it.
 */

/**
 * The signed-in person as the header chip shows them: the name, its
 * initials, and a second line (the organisation they act for) when the
 * session carries one. Null when there is no name to show.
 *
 * @param {object|null} session The portal session.
 * @return {{name: string, initials: string, subline: string}|null} The person.
 *
 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-header-must-carry-the-search-box-and-one-way-to-the-own-area
 */
export function personOf(session) {
	const name = String(
		(session && (session.displayName || session.name)) || '',
	).trim()
	if (
		name === ''
		|| name === String(session.subjectRef || '')
		|| /^\d+$/.test(name)
	) {
		return null
	}
	const words = name.split(/\s+/)
	const initials =
		words[0][0] + (words.length > 1 ? words[words.length - 1][0] : '')
	return {
		name,
		initials: initials.toUpperCase(),
		subline: String(session.organisationName || ''),
	}
}
