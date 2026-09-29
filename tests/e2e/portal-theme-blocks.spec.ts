/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-theme-blocks-and-contributed-pages tasks 4 to 7: the header, footer
 * and hero blocks, and the five regions, on a live instance.
 *
 * The unit-level proofs are node specs (tests/site-shell-blocks.spec.mjs,
 * tests/site-grid.spec.mjs, tests/site-regions.spec.mjs) and PHPUnit
 * (PortalShellTest, PortalRegionResolverTest, CmsReaderTest). What only a
 * browser can show is here: computed styles of the footer bands, the heading
 * outline of a rendered page, and the served contract.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const CONTENT = `${BASE}/index.php/apps/portaliq/api/content`
const PAGES = `${BASE}/index.php/apps/openregister/api/objects/portaliq/page`

const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** The route this file owns. */
const ROUTE = '/e2e-regions'

/**
 * An admin API context with its own cookie jar.
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
 * Remove every page at this file's route.
 *
 * @param api the admin context
 */
async function removeFixture(api: APIRequestContext): Promise<void> {
	const existing = await api.get(`${PAGES}?_limit=500`)
	if (existing.ok()) {
		for (const row of (await existing.json()).results ?? []) {
			if (row.route === ROUTE) {
				await api.delete(`${PAGES}/${row['@self']?.id ?? row.id}`)
			}
		}
	}
}

test.beforeAll(async () => {
	const api = await adminApi()
	await removeFixture(api)
	const created = await api.post(PAGES, {
		data: {
			title: 'E2E regions fixture',
			route: ROUTE,
			portal: 'open-tilburg',
			status: 'published',
			locale: 'nl',
			body: {
				type: 'grid',
				clearedRegions: ['aside'],
				widgets: [
					{
						id: 'e2e-hero',
						widgetKey: 'hero',
						slot: 'hero',
						gridX: 0,
						gridY: 0,
						gridWidth: 12,
						gridHeight: 4,
						props: {
							eyebrow: 'Diensten',
							title: 'Welkom bij de regio-test',
							actions: [
								{ label: 'Een', href: '/een' },
								{ label: 'Twee', href: '/twee' },
								{ label: 'Drie', href: '/drie' },
							],
						},
					},
					{
						id: 'e2e-intro',
						widgetKey: 'markdown',
						slot: 'body',
						gridX: 0,
						gridY: 4,
						gridWidth: 12,
						gridHeight: 2,
						props: {
							markdown: 'Direct onder de band.',
							style: 'position:absolute;top:0',
							class: 'evil',
						},
					},
					{
						id: 'e2e-typo',
						widgetKey: 'markdown',
						slot: 'heder',
						gridX: 0,
						gridY: 6,
						gridWidth: 12,
						gridHeight: 2,
						props: { markdown: 'Verkeerd gebied.' },
					},
				],
			},
		},
	})
	expect(created.ok(), await created.text()).toBe(true)
	await api.dispose()
})

test.afterAll(async () => {
	const api = await adminApi()
	await removeFixture(api)
	await api.dispose()
})

test.describe('portal theme blocks and regions', () => {
	// @e2e portaliq-cms::existing-pages-need-no-migration
	// @e2e portaliq-cms::a-misspelt-region-is-reported
	test('the content API serves body.regions beside body.widgets and reports a misspelt slot', async () => {
		const api = await request.newContext()
		const response = await api.get(
			`${CONTENT}/page?route=${encodeURIComponent(ROUTE)}&portal=open-tilburg`,
		)
		expect(response.status()).toBe(200)
		const body = (await response.json()).body

		expect(body.widgets.map((w: { id: string }) => w.id)).toEqual([
			'e2e-hero',
			'e2e-intro',
			'e2e-typo',
		])
		expect(body.regions.main.map((w: { id: string }) => w.id)).toEqual([
			'e2e-intro',
		])
		expect(body.regions.hero.map((w: { id: string }) => w.id)).toEqual([
			'e2e-hero',
		])
		expect(body.unknownRegions).toEqual(['heder'])
		expect(body.clearedRegions).toEqual(['aside'])
		await api.dispose()
	})

	// @e2e portaliq-cms::a-page-replaces-one-region
	// @e2e portaliq-cms::a-third-action-is-not-rendered
	// @e2e portaliq-cms::the-eyebrow-is-not-a-heading
	// @e2e portaliq-cms::authored-style-never-reaches-the-page
	test('a page with its own hero renders one hero, one h1, two actions and no authored style', async ({
		page,
	}) => {
		await page.goto(`${SITE}?route=${encodeURIComponent(ROUTE)}`)
		await expect(page.getByTestId('site-region-hero')).toBeVisible()

		await expect(page.locator('h1')).toHaveCount(1)
		await expect(page.locator('h1')).toHaveText('Welkom bij de regio-test')
		await expect(page.getByTestId('hero-eyebrow')).toHaveText('Diensten')
		await expect(page.getByTestId('hero-action')).toHaveCount(2)
		await expect(page.locator('.evil')).toHaveCount(0)
		await expect(
			page.locator(
				'[style*="position:absolute"], [style*="position: absolute"]',
			),
		).toHaveCount(0)
		// The site name is not a heading (REQ-PTB-004).
		await expect(page.getByTestId('site-title')).not.toHaveJSProperty(
			'tagName',
			'H1',
		)
	})

	// @e2e portaliq-cms::a-band-leaves-no-hole-in-the-grid
	test('the markdown starts directly below the hero band', async ({ page }) => {
		await page.goto(`${SITE}?route=${encodeURIComponent(ROUTE)}`)
		const hero = await page.getByTestId('site-region-hero').boundingBox()
		const intro = await page.getByTestId('widget-e2e-intro').boundingBox()
		expect(hero).not.toBeNull()
		expect(intro).not.toBeNull()
		// One grid gap and the container's own padding at most, never a row.
		expect(
			(intro?.y ?? 0) - ((hero?.y ?? 0) + (hero?.height ?? 0)),
		).toBeLessThan(40)
	})

	// @e2e portaliq-cms::a-third-band-changes-nothing-about-the-first-two
	test('a third footer band changes nothing about the first two', async ({
		page,
	}) => {
		await page.goto(SITE)
		await expect(page.getByTestId('site-footer')).toBeVisible()

		const measure = () =>
			page.evaluate(() =>
				[
					...document.querySelectorAll(
						'[data-testid="site-footer"] > section',
					),
				]
					.slice(0, 2)
					.map((band) => {
						const style = getComputedStyle(band)
						return [
							style.backgroundColor,
							style.paddingTop,
							style.paddingBottom,
							style.fontFamily,
							style.color,
						].join('|')
					}),
			)
		const before = await measure()
		await page.evaluate(() => {
			document
				.querySelector('[data-testid="site-footer"]')
				?.appendChild(document.createElement('section'))
		})
		expect(await measure()).toEqual(before)
	})

	// @e2e portaliq-cms::the-legal-bar-always-names-someone
	// @e2e portaliq-cms::an-existing-portal-keeps-its-header
	test('a portal with no new fields keeps its header and names itself in the legal bar', async ({
		page,
	}) => {
		await page.goto(SITE)
		await expect(page.getByTestId('site-title')).toHaveText('Open Tilburg')
		await expect(page.locator('.ac-header__navigation-secondary')).toHaveCount(1)
		await expect(page.getByTestId('site-footer-colophon')).not.toBeEmpty()
	})
})
