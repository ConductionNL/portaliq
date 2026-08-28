/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Editing a portal page: the floating control on the public site, and the
 * grid designer it opens.
 *
 * THE SPEC UNDER TEST IS SPLIT ACROSS TWO SURFACES ON PURPOSE, so the
 * assertions are too. The site owns the DOOR — who is offered editing, and for
 * which page — and the Nextcloud app owns the ROOM, because the grid engine
 * and the widget catalogue have no business in a bundle an anonymous visitor
 * downloads on a phone.
 *
 * THE FIXTURE IS THIS FILE'S OWN. It creates a published page at
 * `/e2e-designer`, drives it, and deletes it again, rather than editing the
 * seeded portal's home page — which every other site spec asserts against, and
 * which a failed publish here would leave rearranged for all of them.
 *
 * See tests/e2e/scenarios/portal-phase-two.md.
 */

import { expect, test, type APIRequestContext, type Page } from '@playwright/test'
import { request } from '@playwright/test'
import { readFileSync } from 'fs'
import * as path from 'path'
import { resolveBaseURL } from './base-url'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const CONTENT = `${BASE}/index.php/apps/portaliq/api/content`
const OBJECTS = `${BASE}/index.php/apps/openregister/api/objects/portaliq/page`

/** Nextcloud admin, as exported by the shared quality.yml Playwright step. */
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** The route this file owns. */
const ROUTE = '/e2e-designer'

/** The id of the page created for this run. */
let pageId = ''

/**
 * An admin API context with its own cookie jar.
 *
 * @return the context; the caller disposes it
 */
async function adminApi(): Promise<APIRequestContext> {
	const basic = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')

	return await request.newContext({
		baseURL: BASE,
		// Nextcloud short-circuits its CSRF check on this header, and a
		// Basic-auth request carries no session cookie, so the precondition
		// for that short-circuit holds.
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Authorization: `Basic ${basic}`,
			'Content-Type': 'application/json',
		},
	})
}

/**
 * Sign the browser in to Nextcloud.
 *
 * @param page the page to sign in
 * @param user the account name
 * @param pass the password
 */
async function loginToNextcloud(page: Page, user: string, pass: string): Promise<void> {
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(pass)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 60_000 })
	expect(
		/\/login(\?|$|\/)/.test(page.url()),
		`login as ${user} failed — still on ${page.url()}`,
	).toBe(false)
}

/**
 * The path prefix the app's own router owns, read back rather than predicted.
 *
 * MEASURED, because guessing it costs a whole test. This instance rewrites, so
 * the router's base is `/apps/portaliq`; an instance without mod_rewrite serves
 * the same app at `/index.php/apps/portaliq`. A deep link built with the wrong
 * one does not 404 — vue-router simply matches nothing, its catch-all redirects
 * to `/`, and the run lands on the DASHBOARD looking like the designer failed
 * to render.
 *
 * @param page the page to navigate
 * @return the base path, without a trailing slash
 */
async function spaBase(page: Page): Promise<string> {
	await page.goto(`${BASE}/index.php/apps/portaliq/`)
	await expect(page.locator('#content > *').first()).toBeVisible({ timeout: 60_000 })
	const settled = new URL(page.url()).pathname
	expect(
		settled,
		'the app root must settle inside the router base, not on a login or error page',
	).toContain('/apps/portaliq')

	return settled.replace(/\/+$/, '')
}

/**
 * The published widget placements of this file's page, from the PUBLIC API.
 *
 * Read through the public contract rather than the object API on purpose: what
 * a draft must not change, and what a publish must, is what a visitor sees.
 *
 * @param api the request context
 * @return the placements
 */
async function publishedWidgets(api: APIRequestContext): Promise<Array<Record<string, number | string>>> {
	const response = await api.get(`${CONTENT}/page?route=${encodeURIComponent(ROUTE)}&portal=open-tilburg`)
	expect(response.status(), 'the fixture page must be publicly readable').toBe(200)
	const body = await response.json()

	return body.body?.widgets ?? []
}

test.beforeAll(async () => {
	const api = await adminApi()

	// Idempotent by route: a run that died before its cleanup must not leave a
	// second page at the same route, which would make `identify()` ambiguous
	// and this file's assertions depend on insertion order.
	const existing = await api.get(`${OBJECTS}?_limit=500`)
	if (existing.ok()) {
		for (const row of (await existing.json()).results ?? []) {
			if (row.route === ROUTE) {
				await api.delete(`${OBJECTS}/${row['@self']?.id ?? row.id}`)
			}
		}
	}

	const created = await api.post(OBJECTS, {
		data: {
			title: 'E2E designer fixture',
			route: ROUTE,
			portal: 'open-tilburg',
			status: 'published',
			locale: 'nl',
			summary: 'Fixture page for the page-designer e2e.',
			body: {
				type: 'grid',
				widgets: [
					{
						id: 'fixture-intro',
						widgetKey: 'markdown',
						slot: 'body',
						gridX: 0,
						gridY: 0,
						gridWidth: 6,
						gridHeight: 3,
						props: { markdown: '## Fixture\n\nEen testpagina.' },
					},
				],
			},
		},
	})
	expect(created.ok(), `creating the fixture page failed: ${created.status()}`).toBe(true)
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

test.describe('portal page editing', () => {
	// @e2e portal-page-designer::an-editor-sees-the-control-and-reaches-the-designer
	test('S0: the link the server hands out is the route the manifest declares', async () => {
		// TWO HALVES OF ONE DEEP LINK, WRITTEN IN DIFFERENT FILES. The floating
		// control's target is built server-side by CmsEditorController, and the
		// screen it opens exists only because src/manifest.json routes
		// `/pages/:id/layout` to the PageLayoutDesigner component. Nothing
		// connects them at build time.
		//
		// When they disagree the failure is silent in the worst way: vue-router
		// matches nothing, its catch-all redirects to `/`, and the editor lands
		// on the DASHBOARD — a working screen, so it reads as "the designer did
		// not load" rather than "the link was wrong". This run hit exactly that
		// while being written, from a base-path mismatch.
		const manifest = JSON.parse(
			readFileSync(path.join(__dirname, '..', '..', 'src', 'manifest.json'), 'utf8'),
		)
		const declared = manifest.pages.find(
			(entry: Record<string, string>) => entry.id === 'PageLayout',
		)
		expect(declared, 'the manifest must declare the PageLayout page').toBeTruthy()
		expect(declared.component).toBe('PageLayoutDesigner')

		const api = await adminApi()
		const probe = await api.get(
			`${BASE}/index.php/apps/portaliq/api/cms/editing-context?route=${encodeURIComponent(ROUTE)}&portal=open-tilburg`,
		)
		expect(probe.status()).toBe(200)
		const context = await probe.json()
		expect(context.canEdit).toBe(true)
		expect(context.pageId).toBe(pageId)

		const expected = declared.route.replace(':id', pageId)
		expect(
			context.designerUrl.endsWith(expected),
			`the server offers ${context.designerUrl}, which does not end in the manifest's ${expected}`,
		).toBe(true)

		await api.dispose()
	})

	// @e2e portal-page-designer::an-anonymous-visitor-sees-no-control
	test('S1: an anonymous visitor is offered no editing control at all', async ({
		page,
	}) => {
		await page.goto(`${SITE}?route=${encodeURIComponent(ROUTE)}`)
		await expect(page.getByTestId('site-title')).toBeVisible()

		// ABSENT, not hidden. A control that exists in the document with
		// `display: none` is still in several screen readers' trees and still
		// tells a reader of the source that an editing surface is there.
		await expect(page.getByTestId('site-edit')).toHaveCount(0)
		await expect(page.getByTestId('site-edit-button')).toHaveCount(0)
	})

	// @e2e portal-page-designer::an-editor-sees-the-control-and-reaches-the-designer
	test('S2: an editor is offered the control, and it opens the designer for this page', async ({
		page,
	}) => {
		await loginToNextcloud(page, ADMIN_USER, ADMIN_PASS)
		await page.goto(`${SITE}?route=${encodeURIComponent(ROUTE)}`)

		const button = page.getByTestId('site-edit-button')
		await expect(button).toBeVisible({ timeout: 30_000 })
		await expect(button).toHaveAttribute('aria-expanded', 'false')

		// Bottom-right, which is the whole placement requirement.
		const box = await button.boundingBox()
		const viewport = page.viewportSize()
		expect(box).not.toBeNull()
		expect(viewport).not.toBeNull()
		expect(box!.x + box!.width).toBeGreaterThan(viewport!.width * 0.75)
		expect(box!.y + box!.height).toBeGreaterThan(viewport!.height * 0.75)

		await button.click()
		await expect(button).toHaveAttribute('aria-expanded', 'true')
		await expect(page.getByTestId('site-edit-menu')).toHaveAttribute('role', 'menu')

		await page.getByTestId('site-edit-page').click()
		await expect(page).toHaveURL(new RegExp(`/pages/${pageId}/layout$`))
		await expect(page.getByTestId('designer-title')).toHaveText('E2E designer fixture')
	})

	// @e2e portal-page-designer::a-saved-draft-does-not-change-the-public-page
	// @e2e portal-page-designer::publishing-promotes-the-draft
	// @e2e portal-page-designer::a-moved-widget-keeps-its-new-cell
	// @e2e portal-page-designer::the-grid-may-be-edited-without-a-pointer
	test('S3: a draft leaves the published page alone; publishing changes it', async ({
		page,
	}) => {
		const api = await adminApi()
		const before = await publishedWidgets(api)
		expect(before[0].gridX, 'the fixture starts in column 0').toBe(0)

		await loginToNextcloud(page, ADMIN_USER, ADMIN_PASS)
		const app = await spaBase(page)
		await page.goto(`${BASE}${app}/pages/${pageId}/layout`)
		await expect(page.getByTestId('designer-title')).toBeVisible({ timeout: 30_000 })

		// MOVED BY KEYBOARD, deliberately: the pointer drag is GridStack's own
		// and is covered by the library, while the keyboard path is the one
		// this app promises and the one a drag-only grid silently lacks.
		const item = page.locator('.grid-stack-item', {
			has: page.getByTestId('designer-widget-fixture-intro'),
		})
		await item.focus()
		await page.keyboard.press('ArrowRight')
		await expect(item).toHaveAttribute('gs-x', '1')
		await expect(page.getByTestId('designer-dirty')).toBeVisible()

		await page.getByTestId('designer-save-draft').click()
		await expect(page.getByTestId('designer-notice')).toBeVisible()
		await expect(page.getByTestId('designer-has-draft')).toBeVisible()

		// THE PUBLISHED PAGE IS UNTOUCHED. This is the assertion the whole
		// draft field exists for; without it, "save" and "publish" are two
		// buttons doing one thing.
		const afterDraft = await publishedWidgets(api)
		expect(afterDraft[0].gridX, 'a draft must not move the published widget').toBe(0)
		expect(
			JSON.stringify(afterDraft),
			'no part of the draft may appear in the public response',
		).not.toContain('draftBody')

		await page.getByTestId('designer-publish').click()
		await expect(page.getByTestId('designer-notice')).toBeVisible()
		await expect(page.getByTestId('designer-has-draft')).toHaveCount(0)

		const afterPublish = await publishedWidgets(api)
		expect(afterPublish[0].gridX, 'publishing must move the published widget').toBe(1)

		await api.dispose()
	})

	// @e2e portal-page-designer::a-widget-is-added-from-the-palette
	// @e2e portal-page-designer::a-non-public-widget-is-marked
	// @e2e portal-page-designer::a-public-widget-is-offered-normally
	test('S4: the palette offers the whole catalogue and marks what a public page will not show', async ({
		page,
	}) => {
		await loginToNextcloud(page, ADMIN_USER, ADMIN_PASS)
		const app = await spaBase(page)
		await page.goto(`${BASE}${app}/pages/${pageId}/layout`)
		await expect(page.getByTestId('designer-title')).toBeVisible({ timeout: 30_000 })

		await page.getByTestId('designer-add-widget').click()
		const palette = page.getByTestId('widget-palette')
		await expect(palette).toBeVisible()

		// Both halves of "the whole catalogue, marked". Asserting only the
		// public half would pass for a palette that hides everything else,
		// which is the design this one deliberately rejects.
		await expect(palette.locator('[data-public="true"]').first()).toBeVisible()
		const nonPublic = palette.locator('[data-public="false"]')
		expect(
			await nonPublic.count(),
			'the app catalogue must be offered, not hidden',
		).toBeGreaterThan(0)
		await expect(nonPublic.first()).toContainText('openbare pagina')

		await page.getByTestId('widget-palette-hero').click()
		await expect(palette).toHaveCount(0)

		// Placed, selected, and editable through fields derived from the
		// block's own props.
		await expect(page.getByTestId('designer-widget-hero-1')).toBeVisible()
		const title = page.getByTestId('designer-field-title')
		await title.fill('Welkom in Tilburg')
		await expect(page.getByTestId('designer-widget-hero-1')).toContainText(
			'Welkom in Tilburg',
		)

		// A widget the public renderer will not mount previews as the
		// placeholder the site would show — never as a working widget.
		await page.getByTestId('designer-add-widget').click()
		await page.getByTestId('widget-palette-stat').click()
		await expect(page.getByTestId('designer-placeholder-stat-1')).toBeVisible()

		// Left as an unsaved draft on purpose: nothing here was saved, so the
		// published page this file created is still the one S3 left behind.
	})
})
