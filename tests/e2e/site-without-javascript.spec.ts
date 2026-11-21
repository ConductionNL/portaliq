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
 * The publication scenarios (REQ-SHJ-003 to REQ-SHJ-005) are not here: they
 * need opencatalogi in the CI job with a seeded publication (task 3.4).
 *
 * Every test carries the `@e2e site-without-javascript::<scenario>` tag of
 * the scenario it proves.
 *
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#1.1
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
 */

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
