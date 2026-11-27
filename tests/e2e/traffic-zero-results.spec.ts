/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-traffic-zero-result-searches, task 3.1: the roll-up counts the
 * searches that found nothing, apart from the searches that did not say, and
 * the Traffic page lists them with a link into the portal's public search.
 *
 * WHAT IS REAL. The seeded measured portal (`open-tilburg`, traffic on with
 * the `search` event and the `searchTerm` dimension, tests/e2e/fixtures/seed-cms.sh),
 * the collector (`POST /api/traffic`), the stored events, the aggregation job
 * (run inside the container, as every traffic spec does), the
 * `portalTrafficDaily` record read back through OpenRegister's object API,
 * the Traffic page, and in the last test the site's own traffic client
 * reporting the search the block ran on the seeded `/zoeken` page.
 *
 * WHAT IS STUBBED, AND WHY. Only opencatalogi's `/api/federation/publications`,
 * in the last test, with `page.route`: it is a sibling app and not on the test
 * instance, and the block reports the result count the endpoint answered. It
 * answers zero rows, which is what a nonsense term gets from a real catalogue.
 *
 * Everything here needs the container (the aggregation job), so the file
 * skips with a stated reason when E2E_CONTAINER is unset. Terms carry a
 * run-unique suffix, so a rerun on the same day reads its own counts.
 *
 * Not here: the roll-up portal's sum (REQ-PZR-001 "A roll-up portal sums its
 * members"), because the seeded second member, open-venray, measures no
 * traffic; TrafficRollupSumTest pins it.
 *
 * @spec openspec/specs/portal-traffic-reporting/spec.md
 */

import type { Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	aggregate,
	APP,
	CONTAINER,
	DESKTOP_UA,
	ENABLED,
	event,
	login,
	newestEvents,
	post,
	todaysRollup,
} from './lib/traffic.ts'

/** A run-unique suffix. */
const RUN = Date.now().toString(36)
const PATH = `/zero-results-${RUN}`

/** The scenario's terms, made run-unique. */
const FOUND = `parkeervergunning ${RUN}`
const MISSPELT = `parkeervergunnig ${RUN}`
const DOG_TAX = `hondenbelasting ${RUN}`
const UNCOUNTED = `zonder telling ${RUN}`
const NONSENSE = `qzxvbn${RUN}`

/** The search page seed-cms.sh places on the measured portal. */
const SEARCH_ROUTE = '/zoeken'

/** opencatalogi's search, as the browser asks it. */
const FEDERATION = '**/apps/opencatalogi/api/federation/publications**'

/** One zero-result entry of a daily record. */
type TermCount = { term: string; count: number }

/**
 * A visitor of its own: the agent is half the daily hash.
 *
 * @param tag Which visitor.
 * @return the headers
 */
function visitor(tag: string): Record<string, string> {
	return { 'User-Agent': `${DESKTOP_UA} zero-results/${RUN}/${tag}` }
}

/**
 * A `search` event as the client posts it: the term lifted to the top level,
 * the result count in `params.results` (src/traffic/client.js). `results`
 * left out means the event says nothing about the count.
 *
 * @param sequence The in-session sequence.
 * @param term     The search term.
 * @param results  The result count, or undefined for none.
 * @return the event
 */
function searchEvent(
	sequence: number,
	term: string,
	results?: number,
): Record<string, unknown> {
	const params: Record<string, unknown> = results === undefined ? {} : { results }
	return { ...event(sequence, PATH, 'search', params), searchTerm: term }
}

/**
 * Today's zero-result entry for one term, or undefined.
 *
 * @param daily The daily record.
 * @param term  The term.
 * @return the entry
 */
function zeroEntry(daily: Record<string, unknown>, term: string): TermCount | undefined {
	const rows = (daily.zeroResultSearches ?? []) as TermCount[]
	return rows.find((row) => row.term === term)
}

/**
 * Wait until the collector stored a search event for a term.
 *
 * @param request The request context.
 * @param term    The term.
 * @param results The result count the event must carry; any when undefined.
 */
async function waitForStoredSearch(
	request: Parameters<typeof newestEvents>[0],
	term: string,
	results?: number,
): Promise<void> {
	await expect
		.poll(
			async () =>
				(await newestEvents(request)).some(
					(e) =>
						e.name === 'search'
						&& e.searchTerm === term
						&& (results === undefined
							|| (e.results ?? (e.params as Record<string, unknown>)?.results)
								=== results),
				),
			{ timeout: 20_000 },
		)
		.toBe(true)
}

/**
 * Answer every search on this page with nothing found.
 *
 * @param page The page.
 */
async function stubEmptyCatalogue(page: Page): Promise<void> {
	await page.route(FEDERATION, (route: Route) =>
		route.fulfill({ json: { results: [], total: 0, page: 1, pages: 1, facets: {} } }),
	)
}

test.describe('searches that found nothing', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	test.beforeAll(() => {
		test.skip(CONTAINER === '', 'set E2E_CONTAINER to the Nextcloud container')
	})

	// REQ-PZR-001, scenario "Two terms found nothing".
	test('REQ-PZR-001: the day lists the two terms that found nothing, with their counts, and not the one that found results', async ({
		request,
	}) => {
		const res = await post(
			request,
			[
				searchEvent(0, FOUND, 12),
				searchEvent(1, MISSPELT, 0),
				searchEvent(2, MISSPELT, 0),
				searchEvent(3, DOG_TAX, 0),
			],
			visitor('two-terms'),
		)
		expect(res.status()).toBe(204)
		await waitForStoredSearch(request, DOG_TAX)
		aggregate()

		const daily = await todaysRollup(request)
		expect(zeroEntry(daily, MISSPELT)?.count).toBe(2)
		expect(zeroEntry(daily, DOG_TAX)?.count).toBe(1)
		expect(zeroEntry(daily, FOUND)).toBeUndefined()
		// Sorted by count, most first.
		const counts = ((daily.zeroResultSearches ?? []) as TermCount[]).map((row) => row.count)
		expect(counts).toEqual([...counts].sort((a, b) => b - a))
	})

	// REQ-PZR-001, scenario "An unknown count is not a zero"; REQ-PZR-002 (the without-count sentence).
	test('REQ-PZR-001: a search without a result count is not a zero, and the page says how many had none', async ({
		request,
		page,
	}) => {
		aggregate()
		const before = Number((await todaysRollup(request)).searchesWithoutCount ?? 0)

		const res = await post(request, [searchEvent(0, UNCOUNTED)], visitor('uncounted'))
		expect(res.status()).toBe(204)
		await waitForStoredSearch(request, UNCOUNTED)
		aggregate()

		const daily = await todaysRollup(request)
		expect(zeroEntry(daily, UNCOUNTED)).toBeUndefined()
		expect(Number(daily.searchesWithoutCount)).toBe(before + 1)

		await login(page)
		await page.goto(`${APP}/traffic`)
		const sentence = page.getByTestId('traffic-searches-without-count').first()
		await expect(sentence).toBeVisible({ timeout: 30_000 })
		await expect(sentence).toContainText(/did not report how many results|meldd?en? niet hoeveel resultaten/)
	})

	// REQ-PZR-002, scenario "An editor sees the misspelling" (task 3.1: search a nonsense term on
	// the site, run the roll-up, find the term on the Traffic page); REQ-PZR-001 for the stored count.
	test('REQ-PZR-002: a nonsense term searched on the site is listed on the Traffic page with a link to the public search', async ({
		request,
		page,
	}) => {
		await stubEmptyCatalogue(page)
		await page.goto(`${APP}/site?portal=${ENABLED}&route=${SEARCH_ROUTE}`)
		await expect(page.getByTestId('federated-search')).toBeVisible({ timeout: 60_000 })

		// The address the block writes (`?_search=`) is itself reported as a
		// search with no count by the client's page-view hook; the block's own
		// report is the one carrying `results`.
		const beacon = page.waitForRequest(
			(req) =>
				req.method() === 'POST'
				&& req.url().includes('/apps/portaliq/api/traffic')
				&& /"name":"search"/.test(req.postData() ?? '')
				&& /"results":0/.test(req.postData() ?? ''),
			{ timeout: 30_000 },
		)
		await page.locator('#pq-federated-search').fill(NONSENSE)
		await page.locator('#pq-federated-search').press('Enter')
		await expect(page.getByTestId('federated-search-empty')).toBeVisible({ timeout: 30_000 })

		// What the site's client sent: the term and a result count of 0.
		const batch = JSON.parse((await beacon).postData() ?? '{}')
		const sent = ((batch.events ?? []) as Array<Record<string, unknown>>).find(
			(e) =>
				e.name === 'search'
				&& (e.params as Record<string, unknown>)?.results === 0,
		)
		expect(sent, 'the block reported the search with its result count').toBeTruthy()
		expect(sent!.searchTerm ?? (sent!.params as Record<string, unknown>)?.searchTerm).toBe(
			NONSENSE,
		)

		await waitForStoredSearch(request, NONSENSE, 0)
		aggregate()
		expect(zeroEntry(await todaysRollup(request), NONSENSE)?.count).toBe(1)

		await login(page)
		await page.goto(`${APP}/traffic`)
		const table = page.getByTestId('traffic-zero-results').first()
		await expect(table).toBeVisible({ timeout: 30_000 })
		const row = table.locator('tr').filter({ hasText: NONSENSE })
		await expect(row.locator('td').nth(1)).toHaveText('1')
		const link = row.getByRole('link', { name: NONSENSE })
		await expect(link).toHaveAttribute('href', new RegExp(`_search=${NONSENSE}`))
		await expect(link).toHaveAttribute('href', /portal=open-tilburg/)

		// The link opens the portal's public search with the term typed in.
		const href = String(await link.getAttribute('href'))
		await page.goto(new URL(href, APP).toString())
		await expect(page.locator('#pq-federated-search')).toHaveValue(NONSENSE, {
			timeout: 60_000,
		})
	})
})
