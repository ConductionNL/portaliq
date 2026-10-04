/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-widget-palette: placing a widget from the palette, with a pointer and
 * without one (site-nlds-widget-palette REQ-SNW-001, REQ-SNW-002).
 *
 * WHAT THE FIRST VERSION GOT WRONG, because the next reader will wonder:
 *
 * 1. It set `httpCredentials`, which is HTTP Basic. Nextcloud's web UI ignores
 *    that: every page answered the LOGIN FORM, and the failure read as "the
 *    palette button is missing" for all three tests. The browser needs a
 *    session, so this logs in through the form, as `app-chrome.spec.ts` does.
 * 2. It built the designer's address as `#/pages/layout?portal=…&page=…`. The
 *    route is `/pages/:id/layout` (src/manifest.json, the `PageLayout` page),
 *    and the view reads `$route.params.id`. A query string was never going to
 *    resolve a page.
 * 3. It assumed a page existed, named by a slug. The `page` schema has no
 *    slug, and which pages an instance holds is not this spec's business. So
 *    it SEEDS its own grid page through OpenRegister's object API and deletes
 *    it afterwards, the way the mijn omgeving specs seed theirs.
 *
 * Environment (all optional): PORTALIQ_E2E_PORTAL (default demo),
 * PORTALIQ_E2E_ADMIN (default admin:admin).
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const APP_BASE = '/index.php/apps/portaliq'
const ADMIN = process.env.PORTALIQ_E2E_ADMIN || 'admin:admin'
const [ADMIN_USER, ADMIN_PASS] = ADMIN.split(':')
const BASIC = Buffer.from(ADMIN).toString('base64')
const PORTAL = process.env.PORTALIQ_E2E_PORTAL || 'demo'

/** What this run created, as `schema/id`, to delete afterwards. */
const created: string[] = []

/**
 * Sign in through the form, so the browser has a session.
 *
 * @param page The page.
 * @return nothing
 */
async function login(page: Page): Promise<void> {
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(ADMIN_USER)
	await page.locator('input[name="password"]').fill(ADMIN_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 60_000 })
}

/**
 * Close the first-run setup wizard when it is open: its modal swallows every
 * pointer event, which reads as a broken screen rather than a modal.
 *
 * @param page The page.
 * @return nothing
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}

	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

/**
 * Seed an empty grid page for this run and answer its id.
 *
 * @param request The request fixture.
 * @return the page's id
 */
async function seedGridPage(request: APIRequestContext): Promise<string> {
	const stamp = Date.now()
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/page`, {
		headers: { Authorization: `Basic ${BASIC}`, 'OCS-APIRequest': 'true' },
		data: {
			title: `Palette proef ${stamp}`,
			route: `/palette-proef-${stamp}`,
			portal: PORTAL,
			status: 'draft',
			body: { type: 'grid', widgets: [] },
		},
	})
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for page: ${res.status()}`,
	).toBeTruthy()

	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id, 'the seeded page must have an id').toBeTruthy()
	created.push(`page/${id}`)
	return id
}

/**
 * The designer's address for one page: `/pages/:id/layout`, as the manifest
 * declares it.
 *
 * @param id The page's id.
 * @return the path, including the hash route
 */
function designerAddress(id: string): string {
	return `${APP_BASE}/#/pages/${id}/layout`
}

/**
 * Open the designer on a page seeded for this test.
 *
 * @param page The page.
 * @param request The request fixture.
 * @return nothing
 */
async function openDesigner(page: Page, request: APIRequestContext): Promise<void> {
	const id = await seedGridPage(request)
	await login(page)
	await page.goto(designerAddress(id))
	await dismissSetupWizard(page)
	await expect(
		page.getByTestId('designer-add-widget'),
		'the designer must open on the seeded page; a login form here means the session was not set',
	).toBeVisible({ timeout: 30_000 })
}

test.describe('site-widget-palette', () => {
	test.afterAll(async ({ request }) => {
		for (const entry of created.splice(0)) {
			await request.delete(`${OR_OBJECTS_BASE}/portaliq/${entry}`, {
				headers: {
					Authorization: `Basic ${BASIC}`,
					'OCS-APIRequest': 'true',
				},
			})
		}
	})

	// @e2e site-nlds-widget-palette::an-editor-looks-for-a-heading
	test('the palette groups its widgets and says how many a search finds', async ({
		page,
		request,
	}) => {
		await openDesigner(page, request)
		await page.getByTestId('designer-add-widget').click()
		await expect(page.getByTestId('widget-palette')).toBeVisible()

		const headings = await page
			.getByTestId('widget-palette')
			.locator('.palette__group-heading')
			.allInnerTexts()
		expect(headings).toContain('Inhoud')
		expect(headings.indexOf('Inhoud')).toBeLessThan(headings.indexOf('Opmaak'))

		// An author types the word for the thing, not the component's name.
		await page.getByTestId('widget-palette-search').fill('kop')
		await expect(page.getByTestId('widget-palette-nlHeading')).toBeVisible()
		await expect(
			page.getByTestId('widget-palette-hits'),
			'the hit count is announced, so a narrow search is not read as a broken one',
		).toHaveText(/\d+ widget/)

		await page.getByTestId('widget-palette-search').fill('parkeervergunning')
		await expect(page.getByTestId('widget-palette-empty')).toBeVisible()
	})

	// @e2e site-nlds-widget-palette::a-heading-dropped-at-the-top
	test('a widget can be dragged onto the grid', async ({ page, request }) => {
		await openDesigner(page, request)
		await page.getByTestId('designer-add-widget').click()
		await page.getByTestId('widget-palette-search').fill('kop')

		const entry = page.getByTestId('widget-palette-nlHeading')
		const canvas = page.getByTestId('designer-canvas')
		await entry.dragTo(canvas, { targetPosition: { x: 40, y: 40 } })

		await expect(canvas.getByTestId('nl-heading')).toBeVisible()
	})

	// @e2e site-nlds-widget-palette::keyboard-only
	test('a widget can be placed with the keyboard alone', async ({
		page,
		request,
	}) => {
		await openDesigner(page, request)

		// Everything from here on is keys: no click, no drag. If any step needs
		// a pointer, this test cannot pass, which is the point of it.
		await page.getByTestId('designer-add-widget').press('Enter')
		await expect(page.getByTestId('widget-palette')).toBeVisible()

		await page.getByTestId('widget-palette-search').fill('kop')
		await page.getByTestId('widget-palette-nlHeading').press('Enter')

		await expect(page.getByTestId('widget-palette')).toBeHidden()
		await expect(
			page.getByTestId('designer-canvas').getByTestId('nl-heading'),
			'the widget is on the canvas, placed below everything so the author can see it',
		).toBeVisible()
	})
})
