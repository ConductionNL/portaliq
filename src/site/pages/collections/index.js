// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// SLICE B OF site-reaches-portal-parity: COLLECTIONS.
//
// The pages this slice adds to the site, by the key the shell's page registry
// looks them up by, each a lazy loader so the visitor's entry bundle stays
// inside its budget. `contribution` is the key every contribution page falls
// back to (src/site/pages/registry.js on feat/site-signed-in-shell), the
// site's counterpart of the React portal's PageView.
//
// To wire it once the shell lands: for each `[key, loader]` of `pages`, call
// `registerSitePage(key, loader)`; and on boot, once the navigation is built,
// open the page a record link names with `openRecordEntry(nav)` (below), then
// route to that entry. The page itself selects the record.
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-contribution-page-must-render-its-blocks-req-srp-014

import {
	consumeOpenTarget,
	forgetOpenTarget,
	navKeyFor,
} from '../../../shared/openRecord.js'

/**
 * What every page of this slice receives. It is the slice contract's set plus
 * what the shell's AccountArea hands every page.
 *
 * @typedef {object} SitePageProps
 * @property {object|null} session The session, as `/portal/api/session` answers it.
 * @property {object|null} [portal] The portal record.
 * @property {object} api The shared portal api (src/shared/portalApi.js), bound to the resident's bearer.
 * @property {(key: string, vars?: object) => string} t The site translator; this slice falls back to its own strings.js.
 * @property {(key: string, params?: object) => void} [navigate] Go to another entry; the shell's AccountArea uses the `navigate` event instead.
 * @property {object} [entry] The navigation entry: `key`, `label`, `page`, `contribution`.
 * @property {object|Array} [contributions] The contributions aggregate.
 * @property {Array<object>} [nav] Every navigation entry.
 * @property {string} [locale] The language, `nl` or `en`.
 * @property {object} [openRecord] A record link the shell already read: `{app, collection, id, row?}`.
 */

/**
 * The pages of this slice, by registry key.
 *
 * @type {Record<string, () => Promise<object>>}
 */
export const pages = {
	contribution: () => import('./ContributionPage.vue'),
}

/**
 * The navigation entry a record link opens, for the shell to route to on boot.
 *
 * Reads `#open=<app>/<collection>/<id>` from the address, or the one kept in
 * sessionStorage across the sign-in. A link whose collection no page shows is
 * forgotten and opens nothing; otherwise it stays kept until the page that
 * shows it has selected the row.
 *
 * @param {Array<object>} nav The shell's navigation entries.
 * @param {object} [where] The location, history and storage; the browser's own by default.
 * @param {object} [where.location] The location.
 * @param {object} [where.history] The history.
 * @param {object|null} [where.storage] sessionStorage.
 * @return {object|null} The entry, or null.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-record-link-must-open-its-record-after-sign-in-req-srp-021
 */
export function openRecordEntry(nav, where = {}) {
	const location =
		where.location || (typeof window !== 'undefined' ? window.location : null)
	const history =
		where.history || (typeof window !== 'undefined' ? window.history : null)
	let storage = where.storage
	if (storage === undefined) {
		try {
			storage = typeof window !== 'undefined' ? window.sessionStorage : null
		} catch {
			storage = null
		}
	}
	const target = consumeOpenTarget(location, history, storage)
	if (!target) {
		return null
	}
	const key = navKeyFor(nav, target)
	if (!key) {
		forgetOpenTarget(storage)
		return null
	}
	return (nav || []).find((entry) => entry.key === key) || null
}

export { BLOCK_SLOTS, registerBlockSlot } from './blockSlots.js'
