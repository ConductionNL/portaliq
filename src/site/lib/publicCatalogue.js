// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// A portal's public catalogue over the headless content contract
// (`/api/content/catalogue`, portal-public-catalogue): its public news and
// every app's public index, searched, filtered and paged by the server.
// Loaded only by the widgets that read it, so the site entry carries none of it.
//
// @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue

import { adoptSessionToken } from './authApi.js'
import { resolveApiBase } from './contentApi.js'

/**
 * The bearer of this tab's resident session goes along when it holds one.
 *
 * @return {Record<string, string>} The request headers.
 */
function requestHeaders() {
	const headers = { Accept: 'application/json' }
	const token = adoptSessionToken()
	if (token) {
		headers.Authorization = `Bearer ${token}`
	}
	return headers
}

/**
 * @param {string} path The endpoint path below the content API.
 * @return {URL} The endpoint's address at this origin.
 */
function catalogueUrl(path) {
	return new URL(resolveApiBase() + path, window.location.origin)
}

/**
 * Put every value that says something on the address; empty ones stay off.
 *
 * @param {URL} url The address.
 * @param {Record<string, unknown>} params The values by name.
 */
function setPresentParams(url, params) {
	for (const [key, value] of Object.entries(params)) {
		if (value !== undefined && value !== null && value !== '') {
			url.searchParams.set(key, String(value))
		}
	}
}

/**
 * The error a non-2xx content answer throws.
 *
 * @param {number} status The answer's status.
 * @param {string} path The endpoint path.
 * @return {Error} The error, carrying the status.
 */
function contentApiError(status, path) {
	const error = new Error(`content api ${status} for ${path}`)
	error.status = status
	return error
}

/**
 * @param {Array<string>|undefined} list A list of names.
 * @return {string} The names joined by commas.
 */
function commaList(list) {
	return (list || []).join(',')
}

/**
 * @param {object|undefined} map A map of choices.
 * @return {string} The map as JSON, or empty for no choices.
 */
function jsonMap(map) {
	return map && Object.keys(map).length > 0 ? JSON.stringify(map) : ''
}

/**
 * What is searched for: the words, the types, the choices and the page.
 *
 * @param {string} portal The portal slug.
 * @param {object} query The query, as fetchCatalogue takes it.
 * @return {Record<string, unknown>} The parameters.
 */
function searchParams(portal, query) {
	return {
		portal,
		search: query.q || '',
		types: commaList(query.types),
		filters: jsonMap(query.filters),
		sort: query.sort || '',
		page: query.page || '',
		limit: query.limit || '',
	}
}

/**
 * Where the results come from: still to come, one app, categories, a range.
 *
 * @param {object} query The query, as fetchCatalogue takes it.
 * @return {Record<string, unknown>} The parameters.
 */
function scopeParams(query) {
	return {
		upcoming: query.upcoming ? '1' : '',
		app: query.app || '',
		categories: commaList(query.categories),
		range: query.range || '',
		// The facets the block asks for besides the declared ones
		// (site-catalogue-follows-the-school-boards).
		facetsBy: jsonMap(query.facetsBy),
	}
}

/**
 * @param {unknown} value A value that should be a list.
 * @return {Array} The value, or an empty list when it is not one.
 */
function listOr(value) {
	return Array.isArray(value) ? value : []
}

/**
 * @param {unknown} value A value that should be a number.
 * @param {number} fallback The number for a missing or zero value.
 * @return {number} The number.
 */
function numberOr(value, fallback) {
	return Number(value) || fallback
}

/**
 * One catalogue page as the widgets read it, whatever the server sent.
 *
 * @param {object|null} body The parsed answer.
 * @return {{items: Array<object>, total: number, page: number, pages: number, facets: Array<object>}}
 */
function cataloguePage(body) {
	return {
		items: listOr(body?.items),
		total: numberOr(body?.total, 0),
		page: numberOr(body?.page, 1),
		pages: numberOr(body?.pages, 1),
		facets: listOr(body?.facets),
	}
}

/**
 * One page of the catalogue.
 *
 * @param {string} portal The portal slug.
 * @param {object} query The query.
 * @param {string} [query.q] The words searched for.
 * @param {Array<string>} [query.types] Only these item types.
 * @param {Record<string, Array<string>>} [query.filters] The facet choices.
 * @param {string} [query.sort] `relevance`, `date`, `dateDesc` or `title`.
 * @param {number} [query.page] The page, from 1.
 * @param {number} [query.limit] Results per page.
 * @param {boolean} [query.upcoming] Only what is still to come.
 * @param {{kind?: string, news?: string, audience?: string}} [query.facetsBy] Facets by kind and by a news item's audience.
 * @return {Promise<{items: Array<object>, total: number, page: number, pages: number, facets: Array<object>}>}
 */
export async function fetchCatalogue(portal, query = {}) {
	const url = catalogueUrl('/catalogue')
	setPresentParams(url, { ...searchParams(portal, query), ...scopeParams(query) })
	const response = await fetch(url.toString(), { headers: requestHeaders() })
	if (!response.ok) {
		throw contentApiError(response.status, '/catalogue')
	}
	return cataloguePage(await response.json())
}

/**
 * The kinds each app declares its public index can be narrowed by and drawn
 * as (categories, filters, columns), for the editor's block forms.
 *
 * @param {string} portal The portal slug.
 * @return {Promise<Array<object>>} The kinds, or none when the read fails.
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-4
 */
export async function fetchCatalogueKinds(portal) {
	const url = catalogueUrl('/catalogue/kinds')
	if (portal) {
		url.searchParams.set('portal', portal)
	}
	return readKinds(url, requestHeaders())
}

/**
 * Read the kinds; a failed or broken read is none.
 *
 * @param {URL} url The kinds address.
 * @param {Record<string, string>} headers The request headers.
 * @return {Promise<Array<object>>} The kinds.
 */
async function readKinds(url, headers) {
	try {
		const response = await fetch(url.toString(), { headers })
		if (!response.ok) {
			return []
		}
		const body = await response.json()
		return listOr(body?.kinds)
	} catch {
		return []
	}
}

/**
 * One item of an app's public index with the page the app projects for it.
 *
 * @param {string} portal The portal slug.
 * @param {{app: string, kind: string, slug: string}} address Which item.
 * @return {Promise<{item: object, detail: object}|null>} The page, or null for an item that is not public.
 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-3
 */
export async function fetchCatalogueDetail(portal, address) {
	const url = catalogueUrl('/catalogue/detail')
	setPresentParams(url, { portal, ...address })
	const response = await fetch(url.toString(), { headers: requestHeaders() })
	if (response.status === 404) {
		return null
	}
	if (!response.ok) {
		throw contentApiError(response.status, '/catalogue/detail')
	}
	const body = await response.json()
	return body?.detail ? { item: body.item || {}, detail: body.detail } : null
}
