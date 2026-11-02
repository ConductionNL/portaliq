// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The start tiles of a portal (site-nlds-widget-palette T10, design D6): the
// actions that offer themselves with a summary, read from the public
// `/api/content/start-tiles` endpoint. Framework-free and without imports, so
// tests/start-tiles.spec.mjs runs it in node; the widget hands in `fetch`
// and the content API base.
//
// @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020

/** Every tile route lives in the signed-in area. */
const ACCOUNT_ROUTE = /^\/mijn\//

/**
 * The portal's start tiles; [] when the read fails.
 *
 * @param {string} portal The portal slug.
 * @param {Function} fetchFn The fetch to use.
 * @param {string} apiBase The content API base, no trailing slash.
 * @return {Promise<Array<{label: string, summary: string, audiences: Array<string>, route: string}>>} The tiles.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
 */
export async function fetchStartTiles(portal, fetchFn, apiBase) {
	const query = portal ? `?portal=${encodeURIComponent(portal)}` : ''
	try {
		const response = await fetchFn(`${apiBase}/start-tiles${query}`, {
			headers: { Accept: 'application/json' },
		})
		if (!response.ok) {
			return []
		}
		const body = await response.json()
		return Array.isArray(body?.tiles) ? body.tiles : []
	} catch {
		return []
	}
}

/**
 * The tiles as the task list draws them: one link per action, to its page
 * in the signed-in area (which asks a signed-out visitor to sign in first).
 *
 * @param {Array<object>|null} tiles The tiles.
 * @return {Array<{label: string, href: string}>} The tasks.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
 */
export function tilesToTasks(tiles) {
	return (Array.isArray(tiles) ? tiles : [])
		.map((tile) => ({
			label: String(tile?.label ?? '').trim(),
			href: String(tile?.route ?? ''),
		}))
		.filter((task) => task.label !== '' && ACCOUNT_ROUTE.test(task.href))
}
