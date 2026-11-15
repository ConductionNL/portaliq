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

import { getContent, unlessMissing } from './contentRequest.js'

/**
 * GET a content path as JSON, without a bearer; a non-2xx is an error
 * carrying its status.
 *
 * @param {string} path The path under the content API.
 * @param {object} query The query.
 * @return {Promise<object>} The body.
 */
function get(path, query) {
	return getContent({ path, query, headers: { Accept: 'application/json' } })
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
	const body = await unlessMissing(() => get('/finder', { portal, finder: id }))
	return body?.finder || null
}
