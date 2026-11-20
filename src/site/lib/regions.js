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

/** The slots that mean `main`: none, an empty one, and `body`. */
const MAIN_SLOTS = [undefined, null, '', 'body']

/**
 * The region a slot names, or null. `body` and an empty slot mean `main`.
 *
 * @param {string} slot The widget's slot.
 * @return {string|null} The region.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-widgets-slot-must-select-one-of-five-regions-req-ptb-008
 */
export function regionOf(slot) {
	if (MAIN_SLOTS.includes(slot)) {
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
	if (!body) {
		return {}
	}

	const served = body.regions && !Array.isArray(body.regions) ? body.regions : null
	const page = served !== null ? statedRegions(served) : regionsBySlot(body.widgets)
	addClearedRegions(page, body.clearedRegions)
	return page
}

/**
 * The regions the content API served, in render order.
 *
 * @param {object} served Region name to widgets.
 * @return {object} The known regions it states.
 */
function statedRegions(served) {
	const page = {}
	for (const region of REGIONS) {
		if (Object.hasOwn(served, region)) {
			page[region] = served[region]
		}
	}
	return page
}

/**
 * The page's widgets grouped by the region their slot names; a slot that
 * names no region is left out.
 *
 * @param {Array<object>|undefined} widgets The page's widgets.
 * @return {object} Region name to widgets, in the order the regions first occur.
 */
function regionsBySlot(widgets) {
	const page = {}
	for (const widget of widgets || []) {
		const region = regionOf(widget.slot)
		if (region !== null) {
			page[region] = [...(page[region] || []), widget]
		}
	}
	return page
}

/**
 * Add each cleared region the page does not state already, as an empty list.
 *
 * @param {object} page Region name to widgets.
 * @param {Array<string>|undefined} cleared The regions the page clears.
 * @return {void}
 */
function addClearedRegions(page, cleared) {
	for (const region of cleared || []) {
		if (REGIONS.includes(region) && !Object.hasOwn(page, region)) {
			page[region] = []
		}
	}
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
		resolved[region] = [...regionWidgets(region, { page, portal })]
	}

	return resolved
}

/**
 * The widgets of one region: the page's when it states the region, then the
 * portal's, then the default.
 *
 * @param {string} region The region.
 * @param {{page: object, portal: object}} stated The page's and the portal's regions.
 * @return {Array<object>} The widgets.
 */
function regionWidgets(region, stated) {
	if (Object.hasOwn(stated.page, region)) {
		return stated.page[region] || []
	}
	if (Object.hasOwn(stated.portal, region)) {
		return stated.portal[region] || []
	}
	return DEFAULT_REGIONS[region]
}
