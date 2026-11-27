/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-federated-search (task 3, task 4) and search-filter-by-kind (task
 * 2.2): the `federatedSearch` block on a live public page, searched by an
 * anonymous visitor.
 *
 * WHAT IS REAL. The seeded portal (`open-tilburg`, tests/e2e/fixtures/seed-cms.sh),
 * two pages this file creates through OpenRegister's object API and removes
 * again (a search page placing the block, and the `/onderwerp` page placing
 * `nlSubjectLanding` when the portal has none), the site SPA, the widget
 * gate, the block, its request building, its rendering, the address it
 * writes and the in-site navigation to a result.
 *
 * WHAT IS STUBBED, AND WHY. opencatalogi is a sibling app and is not on the
 * test instance (the seed installs no catalogue). Its two public reads are
 * answered in the browser with `page.route`:
 *
 * - `/api/federation/publications`: answered in the endpoint's own envelope
 *   (`results`, `total`, `pages`, `facets`), filtering a small corpus on the
 *   parameters the block sends (`_search`, `categories`, `resultType`). The
 *   stub records every search request, so a test asserts what the block
 *   ASKED, which is portaliq's half, and what it DREW from the answer.
 * - `/api/themes/{slug}`: the subject read the landing page makes after a
 *   subject hit is opened (REQ-SUB-003's shape, as in
 *   tests/fixtures/subjects-contract.json).
 *
 * What a stub cannot prove, and this file therefore leaves alone: that
 * opencatalogi honours `_content` and searches only the published, redacted
 * copy of a document (REQ-PFS-CONTENT-001 "A redacted word finds nothing" is
 * opencatalogi's index behaviour, run against the dev rig), the empty-term
 * request shape and the malformed row (`@e2e exclude`, in
 * tests/federated-search.spec.mjs), and the superseded-response guard
 * (`@e2e exclude`).
 *
 * THE SOURCE OF A ROW. The portal-federated-search spec says a row without a
 * directory reads `local`; search-names-only-a-foreign-source (REQ-SNF-001)
 * later changed that: a row of this portal names no source, a row from
 * another catalogue names its own. S31 asserts what the code renders now.
 *
 * PATH ADDRESSING. The pages are opened as `/site/<route>` rather than
 * `/site?route=<route>`: `nlSubjectLanding` reads its slug from the last
 * path segment, because WidgetGrid.propsFor() hands it no `routeParam`.
 *
 * @spec openspec/specs/portal-federated-search/spec.md
 * @spec openspec/changes/search-filter-by-kind/specs/portal-federated-search/spec.md
 */

import type { APIRequestContext, Page, Request, Route } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const PAGES = `${BASE}/index.php/apps/openregister/api/objects/portaliq/page`

const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** The seeded portal (fixtures/seed-cms.sh). */
const PORTAL = process.env.PORTALIQ_E2E_PORTAL || 'open-tilburg'

/** The search page this file owns. */
const SEARCH_ROUTE = '/e2e-federated-search'

/** The page subject addresses hang under (the block's default `subjectRoute`). */
const SUBJECT_ROUTE = '/onderwerp'

/** opencatalogi's two public reads, as the browser asks them. */
const FEDERATION = '**/apps/opencatalogi/api/federation/publications**'
const THEMES = '**/apps/opencatalogi/api/themes/**'

/** One row of the stubbed corpus, in the endpoint's own row shape. */
type Row = Record<string, unknown>

/** A publication of this portal: no directory. */
const LOCAL_PARKING: Row = {
	id: 'e2e-pfs-local',
	name: 'Parkeerbeleid binnenstad 2026',
	summary: 'Het parkeerbeleid voor de binnenstad, met de nieuwe tarieven.',
	publicationDate: '2026-09-01T09:00:00+00:00',
	categories: ['verkeer'],
	resultType: 'publication',
	'@self': { id: 'e2e-pfs-local', schema: 'publication', relevance: 90 },
}

/** A publication from a peer catalogue. */
const PEER_PARKING: Row = {
	id: 'e2e-pfs-peer-a',
	name: 'Parkeervergunning bewoners Venray',
	summary: 'Hoe bewoners van Venray een parkeervergunning aanvragen.',
	publicationDate: '2026-08-15T09:00:00+00:00',
	categories: ['vergunningen'],
	resultType: 'publication',
	'@self': {
		id: 'e2e-pfs-peer-a',
		schema: 'publication',
		directory: 'venray.example.org',
		relevance: 80,
	},
}

/**
 * A publication from a second peer catalogue. Its name carries "parkeer"
 * itself: the stub matches by substring, and "parkeren" does not contain
 * "parkeer", so a name without it drops this row from the 'parkeer' search
 * S31, S32 and the kinds test count on. The document and the subject below
 * carry it for the same reason.
 */
const OTHER_PEER_PARKING: Row = {
	id: 'e2e-pfs-peer-b',
	name: 'Parkeerafspraken bij evenementen',
	summary: 'Afspraken over parkeren rond evenementen in Horst.',
	publicationDate: '2026-07-01T09:00:00+00:00',
	categories: ['verkeer'],
	resultType: 'publication',
	'@self': {
		id: 'e2e-pfs-peer-b',
		schema: 'publication',
		directory: 'horst.example.org',
		relevance: 70,
	},
}

/** A document whose text holds the term (REQ-SFK-002, REQ-PFS-CONTENT-001). */
const DOCUMENT_HIT: Row = {
	id: 'd-42',
	name: 'Bijlage geluidsscherm parkeerterrein A2.pdf',
	resultType: 'document',
	publication: { id: 'p-7', title: 'Besluit Stationsweg' },
	'@self': { id: 'd-42', schema: 'document', relevance: 60 },
}

/** A subject (REQ-SFK-002). */
const SUBJECT_HIT: Row = {
	id: 'th-parkeren',
	name: 'Parkeren',
	// "parkeer" in the summary keeps the subject in the stub's 'parkeer' search.
	summary: 'Parkeervergunningen, parkeerplaatsen en tarieven.',
	slug: 'parkeren',
	resultType: 'subject',
	publicationCount: 14,
	'@self': { id: 'th-parkeren', schema: 'subject', relevance: 95 },
}

/** Everything the stub can answer. */
const CORPUS: Row[] = [
	LOCAL_PARKING,
	PEER_PARKING,
	OTHER_PEER_PARKING,
	DOCUMENT_HIT,
	SUBJECT_HIT,
]

/** The subject read the landing page makes (REQ-SUB-003's keys). */
const PARKEREN = {
	id: 'th-parkeren',
	slug: 'parkeren',
	title: 'Parkeren',
	summary: 'Vergunningen en tarieven',
	description: 'Alles over parkeren in de gemeente.',
	image: {
		url: '/index.php/apps/opencatalogi/api/e2e-files/parkeren.png',
		alt: 'Een parkeerplaats',
	},
	publicationCount: 14,
}

/** A one-pixel PNG for the subject's image. */
const PIXEL = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
	'base64',
)

/** The pages this file created, by id, removed again in afterAll. */
const created: string[] = []

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
 * The portal's pages at one route.
 *
 * @param api   the admin context
 * @param route the route
 * @return the page rows
 */
async function pagesAt(api: APIRequestContext, route: string): Promise<Row[]> {
	const listed = await api.get(`${PAGES}?_limit=500`)
	expect(listed.ok(), await listed.text()).toBe(true)
	const body = await listed.json()
	const rows: Row[] = Array.isArray(body) ? body : (body.results ?? [])
	return rows.filter((row) => row.route === route && row.portal === PORTAL)
}

/**
 * The id of a page row.
 *
 * @param row the page row
 * @return its id
 */
function idOf(row: Row): string {
	const self = (row['@self'] ?? {}) as Row
	return String(self.id ?? row.id ?? '')
}

/**
 * A grid widget filling the page's width.
 *
 * @param id        the widget id
 * @param widgetKey the registry key
 * @param props     the authored props
 * @return the widget
 */
function widget(id: string, widgetKey: string, props: Row): Row {
	return {
		id,
		widgetKey,
		slot: 'body',
		gridX: 0,
		gridY: 0,
		gridWidth: 12,
		gridHeight: 10,
		props,
	}
}

/**
 * Create one published page of the seeded portal and remember it.
 *
 * @param api     the admin context
 * @param title   the page title
 * @param route   the page route
 * @param widgets the grid widgets
 */
async function createPage(
	api: APIRequestContext,
	title: string,
	route: string,
	widgets: Row[],
): Promise<void> {
	const response = await api.post(PAGES, {
		data: {
			title,
			route,
			portal: PORTAL,
			status: 'published',
			locale: 'nl',
			body: { type: 'grid', widgets },
		},
	})
	expect(response.ok(), await response.text()).toBe(true)
	created.push(idOf(await response.json()))
}

/**
 * Make sure the portal has a `/onderwerp` page placing the subject page. An
 * existing one is used and left alone; otherwise one is created and removed
 * again afterwards.
 *
 * @param api the admin context
 */
async function ensureSubjectPage(api: APIRequestContext): Promise<void> {
	const existing = await pagesAt(api, SUBJECT_ROUTE)
	if (existing.length === 0) {
		await createPage(api, 'Onderwerp', SUBJECT_ROUTE, [
			widget('e2e-subject', 'nlSubjectLanding', { backLabel: 'Alle onderwerpen' }),
		])
		return
	}
	const placed = JSON.stringify(existing[0].body ?? {}).includes('nlSubjectLanding')
	expect(
		placed,
		`${PORTAL} already has a ${SUBJECT_ROUTE} page, and it does not place nlSubjectLanding`,
	).toBe(true)
}

/**
 * Whether a row matches the term, on its name or summary.
 *
 * @param row  the row
 * @param term the term, lower case
 * @return true when it matches
 */
function matches(row: Row, term: string): boolean {
	const text = `${row.name ?? ''} ${row.summary ?? ''}`.toLowerCase()
	return term.split(/\s+/).every((word) => text.includes(word))
}

/**
 * The terms buckets of one field over some rows, in the object-field dialect
 * (`{data: {buckets: [{value, count}]}}`).
 *
 * @param rows  the rows
 * @param field the field
 * @return the facet
 */
function objectFieldFacet(rows: Row[], field: string): Row {
	const counts = new Map<string, number>()
	for (const row of rows) {
		const values = row[field]
		for (const value of Array.isArray(values) ? values : [values]) {
			if (typeof value === 'string' && value !== '') {
				counts.set(value, (counts.get(value) ?? 0) + 1)
			}
		}
	}
	return {
		data: {
			buckets: [...counts].map(([value, count]) => ({ value, count })),
		},
	}
}

/**
 * Answer one search the way the federation endpoint does.
 *
 * @param url       the request address
 * @param withKinds whether the endpoint counts the kinds of record
 * @return the answer body
 */
function searchAnswer(url: URL, withKinds: boolean): Row {
	const params = url.searchParams
	const term = (params.get('_search') ?? '').trim().toLowerCase()
	const categories = params.getAll('categories')
	const kinds = params.getAll('resultType')

	// An endpoint without the kinds answers publications only.
	const searchable = withKinds
		? CORPUS
		: CORPUS.filter((row) => row.resultType === 'publication')
	const found = searchable.filter((row) => term === '' || matches(row, term))
	const narrowed = found
		.filter(
			(row) =>
				categories.length === 0
				|| (row.categories as string[] | undefined ?? []).some((c) =>
					categories.includes(c),
				),
		)
		.filter((row) => kinds.length === 0 || kinds.includes(String(row.resultType)))

	const facets: Row = {}
	if (params.has('_facets[categories][type]')) {
		facets.categories = objectFieldFacet(found, 'categories')
	}
	if (withKinds && params.has('_facets[resultType][type]')) {
		// The kinds in OpenCatalogi's virtual-facet dialect.
		const counted = new Map<string, number>()
		for (const row of found) {
			counted.set(String(row.resultType), (counted.get(String(row.resultType)) ?? 0) + 1)
		}
		facets.resultType = {
			buckets: [...counted].map(([key, results]) => ({ key, results })),
		}
	}

	return {
		results: narrowed,
		total: narrowed.length,
		page: 1,
		pages: 1,
		facets,
	}
}

/**
 * Stub opencatalogi's reads for this page and record the searches.
 *
 * Only the block's own searches are recorded: the typing suggestions ask the
 * same endpoint with `_fields`.
 *
 * @param page      the page
 * @param withKinds whether the endpoint counts the kinds of record (an older
 *                  catalogue does not)
 * @return the search requests the block sent
 */
async function stubCatalogue(page: Page, withKinds = true): Promise<Request[]> {
	const searches: Request[] = []

	await page.route(FEDERATION, async (route: Route) => {
		const url = new URL(route.request().url())
		if (!url.searchParams.has('_fields')) {
			searches.push(route.request())
		}
		await route.fulfill({ json: searchAnswer(url, withKinds) })
	})
	await page.route(THEMES, async (route: Route) => {
		const slug = new URL(route.request().url()).pathname.split('/').pop()
		if (slug === PARKEREN.slug) {
			await route.fulfill({ json: PARKEREN })
			return
		}
		await route.fulfill({ status: 404, json: { message: 'Not found' } })
	})
	await page.route(`**${PARKEREN.image.url}`, (route: Route) =>
		route.fulfill({ status: 200, contentType: 'image/png', body: PIXEL }),
	)

	return searches
}

/**
 * Open the search page, anonymously, and wait for the block's first answer.
 *
 * @param page the page
 */
async function openSearchPage(page: Page): Promise<void> {
	await page.goto(`${SITE}${SEARCH_ROUTE}?portal=${PORTAL}`)
	await expect(page.getByTestId('federated-search')).toBeVisible({ timeout: 60_000 })
	await expect(page.getByTestId('federated-search-count')).toBeVisible({ timeout: 30_000 })
}

/**
 * Search for a term through the shared search box.
 *
 * @param page the page
 * @param term the term
 */
async function search(page: Page, term: string): Promise<void> {
	const answered = page.waitForResponse((response) => {
		const url = new URL(response.url())
		return (
			url.pathname.endsWith('/api/federation/publications')
			&& !url.searchParams.has('_fields')
			&& url.searchParams.get('_search') === term
		)
	})
	await page.locator('#pq-federated-search').fill(term)
	await page.locator('#pq-federated-search').press('Enter')
	await answered
}

/**
 * The parameters of the newest search request.
 *
 * @param searches the recorded search requests
 * @return its parameters
 */
function lastSearch(searches: Request[]): URLSearchParams {
	expect(searches.length, 'the block sent a search').toBeGreaterThan(0)
	return new URL(searches[searches.length - 1].url()).searchParams
}

/**
 * The result card with a title.
 *
 * @param page  the page
 * @param title the result's title
 * @return the card
 */
function resultCard(page: Page, title: string) {
	return page
		.getByTestId('federated-search-result')
		.filter({ has: page.getByRole('heading', { name: title, exact: true }) })
}

test.beforeAll(async () => {
	const api = await adminApi()
	for (const row of await pagesAt(api, SEARCH_ROUTE)) {
		await api.delete(`${PAGES}/${idOf(row)}`)
	}
	await createPage(api, 'E2E federated search', SEARCH_ROUTE, [
		// `categories` is the object field the S32 scenario facets on; the
		// kind filter is added by the block itself when the endpoint counts
		// the kinds (REQ-SFK-001).
		widget('e2e-federated-search', 'federatedSearch', {
			facetField: 'categories',
			hidePeriod: true,
		}),
	])
	await ensureSubjectPage(api)
	await api.dispose()
})

test.afterAll(async () => {
	const api = await adminApi()
	for (const id of created.reverse()) {
		await api.delete(`${PAGES}/${id}`)
	}
	await api.dispose()
})

test.describe('an anonymous visitor searches federated publications', () => {
	test.setTimeout(120_000)

	// @e2e openspec/specs/portal-federated-search/spec.md#scenario-a-term-matches-a-publication
	test('S30: a term present in one publication lists it with its title and a link', async ({
		page,
	}) => {
		const searches = await stubCatalogue(page)
		await openSearchPage(page)

		await search(page, 'binnenstad')

		expect(lastSearch(searches).get('_search')).toBe('binnenstad')
		await expect(page.getByTestId('federated-search-result')).toHaveCount(1)
		const card = resultCard(page, String(LOCAL_PARKING.name))
		await expect(card).toBeVisible()
		const link = card.getByTestId('federated-search-result-link')
		await expect(link).toHaveAccessibleName(`Lees meer over ${LOCAL_PARKING.name}`)
		await expect(link).toHaveAttribute('href', /publicatie(%2F|\/)e2e-pfs-local/)
		await expect(page.getByTestId('federated-search-count')).toHaveText('1 resultaat')
		// The search is in the address, so the link can be shared.
		expect(new URL(page.url()).searchParams.get('_search')).toBe('binnenstad')
	})

	// @e2e openspec/specs/portal-federated-search/spec.md#scenario-the-block-mounts-at-a-public-origin
	test('S30b: without a Nextcloud session the block asks with fetch and no CSRF token', async ({
		page,
	}) => {
		const searches = await stubCatalogue(page)
		await openSearchPage(page)
		await search(page, 'parkeer')

		// The import graph (no `@nextcloud/*`) is not observable from the
		// page; what is, is the request that import graph produces.
		for (const sent of searches) {
			expect(sent.resourceType()).toBe('fetch')
			const headers = await sent.allHeaders()
			expect(headers.requesttoken).toBeUndefined()
			expect(headers.authorization).toBeUndefined()
		}
		await expect(page.getByTestId('federated-search-result').first()).toBeVisible()
	})

	// @e2e openspec/specs/portal-federated-search/spec.md#scenario-a-federated-row-names-its-peer
	// REQ-SNF-001 (search-names-only-a-foreign-source): a row of this portal names no source.
	test('S31: each row from another catalogue names its own directory, not one banner for the page', async ({
		page,
	}) => {
		await stubCatalogue(page, false)
		await openSearchPage(page)

		await search(page, 'parkeer')

		await expect(page.getByTestId('federated-search-result')).toHaveCount(3)
		await expect(
			resultCard(page, String(PEER_PARKING.name)).getByTestId('federated-search-source'),
		).toHaveText('Bron: venray.example.org')
		await expect(
			resultCard(page, String(OTHER_PEER_PARKING.name)).getByTestId(
				'federated-search-source',
			),
		).toHaveText('Bron: horst.example.org')
		await expect(
			resultCard(page, String(LOCAL_PARKING.name)).getByTestId('federated-search-source'),
		).toHaveCount(0)
	})

	// @e2e openspec/specs/portal-federated-search/spec.md#scenario-an-object-field-facet-produces-filters
	test('S32: an object-field facet lists each category with its count and narrows the results', async ({
		page,
	}) => {
		const searches = await stubCatalogue(page, false)
		await openSearchPage(page)
		await search(page, 'parkeer')

		// Facets travel in the same request as the results.
		expect(lastSearch(searches).get('_facets[categories][type]')).toBe('terms')
		const group = page.getByTestId('federated-search-facet-group-categories')
		await expect(group).toBeVisible()
		await expect(group.locator('label').filter({ hasText: 'verkeer' })).toContainText('(2)')
		await expect(group.locator('label').filter({ hasText: 'vergunningen' })).toContainText(
			'(1)',
		)

		await page.getByTestId('federated-search-facet-vergunningen').check()

		await expect(page.getByTestId('federated-search-result')).toHaveCount(1)
		await expect(resultCard(page, String(PEER_PARKING.name))).toBeVisible()
		expect(lastSearch(searches).getAll('categories')).toEqual(['vergunningen'])
		expect(new URL(page.url()).searchParams.get('f.categories')).toBe('vergunningen')
	})

	// REQ-PFS-CONTENT-001, scenario "A word in the body of a document finds it", portaliq's half;
	// REQ-SFK-002, scenario "A document hit".
	test('a word inside a document sends the content flag, and the hit links to the document and names its publication', async ({
		page,
	}) => {
		const searches = await stubCatalogue(page)
		await openSearchPage(page)

		await search(page, 'geluidsscherm')

		expect(lastSearch(searches).get('_content')).toBe('true')
		const card = resultCard(page, String(DOCUMENT_HIT.name))
		await expect(card).toBeVisible()
		await expect(card.getByTestId('federated-search-result-link')).toHaveAttribute(
			'href',
			/document(%2F|\/)d-42/,
		)
		await expect(card.getByTestId('federated-search-publication')).toHaveText(
			'Onderdeel van Besluit Stationsweg',
		)
	})
})

test.describe('the results filter by kind (search-filter-by-kind)', () => {
	test.setTimeout(120_000)
	// The seeded portal offers nl and en, and the site follows the browser's
	// language among those, so an en-US browser reads the kinds in English.
	// These assertions read the Dutch names.
	test.use({ locale: 'nl-NL' })

	// REQ-SFK-001, scenario "Only subjects"; REQ-SFK-002 (a subject links to /onderwerp/{slug} with its count).
	// search-filter-by-kind task 2.2: search a seeded term, choose "Onderwerp", open the subject.
	test('choosing "Onderwerp" sends resultType=subject, writes it to the address, and the subject opens', async ({
		page,
	}) => {
		const searches = await stubCatalogue(page)
		await openSearchPage(page)
		await search(page, 'parkeer')

		const kinds = page.getByTestId('federated-search-facet-group-resultType')
		await expect(kinds.locator('legend')).toHaveText('Soort')
		await expect(kinds.locator('label').filter({ hasText: 'Publicatie' })).toContainText('(3)')
		await expect(kinds.locator('label').filter({ hasText: 'Document' })).toContainText('(1)')
		await expect(kinds.locator('label').filter({ hasText: 'Onderwerp' })).toContainText('(1)')
		// No kind chosen, no kind sent.
		expect(lastSearch(searches).has('resultType')).toBe(false)

		await page.getByTestId('federated-search-facet-subject').check()

		await expect(page.getByTestId('federated-search-result')).toHaveCount(1)
		expect(lastSearch(searches).getAll('resultType')).toEqual(['subject'])
		expect(new URL(page.url()).searchParams.get('f.resultType')).toBe('subject')

		const card = resultCard(page, 'Parkeren')
		await expect(card.getByTestId('federated-search-publication-count')).toHaveText(
			'14 publicaties',
		)
		const link = card.getByTestId('federated-search-result-link')
		await expect(link).toHaveAttribute('href', /onderwerp(%2F|\/)parkeren/)

		await link.click()

		await expect(page.getByTestId('nl-subject-title')).toHaveText('Parkeren', {
			timeout: 30_000,
		})
		await expect(page.getByTestId('nl-subject-description')).toHaveText(
			PARKEREN.description,
		)
	})

	// REQ-SFK-001, scenario "An older catalogue gets no dead filter".
	test('an endpoint that does not count the kinds gets no "Soort" filter', async ({ page }) => {
		await stubCatalogue(page, false)
		await openSearchPage(page)
		await search(page, 'parkeer')

		await expect(page.getByTestId('federated-search-result').first()).toBeVisible()
		await expect(page.getByTestId('federated-search-facet-group-resultType')).toHaveCount(0)
		await expect(page.getByTestId('federated-search-facet-subject')).toHaveCount(0)
	})
})
