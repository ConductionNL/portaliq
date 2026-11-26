/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * search-sort-by-relevance, tasks T04 and T11: the public search block puts
 * the best matches first, offers "Beste match" only with a term, describes a
 * row's score to assistive technology only, tolerates a misspelt title and
 * offers a checked correction.
 *
 * WHAT IS REAL. The seeded portal (`open-tilburg`, tests/e2e/fixtures/seed-cms.sh),
 * a search page this file creates through OpenRegister's object API and
 * removes again, the site SPA, the block, the order and the fuzzy flag it
 * asks for, the sort control it draws, the accessible description of a row
 * link, and portaliq's own suggest route (`/api/site/search/suggest`).
 *
 * WHAT IS STUBBED, AND WHY. opencatalogi is a sibling app and is not on the
 * test instance (the seed installs no catalogue), so for the first four
 * tests its `/api/federation/publications` is answered in the browser with
 * `page.route`. The stub behaves like OpenRegister behind it as the design
 * reads it (design.md, "What exists"): with `_order[_relevance]=DESC` and
 * `_fuzzy=true` it scores each row on title similarity, puts it on
 * `@self.relevance` and sorts by it; without that order it answers newest
 * first; with `_fuzzy=true` a title one or two letters off still matches.
 * What those tests prove is portaliq's half: what the block ASKS and how it
 * DRAWS the answer. The ranking itself is OpenRegister's (pg_trgm).
 *
 * THE SUGGESTION IS NOT STUBBED. The correction comes from portaliq's server,
 * from a word list built out of what the anonymous public search returns
 * (SuggestionWordList, rebuilt by SuggestionWordListJob). A browser stub
 * cannot reach that server-side read, so the REQ-SSR-005 test runs only where
 * opencatalogi answers on the instance and the container is reachable for
 * the job (`E2E_CONTAINER`), and skips with that reason otherwise. It writes
 * one public publication and deletes it again.
 *
 * Not here: REQ-SSR-002 (`@e2e exclude`: needs an instance without pg_trgm;
 * pinned by the node tests), REQ-SSR-006 (the admin's "How search ranks",
 * pinned by `the explanation is rendered from the declaration`).
 *
 * The labels are the block's Dutch ones: "Beste match" (board Zoeken) and
 * "Overeenkomst: {n} procent" (T05: the block's strings are Dutch only).
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md
 */

import type { APIRequestContext, Page, Request, Route } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'
import { CONTAINER, runJob } from './lib/traffic.ts'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const OBJECTS = `${BASE}/index.php/apps/openregister/api/objects`
const PAGES = `${OBJECTS}/portaliq/page`
const FEDERATION_API = `${BASE}/index.php/apps/opencatalogi/api/federation/publications`

const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** The seeded portal (fixtures/seed-cms.sh). */
const PORTAL = process.env.PORTALIQ_E2E_PORTAL || 'open-tilburg'

/** The search page this file owns. */
const ROUTE = '/e2e-search-relevance'

/** opencatalogi's search, as the browser asks it. */
const FEDERATION = '**/apps/opencatalogi/api/federation/publications**'

/** One row of the stubbed corpus. */
type Row = Record<string, unknown>

/** The scenario's two publications, and a third that matches nothing. */
const CORPUS: Row[] = [
	{
		id: 'e2e-ssr-jaarverslag',
		name: 'Jaarverslag 2025',
		summary: 'Het jaarverslag over 2025, met een paragraaf over de parkeervergunning.',
		publicationDate: new Date(Date.now() - 7 * 86_400_000).toISOString(),
		'@self': { id: 'e2e-ssr-jaarverslag', schema: 'publication' },
	},
	{
		id: 'e2e-ssr-parkeren',
		name: 'Parkeervergunning aanvragen',
		summary: 'Zo vraagt u een parkeervergunning aan.',
		publicationDate: new Date(Date.now() - 365 * 86_400_000).toISOString(),
		'@self': { id: 'e2e-ssr-parkeren', schema: 'publication' },
	},
	{
		id: 'e2e-ssr-bewoners',
		name: 'Parkeervergunning bewoners',
		summary: 'Tarieven voor bewoners.',
		publicationDate: new Date(Date.now() - 400 * 86_400_000).toISOString(),
		'@self': { id: 'e2e-ssr-bewoners', schema: 'publication' },
	},
	{
		id: 'e2e-ssr-afval',
		name: 'Afvalkalender 2026',
		summary: 'Wanneer het afval wordt opgehaald.',
		publicationDate: new Date(Date.now() - 30 * 86_400_000).toISOString(),
		'@self': { id: 'e2e-ssr-afval', schema: 'publication' },
	},
]

/**
 * The edit distance between two words.
 *
 * @param a one word
 * @param b the other
 * @return the number of single-letter edits between them
 */
function distance(a: string, b: string): number {
	const row = Array.from({ length: b.length + 1 }, (_, index) => index)
	for (let i = 1; i <= a.length; i++) {
		let previous = row[0]
		row[0] = i
		for (let j = 1; j <= b.length; j++) {
			const kept = row[j]
			row[j] = Math.min(
				row[j] + 1,
				row[j - 1] + 1,
				previous + (a[i - 1] === b[j - 1] ? 0 : 1),
			)
			previous = kept
		}
	}
	return row[b.length]
}

/**
 * How well a title matches a term, 0 to 100, standing in for OpenRegister's
 * `similarity(_name, term) * 100`: how close the title's closest word is to
 * the term. Rows that score the same keep the corpus order, as a stable sort
 * on the server keeps its own.
 *
 * @param title the title
 * @param term  the term, lower case
 * @return the score
 */
function similarity(title: string, term: string): number {
	const words = title.toLowerCase().split(/\s+/)
	const closest = words.reduce((best, word) =>
		distance(word, term) < distance(best, term) ? word : best,
	)
	const longest = Math.max(term.length, closest.length)
	return Math.round((1 - distance(closest, term) / longest) * 100)
}

/**
 * Whether a row matches a term: exactly in its title or summary, or, with
 * `_fuzzy`, with a title word at most two letters off.
 *
 * @param row   the row
 * @param term  the term, lower case
 * @param fuzzy whether `_fuzzy=true` was sent
 * @return true when it matches
 */
function matches(row: Row, term: string, fuzzy: boolean): boolean {
	const text = `${row.name} ${row.summary}`.toLowerCase()
	if (text.includes(term)) {
		return true
	}
	return (
		fuzzy
		&& String(row.name)
			.toLowerCase()
			.split(/\s+/)
			.some((word) => distance(word, term) <= 2)
	)
}

/**
 * Answer one search the way the endpoint behind the block does.
 *
 * @param url the request address
 * @return the answer body
 */
function searchAnswer(url: URL): Row {
	const params = url.searchParams
	const term = (params.get('_search') ?? '').trim().toLowerCase()
	const fuzzy = params.get('_fuzzy') === 'true'
	const ranked = params.get('_order[_relevance]') === 'DESC' && fuzzy && term !== ''

	const found = CORPUS.filter((row) => term === '' || matches(row, term, fuzzy)).map(
		(row) => {
			const self = { ...(row['@self'] as Row) }
			if (ranked) {
				self.relevance = similarity(String(row.name), term)
			}
			return { ...row, '@self': self }
		},
	)
	found.sort((a, b) =>
		ranked
			? Number((b['@self'] as Row).relevance) - Number((a['@self'] as Row).relevance)
			: String(b.publicationDate).localeCompare(String(a.publicationDate)),
	)

	return { results: found, total: found.length, page: 1, pages: 1, facets: {} }
}

/**
 * Stub opencatalogi's search for this page and record the block's searches
 * (the typing suggestions ask the same endpoint with `_fields`).
 *
 * @param page the page
 * @return the search requests the block sent
 */
async function stubCatalogue(page: Page): Promise<Request[]> {
	const searches: Request[] = []
	await page.route(FEDERATION, async (route: Route) => {
		const url = new URL(route.request().url())
		if (!url.searchParams.has('_fields')) {
			searches.push(route.request())
		}
		await route.fulfill({ json: searchAnswer(url) })
	})
	return searches
}

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
 * The id of an object row.
 *
 * @param row the row
 * @return its id
 */
function idOf(row: Row): string {
	const self = (row['@self'] ?? {}) as Row
	return String(self.id ?? row.id ?? '')
}

/**
 * Remove this file's search page from the seeded portal.
 *
 * @param api the admin context
 */
async function removePage(api: APIRequestContext): Promise<void> {
	const listed = await api.get(`${PAGES}?_limit=500`)
	if (!listed.ok()) {
		return
	}
	const body = await listed.json()
	const rows: Row[] = Array.isArray(body) ? body : (body.results ?? [])
	for (const row of rows) {
		if (row.route === ROUTE && row.portal === PORTAL) {
			await api.delete(`${PAGES}/${idOf(row)}`)
		}
	}
}

/**
 * Open the search page and wait for the block's first answer.
 *
 * @param page the page
 */
async function openSearchPage(page: Page): Promise<void> {
	await page.goto(`${SITE}?portal=${PORTAL}&route=${ROUTE}`)
	await expect(page.getByTestId('federated-search')).toBeVisible({ timeout: 60_000 })
	await expect(page.getByTestId('federated-search-count')).toBeVisible({ timeout: 30_000 })
}

/**
 * Search for a term through the search box and wait for the block's answer.
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
 * The titles of the listed results, in order.
 *
 * @param page the page
 * @return the titles
 */
async function resultTitles(page: Page): Promise<string[]> {
	return (
		await page
			.getByTestId('federated-search-result')
			.locator('.pq-search__result-title')
			.allInnerTexts()
	).map((title) => title.trim())
}

test.beforeAll(async () => {
	const api = await adminApi()
	await removePage(api)
	const created = await api.post(PAGES, {
		data: {
			title: 'E2E search by relevance',
			route: ROUTE,
			portal: PORTAL,
			status: 'published',
			locale: 'nl',
			body: {
				type: 'grid',
				widgets: [
					{
						id: 'e2e-search-relevance',
						widgetKey: 'federatedSearch',
						slot: 'body',
						gridX: 0,
						gridY: 0,
						gridWidth: 12,
						gridHeight: 8,
						props: { hidePeriod: true },
					},
				],
			},
		},
	})
	expect(created.ok(), await created.text()).toBe(true)
	await api.dispose()
})

test.afterAll(async () => {
	const api = await adminApi()
	await removePage(api)
	await api.dispose()
})

test.describe('a search with a term can be sorted by relevance', () => {
	test.setTimeout(120_000)

	// @e2e openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#scenario-a-visitor-gets-the-best-matches-first
	test('REQ-SSR-001: a search for "parkeervergunning" opens on "Beste match" with the matching publication first', async ({
		page,
	}) => {
		const searches = await stubCatalogue(page)
		await openSearchPage(page)

		await search(page, 'parkeervergunning')

		const sent = lastSearch(searches)
		expect(sent.get('_order[_relevance]')).toBe('DESC')
		expect(sent.get('_fuzzy')).toBe('true')

		const sort = page.getByTestId('federated-search-sort')
		await expect(sort).toHaveValue('_relevance:DESC')
		await expect(sort.locator('option:checked')).toHaveText('Beste match')
		const titles = await resultTitles(page)
		expect(titles[0]).toBe('Parkeervergunning aanvragen')
		// The newest publication matched too, and is not first.
		expect(titles).toContain('Jaarverslag 2025')
		expect(titles[0]).not.toBe('Jaarverslag 2025')
		// The order was applied: no "not available here" notice.
		await expect(page.getByTestId('federated-search-status')).not.toContainText(
			'Sorteren op relevantie kan hier niet.',
		)
	})

	// @e2e openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#scenario-no-term-no-relevance-option
	test('REQ-SSR-001: without a term "Beste match" is not among the options', async ({ page }) => {
		const searches = await stubCatalogue(page)
		await openSearchPage(page)

		expect(lastSearch(searches).has('_search')).toBe(false)
		expect(lastSearch(searches).has('_order[_relevance]')).toBe(false)
		const options = await page
			.getByTestId('federated-search-sort')
			.locator('option')
			.allInnerTexts()
		expect(options.map((option) => option.trim())).toEqual([
			'Standaardvolgorde',
			'Datum - nieuw naar oud',
			'Datum - oud naar nieuw',
			'Naam - A naar Z',
			'Naam - Z naar A',
		])
	})

	// @e2e openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#scenario-a-screen-reader-user-hears-the-match
	test('REQ-SSR-003: a ranked result link is described to assistive technology with its score, hidden from sight', async ({
		page,
	}) => {
		await stubCatalogue(page)
		await openSearchPage(page)
		await search(page, 'parkeervergunning')

		const first = page.getByTestId('federated-search-result').first()
		const score = similarity('Parkeervergunning aanvragen', 'parkeervergunning')
		await expect(first.getByTestId('federated-search-result-link')).toHaveAccessibleDescription(
			`Overeenkomst: ${score} procent`,
		)
		// For assistive technology only: the words sit in a visually hidden span.
		await expect(first.getByTestId('federated-search-match')).toHaveClass(/\bsr-only\b/)
	})
})

test.describe('a misspelt search still finds, and a correction is offered', () => {
	test.setTimeout(180_000)

	// REQ-SSR-004, scenario "A misspelt title is still found" (task T11).
	test('REQ-SSR-004: "parkeervergunnig" in the default order sends _fuzzy and finds "Parkeervergunning bewoners"', async ({
		page,
	}) => {
		const searches = await stubCatalogue(page)
		await openSearchPage(page)

		await search(page, 'parkeervergunnig')
		expect(lastSearch(searches).get('_fuzzy')).toBe('true')

		// The same term in the default order: still fuzzy, no relevance order.
		const reordered = page.waitForResponse((response) => {
			const url = new URL(response.url())
			return (
				url.pathname.endsWith('/api/federation/publications')
				&& url.searchParams.get('_search') === 'parkeervergunnig'
				&& !url.searchParams.has('_order[_relevance]')
			)
		})
		await page.getByTestId('federated-search-sort').selectOption('')
		await reordered

		const sent = lastSearch(searches)
		expect(sent.get('_fuzzy')).toBe('true')
		expect(sent.has('_order[_relevance]')).toBe(false)
		await expect(page.getByTestId('federated-search-sort')).toHaveValue('')
		expect(await resultTitles(page)).toContain('Parkeervergunning bewoners')
	})

	// REQ-SSR-005, scenario "A misspelling in a summary word gets a suggestion" (task T11). Live only: see the file header.
	test('REQ-SSR-005: "hondenbelasing" finds nothing and offers "Bedoelde u: hondenbelasting?", which finds results', async ({
		page,
		request: api,
	}) => {
		test.skip(CONTAINER === '', 'set E2E_CONTAINER: the word list is built by a background job')
		const catalogue = await api.get(`${FEDERATION_API}?_limit=1`)
		test.skip(
			!catalogue.ok(),
			`opencatalogi does not answer on this instance (${catalogue.status()}): the word list is read from its public search`,
		)

		const basic = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
		const headers = {
			'OCS-APIRequest': 'true',
			Authorization: `Basic ${basic}`,
			'Content-Type': 'application/json',
		}
		const publication = await api.post(`${OBJECTS}/publication/publication`, {
			headers,
			data: {
				title: `Belastingen e2e ${Date.now().toString(36)}`,
				summary: 'Alles over de hondenbelasting en hoe u die betaalt.',
				publicationDate: new Date(Date.now() - 60_000).toISOString(),
			},
		})
		expect(publication.ok(), await publication.text()).toBe(true)
		const publicationId = idOf(await publication.json())

		try {
			// A new publication starts in "draft" and OpenRegister refuses "published" on create
			// (lifecycle-invalid-initial); with review required it reaches "published" only through
			// the lifecycle: submit, approve, publish (opencatalogi publication-lifecycle-on-or).
			for (const action of ['submit', 'approve', 'publish']) {
				const moved = await api.post(`${OBJECTS}/${publicationId}/transition`, {
					headers,
					data: { action },
				})
				expect(moved.ok(), `${action}: ${await moved.text()}`).toBe(true)
			}

			runJob('SuggestionWordListJob')

			await openSearchPage(page)
			await search(page, 'hondenbelasing')

			const suggestion = page.getByTestId('federated-search-did-you-mean')
			await expect(suggestion).toHaveText('Bedoelde u: hondenbelasting?', {
				timeout: 30_000,
			})
			// Inside the live region, so it is announced with the count.
			await expect(
				page.getByTestId('federated-search-status').getByTestId('federated-search-did-you-mean'),
			).toBeVisible()

			await suggestion.click()

			await expect(page.locator('#pq-federated-search')).toHaveValue('hondenbelasting')
			await expect(page.getByTestId('federated-search-result').first()).toBeVisible({
				timeout: 30_000,
			})
		} finally {
			await api.delete(`${OBJECTS}/publication/publication/${publicationId}`, { headers })
		}
	})
})
