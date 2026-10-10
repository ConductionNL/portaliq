/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * search-sort-by-relevance: on an instance whose OpenRegister has pg_trgm, and
 * with local publications only (the case that works without opencatalogi's
 * pass-through), a search with a term puts the best match first, describes
 * each match to assistive technology, still finds a misspelt title, and turns
 * a misspelt summary word into a checked "Bedoelde u".
 *
 * Run it:
 *
 *     PORTALIQ_E2E_ALLOW_SHARED_INSTANCE=http://localhost:8080 \
 *     PLAYWRIGHT_BASE_URL=http://localhost:8080 PORTALIQ_E2E_CONTAINER=nextcloud \
 *     npx playwright test -c tests/e2e/playwright.config.ts search-sort-by-relevance
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md
 */

import type { APIRequestContext, APIResponse } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { BASE_URL } from './base-url.ts'
import { occPrefix } from './shared-instance.ts'

const STAMP = Date.now()
// Letters only: the word list keeps letters, so a digit would split the word.
const LETTERS = String(STAMP)
	.split('')
	.map((digit) => 'abcdefghij'[Number(digit)])
	.join('')
const PORTAL = process.env.PORTALIQ_E2E_PORTAL ?? 'open-tilburg'
const SEARCH_ROUTE = `/relevantie-e2e-${STAMP}`
const SITE = '/index.php/apps/portaliq/site'
const OR_API = '/index.php/apps/openregister/api/objects'
const WANTED = `Parkeervergunning aanvragen ${LETTERS}`
const NEWER = `Jaarverslag parkeren ${LETTERS}`
const SUMMARY_WORD = `hondenbelasting${LETTERS}`
const MISSPELT_SUMMARY_WORD = `hondenbelasing${LETTERS}`

const ADMIN_HEADERS = {
	Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`,
	'OCS-APIRequest': 'true',
	requesttoken: 'x',
}

const created: Array<{ register: string; schema: string; id: string }> = []
let admin: APIRequestContext | null = null

/**
 * The JSON body of an answer, with its status in the failure message.
 *
 * @param res The answer.
 * @param what What was asked.
 * @return The decoded body.
 */
async function json(res: APIResponse, what: string): Promise<any> {
	const text = await res.text()
	expect(
		res.ok(),
		`${what}: HTTP ${res.status()} ${text.slice(0, 400)}`,
	).toBeTruthy()
	return text === '' ? {} : JSON.parse(text)
}

/**
 * Create an OpenRegister object as admin and record it for cleanup.
 *
 * @param register Register slug.
 * @param schema Schema slug.
 * @param data The object.
 * @return The stored object.
 */
async function createObject(
	register: string,
	schema: string,
	data: object,
): Promise<any> {
	const object = await json(
		await admin!.post(`${OR_API}/${register}/${schema}`, {
			headers: ADMIN_HEADERS,
			data,
		}),
		`create ${register}/${schema}`,
	)
	created.push({
		register,
		schema,
		id: String(object?.id ?? object?.['@self']?.id ?? ''),
	})
	return object
}

/**
 * Run occ in the instance.
 *
 * @param args The occ arguments.
 * @return Its output.
 */
function occ(...args: string[]): string {
	const [command, ...prefix] = occPrefix()
	return execFileSync(command, [...prefix, ...args], {
		encoding: 'utf8',
		timeout: 300_000,
	})
}

/**
 * The search page, searched for a term.
 *
 * @param term The term.
 * @return The address.
 */
function searchFor(term: string): string {
	const params = new URLSearchParams({ portal: PORTAL, route: SEARCH_ROUTE })
	if (term !== '') {
		params.set('_search', term)
	}
	return `${SITE}?${params.toString()}`
}

test.describe.serial('search sorts by relevance and corrects a misspelling', () => {
	test.beforeAll(async () => {
		admin = await playwrightRequest.newContext({ baseURL: BASE_URL })
		const lastYear = new Date(Date.now() - 365 * 86_400_000).toISOString()
		const lastWeek = new Date(Date.now() - 7 * 86_400_000).toISOString()
		await createObject('publication', 'publication', {
			title: WANTED,
			summary: `Zo vraagt u een parkeervergunning aan. Over de ${SUMMARY_WORD}.`,
			publicationDate: lastYear,
			status: 'published',
		})
		await createObject('publication', 'publication', {
			title: NEWER,
			summary: 'Het jaarverslag.',
			publicationDate: lastWeek,
			status: 'published',
		})
		await createObject('portaliq', 'page', {
			title: 'Zoeken op relevantie (e2e)',
			route: SEARCH_ROUTE,
			portal: PORTAL,
			status: 'published',
			locale: 'nl',
			body: {
				type: 'grid',
				widgets: [
					{
						id: 'main',
						slot: 'body',
						gridX: 0,
						gridY: 0,
						gridWidth: 12,
						gridHeight: 6,
						widgetKey: 'federatedSearch',
						props: { pageSize: 10 },
					},
				],
			},
		})
	})

	test.afterAll(async () => {
		for (const { register, schema, id } of [...created].reverse()) {
			if (id !== '') {
				await admin?.delete(`${OR_API}/${register}/${schema}/${id}`, {
					headers: ADMIN_HEADERS,
				})
			}
		}
		await admin?.dispose()
	})

	// @e2e portal-federated-search::a-visitor-gets-the-best-matches-first
	// @e2e portal-federated-search::a-screen-reader-user-hears-the-match
	test('a term puts the best match first, and the match is described, not shown', async ({
		page,
	}) => {
		await page.goto(searchFor(`parkeervergunning aanvragen ${LETTERS}`))
		const sort = page.getByTestId('federated-search-sort')
		await expect(
			sort.locator('option', { hasText: 'Meest relevant' }),
		).toHaveCount(1)
		await expect(sort).toHaveValue('_relevance:DESC')

		const first = page.getByTestId('federated-search-result').first()
		await expect(first).toContainText(WANTED)

		const link = first.getByTestId('federated-search-result-link')
		await expect(link).toHaveAccessibleDescription(
			/^Overeenkomst: \d{1,3} procent$/,
		)
		await expect(first.getByTestId('federated-search-result-match')).toHaveClass(
			/sr-only/,
		)
	})

	// @e2e portal-federated-search::no-term-no-relevance-option
	test('without a term there is no relevance option', async ({ page }) => {
		await page.goto(searchFor(''))
		const sort = page.getByTestId('federated-search-sort')
		await expect(sort).toBeVisible()
		await expect(
			sort.locator('option', { hasText: 'Meest relevant' }),
		).toHaveCount(0)
	})

	// @e2e portal-federated-search::a-misspelt-title-is-still-found
	test('a misspelt title is still found in the default order', async ({
		page,
	}) => {
		const asked = page.waitForRequest(
			(request) =>
				request.url().includes('/api/federation/publications')
				&& request.url().includes('_fuzzy=true'),
		)
		await page.goto(searchFor(`parkeervergunnig aanvragen ${LETTERS}`))
		await asked
		await expect(page.getByText(WANTED).first()).toBeVisible()
	})

	// @e2e portal-federated-search::a-misspelling-in-a-summary-word-gets-a-suggestion
	test('a misspelt summary word gets a "Bedoelde u" that finds results', async ({
		page,
	}) => {
		const listing = occ(
			'background-job:list',
			'--class=OCA\\Portaliq\\BackgroundJob\\SuggestionWordListJob',
		)
		const ids = [...listing.matchAll(/^\|\s*(\d+)\s*\|/gm)].map(
			(match) => match[1],
		)
		expect(ids.length, 'SuggestionWordListJob is registered').toBeGreaterThan(0)
		occ('background-job:execute', ids[0], '--force-execute')

		await page.goto(searchFor(MISSPELT_SUMMARY_WORD))
		const suggestion = page.getByTestId('federated-search-did-you-mean')
		await expect(suggestion).toHaveText(SUMMARY_WORD)
		await expect(page.getByTestId('federated-search-status')).toContainText(
			`Bedoelde u: ${SUMMARY_WORD}?`,
		)
		await suggestion.click()
		await expect(page.getByText(WANTED).first()).toBeVisible()
	})
})
