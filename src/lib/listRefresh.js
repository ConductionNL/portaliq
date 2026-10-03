/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Refresh an index page's list in place, on the page, sort and filters the
 * reader is looking at.
 *
 * A manifest handler (`Publish`, `Change`) gets no handle on the list it was
 * started from, so the News page reloaded the whole browser page after every
 * save. Filters, search and sort survive that (CnIndexPage keeps them in the
 * address), but the page number does not: staff working through page 3 were
 * sent back to page 1 after every publish.
 *
 * Every index page fetches through the shared object store's
 * `fetchCollection(type, params)`, so this module remembers the last params
 * per type and asks for the same list again. The page's rows and pagination
 * read from the store, so they update where they are.
 *
 * @spec openspec/changes/news-list-keeps-its-page/specs/admin-ui/spec.md#requirement-saving-from-a-list-must-keep-the-list-where-it-was
 */

/** The store this module watches, once recorded. */
let watched = null

/** The last params each type was fetched with. */
const lastParams = new Map()

/**
 * Start remembering what each list last fetched. Call once, after the app
 * has its store; a second call is ignored.
 *
 * @param {object} store The shared object store (`useObjectStore()`).
 * @return {void}
 * @spec openspec/changes/news-list-keeps-its-page/specs/admin-ui/spec.md#requirement-saving-from-a-list-must-keep-the-list-where-it-was
 */
export function recordListFetches(store) {
	if (watched !== null || !store || typeof store.$onAction !== 'function') {
		return
	}
	watched = store
	store.$onAction(({ name, args }) => {
		if (name === 'fetchCollection' && typeof args[0] === 'string') {
			lastParams.set(args[0], { ...(args[1] || {}) })
		}
	})
}

/**
 * Fetch a list again with the params it was last fetched with.
 *
 * @param {string} type The store type, `<register>-<schema>`.
 * @return {boolean} False when that list was never fetched here, so the
 *   caller can fall back to reloading the page.
 * @spec openspec/changes/news-list-keeps-its-page/specs/admin-ui/spec.md#requirement-saving-from-a-list-must-keep-the-list-where-it-was
 */
export function refreshList(type) {
	if (watched === null || !lastParams.has(type)) {
		return false
	}
	watched.fetchCollection(type, { ...lastParams.get(type) })
	return true
}

/**
 * Forget everything recorded; for tests.
 *
 * @return {void}
 */
export function resetListFetches() {
	watched = null
	lastParams.clear()
}
