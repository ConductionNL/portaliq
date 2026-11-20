// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// One GET over the headless content contract, shared by the widget-only
// reads (the FAQ and product finder, the public news) so each module only
// says which path and query it reads and whether a bearer goes along.

import { resolveApiBase } from './contentApi.js'

/**
 * GET a content path as JSON; a non-2xx is an error carrying its status.
 *
 * A query value that is undefined, null or '' is left off the address.
 *
 * @param {{path: string, query: object, headers: object}} request The path under the content API, its query and the request headers.
 * @return {Promise<object>} The body.
 */
export async function getContent(request) {
	const response = await fetch(contentAddress(request), {
		headers: request.headers,
	})
	if (!response.ok) {
		const error = new Error(`content api ${response.status} for ${request.path}`)
		error.status = response.status
		throw error
	}
	return response.json()
}

/**
 * The absolute address of a content read, with the filled query values.
 *
 * @param {{path: string, query: object}} request The path and the query.
 * @return {string} The address.
 */
function contentAddress(request) {
	const url = new URL(resolveApiBase() + request.path, window.location.origin)
	for (const [key, value] of Object.entries(request.query)) {
		if (value !== undefined && value !== null && value !== '') {
			url.searchParams.set(key, String(value))
		}
	}
	return url.toString()
}

/**
 * A content read, or null when the content API answers that it does not
 * exist (404); any other refusal is thrown on.
 *
 * @param {() => Promise<object>} read The read.
 * @return {Promise<object|null>} The body, or null.
 */
export async function unlessMissing(read) {
	try {
		return await read()
	} catch (error) {
		if (error.status === 404) {
			return null
		}
		throw error
	}
}
