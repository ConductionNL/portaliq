/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The accessibility measurement on a portal's own page, without the widget
 * (site-accessibility-statement REQ-SAS-001 and REQ-SAS-003).
 *
 * The administrator's browser opens each page of the portal in a frame, runs
 * axe-core on the site root with the five rule sets the e2e suite uses, and
 * posts what it found. A page that does not load, cannot be framed or never
 * shows the site root is posted as NOT measured, with the reason, so it can
 * never read as a page without findings.
 *
 * WHY THIS MODULE IMPORTS NOTHING. The frame loader, axe-core and the
 * transport are handed in by `src/widgets/PortalAccessibility.vue`, so
 * `tests/accessibility-measure.spec.mjs` runs it as a plain node script, and
 * axe-core stays a lazy admin chunk that never enters the site entry.
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */

/** The axe-core rule sets, the same five as tests/e2e/site-accessibility.spec.ts. */
export const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

/** The element axe-core measures inside each page. */
export const SITE_ROOT = '[data-testid=site-root]'

/** The impacts axe-core reports. */
const IMPACTS = ['minor', 'moderate', 'serious', 'critical']

/** How long an audit supports status A or B, in years. */
const AUDIT_YEARS = 3

/**
 * The address the frame loads for one site route: the site of this portal,
 * marked as a measurement so the server lets its own origin frame it.
 *
 * @param {string} siteBase The site's address (`/index.php/apps/portaliq/site`).
 * @param {string} slug The portal slug.
 * @param {string} route The site route.
 * @return {string}
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */
export function frameAddress(siteBase, slug, route) {
	const query = new URLSearchParams({
		portal: String(slug || ''),
		route: String(route || '/'),
		measure: '1',
	})
	return `${siteBase}?${query.toString()}`
}

/**
 * The violations of one axe-core result, in the shape the server stores.
 *
 * @param {object} results What `axe.run()` resolved with.
 * @return {Array<object>} `{rule, impact, nodes, help, helpUrl}` per rule.
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */
export function violationsOf(results) {
	const out = []
	for (const violation of results?.violations || []) {
		if (!violation?.id || !IMPACTS.includes(violation.impact)) {
			continue
		}
		out.push({
			rule: String(violation.id),
			impact: violation.impact,
			nodes: Array.isArray(violation.nodes) ? violation.nodes.length : 0,
			help: String(violation.help || ''),
			helpUrl: String(violation.helpUrl || ''),
		})
	}
	return out
}

/**
 * Measure one page. Every failure becomes a row with `measured: false` and
 * the reason in words; nothing here throws.
 *
 * @param {string} route The site route.
 * @param {object} deps The collaborators.
 * @param {(route: string) => Promise<object>} deps.open Loads the page in a frame
 *   and resolves with its site root element, or rejects with the reason.
 * @param {(root: object) => Promise<object>} deps.run Runs axe-core on the root.
 * @return {Promise<object>} The page row.
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */
export async function measurePage(route, { open, run }) {
	let root
	try {
		root = await open(route)
	} catch (error) {
		return {
			url: route,
			measured: false,
			reason: String(error?.message || 'The page could not be loaded.'),
		}
	}
	if (!root) {
		return {
			url: route,
			measured: false,
			reason: 'The page showed no site content.',
		}
	}
	try {
		const results = await run(root)
		return { url: route, measured: true, violations: violationsOf(results) }
	} catch {
		return {
			url: route,
			measured: false,
			reason: 'The measurement could not run on this page.',
		}
	}
}

/**
 * Measure every page in turn and build the body the server stores.
 *
 * @param {Array<string>} routes The site routes.
 * @param {object} deps `open`, `run` (see measurePage), `axeVersion`, `theme`.
 * @param {(done: number, total: number) => void} [progress] Called after each page.
 * @return {Promise<object>} `{axeVersion, tags, theme, pages}`.
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */
export async function measureRun(routes, deps, progress = () => {}) {
	const pages = []
	for (const route of routes || []) {
		pages.push(await measurePage(route, deps))
		progress(pages.length, routes.length)
	}
	return {
		axeVersion: String(deps.axeVersion || ''),
		tags: AXE_TAGS,
		theme: String(deps.theme || ''),
		pages,
	}
}

/**
 * Why an audit cannot back an A or B claim, or '' when it can or when the
 * claim is C or D. The server applies the same rule and refuses the save.
 *
 * @param {object} audit `{party, date, reportUrl, result}`.
 * @param {Date} today The clock.
 * @return {string} '', 'incomplete' or 'expired'.
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
 */
export function auditProblem(audit, today) {
	const result = String(audit?.result || '')
	if (result !== 'A' && result !== 'B') {
		return ''
	}
	const party = String(audit?.party || '').trim()
	const report = String(audit?.reportUrl || '').trim()
	const date = String(audit?.date || '').trim()
	if (
		party === ''
		|| !/^https:\/\/\S+/.test(report)
		|| !/^\d{4}-\d{2}-\d{2}$/.test(date)
	) {
		return 'incomplete'
	}
	const audited = new Date(`${date}T00:00:00Z`)
	if (Number.isNaN(audited.getTime()) || audited > today) {
		return 'incomplete'
	}
	const limit = new Date(today.getTime())
	limit.setUTCFullYear(limit.getUTCFullYear() - AUDIT_YEARS)
	return audited < limit ? 'expired' : ''
}

/**
 * The widget's calls over an injected transport.
 *
 * @param {object} deps The collaborators.
 * @param {(url: string) => Promise<{data: object}>} deps.get GETs JSON.
 * @param {(url: string, body: object) => Promise<{data: object}>} deps.put PUTs JSON.
 * @param {(url: string, body: object) => Promise<{data: object}>} deps.post POSTs JSON.
 * @param {(path: string, params: object) => string} deps.url Builds an app url.
 * @return {object} `load`, `save`, `store`, each resolving `{ok, data, error}`.
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */
export function createAccessibilityApi({ get, put, post, url }) {
	const path = (slug, tail = '') =>
		url('/api/portals/{slug}/accessibility' + tail, { slug })
	const call = async (send) => {
		try {
			const response = await send()
			return { ok: true, data: response.data, error: '' }
		} catch (error) {
			return {
				ok: false,
				data: null,
				error: String(error?.response?.data?.error || 'failed'),
			}
		}
	}
	return {
		load: (slug) => call(() => get(path(slug))),
		save: (slug, body) => call(() => put(path(slug), body)),
		store: (slug, run) => call(() => post(path(slug, '/measurements'), run)),
	}
}
