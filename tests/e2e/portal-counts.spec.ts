/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * home-and-theme-landing-pages, task 4.1: the `nlPortalCounts` widget shows
 * what the portal publishes in numbers, each number a link into the search
 * with that filter set, read as an anonymous visitor would read it.
 *
 * WHAT IS REAL. The seeded portal (`open-tilburg`, tests/e2e/fixtures/seed-cms.sh),
 * two pages this file creates through OpenRegister's object API and removes
 * again (one placing the counts per category, one placing the search block
 * the counts link into), the site SPA, the widget, the one request it makes,
 * the links it draws, the search page that opens with the filter ticked, and
 * in the second test a real Nextcloud sign-in as the admin (the officer).
 *
 * WHAT IS STUBBED, AND WHY. opencatalogi is a sibling app and is not on the
 * test instance (the seed installs no catalogue), so its
 * `/api/federation/publications` is answered in the browser with `page.route`.
 * The stub stands in for a catalogue that applies the caller's session: a
 * request that carries the browser's cookies is answered with the officer's
 * view, drafts included (17 in the category), a request without them with the
 * public view (12). The widget must show 12 to a signed-in officer, which it
 * does only because it asks with `credentials: 'omit'`. Whether opencatalogi
 * really counts drafts for a session is opencatalogi's half.
 *
 * Category `infocat008` stands in for the scenario's "Raadsstukken": it is
 * the Woo category the site names "Vergaderstukken decentrale overheden"
 * (src/site/config/valueLists.js), and a category reads by its name.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */

import type { APIRequestContext, Page, Request, Route } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'
import { login } from './lib/traffic.ts'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const PAGES = `${BASE}/index.php/apps/openregister/api/objects/portaliq/page`

const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** The seeded portal (fixtures/seed-cms.sh). */
const PORTAL = process.env.PORTALIQ_E2E_PORTAL || 'open-tilburg'

/** The pages this file owns. */
const COUNTS_ROUTE = '/e2e-portal-counts'
const SEARCH_ROUTE = '/e2e-portal-counts-zoeken'
const OWNED = [COUNTS_ROUTE, SEARCH_ROUTE]

/** opencatalogi's search, as the browser asks it. */
const FEDERATION = '**/apps/opencatalogi/api/federation/publications**'

/** The scenario's category and its name on the site, in Dutch or English. */
const CATEGORY = 'infocat008'
const CATEGORY_NAME = /Vergaderstukken decentrale overheden|Meeting documents of local and regional governments/
const OTHER_NAME = /Organisatie en werkwijze|Organisation and working methods/

/** What the public sees, and what a session would see with its drafts. */
const PUBLIC_COUNT = 12
const WITH_DRAFTS = PUBLIC_COUNT + 5

/** One row of an answer. */
type Row = Record<string, unknown>

/**
 * An admin API context with its own cookie jar.
 *
 * @return the context; the caller disposes it
 */
async function adminApi(): Promise<APIRequestContext> {
	const basic = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')

	return await request.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Authorization: `Basic ${basic}`,
			'Content-Type': 'application/json',
		},
	})
}

/**
 * Remove every page of the seeded portal at this file's routes.
 *
 * @param api the admin context
 */
async function removeFixture(api: APIRequestContext): Promise<void> {
	const listed = await api.get(`${PAGES}?_limit=500`)
	if (!listed.ok()) {
		return
	}
	const body = await listed.json()
	const rows: Row[] = Array.isArray(body) ? body : (body.results ?? [])
	for (const row of rows) {
		if (OWNED.includes(String(row.route)) && row.portal === PORTAL) {
			const self = (row['@self'] ?? {}) as Row
			await api.delete(`${PAGES}/${self.id ?? row.id}`)
		}
	}
}

/**
 * Create one published page of the seeded portal with one widget.
 *
 * @param api       the admin context
 * @param route     the page route
 * @param widgetKey the widget's registry key
 * @param props     its authored props
 */
async function createPage(
	api: APIRequestContext,
	route: string,
	widgetKey: string,
	props: Row,
): Promise<void> {
	const created = await api.post(PAGES, {
		data: {
			title: `E2E ${route}`,
			route,
			portal: PORTAL,
			status: 'published',
			locale: 'nl',
			body: {
				type: 'grid',
				widgets: [
					{
						id: `e2e${route.replace(/\W+/g, '-')}`,
						widgetKey,
						slot: 'body',
						gridX: 0,
						gridY: 0,
						gridWidth: 12,
						gridHeight: 8,
						props,
					},
				],
			},
		},
	})
	expect(created.ok(), await created.text()).toBe(true)
}

/**
 * The category facet: the scenario's category, a second one, and one that
 * answered 0 (which the widget leaves out).
 *
 * @param withDrafts whether the caller's session is applied
 * @return the facet envelope
 */
function categoryFacet(withDrafts: boolean): Row {
	return {
		wooCategory: {
			data: {
				buckets: [
					{ value: CATEGORY, count: withDrafts ? WITH_DRAFTS : PUBLIC_COUNT },
					{ value: 'infocat004', count: 7 },
					{ value: 'infocat001', count: 0 },
				],
			},
		},
	}
}

/**
 * Stub opencatalogi's search: a request carrying the browser's cookies gets
 * the session's view, one without them the public view. Records the counts
 * request (`_limit=0`) and the search block's requests.
 *
 * @param page the page
 * @return the counts requests and the search requests
 */
async function stubCatalogue(
	page: Page,
): Promise<{ counts: Request[]; searches: Request[] }> {
	const counts: Request[] = []
	const searches: Request[] = []

	await page.route(FEDERATION, async (route: Route) => {
		const sent = route.request()
		const url = new URL(sent.url())
		const withSession = Boolean((await sent.allHeaders()).cookie)
		if (url.searchParams.get('_limit') === '0') {
			counts.push(sent)
			await route.fulfill({
				json: {
					results: [],
					total: withSession ? 45 : 40,
					facets: categoryFacet(withSession),
				},
			})
			return
		}
		if (!url.searchParams.has('_fields')) {
			searches.push(sent)
		}
		const chosen = url.searchParams.getAll('wooCategory')
		const results = chosen.includes(CATEGORY)
			? [
					{
						id: 'e2e-counts-1',
						name: 'Raadsvoorstel parkeerbeleid',
						wooCategory: CATEGORY,
						'@self': { id: 'e2e-counts-1', schema: 'publication' },
					},
				]
			: []
		await route.fulfill({
			json: {
				results,
				total: results.length,
				pages: 1,
				facets: categoryFacet(false),
			},
		})
	})

	return { counts, searches }
}

/**
 * Open the counts page and wait for the numbers.
 *
 * @param page the page
 */
async function openCountsPage(page: Page): Promise<void> {
	await page.goto(`${SITE}?portal=${PORTAL}&route=${COUNTS_ROUTE}`)
	await expect(page.getByTestId('nl-portal-counts')).toBeVisible({ timeout: 60_000 })
	await expect(page.getByTestId('nl-portal-count').first()).toBeVisible({ timeout: 30_000 })
}

/**
 * The count link for one category.
 *
 * @param page the page
 * @param name the category's name
 * @return the link
 */
function countLink(page: Page, name: RegExp) {
	return page
		.getByTestId('nl-portal-count')
		.filter({ hasText: name })
		.getByRole('link')
}

test.beforeAll(async () => {
	const api = await adminApi()
	await removeFixture(api)
	await createPage(api, COUNTS_ROUTE, 'nlPortalCounts', {
		heading: 'Wat we publiceren, in aantallen',
		by: 'category',
		searchRoute: SEARCH_ROUTE,
	})
	await createPage(api, SEARCH_ROUTE, 'federatedSearch', { hidePeriod: true })
	await api.dispose()
})

test.afterAll(async () => {
	const api = await adminApi()
	await removeFixture(api)
	await api.dispose()
})

test.describe('the portal shows live counts, as an anonymous visitor would see them', () => {
	test.setTimeout(120_000)

	// REQ-HTL-004, scenario "Counts link into the search".
	test('REQ-HTL-004: each category shows its count and links to the search with that category set', async ({
		page,
	}) => {
		const { counts, searches } = await stubCatalogue(page)
		await openCountsPage(page)

		// One request, with no results and the facet.
		expect(counts).toHaveLength(1)
		const asked = new URL(counts[0].url()).searchParams
		expect(asked.get('_limit')).toBe('0')
		expect(asked.get('_facets[wooCategory][type]')).toBe('terms')

		const link = countLink(page, CATEGORY_NAME)
		await expect(link).toContainText(String(PUBLIC_COUNT))
		await expect(link).toHaveAttribute('href', /e2e-portal-counts-zoeken/)
		await expect(link).toHaveAttribute('href', new RegExp(`f\\.wooCategory=${CATEGORY}`))
		await expect(countLink(page, OTHER_NAME)).toContainText('7')
		// A category that answered 0 is left out, never shown as 0.
		await expect(page.getByTestId('nl-portal-count')).toHaveCount(2)

		await link.click()

		const box = page.getByTestId(`federated-search-facet-${CATEGORY}`)
		await expect(box).toBeChecked({ timeout: 60_000 })
		await expect(page.getByTestId('federated-search-result')).toHaveCount(1)
		const last = new URL(searches[searches.length - 1].url()).searchParams
		expect(last.getAll('wooCategory')).toEqual([CATEGORY])
	})

	// REQ-HTL-004, scenario "An officer sees the public count".
	test('REQ-HTL-004: a signed-in officer sees the public count, not the count with drafts', async ({
		page,
	}) => {
		await login(page)
		const { counts } = await stubCatalogue(page)
		await openCountsPage(page)

		expect(counts).toHaveLength(1)
		const headers = await counts[0].allHeaders()
		expect(headers.cookie, 'the counts are read without the session').toBeUndefined()
		expect(headers.authorization).toBeUndefined()
		expect(headers.requesttoken).toBeUndefined()

		const link = countLink(page, CATEGORY_NAME)
		await expect(link).toContainText(String(PUBLIC_COUNT))
		await expect(link).not.toContainText(String(WITH_DRAFTS))
	})
})
