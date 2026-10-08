// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The engine behind the search suggestions (search-suggestions-while-typing):
// when to ask, what to ask, what comes back and how the keyboard moves
// through it. Imports nothing from the framework or from Nextcloud, so a node
// test drives it with a fake clock and a fake fetch.
//
// Portaliq adds no suggestion endpoint and no index of its own (ADR-022): the
// question goes to the federation endpoint the search block already uses, so
// the suggestions honour the same publication visibility as the search.
//
// @spec openspec/changes/search-suggestions-while-typing/specs/portaliq-cms/spec.md#requirement-the-search-box-suggests-publications-while-you-type-req-sst-001

import { resultKind } from './federatedSearch.js'

/** Characters needed before the first question. */
export const MIN_CHARS = 3
/** Quiet time after the last keystroke, in milliseconds. */
export const DEBOUNCE_MS = 250
/** A request slower than this shows no list, in milliseconds. */
export const TIMEOUT_MS = 1000
/** Suggestions shown at most. */
export const LIMIT = 5

/** The federation endpoint the search block already uses. */
export const DEFAULT_ENDPOINT =
	'/index.php/apps/opencatalogi/api/federation/publications'

/**
 * The request for one typed text: the text, five rows and only the fields a
 * suggestion shows.
 *
 * @param {object} args The arguments.
 * @param {string} args.endpoint Endpoint path or absolute URL.
 * @param {string} args.origin Origin to resolve a relative endpoint against.
 * @param {string} args.query The typed text.
 * @return {string} The absolute request URL.
 */
export function suggestionUrl({ endpoint, origin, query }) {
	const url = new URL(endpoint || DEFAULT_ENDPOINT, origin)
	url.searchParams.set('_search', query)
	url.searchParams.set('_limit', String(LIMIT))
	url.searchParams.set('_page', '1')
	url.searchParams.set('_fields', 'name,title,resultType,@self')
	return url.toString()
}

/**
 * The suggestions in an answer: at most five, each with a title, a kind and an
 * id to open. A row with no title or no id is left out.
 *
 * @param {object} body The endpoint's answer.
 * @return {Array<{id: string, title: string, kind: string}>} The suggestions.
 */
export function toSuggestions(body) {
	const rows = Array.isArray(body?.results) ? body.results : []
	const out = []
	for (const row of rows) {
		const self = row?.['@self'] || {}
		const id = String(self.id || row?.id || '')
		const title = String(row?.name || row?.title || self.name || '').trim()
		if (id !== '' && title !== '') {
			out.push({ id, title, kind: resultKind(row) })
		}
		if (out.length === LIMIT) {
			break
		}
	}
	return out
}

/**
 * The site route a suggestion opens: a document has its own page, anything
 * else is a publication.
 *
 * @param {{id: string, kind: string}} item The suggestion.
 * @param {{detail?: string, document?: string}} routes The routes, ids appended.
 * @return {string} The route.
 */
export function suggestionRoute(item, routes = {}) {
	const base =
		item.kind === 'document'
			? routes.document || '/document'
			: routes.detail || '/publicatie'
	return `${base}/${item.id}`
}

/**
 * A title split into the part the visitor typed (shown bold) and the rest.
 *
 * @param {string} title The title.
 * @param {string} query The typed text.
 * @return {{typed: string, rest: string}} The two parts.
 */
export function typedPart(title, query) {
	const text = String(title)
	const typed = String(query).trim()
	if (typed !== '' && text.toLowerCase().startsWith(typed.toLowerCase())) {
		return { typed: text.slice(0, typed.length), rest: text.slice(typed.length) }
	}
	return { typed: '', rest: text }
}

/**
 * The active suggestion after a key. `null` for a key the list does not use.
 * No row is active when the list opens (index -1), so Enter still runs the
 * search until the visitor has picked one with the arrow keys: Down twice and
 * Enter opens the second suggestion (REQ-SST-002). Down on the last and Up on
 * the first stay where they are; Up with none active goes to the last.
 *
 * @param {number} index The active index.
 * @param {number} count How many suggestions.
 * @param {string} key The key.
 * @return {number|null} The new index, or null.
 */
export function moveActive(index, count, key) {
	if (count < 1) {
		return null
	}
	if (key === 'ArrowDown') {
		return Math.min(count - 1, index + 1)
	}
	if (key === 'ArrowUp') {
		return index < 0 ? count - 1 : Math.max(0, index - 1)
	}
	return null
}

/**
 * The announcement when the list opens.
 *
 * @param {number} count How many suggestions.
 * @param {string} many The pattern for several, with `{n}`.
 * @param {string} one The text for one.
 * @return {string} The sentence.
 */
export function announcement(count, many = '{n} suggesties', one = '1 suggestie') {
	return count === 1 ? one : many.replace('{n}', String(count))
}

/**
 * A suggester: call `input(text)` on every change; `onChange` hears the list
 * (empty to close it). Below three characters nothing is asked and the list
 * closes. A newer keystroke aborts the older request. A failed or slow request
 * gives an empty list and no error: searching keeps working.
 *
 * @param {object} options The options.
 * @param {string} options.endpoint The federation endpoint.
 * @param {string} options.origin The origin to resolve it against.
 * @param {(list: Array<object>, query: string) => void} options.onChange Hears each list.
 * @param {Function} [options.fetchImpl] fetch, for a test.
 * @param {Function} [options.setTimer] setTimeout, for a test.
 * @param {Function} [options.clearTimer] clearTimeout, for a test.
 * @return {{input: Function, close: Function}} The suggester.
 */
export function createSuggester({
	endpoint,
	origin,
	onChange,
	fetchImpl = (...args) => globalThis.fetch(...args),
	setTimer = (fn, ms) => setTimeout(fn, ms),
	clearTimer = (id) => clearTimeout(id),
}) {
	let debounce = null
	let controller = null
	let sequence = 0

	const cancel = () => {
		sequence++
		if (debounce !== null) {
			clearTimer(debounce)
			debounce = null
		}
		if (controller !== null) {
			controller.abort()
			controller = null
		}
	}

	const ask = async (query, mine) => {
		const local = new AbortController()
		controller = local
		const timer = setTimer(() => local.abort(), TIMEOUT_MS)
		try {
			const res = await fetchImpl(suggestionUrl({ endpoint, origin, query }), {
				headers: { Accept: 'application/json' },
				signal: local.signal,
			})
			if (!res.ok) {
				throw new Error('refused')
			}
			const list = toSuggestions(await res.json())
			if (mine === sequence) {
				onChange(list, query)
			}
		} catch {
			// Failed, refused or too slow: no list, no error.
			if (mine === sequence) {
				onChange([], query)
			}
		} finally {
			clearTimer(timer)
		}
	}

	return {
		input(text) {
			cancel()
			const query = String(text ?? '').trim()
			if (query.length < MIN_CHARS) {
				onChange([], query)
				return
			}
			const mine = sequence
			debounce = setTimer(() => {
				debounce = null
				ask(query, mine)
			}, DEBOUNCE_MS)
		},
		close() {
			cancel()
			onChange([], '')
		},
	}
}
