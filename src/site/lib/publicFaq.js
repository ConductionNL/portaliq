// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The portal's published FAQ entries and product finder, over the headless
// content contract (`/api/content/faq`, `/api/content/finder`). Loaded only by
// the two widgets that need them, so the site entry carries none of it. A
// request carries the portal and which entries to list; never an answer of a
// resident.
//
// @spec openspec/changes/public-faq-and-product-finder/tasks.md#t03

import { resolveApiBase } from './contentApi.js'

/**
 * GET a content path as JSON; a non-2xx is an error carrying its status.
 *
 * @param {string} path The path under the content API.
 * @param {object} query The query.
 * @return {Promise<object>} The body.
 */
async function get(path, query) {
	const url = new URL(resolveApiBase() + path, window.location.origin)
	for (const [key, value] of Object.entries(query)) {
		if (value !== undefined && value !== null && value !== '') {
			url.searchParams.set(key, String(value))
		}
	}
	const response = await fetch(url.toString(), {
		headers: { Accept: 'application/json' },
	})
	if (!response.ok) {
		const error = new Error(`content api ${response.status} for ${path}`)
		error.status = response.status
		throw error
	}
	return response.json()
}

/**
 * The published FAQ entries of a portal.
 *
 * @param {string} portal The portal slug.
 * @param {{page?: string, topic?: string}} filter Only the entries of this page route or topic.
 * @return {Promise<Array<{question: string, answer: string, topic: string, pages: Array<string>}>>} The entries.
 */
export async function fetchFaq(portal, filter = {}) {
	const body = await get('/faq', {
		portal,
		page: filter.page,
		topic: filter.topic,
	})
	return Array.isArray(body?.entries) ? body.entries : []
}

/**
 * One published product finder; null when there is none.
 *
 * @param {string} portal The portal slug.
 * @param {string} id The finder's id; empty for the portal's first published one.
 * @return {Promise<object|null>} The finder.
 */
export async function fetchFinder(portal, id) {
	try {
		const body = await get('/finder', { portal, finder: id })
		return body?.finder || null
	} catch (error) {
		if (error.status === 404) {
			return null
		}
		throw error
	}
}
