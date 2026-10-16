// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The plain logic of the mijn omgeving action rows: the words of a deadline
// badge, the rows of a `tasks` block and of an `inbox` block, and the
// translator these components share. No Vue, so tests/mijn-components.spec.mjs
// runs it as node.
//
// @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004

import strings from './strings.js'

/** From this many days out a deadline counts down in days. */
export const COUNTDOWN_DAYS = 7

/** How many rows a list block shows when it declares no limit. */
export const DEFAULT_LIMIT = 5

const DAY_MS = 24 * 60 * 60 * 1000

/**
 * A translator that falls back to this folder's strings.
 *
 * @param {((key: string) => string)|null} t The site's translator.
 * @param {string} [locale] The page language.
 * @return {(key: string, vars?: object) => string}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
 */
export function mijnTranslator(t, locale) {
	const own = strings[pageLanguage(locale)] || strings.en
	return function translate(key, vars) {
		const site = typeof t === 'function' ? t(key) : key
		let text = site !== key || !Object.hasOwn(own, key) ? site : own[key]
		for (const [name, value] of Object.entries(vars || {})) {
			text = String(text).split(`{${name}}`).join(String(value))
		}
		return String(text)
	}
}

/**
 * The page language: `en` or `nl`.
 *
 * @param {string} [locale] The locale handed in.
 * @return {string}
 */
function pageLanguage(locale) {
	return String(locale || 'nl')
		.toLowerCase()
		.startsWith('en')
		? 'en'
		: 'nl'
}

/**
 * A date at local midnight, or null when the value is no date.
 *
 * @param {string|Date} value The value.
 * @return {Date|null}
 */
function localDay(value) {
	const date = value instanceof Date ? value : new Date(value)
	if (!value || Number.isNaN(date.getTime())) {
		return null
	}
	return new Date(date.getFullYear(), date.getMonth(), date.getDate())
}

/**
 * A day in words: "12 oktober", with the year when it is not this year's.
 *
 * @param {Date} day The day.
 * @param {Date} today Today.
 * @param {string} locale The page language.
 * @return {string}
 */
function dayInWords(day, today, locale) {
	const options = { day: 'numeric', month: 'long' }
	if (day.getFullYear() !== today.getFullYear()) {
		options.year = 'numeric'
	}
	return day.toLocaleDateString(
		pageLanguage(locale) === 'en' ? 'en-GB' : 'nl-NL',
		options,
	)
}

/**
 * The badge of a deadline, in words: "Voor 12 oktober"; from seven days out
 * "Nog 3 dagen"; "Vandaag" on the day; "Verlopen" after it. The state gives
 * the colour, the text carries the meaning.
 *
 * @param {string|Date} due The deadline.
 * @param {Date} today Today.
 * @param {(key: string, vars?: object) => string} tr The translator.
 * @param {string} [locale] The page language.
 * @return {{text: string, state: string, datetime: string}|null} The badge, or null without a deadline.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
 */
export function deadlineBadge(due, today, tr, locale = 'nl') {
	const day = localDay(due)
	const now = localDay(today || new Date())
	if (!day || !now) {
		return null
	}
	const datetime = [
		day.getFullYear(),
		String(day.getMonth() + 1).padStart(2, '0'),
		String(day.getDate()).padStart(2, '0'),
	].join('-')
	const days = Math.round((day.getTime() - now.getTime()) / DAY_MS)
	if (days < 0) {
		return { text: tr('Overdue'), state: 'error', datetime }
	}
	if (days === 0) {
		return { text: tr('Due today'), state: 'warning', datetime }
	}
	if (days === 1) {
		return { text: tr('1 day left'), state: 'warning', datetime }
	}
	if (days <= COUNTDOWN_DAYS) {
		return {
			text: tr('{count} days left', { count: days }),
			state: 'warning',
			datetime,
		}
	}
	return {
		text: tr('Before {date}', { date: dayInWords(day, now, locale) }),
		state: 'neutral',
		datetime,
	}
}

/**
 * The id of a row.
 *
 * @param {object} row The row.
 * @return {string}
 */
function idOf(row) {
	return String(row?.id || row?.uuid || row?.['@self']?.id || '')
}

/**
 * The block's limit, or the default.
 *
 * @param {object} block The block.
 * @return {number}
 */
function limitOf(block) {
	const limit = Number(block?.limit)
	return Number.isInteger(limit) && limit >= 1 ? limit : DEFAULT_LIMIT
}

/**
 * A sentence with `{field}` places filled from a row, or '' when a place
 * stays empty, so a half sentence never shows (lookup-by-row-field).
 *
 * @param {string|undefined} template The block's `titleTemplate`.
 * @param {object} row The row, with its looked-up values.
 * @return {string} The sentence, or ''.
 * @spec openspec/changes/lookup-by-row-field/specs/portal-contribution-contract/spec.md#requirement-a-task-may-be-titled-by-a-sentence-with-fields
 */
export function filledTemplate(template, row) {
	if (typeof template !== 'string' || template === '') {
		return ''
	}
	let empty = false
	const out = template.replace(/\{([A-Za-z_][A-Za-z0-9_]*)\}/g, (match, name) => {
		const value = row?.[name]
		const text =
			typeof value === 'string' || typeof value === 'number'
				? String(value).trim()
				: ''
		if (text === '') {
			empty = true
		}
		return text
	})
	return empty ? '' : out
}

/**
 * The rows of a `tasks` block: titled by its title fields, soonest deadline
 * first, rows without a deadline after them, at most its limit.
 *
 * @param {Array<object>} rows The collection's rows, already scoped to the resident.
 * @param {object} block The block (`dueField`, `titleFields`, `limit`).
 * @param {object} collection The collection (`titleFields`, `label`).
 * @return {Array<{id: string, title: string, due: string, row: object}>}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
 */
export function taskRows(rows, block, collection) {
	const titleFields = (Array.isArray(block?.titleFields)
	&& block.titleFields.length > 0
		? block.titleFields
		: collection?.titleFields) || ['title', 'name', 'subject', 'onderwerp']
	const dueField = block?.dueField || ''
	// A row whose lookup value is listed in excludeWhen is not a task: work
	// already handed in (REQ-SMO-025).
	const exclude = block?.excludeWhen
	const lookup = (block?.lookups || []).find(
		(candidate) => candidate?.as === exclude?.lookup,
	)
	const left = (row) =>
		!exclude
		|| !lookup
		|| !(exclude.in || []).map(String).includes(String(row?.[lookup.as] ?? ''))
	const list = (Array.isArray(rows) ? rows : [])
		.filter(Boolean)
		.filter(left)
		.map((row) => ({
			id: idOf(row),
			title:
				filledTemplate(block?.titleTemplate, row)
				|| titleFields
					.map((field) => row[field])
					.filter(
						(value) => typeof value === 'string' && value.trim() !== '',
					)
					.join(' ')
				|| collection?.label
				|| '',
			due: dueField && row[dueField] ? String(row[dueField]) : '',
			row,
		}))
	const time = (entry) => {
		const ms = entry.due ? new Date(entry.due).getTime() : Number.NaN
		return Number.isNaN(ms) ? Number.POSITIVE_INFINITY : ms
	}
	return list
		.map((entry, index) => ({ entry, index }))
		.sort((a, b) => time(a.entry) - time(b.entry) || a.index - b.index)
		.map(({ entry }) => entry)
		.slice(0, limitOf(block))
}

/**
 * The rows of an `inbox` block: the messages of its collection, or of every
 * inbox; unread first, then newest first; at most its limit.
 *
 * @param {Array<object>} messages The unified inbox, each with its `_source`.
 * @param {object} block The block (`collection`, `limit`).
 * @param {string} app The app of the contribution the block belongs to.
 * @return {Array<object>}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
 */
export function inboxRows(messages, block, app) {
	const wanted = block?.collection || ''
	const time = (message) => {
		const ms = new Date(message?.receivedAt || 0).getTime()
		return Number.isNaN(ms) ? 0 : ms
	}
	return (Array.isArray(messages) ? messages : [])
		.filter(Boolean)
		.filter(
			(message) =>
				!wanted
				|| (message._source?.collection === wanted
					&& message._source?.appId === app),
		)
		.map((message, index) => ({ message, index }))
		.sort(
			(a, b) =>
				Number(a.message.read === true) - Number(b.message.read === true)
				|| time(b.message) - time(a.message)
				|| a.index - b.index,
		)
		.map(({ message }) => message)
		.slice(0, limitOf(block))
}

/**
 * When a message came in, in words: "Vandaag om 14.20 uur", else the date
 * and time.
 *
 * @param {string} value An ISO date-time.
 * @param {Date} today Today.
 * @param {(key: string, vars?: object) => string} tr The translator.
 * @param {string} [locale] The page language.
 * @return {string}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
 */
export function receivedInWords(value, today, tr, locale = 'nl') {
	const at = value ? new Date(value) : null
	const day = localDay(value)
	const now = localDay(today || new Date())
	if (!at || !day || !now) {
		return ''
	}
	const english = pageLanguage(locale) === 'en'
	const time = at
		.toLocaleTimeString(english ? 'en-GB' : 'nl-NL', {
			hour: '2-digit',
			minute: '2-digit',
		})
		.replace(':', english ? ':' : '.')
	if (day.getTime() === now.getTime()) {
		return tr('Today at {time}', { time })
	}
	return tr('{date} at {time}', { date: dayInWords(day, now, locale), time })
}

/**
 * A real address for an in-site route, so a link opens in a new tab and works
 * without the bundle; the route itself outside a browser.
 *
 * @param {string} route The in-site route.
 * @return {string}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
 */
export function siteHref(route) {
	if (!route) {
		return ''
	}
	try {
		const url = new URL(window.location.href)
		// As App.vue's hrefForRoute: the portal the page is served as stays
		// on the address, or a new tab opens another portal.
		const portal = url.searchParams.get('portal')
		url.search = ''
		url.hash = ''
		if (portal) {
			url.searchParams.set('portal', portal)
		}
		url.searchParams.set('route', route)
		return url.toString()
	} catch {
		return route
	}
}
