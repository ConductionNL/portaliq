/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * page-public-on-purpose (task 1.3): publishing a page is the act that makes
 * it readable without signing in, the editor is told so before it happens,
 * and taking the page back to draft makes its route answer exactly as a
 * route that never existed.
 *
 * The journey: a draft page is created through OpenRegister's object API;
 * the editor opens it in the page designer, chooses Publish and cancels (the
 * page stays a draft and 404s), chooses Publish again and confirms (a
 * signed-out visitor reads it, the page list says Public), then takes it back
 * to draft from the page list (the route answers with the same 404 status and
 * body as an unknown route, and the list says Draft).
 *
 * EVERYTHING HERE IS REAL: the designer, PublishPageDialog, the page list's
 * `takePageBackToDraft` handler, the public content API and the site's
 * not-found page all belong to portaliq itself. Nothing is stubbed with
 * page.route; no sibling app takes part.
 *
 * The fixture is this file's own: a fresh route per run on the seeded portal
 * (`open-tilburg`, or PORTALIQ_E2E_PORTAL), deleted again afterwards, so no
 * other spec's page is touched. The editor signs in as the Nextcloud admin in
 * the browser; the visitor reads in a fresh context with an empty storage
 * state. The base URL comes from tests/e2e/base-url.ts.
 *
 * @spec openspec/changes/page-public-on-purpose/tasks.md#1.3
 */

import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'
import { SITE_PATH, SITE_PORTAL } from './portal-nav.ts'

const BASE = resolveBaseURL()
const CONTENT = `${BASE}/index.php/apps/portaliq/api/content`
const OBJECTS = `${BASE}/index.php/apps/openregister/api/objects/portaliq/page`

/** Nextcloud admin, as exported by the shared quality.yml Playwright step. */
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** The page the scenario names. */
const TITLE = 'Afval en milieu'

/** This run's own route, so a dead earlier run cannot collide with it. */
const STAMP = Date.now()
const ROUTE = `/e2e-afval-${STAMP}`

/** A route that never existed, the yardstick for "answers as absent". */
const UNKNOWN_ROUTE = `/e2e-never-existed-${STAMP}`

/** A sentence only this page carries. */
const BODY_TEXT = `Het grofvuil wordt op afspraak opgehaald (${STAMP}).`

/** The id of the page created for this run. */
let pageId = ''

/**
 * An admin API context, Basic auth with the OCS header so Nextcloud skips
 * its CSRF check. Seeding and cleanup only; the browser never holds it.
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
 * Sign the browser in to Nextcloud as the editor.
 *
 * @param page the page to sign in
 * @return nothing
 */
async function signInAsEditor(page: Page): Promise<void> {
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(ADMIN_USER)
	await page.locator('input[name="password"]').fill(ADMIN_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 60_000 })
	expect(/\/login(\?|$|\/)/.test(page.url()), `login failed — still on ${page.url()}`).toBe(false)
}

/**
 * The path prefix the app's router owns, read back rather than predicted:
 * `/apps/portaliq` with rewrites, `/index.php/apps/portaliq` without.
 *
 * @param page the signed-in page
 * @return the base path, without a trailing slash
 */
async function spaBase(page: Page): Promise<string> {
	await page.goto(`${BASE}/index.php/apps/portaliq/`)
	await expect(page.locator('#content > *').first()).toBeVisible({ timeout: 60_000 })
	const settled = new URL(page.url()).pathname
	expect(settled).toContain('/apps/portaliq')
	return settled.replace(/\/+$/, '')
}

/**
 * Open this run's page in the designer and choose Publish, which asks first.
 *
 * @param page the signed-in page
 * @return the confirmation dialog
 */
async function choosePublish(page: Page) {
	const app = await spaBase(page)
	await page.goto(`${BASE}${app}/pages/${pageId}/layout`)
	await expect(page.getByTestId('designer-title')).toHaveText(TITLE, { timeout: 30_000 })
	await page.getByTestId('designer-publish').click()
	const dialog = page.getByTestId('publish-page-dialog')
	await expect(dialog).toBeVisible()
	return dialog
}

/**
 * The page list's row for this run's page.
 *
 * @param page the signed-in page
 * @return the row
 */
async function pageRow(page: Page) {
	const app = await spaBase(page)
	await page.goto(`${BASE}${app}/pages`)
	const row = page.locator('tr', { hasText: ROUTE }).first()
	await expect(row).toBeVisible({ timeout: 60_000 })
	return row
}

/**
 * Read one route through the public content API without any credentials.
 *
 * @param route the in-site route
 * @return the status and the raw body
 */
async function readSignedOut(route: string): Promise<{ status: number, body: string }> {
	const anonymous = await request.newContext({ baseURL: BASE })
	const response = await anonymous.get(
		`${CONTENT}/page?route=${encodeURIComponent(route)}&portal=${encodeURIComponent(SITE_PORTAL)}`,
	)
	const result = { status: response.status(), body: await response.text() }
	await anonymous.dispose()
	return result
}

/**
 * Open one route of the site in a fresh, signed-out browser context.
 *
 * @param browser the browser fixture
 * @param route the in-site route
 * @return the page (the caller closes its context) and the context
 */
async function visitSignedOut(browser: Browser, route: string) {
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	const visitor = await context.newPage()
	const params = new URLSearchParams({ portal: SITE_PORTAL, route })
	await visitor.goto(`${BASE}${SITE_PATH}?${params.toString()}`)
	return { context, visitor }
}

test.beforeAll(async () => {
	const api = await adminApi()
	const created = await api.post(OBJECTS, {
		data: {
			title: TITLE,
			route: ROUTE,
			portal: SITE_PORTAL,
			status: 'draft',
			locale: 'nl',
			body: {
				type: 'grid',
				widgets: [
					{
						id: 'afval-intro',
						widgetKey: 'markdown',
						slot: 'body',
						gridX: 0,
						gridY: 0,
						gridWidth: 6,
						gridHeight: 3,
						props: { markdown: `## ${TITLE}\n\n${BODY_TEXT}` },
					},
				],
			},
		},
	})
	expect(created.ok(), `creating the draft page failed: ${created.status()}`).toBe(true)
	const row = await created.json()
	pageId = row['@self']?.id ?? row.id
	expect(pageId, 'the created page must report an id').toBeTruthy()
	await api.dispose()
})

test.afterAll(async () => {
	if (pageId === '') {
		return
	}
	const api = await adminApi()
	await api.delete(`${OBJECTS}/${pageId}`)
	await api.dispose()
})

// One page walks through the three states in order.
test.describe.serial('page-public-on-purpose', () => {
	// The editor signs in in the browser itself; start from nothing.
	test.use({ storageState: { cookies: [], origins: [] } })

	// Requirement: Publishing a page is the act that makes it public, and the editor is told so
	// Scenario: An editor who does not confirm leaves the page private
	test('a draft page answers as absent, and cancelling Publish leaves it a draft', async ({ page }) => {
		const unknown = await readSignedOut(UNKNOWN_ROUTE)
		expect(unknown.status).toBe(404)
		expect(await readSignedOut(ROUTE), 'a draft answers exactly as an unknown route').toEqual(unknown)

		await signInAsEditor(page)
		const dialog = await choosePublish(page)
		await dialog.getByTestId('publish-page-cancel').click()
		await expect(page.getByTestId('publish-page-dialog')).toHaveCount(0)

		expect(await readSignedOut(ROUTE), 'cancel must not publish').toEqual(unknown)
		const row = await pageRow(page)
		await expect(row).toContainText(/Draft|Concept/)
	})

	// Requirement: Publishing a page is the act that makes it public, and the editor is told so
	// Scenario: An editor publishes a page and is told who can read it
	test('Publish says anyone can read the page and where; confirmed, a signed-out visitor reads it', async ({
		page,
		browser,
	}) => {
		await signInAsEditor(page)
		const dialog = await choosePublish(page)
		await expect(dialog).toContainText(
			/Anyone can read this page, without signing in\.|Iedereen kan deze pagina lezen, zonder in te loggen/,
		)
		const address = dialog.getByTestId('publish-page-address')
		await expect(address).toHaveAttribute('href', new RegExp(`route=${encodeURIComponent(ROUTE)}`))
		await expect(address).toHaveAttribute('href', new RegExp(`portal=${encodeURIComponent(SITE_PORTAL)}`))
		await dialog.getByTestId('publish-page-confirm').click()
		await expect(page.getByTestId('designer-notice')).toBeVisible()

		const read = await readSignedOut(ROUTE)
		expect(read.status, 'a published page is readable without signing in').toBe(200)
		expect(read.body).toContain(TITLE)

		const { context, visitor } = await visitSignedOut(browser, ROUTE)
		await expect(visitor.getByText(BODY_TEXT)).toBeVisible({ timeout: 30_000 })
		await expect(visitor.getByTestId('site-error')).toHaveCount(0)
		await context.close()

		const row = await pageRow(page)
		await expect(row).toContainText(/Public|Openbaar/)
	})

	// Requirement: Publishing a page is the act that makes it public, and the editor is told so
	// Scenario: An editor takes a public page back
	test('taken back to draft, the route answers with the same status and body as one that never existed', async ({
		page,
		browser,
	}) => {
		await signInAsEditor(page)
		const row = await pageRow(page)
		await row.getByTestId('cn-row-actions').getByRole('button').first().click()
		// CnRowActions slugifies the (translated) label into the test id.
		await page.getByTestId(/^cn-action-item-(take-back-to-draft|terugzetten-naar-concept)$/).click()

		await expect(page.locator('tr', { hasText: ROUTE }).first()).toContainText(/Draft|Concept/, {
			timeout: 30_000,
		})

		const unknown = await readSignedOut(UNKNOWN_ROUTE)
		const takenBack = await readSignedOut(ROUTE)
		expect(takenBack.status).toBe(404)
		expect(takenBack, 'same status and body as a route that never existed').toEqual(unknown)

		// And the site draws the same not-found page for both.
		const gone = await visitSignedOut(browser, ROUTE)
		await expect(gone.visitor.getByTestId('not-found-code')).toContainText('404')
		await expect(gone.visitor.getByText(BODY_TEXT)).toHaveCount(0)
		await gone.context.close()

		const never = await visitSignedOut(browser, UNKNOWN_ROUTE)
		await expect(never.visitor.getByTestId('not-found-code')).toContainText('404')
		await never.context.close()
	})
})
