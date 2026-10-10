/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-honest-without-javascript: with JavaScript off, every site page says so
 * and links to a plain version of the same page, which the server renders;
 * the publication search and detail work there, and nothing leaks.
 *
 * The publication "Woo-besluit afvalinzameling 2026" with one PDF is seeded
 * by tests/e2e/ci-seed.sh through opencatalogi's API. The pages this spec
 * reads are created here, in the e2e portal, and removed afterwards.
 *
 * Run it:
 *
 *     PORTALIQ_E2E_ALLOW_SHARED_INSTANCE=http://localhost:8080 \
 *     PLAYWRIGHT_BASE_URL=http://localhost:8080 \
 *     npx playwright test -c tests/e2e/playwright.config.ts site-without-javascript
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md
 */

import type { APIRequestContext, APIResponse } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'

const STAMP = Date.now()
const PORTAL = process.env.PORTALIQ_E2E_PORTAL ?? 'open-tilburg'
const SITE = '/index.php/apps/portaliq/site'
const PLAIN = `${SITE}/plain`
const OR_API = '/index.php/apps/openregister/api/objects'
const SEARCH_ROUTE = `/plain-zoeken-${STAMP}`
const DETAIL_ROUTE = `/plain-publicatie-${STAMP}`
const FORM_ROUTE = `/plain-aanvragen-${STAMP}`
const DRAFT_ROUTE = `/plain-concept-${STAMP}`
const SEEDED = 'Woo-besluit afvalinzameling 2026'
const NOTICE = 'Deze website gebruikt JavaScript voor de onderdelen waarmee u iets doet.'

const ADMIN_HEADERS = {
	Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`,
	'OCS-APIRequest': 'true',
	requesttoken: 'x',
}

const created: string[] = []
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
	expect(res.ok(), `${what}: HTTP ${res.status()} ${text.slice(0, 400)}`).toBeTruthy()
	return text === '' ? {} : JSON.parse(text)
}

/**
 * Create a page of the e2e portal holding one widget.
 *
 * @param route The route.
 * @param title The title.
 * @param status `published` or `draft`.
 * @param widget The widget key and props.
 * @param widget.widgetKey The widget key.
 * @param widget.props The props.
 * @return Resolves once stored.
 */
async function createPage(
	route: string,
	title: string,
	status: string,
	widget: { widgetKey: string; props: object },
): Promise<void> {
	const page = await json(
		await admin!.post(`${OR_API}/portaliq/page`, {
			headers: ADMIN_HEADERS,
			data: {
				title,
				route,
				portal: PORTAL,
				status,
				locale: 'nl',
				body: {
					type: 'grid',
					widgets: [{ id: 'main', slot: 'body', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 6, ...widget }],
				},
			},
		}),
		`create page ${route}`,
	)
	created.push(String(page?.id ?? page?.['@self']?.id ?? ''))
}

/**
 * A plain or full address of a route in the e2e portal.
 *
 * @param base `SITE` or `PLAIN`.
 * @param route The route.
 * @param extra More parameters.
 * @return The address.
 */
function address(base: string, route: string, extra: Record<string, string> = {}): string {
	return `${base}?${new URLSearchParams({ route, portal: PORTAL, ...extra }).toString()}`
}

test.describe.serial('the site is honest without JavaScript', () => {
	test.use({ javaScriptEnabled: false })

	test.beforeAll(async () => {
		admin = await playwrightRequest.newContext({ baseURL: BASE_URL })
		await createPage(SEARCH_ROUTE, 'Zoeken zonder JavaScript', 'published', {
			widgetKey: 'federatedSearch',
			props: { pageSize: 10, detailRoute: DETAIL_ROUTE },
		})
		await createPage(DETAIL_ROUTE, 'Publicatie', 'published', { widgetKey: 'publicationDetail', props: {} })
		await createPage(FORM_ROUTE, 'Aanvragen', 'published', { widgetKey: 'intakeForm', props: {} })
		await createPage(DRAFT_ROUTE, 'Concept', 'draft', { widgetKey: 'nlParagraph', props: { text: 'Geheim concept' } })
	})

	test.afterAll(async () => {
		for (const id of [...created].reverse()) {
			if (id !== '') {
				await admin?.delete(`${OR_API}/portaliq/page/${id}`, { headers: ADMIN_HEADERS })
			}
		}
		await admin?.dispose()
	})

	// @e2e portaliq site-without-javascript::a-visitor-without-javascript-is-told-and-given-a-way-on
	test('a visitor without JavaScript is told and given a way on', async ({ page }) => {
		await page.goto(address(SITE, SEARCH_ROUTE, { _search: 'afval' }))
		const main = page.locator('main#pq-main')
		await expect(main).toContainText(NOTICE)

		const link = main.getByRole('link')
		const href = new URL(String(await link.getAttribute('href')), BASE_URL)
		expect(href.pathname).toBe(PLAIN)
		expect(href.searchParams.get('route')).toBe(SEARCH_ROUTE)
		expect(href.searchParams.get('_search')).toBe('afval')

		await page.keyboard.press('Tab')
		await page.keyboard.press('Enter')
		await expect(main).toBeFocused()
	})

	// @e2e portaliq site-without-javascript::a-visitor-with-javascript-sees-no-notice
	test.describe('with JavaScript on', () => {
		test.use({ javaScriptEnabled: true })

		test('a visitor with JavaScript sees no notice', async ({ page }) => {
			await page.goto(address(SITE, '/over-ons'))
			await expect(page.getByTestId('page-title')).toHaveText('Over ons')
			await expect(page.getByText(NOTICE)).toBeHidden()
			await expect(page.locator('#pq-main')).toHaveCount(1)
		})
	})

	// @e2e portaliq site-without-javascript::a-text-page-reads-the-same-without-javascript
	test('a text page reads the same without JavaScript', async ({ page }) => {
		const response = await page.goto(address(PLAIN, '/over-ons'))
		expect(response?.status()).toBe(200)
		await expect(page.getByRole('heading', { level: 1 })).toHaveText('Over ons')
		await expect(page.locator('main#pq-main')).not.toBeEmpty()
		expect(await page.locator('script').count()).toBe(0)
		await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /\/index\.php\/apps\/portaliq\/site\?/)
	})

	// @e2e portaliq site-without-javascript::a-part-that-needs-javascript-says-so
	test('a part that needs JavaScript says so', async ({ page }) => {
		await page.goto(address(PLAIN, FORM_ROUTE))
		const block = page.locator('.pq-plain__block--needsJs')
		await expect(block).toContainText('dit onderdeel werkt alleen met JavaScript')
		const href = new URL(String(await block.getByRole('link').getAttribute('href')), BASE_URL)
		expect(href.pathname).toBe(SITE)
		expect(href.searchParams.get('route')).toBe(FORM_ROUTE)
	})

	// @e2e portaliq site-without-javascript::a-draft-or-a-session-never-leaks
	test('a draft or a session never leaks', async ({ page, context }) => {
		await context.addCookies([{ name: 'nc_session_id', value: 'resident-session', url: BASE_URL }])
		const draft = await page.goto(address(PLAIN, DRAFT_ROUTE))
		const draftBody = (await page.locator('main').innerText()).replace(DRAFT_ROUTE, '')
		const missing = await page.goto(address(PLAIN, `/bestaat-niet-${STAMP}`))
		const missingBody = (await page.locator('main').innerText()).replace(`/bestaat-niet-${STAMP}`, '')

		expect(draft?.status()).toBe(404)
		expect(missing?.status()).toBe(404)
		expect(draftBody).toBe(missingBody)
		expect(draftBody).not.toContain('Geheim concept')
	})

	// @e2e portaliq site-without-javascript::a-search-works-with-javascript-off
	test('a search works with JavaScript off', async ({ page }) => {
		await page.goto(address(PLAIN, SEARCH_ROUTE))
		await page.getByLabel('Zoek in publicaties').fill('afvalinzameling')
		await page.getByRole('button', { name: 'Zoeken' }).click()

		const result = page.locator('.pq-plain__results li', { hasText: SEEDED })
		await expect(result).toHaveCount(1)
		await expect(result).toContainText(/\d{1,2} [a-z]+ \d{4}/)
		const href = new URL(String(await result.getByRole('link').getAttribute('href')), BASE_URL)
		expect(href.pathname).toBe(PLAIN)
		expect(String(href.searchParams.get('route'))).toMatch(new RegExp(`^${DETAIL_ROUTE}/`))
	})

	// @e2e portaliq site-without-javascript::a-publication-and-its-documents-open-without-javascript
	test('a publication and its documents open without JavaScript', async ({ page, request }) => {
		await page.goto(address(PLAIN, SEARCH_ROUTE, { _search: 'afvalinzameling' }))
		await page.locator('.pq-plain__results li', { hasText: SEEDED }).getByRole('link').click()

		await expect(page.getByRole('heading', { level: 2, name: SEEDED })).toBeVisible()
		await expect(page.locator('.pq-plain__facts')).toContainText('Woo-verzoeken en -besluiten')
		const pdf = page.locator('.pq-plain__block--publication a[download]').first()
		await expect(pdf).toContainText('.pdf')

		const download = await request.get(String(await pdf.getAttribute('href')))
		expect(download.ok()).toBeTruthy()
		const bytes = await download.body()
		expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-')
	})

	// @e2e portaliq site-without-javascript::missing-and-withheld-look-the-same
	test('missing and withheld look the same', async ({ page }) => {
		const missingId = `bestaat-niet-${STAMP}`
		const withheld = await json(
			await admin!.post(`${OR_API}/publication/publication`, {
				headers: ADMIN_HEADERS,
				data: { title: `Niet gepubliceerd ${STAMP}`, publicationDate: new Date(Date.now() + 365 * 86_400_000).toISOString() },
			}),
			'create an unpublished publication',
		)
		const withheldId = String(withheld?.id ?? withheld?.['@self']?.id ?? '')

		const one = await page.goto(address(PLAIN, `${DETAIL_ROUTE}/${missingId}`))
		const oneBody = (await page.locator('main').innerText()).replace(missingId, '')
		const two = await page.goto(address(PLAIN, `${DETAIL_ROUTE}/${withheldId}`))
		const twoBody = (await page.locator('main').innerText()).replace(withheldId, '')
		await admin!.delete(`${OR_API}/publication/publication/${withheldId}`, { headers: ADMIN_HEADERS })

		expect(one?.status()).toBe(404)
		expect(two?.status()).toBe(404)
		expect(oneBody).toBe(twoBody)
	})
})
