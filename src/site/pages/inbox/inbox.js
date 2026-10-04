/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The plain logic of the site's inbox: row ids, dates, the read toggle and
 * the two ways out of a message (open the record it is about, view the task
 * it asks for). The same behaviour as the React portal's InboxPage.jsx and
 * App.jsx, without a framework, so node can test it. The unread count is the
 * page's own, from its rows (src/shared/inboxUnread.js).
 */

import {
	navKeyFor,
	OPEN_STORAGE_KEY,
	opensAsRecordPage,
	parseOpenFragment,
} from '../../../shared/openRecord.js'
import { routeForNav } from '../../../shared/portalNav.js'

/** The site route of "My tasks" in the signed-in area, as the shell builds it. */
export const TASKS_ROUTE = routeForNav({ special: 'tasks' })

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
 * The site route of the page that shows a message's record, or null when
 * none of the resident's pages shows that collection. The route is the
 * shell's own (src/shared/portalNav.js `routeForNav`).
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
	// A record page opens on that record's route (REQ-SMO-010).
	if (link?.id && opensAsRecordPage(entry, link.collection)) {
		return `${routeForNav(entry)}/${encodeURIComponent(link.id)}`
	}
	return routeForNav(entry)
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

/**
 * The files that came with a message: the `_files` the server listed for an
 * inbox collection that declares `filesDownload`, each with an id to fetch.
 *
 * @param {object} message The message.
 * @return {Array<{id: (string|number), name: string, size?: number}>} The files, or `[]`.
 * @spec openspec/changes/inbox-reply-with-attachments/specs/portal-inbox-reply/spec.md#requirement-files-that-came-with-a-message-open-req-ira-004
 */
export function attachmentsOf(message) {
	const files = Array.isArray(message?._files) ? message._files : []
	return files.filter(
		(file) =>
			file
			&& (typeof file.id === 'string' || typeof file.id === 'number')
			&& String(file.id) !== '',
	)
}

/**
 * The collection a message's download goes through: its own source, so the
 * server proves the message is the resident's before it serves a byte.
 *
 * @param {object} message The message.
 * @return {{id: string, register: string, schema: string}|null} The collection, or null without a source.
 * @spec openspec/changes/inbox-reply-with-attachments/specs/portal-inbox-reply/spec.md#requirement-files-that-came-with-a-message-open-req-ira-004
 */
export function downloadCollection(message) {
	const source = message?._source || {}
	if (!source.register || !source.schema) {
		return null
	}
	return {
		id: source.collection || '',
		register: source.register,
		schema: source.schema,
	}
}

/** A web address in a message body, up to the first white space. */
const URL_PATTERN = /https?:\/\/\S+/g

/** The site's own address, the part every link into it shares. */
const SITE_PATH = /\/apps\/portaliq\/site\/?$/

/** Punctuation that closes a sentence after an address, not part of it. */
const TRAILING_PUNCTUATION = /[.,;:!?)\]]+$/

/**
 * A route as one comparable string: decoded, without a trailing slash.
 *
 * @param {string} route A site route.
 * @return {string} The route to compare.
 */
function comparableRoute(route) {
	let value = String(route || '')
	try {
		value = decodeURIComponent(value)
	} catch {
		// A route that does not decode compares as written.
	}
	return value.replace(/\/+$/, '')
}

/**
 * Whether a web address leads where the row's "Open" button leads: a link
 * into this site naming the same record (`#open=<app>/<collection>/<id>`) or
 * the same route (`?route=<route>`). Anything else is a different place.
 *
 * @param {string} url The address in the text.
 * @param {{app: string, collection: string, id: string}} link The row's record link.
 * @param {string} openRoute The route "Open" navigates to.
 * @return {boolean}
 */
function leadsWhereOpenLeads(url, link, openRoute) {
	let parsed
	try {
		parsed = new URL(url)
	} catch {
		return false
	}
	if (!SITE_PATH.test(parsed.pathname)) {
		return false
	}
	const record = parseOpenFragment(parsed.hash)
	if (record) {
		return (
			record.app === link.app
			&& record.collection === link.collection
			&& record.id === String(link.id)
		)
	}
	const route = parsed.searchParams.get('route')
	return Boolean(route) && comparableRoute(route) === comparableRoute(openRoute)
}

/**
 * One line of a body without the addresses that lead where "Open" leads.
 *
 * The words that only introduce such an address go with it: "Lees het
 * antwoord hier: <url>" goes as a whole, from the start of its sentence. In a
 * list line ("- Titel: <url>") only ": <url>" goes, so the title stays.
 *
 * @param {string} line One line of the body.
 * @param {(url: string) => boolean} isOpenTarget Whether an address leads where "Open" leads.
 * @return {{line: string, changed: boolean}} The line, and whether anything went.
 */
function lineWithoutOpenLinks(line, isOpenTarget) {
	const listItem = /^\s*[-*\u2022]\s/.test(line)
	const found = [...line.matchAll(URL_PATTERN)].reverse()
	let result = line
	let changed = false
	for (const match of found) {
		const url = match[0].replace(TRAILING_PUNCTUATION, '')
		if (!isOpenTarget(url)) {
			continue
		}
		let before = result.slice(0, match.index).trimEnd()
		let after = result.slice(match.index + url.length)
		if (before.endsWith(':')) {
			before = before.slice(0, -1)
			if (!listItem) {
				// Keep what ends a sentence before the lead-in, drop the rest.
				const ends = [...before.matchAll(/[.!?]["'\u201d)]?\s+/g)]
				const last = ends[ends.length - 1]
				before = last ? before.slice(0, last.index + last[0].length) : ''
			}
		}
		if (/^[.,;:!?]*\s*$/.test(after) && before.trim() === '') {
			after = ''
		}
		result = (before.trimEnd() + after).trimEnd()
		changed = true
	}
	return { line: result, changed }
}

/**
 * A message body without the web addresses that lead where the row's
 * "Open" button leads.
 *
 * The apps that write a notice keep the address in the text on purpose: the
 * same text is the e-mail. In the inbox the row already has "Open" to that
 * place, so the bare address, and the words that only introduce it, go. An
 * address that leads anywhere else stays, and so does every address of a row
 * without "Open".
 *
 * @param {string} body The message body.
 * @param {{app: string, collection: string, id: string}|null} link The row's record link.
 * @param {string|null} openRoute The route "Open" navigates to, or null without the button.
 * @return {string} The body to show.
 * @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-the-inbox-does-not-repeat-the-open-link-in-the-text-req-nap-012
 */
export function bodyWithoutOpenLink(body, link, openRoute) {
	if (typeof body !== 'string' || body === '' || !link?.id || !openRoute) {
		return typeof body === 'string' ? body : ''
	}
	const isOpenTarget = (url) => leadsWhereOpenLeads(url, link, openRoute)
	const lines = []
	for (const line of body.split('\n')) {
		const shown = lineWithoutOpenLinks(line, isOpenTarget)
		// A line that held only the address and its lead-in goes as a whole.
		if (shown.changed && shown.line.trim() === '') {
			continue
		}
		lines.push(shown.line)
	}
	return lines
		.join('\n')
		.replace(/\n{3,}/g, '\n\n')
		.trim()
}
