// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The words a greeting says (site-school-blocks): by the hour, with the
// first name from the session when there is a real one. A name that is a
// number or the subject reference is no name. Imports nothing, so node tests
// it.
//
// @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-greeting-block-opens-the-overview

/**
 * The first name the session gives, or ''.
 *
 * @param {object|null} session The session.
 * @return {string} The first name.
 */
export function firstNameOf(session) {
	const name = String(session?.displayName || session?.name || '').trim()
	const first = name.split(/\s+/)[0] || ''
	return first === '' || /^\d+$/.test(first) || name === session?.subjectRef
		? ''
		: first
}

/**
 * "Good morning, Fatima" before noon, afternoon until six, evening after.
 *
 * @param {object|null} session The session.
 * @param {Date} now The moment.
 * @param {(key: string, vars?: object) => string} tr The translator.
 * @return {string} The greeting.
 */
export function greetingFor(session, now, tr) {
	const hour = now.getHours()
	const part = hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening'
	const name = firstNameOf(session)
	const key = {
		morning: 'Good morning',
		afternoon: 'Good afternoon',
		evening: 'Good evening',
	}[part]
	return name ? tr(`${key}, {name}`, { name }) : tr(key)
}
