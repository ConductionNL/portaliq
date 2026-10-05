// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The portal's public news, over the headless content contract
// (`/api/content/news`, site-school-blocks). Loaded only by the news widgets,
// so the site entry carries none of it.
//
// @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website

import { adoptSessionToken } from './authApi.js'
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
	const headers = { Accept: 'application/json' }
	const token = adoptSessionToken()
	if (token) {
		headers.Authorization = `Bearer ${token}`
	}
	const response = await fetch(url.toString(), { headers })
	if (!response.ok) {
		const error = new Error(`content api ${response.status} for ${path}`)
		error.status = response.status
		throw error
	}
	return response.json()
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
	try {
		const body = await get(`/news/${encodeURIComponent(id)}`, { portal })
		return body?.item || null
	} catch (error) {
		if (error.status === 404) {
			return null
		}
		throw error
	}
}
