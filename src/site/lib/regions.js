/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Regions, and what fills them when a portal has said nothing.
 *
 * THE SHELL USED TO BE MARKUP. `App.vue` hard-coded the header, the navigation
 * and the footer, which made the chrome the one part of a portal only a
 * developer could change. Expressing it as DEFAULT REGION CONTENT is what turns
 * "the renderer draws a header" into "the header region contains a brandHeader
 * block, unless the portal says otherwise" — the same statement, but now one an
 * editor can act on.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */

/**
 * The regions a page has, in render order.
 *
 * Mirrors `PortalRegionResolver::REGIONS` on the server. The two lists have to
 * agree, and the renderer asserts it at boot rather than discovering a
 * disagreement as an unrendered region on a live page.
 *
 * @type {Array<string>}
 */
export const REGIONS = ['header', 'hero', 'main', 'aside', 'footer']

/**
 * What each region contains when neither the portal nor the page says.
 *
 * THE DEFAULT IS TODAY'S SHELL, EXACTLY. Every existing portal has an empty
 * `regions` map, so every existing portal renders from this — which is why it
 * has to reproduce the current markup rather than improve on it. A migration
 * that changes every portal's appearance is not a migration (task 2.3).
 *
 * `hero`, `main` and `aside` are deliberately empty: their content is the
 * page's own body, which has always come from the widget list.
 *
 * @type {Record<string, Array<object>>}
 */
export const DEFAULT_REGIONS = {
	header: [{ widgetKey: 'brandHeader', props: {} }],
	hero: [],
	main: [],
	aside: [],
	footer: [{ widgetKey: 'footerColumns', props: {} }],
}

/**
 * Every region, keyed, with nothing in it.
 *
 * THE SHAPE MATTERS BEFORE THE CONTENT DOES. The editor renders a region list
 * from the moment it mounts, which is before its fetch resolves — and a `{}`
 * there throws `Cannot read properties of undefined (reading 'length')` on
 * every region, three times, in a component whose stack trace is minified. The
 * fix is not a guard at each read; it is never handing anyone a half-shaped
 * regions map in the first place.
 *
 * @return {object} Every region key, each an empty array.
 */
export function emptyRegions() {
	return Object.fromEntries(REGIONS.map((region) => [region, []]))
}

/**
 * Resolve one page's regions — page first, then portal, then the default.
 *
 * THE THIRD STATE IS THE POINT, and it is the same rule the server applies in
 * `PortalRegionResolver::resolve()`: a region the page mentions wins even when
 * it is EMPTY. `Object.hasOwn` rather than a truthiness test, because `[]` is
 * falsy and would collapse "emptied" into "inherited" — which would make it
 * impossible for a landing page to drop the portal's header.
 *
 * @param {object} pageRegions   The page's regions, keys meaningful.
 * @param {object} portalRegions The portal's regions, keys meaningful.
 * @return {object} Every region, keyed, in render order.
 */
export function resolveRegions(pageRegions = {}, portalRegions = {}) {
	const resolved = {}

	for (const region of REGIONS) {
		if (Object.hasOwn(pageRegions || {}, region)) {
			resolved[region] = [...(pageRegions[region] || [])]
			continue
		}

		if (Object.hasOwn(portalRegions || {}, region)) {
			resolved[region] = [...(portalRegions[region] || [])]
			continue
		}

		resolved[region] = [...(DEFAULT_REGIONS[region] || [])]
	}

	return resolved
}
