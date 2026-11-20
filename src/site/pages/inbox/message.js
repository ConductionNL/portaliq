/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The plain logic of one message on its own page (a-resident-opens-one-message):
 * the route a message opens on, the inbox collection that route names, the
 * rows of "Over dit bericht", the attachment line and the mark-read on open.
 * No framework, so node can test it.
 */

import { routeForNav } from '../../../shared/portalNav.js'
import { formatDateTime, rowId } from './inbox.js'

/** The inbox page's route, as the shell builds it. */
export const INBOX_ROUTE = routeForNav({ special: 'inbox' })

/**
 * Portaliq's own notices: an inbox source of every resident, declared or not
 * (PortalInboxReader::OWN_MESSAGES).
 */
const OWN_SOURCE = { collection: 'portalMessages', register: 'portaliq', schema: 'portalMessage' }

/**
 * The route a message opens on: `/mijn/<inbox page>/<source>/<id>`, or ''
 * when the message names no source or id.
 *
 * @param {object} message An inbox row with its `_source`.
 * @param {string} [inboxRoute] The inbox page's route.
 * @return {string} The route.
 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T05
 */
export function messageRoute(message, inboxRoute = INBOX_ROUTE) {
	const source = message?._source?.collection
	const id = rowId(message)
	if (typeof source !== 'string' || source === '' || id === null || id === '') {
		return ''
	}
	return `${inboxRoute}/${encodeURIComponent(source)}/${encodeURIComponent(String(id))}`
}

/**
 * The source and id a message route names, or null for any other route.
 *
 * @param {string} route The route on screen.
 * @param {string} [inboxRoute] The inbox page's route.
 * @return {{source: string, id: string}|null} The message the route opens.
 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T05
 */
export function messageOfRoute(route, inboxRoute = INBOX_ROUTE) {
	const value = String(route || '')
	if (!value.startsWith(`${inboxRoute}/`)) {
		return null
	}
	const parts = value.slice(inboxRoute.length + 1).split('/')
	if (parts.length !== 2 || parts[0] === '' || parts[1] === '') {
		return null
	}
	try {
		return { source: decodeURIComponent(parts[0]), id: decodeURIComponent(parts[1]) }
	} catch {
		return null
	}
}

/**
 * The inbox collection a source id names in the resident's own
 * contributions, as the message endpoint addresses it; null for a source the
 * resident has no inbox of.
 *
 * @param {object|null} contributions The contributions aggregate.
 * @param {string} sourceId The source id from the route.
 * @return {{collection: string, register: string, schema: string}|null} The collection.
 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T05
 */
export function inboxSource(contributions, sourceId) {
	if (typeof sourceId !== 'string' || sourceId === '') {
		return null
	}
	const list = Array.isArray(contributions?.contributions) ? contributions.contributions : []
	for (const contribution of list) {
		for (const collection of Array.isArray(contribution?.collections) ? contribution.collections : []) {
			if (
				collection?.kind === 'inbox'
				&& collection.id === sourceId
				&& collection.register
				&& collection.schema
			) {
				return { collection: sourceId, register: String(collection.register), schema: String(collection.schema) }
			}
		}
	}
	return sourceId === OWN_SOURCE.collection ? { ...OWN_SOURCE } : null
}

/**
 * The rows of "Over dit bericht": Van, Over zaak and Ontvangen, then Aard,
 * Rechtsgevolg and Termijn. A row without a value is left out.
 *
 * @param {object} message The message.
 * @param {(key: string) => string} tr The translator.
 * @param {string} locale `nl` or `en`.
 * @return {Array<{key: string, label: string, value: string}>} The rows.
 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T04
 */
export function aboutRows(message, tr, locale) {
	const rows = [
		{ key: 'from', label: tr('From'), value: senderOf(message) },
		{ key: 'case', label: tr('About case'), value: caseNumberOf(message) },
		{ key: 'received', label: tr('Received'), value: formatDateTime(message?.receivedAt, locale) },
		{ key: 'nature', label: tr('Nature'), value: textOf(message?.nature) },
		{ key: 'effect', label: tr('Legal effect'), value: textOf(message?.rechtsgevolg) },
		{ key: 'term', label: tr('Deadline'), value: termOf(message?.term, locale) },
	]
	return rows.filter((row) => row.value !== '')
}

/**
 * Who sent a message: its sender role, else its source's label.
 *
 * @param {object} message The message.
 * @return {string} The sender, or ''.
 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T04
 */
export function senderOf(message) {
	return textOf(message?.senderRole) || textOf(message?._source?.label)
}

/**
 * The number of the record a message is about: its `about` line, else the
 * record link's label.
 *
 * @param {object} message The message.
 * @return {string} The number, or ''.
 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T04
 */
export function caseNumberOf(message) {
	return textOf(message?.about) || textOf(message?.recordLink?.number)
}

/**
 * The day and the time a message came in, without seconds, for "Ontvangen
 * op {date} om {time}"; null when it does not say.
 *
 * @param {string} value An ISO date-time.
 * @param {string} locale `nl` or `en`.
 * @return {{date: string, time: string}|null} The parts.
 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T04
 */
export function receivedParts(value, locale) {
	const at = value ? new Date(value) : null
	if (!at || Number.isNaN(at.getTime())) {
		return null
	}
	const tag = locale === 'en' ? 'en-GB' : 'nl-NL'
	return {
		date: at.toLocaleDateString(tag, { day: 'numeric', month: 'long', year: 'numeric' }),
		time: at.toLocaleTimeString(tag, { hour: '2-digit', minute: '2-digit' }),
	}
}

/**
 * One attachment as the page names it: its type from the name's extension
 * and its size ("PDF · 412 kB"); the type alone without a size.
 *
 * @param {{name?: string, size?: number}} file The file.
 * @return {string} The line.
 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T04
 */
export function fileDetails(file) {
	const name = textOf(file?.name)
	const dot = name.lastIndexOf('.')
	const type = dot > 0 && dot < name.length - 1 ? name.slice(dot + 1).toUpperCase() : ''
	const size = Number(file?.size)
	const shown = Number.isFinite(size) && size > 0 ? sizeOf(size) : ''
	return [type, shown].filter(Boolean).join(' · ')
}

/**
 * A size in bytes as kB or MB, rounded.
 *
 * @param {number} bytes The size.
 * @return {string} The size.
 */
function sizeOf(bytes) {
	if (bytes >= 1000 * 1000) {
		return `${Math.round(bytes / 100000) / 10} MB`
	}
	return `${Math.max(1, Math.round(bytes / 1000))} kB`
}

/**
 * Mark a message read on open, once: an unread message through the existing
 * mark-read, a read one never. Answers whether it is read now.
 *
 * @param {object} api The portal API (`markMessageRead`).
 * @param {object} message The message.
 * @return {Promise<{read: boolean, marked: boolean}>} Read now, and whether this call marked it.
 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T07
 */
export async function markOnOpen(api, message) {
	if (!message || message.read === true) {
		return { read: Boolean(message), marked: false }
	}
	const result = await api.markMessageRead(message)
	return result?.ok ? { read: true, marked: true } : { read: false, marked: false }
}

/**
 * A value as trimmed text, '' when it is none.
 *
 * @param {unknown} value The value.
 * @return {string} The text.
 */
function textOf(value) {
	return typeof value === 'string' || typeof value === 'number' ? String(value).trim() : ''
}

/**
 * A deadline: an ISO date-time in the page language, plain text as it is.
 *
 * @param {unknown} value The deadline.
 * @param {string} locale `nl` or `en`.
 * @return {string} The deadline, or ''.
 */
function termOf(value, locale) {
	const text = textOf(value)
	// Only an ISO date is a moment; a sentence stays as written (a browser's
	// Date reads dates out of free text).
	if (!/^\d{4}-\d{2}-\d{2}/.test(text) || Number.isNaN(new Date(text).getTime())) {
		return text
	}
	return formatDateTime(text, locale)
}
