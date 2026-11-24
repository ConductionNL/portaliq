/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-honest-without-javascript (REQ-SHJ-001, REQ-SHJ-002): a visitor whose
 * browser runs no JavaScript is told so on every site page and given the
 * plain version, and the plain version reads the page's text, says which
 * parts need JavaScript, and never serves a draft, whatever the request
 * carries.
 *
 * The pages are the ones tests/e2e/fixtures/seed-cms.sh seeds on the default
 * portal (`open-tilburg`): the markdown page `/over-ons`, the grid home page
 * with its `contributions` block, the search page `/zoeken` and the draft
 * `/concept`. Nothing is stubbed with page.route: the notice and the plain
 * page are server-rendered HTML, which is the whole point.
 *
 * The publication scenarios (REQ-SHJ-003, REQ-SHJ-004) read opencatalogi on
 * the server, so the CI job installs it (`e2e-additional-apps`) and ci-seed.sh
 * seeds "Woo-besluit afvalinzameling 2026" with one PDF and an unpublished
 * sibling through opencatalogi's and OpenRegister's own API (task 3.4).
 *
 * Every test carries the `@e2e site-without-javascript::<scenario>` tag of
 * the scenario it proves.
 *
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#1.1
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.4
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'
import { devLogin } from './portal-nav.ts'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const PLAIN = `${SITE}/plain`

const ORGANISATION = process.env.PORTALIQ_E2E_ORG || 'dev-org'
const AUDIENCE = process.env.PORTALIQ_E2E_AUDIENCE || 'citizen'

/** The notice, in Dutch or English: the document language follows the request. */
const NOTICE =
	/(Deze website gebruikt JavaScript voor de interactieve onderdelen|This site uses JavaScript for its interactive parts)/
/** The notice's link to the plain version. */
const PLAIN_LINK =
	/(Open de eenvoudige versie van deze pagina|Open the plain version of this page)/
/** The sentence a part that needs JavaScript leaves in its place. */
const NEEDS_JS = /(werkt alleen met JavaScript|needs JavaScript)/

/** The publication ci-seed.sh seeds, and its unpublished sibling. */
const PUBLICATION = 'Woo-besluit afvalinzameling 2026'
const UNPUBLISHED = 'Woo-besluit afvalinzameling 2026 (concept)'
const PDF_NAME = 'besluit-afvalinzameling-2026.pdf'

const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/**
 * The id of a seeded publication, read by its title through OpenRegister as
 * the administrator (the unpublished one is not readable anonymously).
 *
 * @param request The request context.
 * @param title The publication's title.
 */
async function publicationId(
	request: APIRequestContext,
	title: string,
): Promise<string> {
	const auth = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	const response = await request.get(
		`${BASE}/index.php/apps/openregister/api/objects/publication/publication`,
		{
			params: { title, _limit: '1' },
			headers: { Authorization: `Basic ${auth}`, 'OCS-APIRequest': 'true' },
		},
	)
	expect(response.ok(), `the seeded publication "${title}" is readable`).toBe(true)
	const body = await response.json()
	const rows = Array.isArray(body) ? body : (body?.results ?? [])
	const id = String(rows[0]?.id ?? rows[0]?.['@self']?.id ?? '')
	expect(id, `ci-seed.sh seeded "${title}"`).not.toBe('')
	return id
}

test.describe('without JavaScript', () => {
	test.use({ javaScriptEnabled: false })

	test('a visitor without JavaScript is told and given a way on', async ({
		page,
	}) => {
		// @e2e site-without-javascript::a-visitor-without-javascript-is-told-and-given-a-way-on
		await page.goto(`${SITE}?route=/zoeken&_search=afval`)

		const notice = page.locator('main#pq-main')
		await expect(notice).toHaveCount(1)
		await expect(notice).toContainText(NOTICE)

		const link = notice.getByRole('link', { name: PLAIN_LINK })
		const href = (await link.getAttribute('href')) || ''
		expect(href).toContain('/site/plain?')
		expect(href).toContain('route=/zoeken')
		expect(href).toContain('_search=afval')

		// The skip link lands on the main that holds the notice.
		await page.keyboard.press('Tab')
		await expect(page.locator('#skip-link')).toBeFocused()
		await page.keyboard.press('Enter')
		await expect(page).toHaveURL(/#pq-main$/)
	})

	test('a text page reads the same without JavaScript', async ({ page }) => {
		// @e2e site-without-javascript::a-text-page-reads-the-same-without-javascript
		const response = await page.goto(`${PLAIN}?route=/over-ons`)
		expect(response?.status()).toBe(200)

		await expect(
			page.getByRole('heading', { level: 1, name: 'Over ons' }),
		).toBeVisible()
		await expect(page.locator('main#pq-main')).toContainText('Wet open overheid')
		await expect(page.locator('main#pq-main table')).toContainText('Twee')

		// The main menu links into the plain version.
		const menu = page.locator('header nav')
		await expect(menu.getByRole('link', { name: 'Over ons' })).toHaveAttribute(
			'href',
			/\/site\/plain\?route=\/over-ons/,
		)

		// Not one script element, not even data.
		expect(await page.locator('script').count()).toBe(0)
		expect(await response?.text()).not.toContain('<script')
	})

	test('a part that needs JavaScript says so', async ({ page }) => {
		// @e2e site-without-javascript::a-part-that-needs-javascript-says-so
		await page.goto(`${PLAIN}?route=/`)

		// The home page's markdown renders as text ...
		await expect(page.locator('main#pq-main')).toContainText(
			'Welkom bij Open Tilburg',
		)

		// ... and its contributions block leaves a sentence and a way on, not a gap.
		const needsJs = page.locator('.pq-plain__needs-js')
		await expect(needsJs).toHaveCount(1)
		await expect(needsJs).toContainText(NEEDS_JS)
		await expect(needsJs.getByRole('link')).toHaveAttribute(
			'href',
			/\/site\?route=\//,
		)
	})
})

test.describe('the plain page and what a request carries', () => {
	test('a draft or a session never leaks', async ({ request }) => {
		// @e2e site-without-javascript::a-draft-or-a-session-never-leaks
		const token = await devLogin(
			request,
			`subject-plain-${Date.now()}`,
			AUDIENCE,
			ORGANISATION,
		)
		const headers = { Authorization: `Bearer ${token}` }

		const draft = await request.get(`${PLAIN}?route=/concept`, { headers })
		const unknown = await request.get(
			`${PLAIN}?route=/bestaat-niet-${Date.now()}`,
		)

		expect(draft.status()).toBe(404)
		expect(unknown.status()).toBe(404)
		const draftBody = await draft.text()
		expect(draftBody).not.toContain('Dit is een concept.')
		expect(draftBody).not.toContain('Nog niet klaar')
	})

	test('a visitor with JavaScript sees no notice', async ({ page }) => {
		// @e2e site-without-javascript::a-visitor-with-javascript-sees-no-notice
		await page.goto(`${SITE}?route=/over-ons`)
		await expect(page.getByTestId('page-title')).toHaveText('Over ons')

		await expect(page.locator('#pq-main')).toHaveCount(1)
		await expect(page.getByText(NOTICE)).toHaveCount(0)
	})
})

test.describe('publications without JavaScript', () => {
	test.use({ javaScriptEnabled: false })

	test('a search works with JavaScript off', async ({ page }) => {
		// @e2e site-without-javascript::a-search-works-with-javascript-off
		await page.goto(`${PLAIN}?route=/zoeken`)

		const form = page.locator('main#pq-main form[role="search"]')
		await form.locator('input[name="_search"]').fill('afvalinzameling')
		await form.locator('button[type="submit"]').click()

		await expect(page).toHaveURL(/\/site\/plain\?/)
		await expect(page).toHaveURL(/_search=afvalinzameling/)
		const result = page
			.locator('.pq-plain__results li')
			.filter({ hasText: PUBLICATION })
			.first()
		await expect(result).toBeVisible()
		// The date line sits under the title.
		await expect(result.locator('p.utrecht-paragraph').first()).not.toBeEmpty()
		await expect(
			result.getByRole('link', { name: PUBLICATION, exact: true }),
		).toHaveAttribute('href', /\/site\/plain\?(.*&)?route=\/publicatie\/[^&]+/)
	})

	test('a publication and its documents open without JavaScript', async ({
		page,
		request,
	}) => {
		// @e2e site-without-javascript::a-publication-and-its-documents-open-without-javascript
		await page.goto(`${PLAIN}?route=/zoeken&_search=afvalinzameling`)
		await page
			.locator('.pq-plain__results')
			.getByRole('link', { name: PUBLICATION, exact: true })
			.click()

		const main = page.locator('main#pq-main')
		await expect(
			main.getByRole('heading', { level: 2, name: PUBLICATION }),
		).toBeVisible()
		const facts = main.locator('dl.utrecht-data-list')
		await expect(facts).toContainText(/(Publicatiedatum|Publication date)/)
		await expect(facts).toContainText(
			/(Overige besluiten van algemene strekking|Other decisions of general scope)/,
		)

		const pdf = main.getByRole('link', { name: PDF_NAME })
		await expect(pdf).toHaveAttribute('download', '')
		const href = (await pdf.getAttribute('href')) || ''
		expect(href).not.toBe('')

		const download = await request.get(new URL(href, page.url()).toString())
		expect(download.status()).toBe(200)
		const bytes = await download.body()
		expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-')
		// No script reached the detail page either.
		expect(await page.locator('script').count()).toBe(0)
	})

	test('missing and withheld look the same', async ({ request }) => {
		// @e2e site-without-javascript::missing-and-withheld-look-the-same
		const withheld = await publicationId(request, UNPUBLISHED)
		const missing = `00000000-0000-4000-8000-${String(Date.now()).padStart(12, '0').slice(-12)}`

		const withheldAnswer = await request.get(
			`${PLAIN}?route=/publicatie/${encodeURIComponent(withheld)}`,
		)
		const missingAnswer = await request.get(
			`${PLAIN}?route=/publicatie/${encodeURIComponent(missing)}`,
		)

		expect(withheldAnswer.status()).toBe(404)
		expect(missingAnswer.status()).toBe(404)
		// Identical apart from the requested address.
		const strip = (body: string, id: string) =>
			body.split(encodeURIComponent(id)).join('<id>').split(id).join('<id>')
		expect(strip(await withheldAnswer.text(), withheld)).toBe(
			strip(await missingAnswer.text(), missing),
		)
		expect(await withheldAnswer.text()).not.toContain(UNPUBLISHED)
	})
})
