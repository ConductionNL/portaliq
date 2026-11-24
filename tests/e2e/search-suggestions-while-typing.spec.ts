/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * search-suggestions-while-typing (REQ-SST-001, REQ-SST-002): the search
 * block suggests publication titles while a visitor types, after three
 * characters and a short pause, shows nothing when the source fails, and the
 * list works by keyboard and announces its size. axe checks the page with
 * the list open.
 *
 * WHAT IS REAL. The seeded portal (`open-tilburg`, tests/e2e/fixtures/seed-cms.sh,
 * or PORTALIQ_E2E_PORTAL), one search page this file creates through
 * OpenRegister's object API and removes again, the site SPA, the block, the
 * suggestion list, its keyboard handling and its live region.
 *
 * WHAT IS STUBBED, AND WHY. opencatalogi is a sibling app and is not on the
 * test instance. Its federation read is answered in the browser with
 * `page.route`, from a small corpus, as tests/e2e/site-federated-search.spec.ts
 * does. The suggestions ask that same endpoint with `_fields`, so the stub
 * tells a suggestion request from a search request by that parameter. The
 * scenario's kind "Woo-besluit" is the catalogue's own wording; the block
 * names the kind of record ("Publicatie"), so that is what is asserted.
 *
 * axe-core is injected from node_modules, as in site-accessibility.spec.ts.
 *
 * Written, not run here (needs a live instance):
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test search-suggestions-while-typing
 *
 * @spec openspec/changes/archive/2026-11-24-search-suggestions-while-typing/specs/portal-federated-search/spec.md#requirement-the-search-box-suggests-publications-while-you-type-req-sst-001
 * @spec openspec/changes/archive/2026-11-24-search-suggestions-while-typing/specs/portal-federated-search/spec.md#requirement-the-suggestion-list-works-by-keyboard-and-screen-reader-req-sst-002
 */

import type { APIRequestContext, Page, Route } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { resolveBaseURL } from './base-url.ts'

// CommonJS require, because this suite is compiled as CJS.
const AXE_SOURCE = readFileSync(require.resolve('axe-core'), 'utf8')

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const PAGES = `${BASE}/index.php/apps/openregister/api/objects/portaliq/page`

const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** The seeded portal (fixtures/seed-cms.sh). */
const PORTAL = process.env.PORTALIQ_E2E_PORTAL || 'open-tilburg'

/** The search page this file owns. */
const SEARCH_ROUTE = `/e2e-zoeksuggesties-${Date.now()}`

/** opencatalogi's federation read, as the browser asks it. */
const FEDERATION = '**/apps/opencatalogi/api/federation/publications**'

/** One row of the stubbed corpus, in the endpoint's own row shape. */
type Row = Record<string, unknown>

/**
 * A publication row.
 *
 * @param id   its id
 * @param name its title
 * @return the row
 */
function publication(id: string, name: string): Row {
	return {
		id,
		name,
		resultType: 'publication',
		'@self': { id, schema: 'publication' },
	}
}

/** The corpus: three titles match "fietspad", one does not. */
const CORPUS: Row[] = [
	publication('e2e-sst-1', 'fietspad Lindelaan verlichting'),
	publication('e2e-sst-2', 'fietspad Lindelaan herinrichting'),
	publication('e2e-sst-3', 'fietspad Stationsweg onderhoud'),
	publication('e2e-sst-4', 'Parkeerbeleid binnenstad 2026'),
]

/** The page this file created, by id, removed again in afterAll. */
const created: string[] = []

/**
 * An admin API context. Seeding and cleanup only; the visitor never holds it.
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
 * The id of a created row.
 *
 * @param row the row
 * @return its id
 */
function idOf(row: Row): string {
	const self = (row['@self'] ?? {}) as Row
	return String(self.id ?? row.id ?? '')
}

/**
 * The rows matching a typed text, on every word of it.
 *
 * @param term the typed text
 * @return the rows
 */
function matching(term: string): Row[] {
	const words = term.trim().toLowerCase().split(/\s+/).filter(Boolean)
	return CORPUS.filter((row) =>
		words.every((word) => String(row.name).toLowerCase().includes(word)),
	)
}

/**
 * Stub the federation read. Suggestion requests (`_fields`) are counted and,
 * when `failSuggestions` is set, answered with a server error; search requests
 * always answer from the corpus.
 *
 * @param page            the page
 * @param failSuggestions whether the suggestion requests fail
 * @return the counters, read after the actions
 */
async function stubCatalogue(
	page: Page,
	failSuggestions = false,
): Promise<{ suggestions: string[]; searches: string[] }> {
	const seen = { suggestions: [] as string[], searches: [] as string[] }
	await page.route(FEDERATION, async (route: Route) => {
		const url = new URL(route.request().url())
		const term = url.searchParams.get('_search') ?? ''
		const rows = matching(term)
		if (url.searchParams.has('_fields')) {
			seen.suggestions.push(term)
			if (failSuggestions) {
				await route.fulfill({ status: 500, json: { message: 'down' } })
				return
			}
			await route.fulfill({
				json: {
					results: rows.slice(0, 5),
					total: rows.length,
					page: 1,
					pages: 1,
				},
			})
			return
		}
		seen.searches.push(term)
		await route.fulfill({
			json: {
				results: rows,
				total: rows.length,
				page: 1,
				pages: 1,
				facets: {},
			},
		})
	})
	return seen
}

/**
 * Open the search page, anonymously, and wait for the block.
 *
 * @param page the page
 */
async function openSearchPage(page: Page): Promise<void> {
	await page.goto(`${SITE}${SEARCH_ROUTE}?portal=${PORTAL}`)
	await expect(page.getByTestId('federated-search')).toBeVisible({
		timeout: 60_000,
	})
}

/**
 * The block's search input.
 *
 * @param page the page
 * @return the input
 */
function searchInput(page: Page) {
	return page.locator('#pq-federated-search')
}

test.beforeAll(async () => {
	const api = await adminApi()
	try {
		const response = await api.post(PAGES, {
			data: {
				title: 'Zoeksuggesties',
				route: SEARCH_ROUTE,
				portal: PORTAL,
				status: 'published',
				locale: 'nl',
				body: {
					type: 'grid',
					widgets: [
						{
							id: 'e2e-sst-search',
							widgetKey: 'federatedSearch',
							slot: 'body',
							gridX: 0,
							gridY: 0,
							gridWidth: 12,
							gridHeight: 10,
							props: {},
						},
					],
				},
			},
		})
		expect(response.ok(), await response.text()).toBe(true)
		created.push(idOf(await response.json()))
	} finally {
		await api.dispose()
	}
})

test.afterAll(async () => {
	const api = await adminApi()
	try {
		for (const id of created) {
			await api.delete(`${PAGES}/${id}`)
		}
	} finally {
		await api.dispose()
	}
})

test.describe('search-suggestions-while-typing', () => {
	test('Three suggestions: typing and pausing lists the matching titles with their kind', async ({
		page,
	}) => {
		await stubCatalogue(page)
		await openSearchPage(page)

		await searchInput(page).pressSequentially('fietspad Lin', { delay: 30 })
		const list = page.getByTestId('search-suggestions-list')
		await expect(list).toBeVisible()
		await expect(page.getByTestId('search-suggestion-0')).toContainText(
			'fietspad Lindelaan verlichting',
		)
		await expect(page.getByTestId('search-suggestion-0')).toContainText(
			'Publicatie',
		)
		await expect(list.getByRole('option')).toHaveCount(2)
	})

	test('Two characters: no suggestion list opens', async ({ page }) => {
		const seen = await stubCatalogue(page)
		await openSearchPage(page)

		await searchInput(page).pressSequentially('fi', { delay: 30 })
		// Longer than the 250 ms pause, so a list would have opened by now.
		await page.waitForTimeout(800)
		await expect(page.getByTestId('search-suggestions-list')).toHaveCount(0)
		expect(seen.suggestions).toEqual([])
	})

	test('The search source is down: no list and no error, and Zoeken still searches', async ({
		page,
	}) => {
		const seen = await stubCatalogue(page, true)
		await openSearchPage(page)

		await searchInput(page).pressSequentially('fietspad', { delay: 30 })
		await expect.poll(() => seen.suggestions.length).toBeGreaterThan(0)
		await expect(page.getByTestId('search-suggestions-list')).toHaveCount(0)
		await expect(page.getByTestId('search-suggestions')).not.toContainText(
			/fout|error|storing/i,
		)

		await searchInput(page).press('Enter')
		await expect.poll(() => seen.searches).toContain('fietspad')
		await expect(
			page.getByTestId('federated-search-result').first(),
		).toBeVisible()
	})

	test('Keyboard only: arrow down twice and Enter opens the second suggestion', async ({
		page,
	}) => {
		await stubCatalogue(page)
		await openSearchPage(page)

		const input = searchInput(page)
		await input.focus()
		await input.pressSequentially('fietspad', { delay: 30 })
		await expect(page.getByTestId('search-suggestions-list')).toBeVisible()

		// The input is a combobox controlling the listbox.
		await expect(input).toHaveAttribute('role', 'combobox')
		await expect(input).toHaveAttribute('aria-expanded', 'true')
		const listId = await page
			.getByTestId('search-suggestions-list')
			.getAttribute('id')
		await expect(input).toHaveAttribute('aria-controls', String(listId))

		await input.press('ArrowDown')
		await input.press('ArrowDown')
		const second = page.getByTestId('search-suggestion-1')
		await expect(second).toHaveAttribute('aria-selected', 'true')
		await expect(input).toHaveAttribute(
			'aria-activedescendant',
			String(await second.getAttribute('id')),
		)

		await input.press('Enter')
		await expect(page).toHaveURL(/publicatie(\/|%2F)e2e-sst-2/)
	})

	test('Escape closes the list and keeps the text', async ({ page }) => {
		await stubCatalogue(page)
		await openSearchPage(page)

		const input = searchInput(page)
		await input.pressSequentially('fietspad', { delay: 30 })
		await expect(page.getByTestId('search-suggestions-list')).toBeVisible()
		await input.press('Escape')
		await expect(page.getByTestId('search-suggestions-list')).toHaveCount(0)
		await expect(input).toHaveValue('fietspad')
	})

	test('Screen reader: the live region announces "3 suggesties"', async ({
		page,
	}) => {
		await stubCatalogue(page)
		await openSearchPage(page)

		await searchInput(page).pressSequentially('fietspad', { delay: 30 })
		await expect(
			page.getByTestId('search-suggestions-list').getByRole('option'),
		).toHaveCount(3)
		await expect(page.getByTestId('search-suggestions-live')).toHaveText(
			'3 suggesties',
		)
	})

	test('the page with the list open has no serious or critical axe violations', async ({
		page,
	}) => {
		await stubCatalogue(page)
		await openSearchPage(page)

		await searchInput(page).pressSequentially('fietspad', { delay: 30 })
		await expect(page.getByTestId('search-suggestions-list')).toBeVisible()

		await page.addScriptTag({ content: AXE_SOURCE })
		const results = await page.evaluate<{
			violations: { id: string; impact: string | null; help: string }[]
		}>(`
			window.axe.run('[data-testid="site-root"]', {
				runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
			})
		`)
		const serious = results.violations.filter(
			(v) => v.impact === 'serious' || v.impact === 'critical',
		)
		expect(serious, serious.map((v) => `${v.id}: ${v.help}`).join('\n')).toEqual(
			[],
		)
	})
})
