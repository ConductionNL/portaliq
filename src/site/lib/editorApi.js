/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The renderer's view of "may I edit this page, and where".
 *
 * SEPARATE FROM `contentApi.js`, for the same reason `authApi.js` is: that
 * file is the headless CONTRACT — a Docusaurus build reads exactly those
 * endpoints and nothing else. This one asks a question about the CALLER, which
 * no third-party front-end has an equivalent of, so putting it on the contract
 * would promise consumers something Portaliq-specific.
 *
 * NOTHING HERE IS A PERMISSION. The answer decides whether to OFFER editing;
 * the refusal that matters happens in OpenRegister, on the write, against the
 * `page` schema's authorization block. A visitor who forges a `canEdit: true`
 * gains a button that leads to an app they cannot write to.
 */

/**
 * The editing probe's base, derived from the content API base.
 *
 * `/api/content` and `/api/cms` are siblings under the app root, so the probe
 * is found by rewriting the last segment rather than by hardcoding a Nextcloud
 * path — the same reason `contentApi.js` resolves its own base from runtime
 * configuration and `authApi.js` derives the auth edge.
 *
 * @param {string} apiBase The content API base, no trailing slash.
 * @return {string} The editing probe's base.
 */
export function editorBaseFrom(apiBase) {
	return String(apiBase || '').replace(/\/api\/content\/?$/, '/api/cms')
}

/**
 * The probe's address for a route.
 *
 * @param {string} editorBase The probe base.
 * @param {{route: string, portalSlug: string}} asked The route, and the portal slug when not resolving by host.
 * @return {string} The address.
 */
function contextUrl(editorBase, asked) {
	const url = new URL(`${editorBase}/editing-context`, window.location.origin)
	url.searchParams.set('route', asked.route || '/')
	if (asked.portalSlug) {
		url.searchParams.set('portal', asked.portalSlug)
	}
	return url.toString()
}

/**
 * The probe's answer when it offers editing, else null.
 *
 * @param {object|null} body The parsed answer.
 * @return {object|null} The context, only for a literal `canEdit: true`.
 */
function editableContext(body) {
	return body && body.canEdit === true ? body : null
}

/**
 * The editing context for a route, or null when there is none.
 *
 * EVERY failure resolves to null, including the 401 an anonymous visitor gets:
 * this endpoint requires a Nextcloud session and most visitors to a public
 * portal do not have one, so "no" is the ordinary answer here rather than an
 * error worth showing anybody. A portal whose probe is unreachable renders
 * exactly as it does for a reader, which is the correct degradation for a
 * control that only ever ADDS an affordance.
 *
 * @param {string} editorBase The probe base.
 * @param {string} route      The in-site route being viewed.
 * @param {string} portalSlug Explicit portal slug, when not resolving by host.
 * @return {Promise<object|null>} The context when the caller may edit, else null.
 */
export async function fetchEditingContext(editorBase, route, portalSlug = '') {
	try {
		const response = await fetch(contextUrl(editorBase, { route, portalSlug }), {
			headers: { Accept: 'application/json' },
			credentials: 'include',
		})
		return response.ok ? editableContext(await response.json()) : null
	} catch {
		return null
	}
}
