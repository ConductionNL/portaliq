// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// A dossier a resident shared by link (hydra `woo-citizen-journey` J3.4).
//
// opencatalogi hands out `/index.php/apps/portaliq/site?route=/gedeeld-dossier/<token>`
// as the share link (its REQ-CCOL-009). This module reads that route, fetches
// the shared view from opencatalogi without the visitor's session, and keeps
// only what a page shows: the title, the note on the dossier, and per item a
// title, a link and a note. opencatalogi already leaves out the owner and every
// item that is not public now; this module copies no other field, so nothing
// else can reach the page even if the answer grows.
//
// Imports nothing, so tests/site-shared-dossier.spec.mjs runs it as node.
//
// @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001

/** The in-site route prefix of a shared dossier. */
export const SHARED_DOSSIER_ROUTE = '/gedeeld-dossier'

/**
 * opencatalogi's read of a shared dossier, before the token, from the instance
 * root on (`instanceRootFrom()` in `instanceRoot.js`).
 */
export const SHARED_DOSSIER_ENDPOINT = '/apps/opencatalogi/api/collections/shared/'

/** A token as opencatalogi makes it: the dossier uuid, a dot, 192 bits in hex. */
const TOKEN = /^[0-9a-f-]{36}\.[0-9a-f]{48}$/

/**
 * Whether a route is a shared-dossier route, with or without a valid token.
 *
 * @param {string} route The in-site route.
 * @return {boolean}
 * @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001
 */
export function isSharedDossierRoute(route) {
	const value = String(route || '')
	return (
		value === SHARED_DOSSIER_ROUTE
		|| value.startsWith(`${SHARED_DOSSIER_ROUTE}/`)
	)
}

/**
 * The token a shared-dossier route carries, or '' when it carries none that
 * opencatalogi could have made.
 *
 * @param {string} route The in-site route.
 * @return {string}
 * @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001
 */
export function sharedDossierToken(route) {
	if (!isSharedDossierRoute(route)) {
		return ''
	}
	const token = String(route).slice(SHARED_DOSSIER_ROUTE.length + 1)
	return TOKEN.test(token) ? token : ''
}

/**
 * A link the page may render: https or http, or a path on this instance.
 *
 * @param {unknown} value The link.
 * @return {string} The link, or ''.
 */
function safeHref(value) {
	if (typeof value !== 'string') {
		return ''
	}
	const link = value.trim()
	if (/^https?:\/\/\S+$/i.test(link) || /^\/(?!\/)\S*$/.test(link)) {
		return link
	}
	return ''
}

/**
 * What the page shows of opencatalogi's answer, and nothing more.
 *
 * @param {object} body The answer of the shared read.
 * @return {{title: string, description: string, items: Array<{id: string, title: string, href: string, note: string}>}}
 * @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-the-page-must-show-only-what-the-share-answers-req-ssd-002
 */
export function sharedDossierView(body) {
	const source = body && typeof body === 'object' ? body : {}
	const items = Array.isArray(source.items) ? source.items : []
	return {
		title: typeof source.title === 'string' ? source.title : '',
		description:
			typeof source.description === 'string' ? source.description : '',
		items: items
			.filter((item) => item && typeof item === 'object')
			.map((item) => ({
				id: String(item.id || ''),
				title: String(item.title || ''),
				href: safeHref(item.url),
				note: String(item.note || ''),
			})),
	}
}

/**
 * Read a shared dossier as an anonymous visitor.
 *
 * The visitor's Nextcloud session stays home (`credentials: 'omit'`): the
 * link is for anyone, so what it shows must not depend on who opens it.
 *
 * @param {string} token The share token.
 * @param {(url: string, init: object) => Promise<object>} fetchImpl `fetch`, or a stand-in in a test.
 * @param {string} [root] The instance root, '/index.php' when not known.
 * @return {Promise<{status: 'ok', dossier: object}|{status: 'notFound'}|{status: 'error'}>}
 * @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001
 */
export async function fetchSharedDossier(token, fetchImpl, root = '/index.php') {
	if (!TOKEN.test(String(token || ''))) {
		return { status: 'notFound' }
	}
	try {
		const response = await fetchImpl(
			root + SHARED_DOSSIER_ENDPOINT + encodeURIComponent(token),
			{ headers: { Accept: 'application/json' }, credentials: 'omit' },
		)
		if (response.status === 404) {
			return { status: 'notFound' }
		}
		if (!response.ok) {
			return { status: 'error' }
		}
		return { status: 'ok', dossier: sharedDossierView(await response.json()) }
	} catch {
		return { status: 'error' }
	}
}
