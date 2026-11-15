// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The portal's public news, over the headless content contract
// (`/api/content/news`, site-school-blocks). Loaded only by the news widgets,
// so the site entry carries none of it.
//
// @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website

import { adoptSessionToken } from './authApi.js'
import { getContent, unlessMissing } from './contentRequest.js'

/**
 * GET a content path as JSON, with the resident's bearer when this tab
 * holds one; a non-2xx is an error carrying its status.
 *
 * @param {string} path The path under the content API.
 * @param {object} query The query.
 * @return {Promise<object>} The body.
 */
function get(path, query) {
	const headers = { Accept: 'application/json' }
	const token = adoptSessionToken()
	if (token) {
		headers.Authorization = `Bearer ${token}`
	}
	return getContent({ path, query, headers })
}

/**
 * The newest public items of a portal.
 *
 * @param {string} portal The portal slug.
 * @param {number} limit How many.
 * @return {Promise<Array<object>>} `{id, title, intro, publishedAt, audienceLabel, image}` each.
 */
export async function fetchPublicNews(portal, limit) {
	const body = await get('/news', { portal, limit })
	return Array.isArray(body?.items) ? body.items : []
}

/**
 * One public item, with its body; null when there is no such item.
 *
 * @param {string} portal The portal slug.
 * @param {string} id The item id.
 * @return {Promise<object|null>} The item.
 */
export async function fetchPublicNewsItem(portal, id) {
	const body = await unlessMissing(() =>
		get(`/news/${encodeURIComponent(id)}`, { portal }),
	)
	return body?.item || null
}
