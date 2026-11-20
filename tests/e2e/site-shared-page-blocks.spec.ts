/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-shared-page-blocks T05 and T06 (REQ-SPB-002, REQ-SPB-005): one block of
 * the organisation, placed on the home page of both of its portals, shows on
 * both; one edit in the designer shows on both after the next read; an editor
 * places the block on a page from the designer's palette, and the block's
 * page lists the pages that place it.
 *
 * WHAT IS REAL. Everything, and nothing is stubbed with `page.route`: the
 * block and the pages are OpenRegister objects, the content API expands the
 * placement (`CmsSharedBlocks::expand()`), the cache is cleared by the block
 * write (`CmsCacheInvalidationListener` over `SharedBlockPortals`), the site
 * draws the placement (`SharedBlock.vue`, `data-testid="shared-block"`), and
 * the designer (`PageLayoutDesigner` on `/shared-blocks/:id/layout` and
 * `/pages/:id/layout`) and the block's page (`SharedBlockDetail`, its Pagina's
 * tab over `GET /api/shared-blocks/{id}/pages`) are the admin's own screens.
 *
 * WHY IT SEEDS ITS OWN BLOCK. T01 left the seed block out (the example site
 * installer creates only menus, pages and news items), so this run creates an
 * organisation of its own with two published portals, a published block and
 * a home page per portal that places it, and deletes them afterwards.
 *
 * Out of scope here, as the change marks them `@e2e exclude`: creating a block
 * from the index (REQ-SPB-001), the organisation boundary (REQ-SPB-003,
 * CmsReaderTest), the cache seam itself (REQ-SPB-004,
 * CmsCacheInvalidationListenerTest) and who may edit (REQ-SPB-006,
 * PageEditorServiceTest).
 *
 * Needs the admin's credentials in ADMIN_USER / ADMIN_PASSWORD (default
 * admin/admin). Browser contexts start from an empty storage state. The target
 * comes from BASE_URL (see tests/e2e/base-url.ts).
 *
 * @spec openspec/changes/site-shared-page-blocks/specs/portal-shared-page-blocks/spec.md
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { SITE_PATH } from './portal-nav.ts'

const APP_BASE = '/index.php/apps/portaliq'
const OBJECTS = '/index.php/apps/openregister/api/objects/portaliq'
const ADMIN_USER = process.env.ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? 'admin'
const ADMIN = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
const HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }
const EMPTY = { cookies: [], origins: [] }

const STAMP = Date.now()
/** The organisation that owns both portals and the block. */
const ORGANISATION = `spb-voorbeeld-${STAMP}`
const PORTALS = [`spb-inwoners-${STAMP}`, `spb-ondernemers-${STAMP}`]
const BLOCK_TITLE = `Contact en openingstijden ${STAMP}`
/** The block's one text widget, and the cell the designer draws for it. */
const HOURS_WIDGET = 'hours'
const OLD_HOURS = 'Open van maandag tot vrijdag, 09:00 tot 17:00.'
const NEW_HOURS = 'Open van maandag tot vrijdag, 08:30 tot 16:30.'
const CONTACT_ROUTE = `/contact-${STAMP}`
const CONTACT_TITLE = `Contact ${STAMP}`

/** What this run created, as `[schema, id]`, deleted newest first. */
const created: Array<[string, string]> = []
let blockId = ''
let contactPageId = ''

test.use({ storageState: EMPTY })
test.describe.configure({ mode: 'serial' })

/**
 * Create one object through OpenRegister's object API, as the administrator.
 *
 * @param request The request fixture.
 * @param schema The portaliq schema.
 * @param data The object.
 * @return The id.
 */
async function seed(request: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<string> {
	const res = await request.post(`${OBJECTS}/${schema}`, { headers: HEADERS, data })
	expect(res.ok(), `seeding ${schema}: ${res.status()} ${(await res.text()).slice(0, 200)}`).toBeTruthy()
	const body = await res.json()
	const id = String(body.id ?? body.uuid ?? body['@self']?.id)
	created.push([schema, id])
	return id
}

/**
 * A grid page body with an introduction and, below it, the block placed.
 *
 * @param intro The introduction's text.
 * @param block The block id the placement names.
 * @return The page body.
 */
function homeBody(intro: string, block: string): Record<string, unknown> {
	return {
		type: 'grid',
		widgets: [
			{ id: 'intro', widgetKey: 'markdown', slot: 'body', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 2, props: { markdown: intro } },
			{ id: 'contact-block', widgetKey: 'sharedBlock', slot: 'body', gridX: 0, gridY: 2, gridWidth: 12, gridHeight: 3, props: { block } },
		],
	}
}

/**
 * The placement of the block on one portal's home page as the public
 * content API answers it, read without a session.
 *
 * @param request A request context without credentials.
 * @param portal The portal slug.
 * @return The placement's props: `block`, `widgets`, `unavailable`.
 */
async function placementOn(request: APIRequestContext, portal: string): Promise<{ block: string, widgets: Array<{ props: Record<string, unknown> }>, unavailable: boolean }> {
	const res = await request.get(`${APP_BASE}/api/content/page?portal=${encodeURIComponent(portal)}&route=/`)
	expect(res.status(), `the home page of ${portal}`).toBe(200)
	const page = await res.json()
	const placement = (page.body?.widgets ?? []).find((widget: { widgetKey: string }) => widget.widgetKey === 'sharedBlock')
	expect(placement, `the home page of ${portal} carries the placement`).toBeTruthy()
	return placement.props
}

/**
 * Sign in as the administrator through Nextcloud's own login form.
 *
 * @param page The page.
 * @return Nothing.
 */
async function signIn(page: Page): Promise<void> {
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(ADMIN_USER)
	await page.locator('input[name="password"]').fill(ADMIN_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 60_000 })
}

/**
 * Open the designer at an address and wait until it shows its object; a
 * modal over the app (the first-run wizard, the tour) is closed when open.
 *
 * @param page The signed-in page.
 * @param path The designer's address, under the app.
 * @return Nothing.
 */
async function openDesigner(page: Page, path: string): Promise<void> {
	await page.goto(`${APP_BASE}${path}`)
	const modal = page.locator('[data-testid="cn-modal"], .modal-container, [data-testid="cn-tour"]').first()
	if (await modal.isVisible({ timeout: 3_000 }).catch(() => false)) {
		await page.keyboard.press('Escape')
	}
	await expect(page, 'the designer must be the page on screen, not the dashboard').toHaveURL(new RegExp(path))
	await expect(page.getByTestId('designer-title')).toBeVisible({ timeout: 30_000 })
}

/**
 * The block's text as an anonymous visitor sees it on one portal's home page.
 *
 * @param page A page without a session.
 * @param portal The portal slug.
 * @return The drawn placement.
 */
async function drawnBlock(page: Page, portal: string): Promise<Locator> {
	await page.goto(`${SITE_PATH}?portal=${encodeURIComponent(portal)}`)
	const block = page.getByTestId('shared-block')
	await expect(block, `the block is drawn on the home page of ${portal}`).toBeVisible({ timeout: 30_000 })
	await expect(block).toHaveAttribute('data-block', blockId)
	return block
}

test.describe('site-shared-page-blocks', () => {
	test.beforeAll(async ({ request }) => {
		blockId = await seed(request, 'sharedBlock', {
			title: BLOCK_TITLE,
			description: 'Het telefoonnummer en de openingstijden van het loket.',
			organisation: ORGANISATION,
			status: 'published',
			widgets: [
				{ id: HOURS_WIDGET, widgetKey: 'markdown', slot: 'body', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 2, props: { markdown: OLD_HOURS } },
			],
		})
		for (const [index, slug] of PORTALS.entries()) {
			await seed(request, 'portal', {
				title: `Gemeente Voorbeeld ${index + 1} ${STAMP}`,
				slug,
				status: 'published',
				organisation: ORGANISATION,
			})
			await seed(request, 'page', {
				title: 'Welkom',
				route: '/',
				portal: slug,
				status: 'published',
				body: homeBody(`Welkom op portaal ${index + 1}.`, blockId),
			})
		}
	})

	test.afterAll(async ({ request }) => {
		for (const [schema, id] of created.splice(0).reverse()) {
			await request.delete(`${OBJECTS}/${schema}/${id}`, { headers: HEADERS })
		}
	})

	// @e2e portal-shared-page-blocks::a-resident-sees-the-block-on-two-portals
	test('REQ-SPB-002: a resident sees the block on the home page of both portals', async ({ browser, playwright }) => {
		const anonymous = await playwright.request.newContext({ storageState: EMPTY })
		const context = await browser.newContext({ storageState: EMPTY })
		const page = await context.newPage()
		for (const portal of PORTALS) {
			const placement = await placementOn(anonymous, portal)
			expect(placement.block).toBe(blockId)
			expect(placement.unavailable).toBe(false)
			expect(placement.widgets.map((widget) => widget.props.markdown)).toEqual([OLD_HOURS])

			await expect(await drawnBlock(page, portal)).toContainText(OLD_HOURS)
		}
		await context.close()
		await anonymous.dispose()
	})

	// @e2e portal-shared-page-blocks::one-edit-shows-everywhere
	test('REQ-SPB-002: one edit in the designer shows on both portals after the next read', async ({ browser, page, playwright, request }) => {
		// Read both pages first, so a cached copy exists that the edit must clear.
		const anonymous = await playwright.request.newContext({ storageState: EMPTY })
		for (const portal of PORTALS) {
			await placementOn(anonymous, portal)
		}

		await signIn(page)
		await openDesigner(page, `/shared-blocks/${blockId}/layout`)
		await expect(page.getByTestId('designer-title')).toContainText(BLOCK_TITLE)
		await page.getByTestId(`designer-widget-${HOURS_WIDGET}`).click()
		await page.getByTestId('designer-field-markdown').fill(NEW_HOURS)
		await page.getByTestId('designer-publish').click()
		await expect(page.getByTestId('designer-notice')).toBeVisible({ timeout: 30_000 })

		await expect.poll(async () => {
			const res = await request.get(`${OBJECTS}/sharedBlock/${blockId}`, { headers: HEADERS })
			return (await res.json()).widgets?.[0]?.props?.markdown
		}, { message: 'the published block carries the new hours' }).toBe(NEW_HOURS)

		const context = await browser.newContext({ storageState: EMPTY })
		const visitor = await context.newPage()
		for (const portal of PORTALS) {
			const placement = await placementOn(anonymous, portal)
			expect(placement.widgets.map((widget) => widget.props.markdown), `the next read of ${portal}`).toEqual([NEW_HOURS])

			const block = await drawnBlock(visitor, portal)
			await expect(block).toContainText(NEW_HOURS)
			await expect(block).not.toContainText(OLD_HOURS)
		}
		await context.close()
		await anonymous.dispose()
	})

	// @e2e portal-shared-page-blocks::an-editor-places-a-block-on-a-page
	test('REQ-SPB-005: an editor places the block from the palette, and the block\'s page lists the page once published', async ({ page, request }) => {
		contactPageId = await seed(request, 'page', {
			title: CONTACT_TITLE,
			route: CONTACT_ROUTE,
			portal: PORTALS[0],
			status: 'draft',
			body: { type: 'grid', widgets: [] },
		})

		await signIn(page)
		await openDesigner(page, `/pages/${contactPageId}/layout`)
		await page.getByTestId('designer-add-widget').click()
		await expect(page.getByTestId('widget-palette')).toBeVisible()
		// "bl" finds the entry in both languages: "Gedeeld blok", "Shared block".
		await page.getByTestId('widget-palette-search').fill('bl')
		await page.getByTestId('widget-palette-tile-sharedBlock').press('Enter')
		await expect(page.getByTestId('widget-palette')).toBeHidden()

		await page.getByTestId('designer-canvas').locator('[data-widget-key="sharedBlock"]').click()
		await page.getByTestId('designer-field-block').selectOption({ label: BLOCK_TITLE })
		await page.getByTestId('designer-save-draft').click()

		const stored = async () => (await (await request.get(`${OBJECTS}/page/${contactPageId}`, { headers: HEADERS })).json())
		await expect.poll(async () => {
			const draft = (await stored()).draftBody?.widgets ?? []
			return draft.filter((widget: { widgetKey: string }) => widget.widgetKey === 'sharedBlock')
				.map((widget: { props: { block?: string } }) => widget.props.block)
		}, { message: 'the page\'s draft carries a placement of the block' }).toEqual([blockId])

		// A draft placement is not listed yet (SharedBlockPlacements reads the published body).
		const listed = async () => {
			const res = await request.get(`${APP_BASE}/api/shared-blocks/${blockId}/pages`, { headers: HEADERS })
			expect(res.status()).toBe(200)
			return ((await res.json()).results as Array<{ title: string, route: string, portal: string }>)
		}
		expect((await listed()).map((row) => row.route), 'a draft placement is not listed').not.toContain(CONTACT_ROUTE)

		// Publish the draft: its body becomes the published body, as the
		// designer's Publish writes it after its confirmation dialog, which
		// this step does not drive.
		const draft = await stored()
		const published = await request.patch(`${OBJECTS}/page/${contactPageId}`, {
			headers: HEADERS,
			data: { status: 'published', body: draft.draftBody },
		})
		expect(published.ok(), `publishing the page: ${published.status()}`).toBeTruthy()

		const rows = await listed()
		expect(rows.map((row) => `${row.portal} ${row.route}`)).toEqual(expect.arrayContaining([
			`${PORTALS[0]} ${CONTACT_ROUTE}`,
			`${PORTALS[0]} /`,
			`${PORTALS[1]} /`,
		]))

		await page.goto(`${APP_BASE}/shared-blocks/${blockId}`)
		await page.getByRole('tab', { name: 'Pagina\'s' }).click()
		await expect(page.getByText(CONTACT_TITLE), 'the block\'s page lists the page that places it').toBeVisible({ timeout: 30_000 })
	})
})
