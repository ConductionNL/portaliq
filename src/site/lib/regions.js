/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * A page's five regions, and what fills each one.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-regions-must-resolve-page-first-then-portal-then-default-req-ptb-009
 */

/**
 * The regions, in render order. Mirrors `PortalRegionResolver::REGIONS`.
 *
 * @type {Array<string>}
 */
export const REGIONS = ['header', 'hero', 'main', 'aside', 'footer']

/**
 * What a region holds when neither the page nor the portal says: today's
 * shell. `hero`, `main` and `aside` are empty, because their content has
 * always been the page's own.
 *
 * @type {Record<string, Array<object>>}
 */
export const DEFAULT_REGIONS = {
	header: [{ id: 'brand-header', widgetKey: 'brandHeader', props: {} }],
	hero: [],
	main: [],
	aside: [],
	footer: [{ id: 'footer-columns', widgetKey: 'footerColumns', props: {} }],
}

/**
 * The region a slot names, or null. `body` and an empty slot mean `main`.
 *
 * @param {string} slot The widget's slot.
 * @return {string|null} The region.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-widgets-slot-must-select-one-of-five-regions-req-ptb-008
 */
export function regionOf(slot) {
	if (slot === undefined || slot === null || slot === '' || slot === 'body') {
		return 'main'
	}

	return REGIONS.includes(slot) ? slot : null
}

/**
 * The regions a page states: those its widgets fill, and those it clears.
 *
 * Reads `body.regions` when the content API served it, else groups
 * `body.widgets` by slot, so an older API still renders. A cleared region is
 * a present key with an empty list.
 *
 * @param {object} body The page body.
 * @return {object} Region name to widgets, keys meaningful.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-regions-must-resolve-page-first-then-portal-then-default-req-ptb-009
 */
export function pageRegionsOf(body) {
	const page = {}
	if (!body) {
		return page
	}

	const served = body.regions && !Array.isArray(body.regions) ? body.regions : null
	if (served !== null) {
		for (const region of REGIONS) {
			if (Object.hasOwn(served, region)) {
				page[region] = served[region]
			}
		}
	} else {
		for (const widget of body.widgets || []) {
			const region = regionOf(widget.slot)
			if (region !== null) {
				page[region] = [...(page[region] || []), widget]
			}
		}
	}

	for (const region of body.clearedRegions || []) {
		if (REGIONS.includes(region) && !Object.hasOwn(page, region)) {
			page[region] = []
		}
	}

	return page
}

/**
 * Resolve every region: the page's when it states one, then the portal's,
 * then the default.
 *
 * The test is for a key's PRESENCE, never for an empty value: `[]` is how a
 * page or portal says "nothing here", and a truthiness test would turn that
 * back into "inherit".
 *
 * @param {object} pageRegions   The page's regions, keys meaningful.
 * @param {object} portalRegions The portal's regions, keys meaningful.
 * @return {object} Every region, keyed, in render order.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-regions-must-resolve-page-first-then-portal-then-default-req-ptb-009
 */
export function resolveRegions(pageRegions, portalRegions) {
	const page = pageRegions || {}
	const portal =
		portalRegions && !Array.isArray(portalRegions) ? portalRegions : {}
	const resolved = {}

	for (const region of REGIONS) {
		if (Object.hasOwn(page, region)) {
			resolved[region] = [...(page[region] || [])]
		} else if (Object.hasOwn(portal, region)) {
			resolved[region] = [...(portal[region] || [])]
		} else {
			resolved[region] = [...DEFAULT_REGIONS[region]]
		}
	}

	return resolved
}
