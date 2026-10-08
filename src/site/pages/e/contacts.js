// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The rules of the contacts page (own-contacts-and-invitations). Imports
// nothing, so the node specs run it as a plain script.

/** The role chips, in the order the board shows them. */
export const ROLE_FILTERS = ['all', 'begeleider', 'contact', 'organisatie']

/**
 * The English source string of a role's label.
 *
 * @param {string} role The role.
 * @return {string} The label key.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
 */
export function roleLabel(role) {
	const labels = {
		all: 'All',
		begeleider: 'Guide',
		contact: 'Contact',
		organisatie: 'Organisation',
	}
	return labels[role] || 'Contact'
}

/**
 * The contacts of one role chip; `all` keeps every contact.
 *
 * @param {Array<object>} contacts The approved contacts.
 * @param {string} role The chip.
 * @return {Array<object>} The contacts to list.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
 */
export function contactsOfRole(contacts, role) {
	const all = Array.isArray(contacts) ? contacts : []
	return role === 'all' ? all : all.filter((contact) => contact.role === role)
}

/**
 * The initials shown in a contact's circle: at most two letters.
 *
 * @param {string} name The display name.
 * @return {string} The initials, upper case, or '?'.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
 */
export function initialsOf(name) {
	const words = String(name || '')
		.trim()
		.split(/\s+/)
		.filter((word) => /^\p{L}/u.test(word))
	if (words.length === 0) {
		return '?'
	}
	const letters = words.length === 1 ? [words[0]] : [words[0], words[words.length - 1]]
	return letters.map((word) => word.charAt(0).toUpperCase()).join('')
}

/**
 * What to tell a resident whose invitation was refused, as an English source
 * key. Anything unexpected reads as a general failure.
 *
 * @param {{status?: number, error?: string}} answer The answer of the invite call.
 * @return {string} The sentence key.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
 */
export function inviteProblem(answer) {
	const reasons = {
		invalid: 'Fill in a valid e-mail address.',
		duplicate: 'You have already invited this person, or you are already connected.',
		limit: 'You sent the most invitations for today. Try again tomorrow.',
	}
	return reasons[answer?.error] || 'That did not work. Try again later.'
}
