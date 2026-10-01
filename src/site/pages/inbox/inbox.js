/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The plain logic of the site's inbox: row ids, dates, the read toggle, the
 * unread count the menu shows, and the two ways out of a message (open the
 * record it is about, view the task it asks for). The same behaviour as the
 * React portal's InboxPage.jsx and App.jsx, without a framework, so node can
 * test it.
 */

import { navKeyFor, OPEN_STORAGE_KEY } from '../../../shared/openRecord.js'

/** The site route of "My tasks" in the signed-in area. */
export const TASKS_ROUTE = '/mijn/tasks'

/** Where the inbox leaves the task "My tasks" opens on arrival. */
export const TASK_STORAGE_KEY = 'portaliq.openTask'

/**
 * A row's id: its own, else its `@self` id, else the fallback.
 *
 * @param {object} row The row.
 * @param {string|number|null} [fallback] Used when the row has no id.
 * @return {string|number|null} The id.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
 */
export function rowId(row, fallback = null) {
	return row?.id || row?.['@self']?.id || fallback
}

/**
 * A date and time in the page language, '' when absent.
 *
 * @param {string} value An ISO date-time.
 * @param {string} locale `nl` or `en`.
 * @return {string} The formatted value.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
 */
export function formatDateTime(value, locale) {
	if (!value) {
		return ''
	}
	const date = new Date(value)
	if (Number.isNaN(date.getTime())) {
		return String(value)
	}
	return date.toLocaleString(locale === 'en' ? 'en-GB' : 'nl-NL')
}

/**
 * A date in the page language, '' when absent.
 *
 * @param {string} value An ISO date-time.
 * @param {string} locale `nl` or `en`.
 * @return {string} The formatted date.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
 */
export function formatDate(value, locale) {
	if (!value) {
		return ''
	}
	const date = new Date(value)
	if (Number.isNaN(date.getTime())) {
		return String(value)
	}
	return date.toLocaleDateString(locale === 'en' ? 'en-GB' : 'nl-NL')
}

/**
 * Whether a message carries any of the optional readiness fields.
 *
 * @param {object} message The message.
 * @return {boolean}
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
 */
export function hasReadiness(message) {
	return Boolean(message?.nature || message?.rechtsgevolg || message?.term)
}

/**
 * The messages with one marked read, the rest untouched.
 *
 * @param {Array<object>} messages The messages.
 * @param {string|number} id The id of the message the server marked read.
 * @return {Array<object>} The new list.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
 */
export function markedRead(messages, id) {
	return (messages || []).map((m) => (rowId(m) === id ? { ...m, read: true } : m))
}

/**
 * The unread count after one message was read: never below zero.
 *
 * @param {number|null|undefined} count The count before.
 * @return {number} The count after.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
 */
export function unreadAfterRead(count) {
	const n = Number(count)
	return Number.isFinite(n) ? Math.max(0, n - 1) : 0
}

/**
 * The site route of the page that shows a message's record, or null when
 * none of the resident's pages shows that collection. The same route the
 * signed-in area gives a contribution page: `/mijn/<app>/<page id>`.
 *
 * @param {Array<object>} nav The navigation entries.
 * @param {{app: string, collection: string, id: string}} link The record link.
 * @return {string|null} The route.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
 */
export function recordRoute(nav, link) {
	const key = navKeyFor(nav, link)
	const entry = key ? (nav || []).find((n) => n.key === key) : null
	if (!entry) {
		return null
	}
	const app = encodeURIComponent(entry.contribution?.app || '')
	const page = encodeURIComponent(entry.page?.id || '')
	return `/mijn/${app}/${page}`
}

/**
 * Keep the record to open, as a link from an e-mail does, so the page that
 * shows it preselects the row.
 *
 * @param {object|null} storage sessionStorage.
 * @param {{app: string, collection: string, id: string}} link The record link.
 * @return {void}
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
 */
export function keepRecordToOpen(storage, link) {
	try {
		storage?.setItem(
			OPEN_STORAGE_KEY,
			JSON.stringify({
				app: link.app,
				collection: link.collection,
				id: link.id,
			}),
		)
	} catch {
		// Without storage the page opens without a preselected row.
	}
}

/**
 * Keep the task "My tasks" opens on arrival.
 *
 * @param {object|null} storage sessionStorage.
 * @param {string} uuid The task uuid.
 * @return {void}
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
 */
export function keepTaskToOpen(storage, uuid) {
	try {
		storage?.setItem(TASK_STORAGE_KEY, String(uuid))
	} catch {
		// Without storage "My tasks" opens on its list.
	}
}

/**
 * Take the task to open, once: it is forgotten as it is read.
 *
 * @param {object|null} storage sessionStorage.
 * @return {string|null} The task uuid.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
 */
export function takeTaskToOpen(storage) {
	try {
		const uuid = storage?.getItem(TASK_STORAGE_KEY) || null
		storage?.removeItem(TASK_STORAGE_KEY)
		return uuid
	} catch {
		return null
	}
}

/**
 * The browser's sessionStorage, or null where it is blocked.
 *
 * @return {object|null} The storage.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
 */
export function sessionStore() {
	try {
		return typeof window !== 'undefined' ? window.sessionStorage : null
	} catch {
		return null
	}
}
