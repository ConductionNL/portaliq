/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-cms-admin-ui: the editorial path of a portal without an API client.
 *
 *  - The four admin surfaces (Portals, Menus, Pages, Glossary) are the shared
 *    manifest index pages over OpenRegister objects.
 *  - A site with a menu, a markdown page and a glossary term is published,
 *    the public content API serves it and the site renders it.
 *  - The publish check names a menu link with no page, warns on a link to a
 *    draft page, and refuses a site without a page at `/`.
 *  - A pending domain shows its exact TXT record, and the check runs again
 *    without the domain being added again.
 *
 * Every context is a fresh one with an empty storage state. The administrator
 * signs in through Nextcloud's login form; a context that never signs in is
 * the anonymous caller, and the CMS admin endpoints refuse it. The content
 * rows are written through OpenRegister's object API as the administrator,
 * the same objects the shared index page's create dialog writes. The target
 * comes from BASE_URL (see tests/e2e/base-url.ts).
 */

import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { SITE_PATH } from './portal-nav.ts'

const APP_BASE = '/index.php/apps/portaliq'
const OR_OBJECTS_BASE = '/index.php/apps/openregister/api/objects'
const ADMIN_USER = process.env.ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? 'admin'
const ADMIN = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
const HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }

const EMPTY = { cookies: [], origins: [] }

test.use({ storageState: EMPTY })

/**
 * Create one object through OpenRegister's object API, as the administrator.
 *
 * @param request The request fixture.
 * @param schema The portaliq schema.
 * @param data The object.
 * @return The id.
 */
async function create(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/${schema}`, {
		headers: HEADERS,
		data,
	})
	expect(res.ok(), `OpenRegister objects#create for ${schema}`).toBeTruthy()
	const body = await res.json()
	return (body.id ?? body['@self']?.id) as string
}

/**
 * Change one object through OpenRegister's object API, as the administrator.
 *
 * @param request The request fixture.
 * @param schema The portaliq schema.
 * @param id The object id.
 * @param data The new fields.
 * @return Nothing.
 */
async function change(
	request: APIRequestContext,
	schema: string,
	id: string,
	data: Record<string, unknown>,
): Promise<void> {
	const res = await request.patch(`${OR_OBJECTS_BASE}/portaliq/${schema}/${id}`, {
		headers: HEADERS,
		data,
	})
	expect(res.ok(), `OpenRegister objects#update for ${schema}`).toBeTruthy()
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
 * The publish check of one portal, as the signed-in administrator.
 *
 * @param page The signed-in page.
 * @param slug The portal slug.
 * @return The check's answer.
 */
async function publishCheck(
	page: Page,
	slug: string,
): Promise<{ ok: boolean, blocking: Array<Record<string, string>>, warnings: Array<Record<string, string>> }> {
	const res = await page.request.get(
		`${APP_BASE}/api/cms/publish-check?portal=${encodeURIComponent(slug)}`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	expect(res.status()).toBe(200)
	return res.json()
}

/**
 * A draft portal of its own, so nothing here touches the seeded sites.
 *
 * @param request The request fixture.
 * @param slug The portal slug.
 * @param extra More portal fields.
 * @return The portal id.
 */
async function newPortal(
	request: APIRequestContext,
	slug: string,
	extra: Record<string, unknown> = {},
): Promise<string> {
	return create(request, 'portal', {
		title: `E2E site ${slug}`,
		slug,
		status: 'draft',
		...extra,
	})
}

/**
 * A fresh browser context that never signs in.
 *
 * @param browser The browser fixture.
 * @return The anonymous page.
 */
async function anonymous(browser: Browser): Promise<Page> {
	const context = await browser.newContext({ storageState: EMPTY })
	return context.newPage()
}

test.describe('portal-cms-admin-ui', () => {
	// @e2e portaliq-cms::the-surfaces-are-the-shared-components
	test('the four content surfaces are the shared index pages', async ({ page }) => {
		await signIn(page)
		for (const route of ['portals', 'menus', 'pages', 'glossary']) {
			await page.goto(`${APP_BASE}/${route}`)
			await expect(
				page.getByTestId('cn-index-page'),
				`/${route} renders the shared index page`,
			).toBeVisible({ timeout: 30_000 })
		}
	})

	// @e2e portaliq-cms::a-site-is-created-and-published-end-to-end
	// @e2e portaliq-cms::a-menu-item-pointing-at-nothing-is-refused
	// @e2e portaliq-cms::a-drafted-target-is-distinguished-from-a-missing-one
	// @e2e portaliq-cms::a-site-with-no-root-page-is-refused
	test('a site is checked, published, served and rendered', async ({ page, request }) => {
		const slug = `e2e-cms-${Date.now()}`
		const portalId = await newPortal(request, slug)
		await create(request, 'menu', {
			title: 'Hoofdmenu',
			portal: slug,
			position: 0,
			items: [
				{ order: 0, name: 'Over ons', link: '/over-ons' },
				{ order: 1, name: 'Begrippen', link: '/begrippen' },
			],
		})
		await signIn(page)

		// No page at `/` and a menu link to /begrippen with no page behind it.
		let check = await publishCheck(page, slug)
		expect(check.ok).toBe(false)
		const codes = check.blocking.map((b) => `${b.code} ${b.route ?? ''}`.trim())
		expect(codes).toContain('no-root-page /')
		expect(codes).toContain('menu-link-no-page /begrippen')

		// A drafted page at /begrippen warns; it no longer blocks.
		await create(request, 'page', {
			title: 'Begrippen',
			route: '/begrippen',
			portal: slug,
			status: 'draft',
			body: { type: 'markdown', markdown: 'Nog niet klaar.' },
		})
		await create(request, 'page', {
			title: 'Welkom',
			route: '/',
			portal: slug,
			status: 'published',
			body: { type: 'markdown', markdown: '# Welkom\n\nDe e2e-site staat live.' },
		})
		await create(request, 'page', {
			title: 'Over ons',
			route: '/over-ons',
			portal: slug,
			status: 'published',
			body: { type: 'markdown', markdown: 'Wie wij zijn.' },
		})
		check = await publishCheck(page, slug)
		expect(check.blocking).toEqual([])
		expect(check.ok).toBe(true)
		expect(check.warnings.map((w) => `${w.code} ${w.route ?? ''}`.trim())).toContain(
			'menu-link-draft-page /begrippen',
		)

		await create(request, 'glossaryTerm', {
			term: 'Woo-verzoek',
			definition: 'Een verzoek om openbaarmaking van overheidsinformatie.',
			portal: slug,
		})
		await change(request, 'portal', portalId, { status: 'published' })

		// The public content API serves it to a caller without a session.
		const visitor = await anonymous(page.context().browser() as Browser)
		const q = `portal=${encodeURIComponent(slug)}`
		const pages = await visitor.request.get(`${APP_BASE}/api/content/pages?${q}`)
		expect(pages.status()).toBe(200)
		const served = JSON.stringify(await pages.json())
		expect(served).toContain('/over-ons')
		expect(served, 'a draft page is not served').not.toContain('Nog niet klaar.')
		const menus = await visitor.request.get(`${APP_BASE}/api/content/menus?${q}`)
		expect(JSON.stringify(await menus.json())).toContain('Over ons')
		const glossary = await visitor.request.get(`${APP_BASE}/api/content/glossary?${q}`)
		expect(JSON.stringify(await glossary.json())).toContain('Woo-verzoek')

		// And the site renders the front page.
		await visitor.goto(`${SITE_PATH}?${q}`)
		await expect(visitor.getByText('De e2e-site staat live.')).toBeVisible({ timeout: 30_000 })
		await visitor.context().close()
	})

	// @e2e portaliq-cms::the-record-to-publish-is-shown
	// @e2e portaliq-cms::verification-can-be-retried
	test('a pending domain shows its record, and the check runs again without re-adding it', async ({ page, request }) => {
		const slug = `e2e-dom-${Date.now()}`
		const hostname = `${slug}.example.invalid`
		await newPortal(request, slug, {
			domains: [{ hostname, verified: false }],
		})
		await signIn(page)

		const read = async () => {
			const res = await page.request.get(
				`${APP_BASE}/api/cms/domains?portal=${encodeURIComponent(slug)}`,
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			expect(res.status()).toBe(200)
			return (await res.json()).domains as Array<{ hostname: string, name: string, value: string, verified: boolean }>
		}
		const before = await read()
		expect(before).toHaveLength(1)
		expect(before[0].hostname).toBe(hostname)
		expect(before[0].name).not.toBe('')
		expect(before[0].value).not.toBe('')
		expect(before[0].verified).toBe(false)

		// `.invalid` never resolves, so this is the normal first outcome.
		const requestToken = await page.evaluate(
			() => (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken ?? '',
		)
		for (let attempt = 0; attempt < 2; attempt++) {
			const res = await page.request.post(`${APP_BASE}/api/cms/domains/verify`, {
				headers: { 'OCS-APIRequest': 'true', requesttoken: requestToken },
				data: { portal: slug, hostname },
			})
			expect(res.status()).toBe(200)
			expect((await res.json()).status).toBe('pending')
		}

		// The same one domain, with the same record: nothing was added again.
		expect(await read()).toEqual(before)

		// Nobody without a session reads the record or runs the check.
		const visitor = await anonymous(page.context().browser() as Browser)
		const records = await visitor.request.get(
			`${APP_BASE}/api/cms/domains?portal=${encodeURIComponent(slug)}`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(records.ok()).toBe(false)
		const check = await visitor.request.get(
			`${APP_BASE}/api/cms/publish-check?portal=${encodeURIComponent(slug)}`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(check.ok()).toBe(false)
		await visitor.context().close()
	})
})
