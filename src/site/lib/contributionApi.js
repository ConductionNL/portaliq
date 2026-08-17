/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Routing to, and posting from, a contributed page.
 *
 * Separate from `authApi.js` because `submitAction` is the one call this
 * renderer makes that CHANGES something. Everything else the site does is a
 * public, cacheable read; this carries a bearer and a body, and keeping it in
 * its own module makes that difference visible rather than buried among the
 * readers.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
 */

/**
 * The in-site route for a contributed page.
 *
 * ONE place, because the index that LINKS to a page and the router that
 * RESOLVES one have to agree — two spellings of the same route is how a link
 * that looks right leads to a 404.
 *
 * @param {string} appId  The contributing app.
 * @param {string} pageId The page id.
 * @return {string} The route.
 */
export function contributionRoute(appId, pageId) {
	return `/diensten/${encodeURIComponent(appId)}/${encodeURIComponent(pageId)}`
}

/**
 * Parse a contributed-page route back into its parts.
 *
 * Returns null for anything that is not one, so the caller falls through to an
 * ordinary CMS page rather than treating every unknown route as contributed.
 *
 * @param {string} route The in-site route.
 * @return {object|null} `{appId, pageId}` or null.
 */
export function parseContributionRoute(route) {
	const match = /^\/diensten\/([^/]+)\/([^/]+)\/?$/.exec(String(route || ''))
	if (match === null) {
		return null
	}

	return {
		appId: decodeURIComponent(match[1]),
		pageId: decodeURIComponent(match[2]),
	}
}

/**
 * Post one declared action.
 *
 * THROWS an object carrying the status rather than returning a flag: the caller
 * has to tell "not signed in" (401) from "not allowed" (403) from "the app
 * behind it is unreachable" (502), and a boolean cannot.
 *
 * @param {object} options          The call.
 * @param {string} options.apiBase  The portal auth/API base.
 * @param {string} options.appId    The contributing app.
 * @param {string} options.actionId The declared action.
 * @param {object} options.payload  The whitelisted field values.
 * @param {string} options.token    The session bearer.
 * @return {Promise<object>} The relayed response body.
 */
export async function submitAction({ apiBase, appId, actionId, payload, token }) {
	const base = String(apiBase || '').replace(/\/$/, '')
	const url = `${base}/actions/${encodeURIComponent(appId)}/${encodeURIComponent(actionId)}`

	const headers = { 'Content-Type': 'application/json' }
	if (token) {
		headers.Authorization = `Bearer ${token}`
	}

	const response = await fetch(url, {
		method: 'POST',
		headers,
		body: JSON.stringify(payload || {}),
	})

	if (response.ok === false) {
		const error = new Error(`action_failed_${response.status}`)
		error.status = response.status
		throw error
	}

	return response.json().catch(() => ({}))
}
