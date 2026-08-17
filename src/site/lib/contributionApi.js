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
 * Whether an action can be submitted with no session at all.
 *
 * BOTH HALVES ARE LOAD-BEARING. The manifest's `anonymous` flag is only
 * honoured by the server on a `type: create` action — that is the one path
 * with a route (`POST /portal/api/collections/…`) that admits a caller with no
 * bearer. An `anonymous: true` on any other type is a claim the server will
 * not keep, so this reads the type as well as the flag rather than trusting
 * the flag alone.
 *
 * @param {object} action The declared action.
 * @return {boolean} Whether a signed-out visitor can submit it.
 */
export function isAnonymouslySubmittable(action) {
	return Boolean(action)
		&& (action.type || '') === 'create'
		&& action.anonymous === true
}

/**
 * The URL one declared action posts to.
 *
 * THE TWO ROUTES ARE NOT INTERCHANGEABLE, AND THIS RENDERER USED THE WRONG ONE.
 *
 * `POST /portal/api/actions/{appId}/{actionId}` forwards to a contributing
 * app's own HTTP endpoint. `ContributionController::action()` matches it with
 * `authorisedEndpointAction()`, which refuses anything not forwardable — so a
 * `type: create` action sent there is refused whether or not the visitor is
 * signed in. Creates belong on the collection route, where `create()` handles
 * the bearer case and `createAnonymous()` the unowned one.
 *
 * The consequence was visible on La Franken: an action the server accepts from
 * a signed-out visitor, and has accepted all along, was rendered as
 * "u moet ingelogd zijn".
 *
 * @param {object} options         The call.
 * @param {string} options.apiBase The portal auth/API base.
 * @param {string} options.appId   The contributing app.
 * @param {object} options.action  The declared action.
 * @return {string} The absolute URL to post to.
 */
export function actionUrl({ apiBase, appId, action }) {
	const base = String(apiBase || '').replace(/\/$/, '')

	if ((action && action.type) === 'create') {
		const register = encodeURIComponent(String(action.register || ''))
		const schema = encodeURIComponent(String(action.schema || ''))
		return `${base}/collections/${register}/${schema}`
	}

	const id = encodeURIComponent(String((action && action.id) || ''))
	return `${base}/actions/${encodeURIComponent(appId)}/${id}`
}

/**
 * Post one declared action.
 *
 * THROWS an object carrying the status rather than returning a flag: the caller
 * has to tell "not signed in" (401) from "not allowed" (403) from "the app
 * behind it is unreachable" (502), and a boolean cannot.
 *
 * @param {object} options         The call.
 * @param {string} options.apiBase The portal auth/API base.
 * @param {string} options.appId   The contributing app.
 * @param {object} options.action  The declared action, whose `type` chooses the route.
 * @param {object} options.payload The whitelisted field values.
 * @param {string} options.token   The session bearer, empty on the anonymous path.
 * @return {Promise<object>} The relayed response body.
 */
export async function submitAction({ apiBase, appId, action, payload, token }) {
	const url = actionUrl({ apiBase, appId, action })

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
