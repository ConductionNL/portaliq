/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-identity-from-the-admin 1.2, 2.1 and 2.2 (REQ-PIA-001, REQ-PIA-002):
 * a portal's favicon, logo and hero image are items of its own media library;
 * a favicon that is not a PNG, SVG or ICO is refused; the site head carries
 * exactly one icon link, to the favicon, which answers without a session; and
 * a hero without an image of its own shows the portal's hero image.
 *
 * WHAT IS REAL. Everything, and nothing is stubbed with `page.route`: the
 * portal, its media items and their files are OpenRegister objects, the
 * save-path rules run on OpenRegister's own write events
 * (`PortalImagesGuardListener` over `PortalImageChoice`), the head is
 * `templates/site.php` with `SiteIcon`, the image addresses are the public
 * content media route (`ContentMediaController::show()`), and the hero is
 * drawn by the site from `ContentController::site()`'s `heroImage`.
 *
 * WHY THE IMAGES ARE SET THROUGH THE OBJECT WRITE. The portal details form is
 * a manifest settings form that writes the `portal` object through
 * OpenRegister, and the reference pickers have no upload of their own (an
 * image is uploaded on the portal's Media page, task 1.2). So the write here
 * is the write the form makes, and the refusals are the ones the form shows.
 * The pickers themselves are pinned by tests/portal-details.spec.mjs.
 *
 * NOT COVERED: REQ-PIA-003 (the organisation type). The type is accepted only
 * as a concept of the TOOI scheme in OpenRegister's concept register, which
 * the test instance does not carry, and task 3.2 (the footer line) is still
 * open; the label's wording is pinned by tests/mijn-components.spec.mjs.
 *
 * Needs the admin's credentials in ADMIN_USER / ADMIN_PASSWORD (default
 * admin/admin). The visitor's contexts start from an empty storage state. The
 * target comes from BASE_URL (see tests/e2e/base-url.ts).
 *
 * @spec openspec/specs/portaliq-cms/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { SITE_PATH } from './portal-nav.ts'

const APP_BASE = '/index.php/apps/portaliq'
const OR_OBJECTS = '/index.php/apps/openregister/api/objects/portaliq'
const ADMIN_USER = process.env.ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? 'admin'
const ADMIN = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
const HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }
const EMPTY = { cookies: [], origins: [] }

const STAMP = Date.now()
const SLUG = `pia-${STAMP}`
const OTHER_SLUG = `pia-other-${STAMP}`

/**
 * A small square SVG, a valid favicon and a valid logo.
 *
 * @param fill The square's colour.
 * @return The SVG source.
 */
function svg(fill: string): string {
	return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><rect width="16" height="16" fill="${fill}"/></svg>`
}

/** The ids this run uses, filled by beforeAll. */
const ids = { portal: '', favicon: '', logo: '', hero: '', jpeg: '', foreign: '' }

/** What this run created, as `[schema, id]`, deleted newest first. */
const created: Array<[string, string]> = []

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
	const res = await request.post(`${OR_OBJECTS}/${schema}`, { headers: HEADERS, data })
	expect(res.ok(), `seeding ${schema}: ${res.status()} ${(await res.text()).slice(0, 200)}`).toBeTruthy()
	const body = await res.json()
	const id = String(body.id ?? body.uuid ?? body['@self']?.id)
	created.push([schema, id])
	return id
}

/**
 * A published image of one portal's media library, with one file attached
 * through OpenRegister's file route (the file's name decides its type).
 *
 * @param request The request fixture.
 * @param portal The portal slug the item belongs to.
 * @param title The item's title, also its alt text.
 * @param name The file's name.
 * @param content The file's content.
 * @return The media item's id.
 */
async function mediaImage(request: APIRequestContext, portal: string, title: string, name: string, content: string): Promise<string> {
	const id = await seed(request, 'media', { portal, title, kind: 'image', status: 'published', alt: title })
	const file = await request.post(`${OR_OBJECTS}/media/${id}/files`, {
		headers: HEADERS,
		data: { name, content, share: false },
	})
	expect(file.ok(), `attaching ${name}: ${file.status()} ${(await file.text()).slice(0, 200)}`).toBeTruthy()
	return id
}

/**
 * Change the portal through OpenRegister's object API, the write the
 * portal details form makes.
 *
 * @param request The request fixture.
 * @param data The fields to change.
 * @return The answer's status and body text.
 */
async function savePortal(request: APIRequestContext, data: Record<string, unknown>): Promise<{ ok: boolean, status: number, text: string }> {
	const res = await request.patch(`${OR_OBJECTS}/portal/${ids.portal}`, { headers: HEADERS, data })
	return { ok: res.ok(), status: res.status(), text: await res.text() }
}

/**
 * The stored portal.
 *
 * @param request The request fixture.
 * @return The portal object.
 */
async function storedPortal(request: APIRequestContext): Promise<Record<string, unknown>> {
	const res = await request.get(`${OR_OBJECTS}/portal/${ids.portal}`, { headers: HEADERS })
	expect(res.ok()).toBeTruthy()
	return res.json()
}

test.describe('portal-identity-from-the-admin', () => {
	test.beforeAll(async ({ request }) => {
		ids.portal = await seed(request, 'portal', { title: `Waterschap Voorbeeld ${STAMP}`, slug: SLUG, status: 'published' })
		await seed(request, 'portal', { title: `Ander portaal ${STAMP}`, slug: OTHER_SLUG, status: 'published' })
		ids.favicon = await mediaImage(request, SLUG, 'Tabblad-icoon', 'favicon.svg', svg('#0b5394'))
		ids.logo = await mediaImage(request, SLUG, 'Logo van het waterschap', 'logo.svg', svg('#38761d'))
		ids.hero = await mediaImage(request, SLUG, 'Dijk bij zonsopgang', 'dijk.svg', svg('#e69138'))
		ids.jpeg = await mediaImage(request, SLUG, 'Foto van het gemaal', 'gemaal.jpg', 'ÿØÿà not really a jpeg')
		ids.foreign = await mediaImage(request, OTHER_SLUG, 'Icoon van een ander portaal', 'ander.svg', svg('#990000'))
		await seed(request, 'page', {
			title: 'Welkom',
			route: '/',
			portal: SLUG,
			status: 'published',
			body: {
				type: 'grid',
				widgets: [
					// A hero with no backgroundImage of its own.
					{ id: 'pia-hero', widgetKey: 'hero', slot: 'hero', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 4, props: { title: 'Welkom bij het waterschap' } },
					{ id: 'pia-intro', widgetKey: 'markdown', slot: 'body', gridX: 0, gridY: 4, gridWidth: 12, gridHeight: 2, props: { markdown: 'Droge voeten en schoon water.' } },
				],
			},
		})
	})

	test.afterAll(async ({ request }) => {
		for (const [schema, id] of created.splice(0).reverse()) {
			await request.delete(`${OR_OBJECTS}/${schema}/${id}`, { headers: HEADERS })
		}
	})

	// @e2e portaliq-cms::an-administrator-sets-the-three-images
	test('REQ-PIA-001: the favicon, logo and hero image are stored as references to this portal\'s media', async ({ request }) => {
		const saved = await savePortal(request, {
			favicon: `media:${ids.favicon}`,
			logo: `media:${ids.logo}`,
			heroImage: `media:${ids.hero}`,
		})
		expect(saved.ok, `saving the three images: ${saved.status} ${saved.text.slice(0, 200)}`).toBe(true)

		const portal = await storedPortal(request)
		expect(portal.favicon).toBe(`media:${ids.favicon}`)
		expect(portal.logo).toBe(`media:${ids.logo}`)
		expect(portal.heroImage).toBe(`media:${ids.hero}`)
	})

	// @e2e portaliq-cms::a-jpeg-is-not-a-favicon
	test('REQ-PIA-001: a JPEG is not a favicon, and another portal\'s media is refused', async ({ request }) => {
		const jpeg = await savePortal(request, { favicon: `media:${ids.jpeg}` })
		expect(jpeg.ok, 'a JPEG favicon must be refused').toBe(false)
		expect(jpeg.text, 'the refusal names the accepted types').toMatch(/PNG, SVG or ICO/)

		// The requirement's last sentence: a reference to another portal's library.
		const foreign = await savePortal(request, { favicon: `media:${ids.foreign}` })
		expect(foreign.ok, 'another portal\'s media must be refused').toBe(false)

		expect((await storedPortal(request)).favicon, 'the refused saves changed nothing').toBe(`media:${ids.favicon}`)
	})

	// @e2e portaliq-cms::the-favicon-is-the-portals-own
	test('REQ-PIA-002: the head carries one icon link, to the favicon, which answers without a session', async ({ browser, playwright }) => {
		const context = await browser.newContext({ storageState: EMPTY })
		const page = await context.newPage()
		await page.goto(`${SITE_PATH}?portal=${SLUG}`)

		const icons = page.locator('head link[rel="icon"]')
		await expect(icons).toHaveCount(1)
		const href = (await icons.getAttribute('href')) ?? ''
		expect(href, 'the icon is the favicon\'s public address').toContain(`/content/media/${ids.favicon}`)
		expect(href, 'the favicon wins over the logo').not.toContain(ids.logo)
		await context.close()

		const anonymous = await playwright.request.newContext({ storageState: EMPTY })
		const res = await anonymous.get(href)
		expect(res.status(), 'the favicon answers without a session').toBe(200)
		expect(await res.text()).toContain('<svg')
		await anonymous.dispose()
	})

	// @e2e portaliq-cms::a-hero-without-its-own-image-uses-the-portals
	test('REQ-PIA-002: a hero without its own image shows the portal\'s hero image', async ({ browser, playwright }) => {
		const anonymous = await playwright.request.newContext({ storageState: EMPTY })
		const site = await anonymous.get(`${APP_BASE}/api/content/site?portal=${SLUG}`)
		expect(site.status()).toBe(200)
		const { heroImage, logo } = await site.json() as { heroImage: string, logo: string }
		expect(heroImage, 'the site read sends a public address, never the reference').toContain(`/content/media/${ids.hero}`)
		expect(logo).toContain(`/content/media/${ids.logo}`)
		expect((await anonymous.get(heroImage)).status(), 'the hero image answers without a session').toBe(200)
		await anonymous.dispose()

		const context = await browser.newContext({ storageState: EMPTY })
		const page = await context.newPage()
		await page.goto(`${SITE_PATH}?portal=${SLUG}`)
		const hero = page.getByTestId('site-region-hero').locator('section').first()
		await expect(hero).toBeVisible({ timeout: 30_000 })
		await expect(hero, 'the hero band paints the portal\'s hero image').toHaveAttribute('style', new RegExp(`content/media/${ids.hero}`))
		await context.close()
	})
})
