/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * WHICH COMPONENT RENDERS A SIGNED-IN SECTION OF THE SITE.
 *
 * The signed-in area (`/mijn/...`, see src/shared/portalNav.js) shows one
 * navigation entry at a time. This registry maps an entry to the component
 * that renders it; an entry nothing is registered for renders the placeholder
 * page, so a section shows up in the menu before its page is built.
 *
 * HOW A PAGE IS REGISTERED. Add one line to `BUILT_IN` below, a loader rather
 * than the component itself, so the page downloads only when a resident opens
 * it and the visitor's entry bundle stays inside its budget:
 *
 *     inbox: () => import('./InboxPage.vue'),
 *
 * or call `registerSitePage(key, loader)` from code that runs before the area
 * mounts. Keys, most specific first:
 *
 *   - the entry's own key, `<app>:<page id>` (for example `learniq:children`),
 *     for a page that needs its own component;
 *   - the section: `cases`, `tasks`, `messages`, `news`, `inbox`, `access`,
 *     `details` or `account`;
 *   - `contribution`, for every contribution page without its own key (the
 *     site's counterpart of the React portal's PageView).
 *
 * WHAT A PAGE RECEIVES. Props, each passed only when the page declares it:
 * `entry` (the navigation entry: `key`, `label`, `special`, and for a
 * contribution page `page` and `contribution`), `page`, `contribution`, `api`
 * (the shared portal API bound to the site's bearer, src/shared/portalApi.js),
 * `session`, `portal` (the portal record), `contributions` (the aggregate),
 * `nav` (every entry), `t` (the site translator), `locale`, `openRecord`,
 * `navigate(keyOrRoute)`, and for my cases `closedMarker`, `canOpen(target)`
 * and `openCase(target, row)`. Events: `navigate` with an in-site route or a
 * section key, `unread` with the inbox's new unread count, `refresh` to read
 * the contributions again, and `removed` after the account is removed (the
 * shell signs out).
 *
 * Imports only loader maps and shared code, so tests/site-signed-in-shell.spec.mjs
 * runs it as node.
 *
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */

import { registerBlockSlot } from './collections/blockSlots.js'
import { pages as collectionPages } from './collections/index.js'
import { pages as accountPages } from './e/index.js'

/** The key every contribution page falls back to. */
export const CONTRIBUTION_PAGE = 'contribution'

/**
 * The pages this site ships, by key. Later slices add their line here.
 *
 * @type {Record<string, () => Promise<object>>}
 */
const BUILT_IN = {
	// Slice b: collections, detail and timeline (the React portal's PageView).
	...collectionPages,
	// Slice e: my cases, access to cases, my details, my account.
	cases: accountPages.__cases__,
	access: accountPages.__access__,
	details: accountPages.__details__,
	account: accountPages.__account__,
}

// Slice e's own case fills slice b's `citizenCase` place on a contribution page.
registerBlockSlot('citizenCase', () => import('../components/e/CitizenCase.vue'))

const loaders = new Map(Object.entries(BUILT_IN))

/**
 * Register the component loader for a key.
 *
 * @param {string} key An entry key, a section name or `contribution`.
 * @param {() => Promise<object>} loader Loads the component, e.g. `() => import('./InboxPage.vue')`.
 * @return {void}
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export function registerSitePage(key, loader) {
	if (typeof key !== 'string' || key === '') {
		throw new TypeError('A site page needs a non-empty key')
	}
	if (typeof loader !== 'function') {
		throw new TypeError(`The site page "${key}" needs a loader function`)
	}
	loaders.set(key, loader)
}

/**
 * The keys an entry is looked up by, most specific first.
 *
 * @param {object} entry A navigation entry.
 * @return {Array<string>} The keys.
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export function sitePageKeys(entry) {
	if (!entry) {
		return []
	}
	return [entry.key, entry.special || CONTRIBUTION_PAGE].filter(Boolean)
}

/**
 * The loader registered for an entry, or null when its page is not built yet.
 *
 * @param {object} entry A navigation entry.
 * @return {(() => Promise<object>)|null} The loader.
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export function sitePageLoader(entry) {
	for (const key of sitePageKeys(entry)) {
		if (loaders.has(key)) {
			return loaders.get(key)
		}
	}
	return null
}
