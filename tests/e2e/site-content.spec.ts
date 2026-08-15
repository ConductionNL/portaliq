/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Scenarios S1–S3 — the built-in site renderer reads the headless content API
 * and renders navigation, a grid page and a markdown page.
 *
 * See tests/e2e/scenarios/portal-phase-two.md.
 */

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const API = `${BASE}/index.php/apps/portaliq/api/content`

test.describe('site renderer — content', () => {
	test('S1: renders the site title, menu and grid page from the API', async ({ page }) => {
		await page.goto(SITE)

		// The title comes from the website object, not from a constant in the
		// bundle. Asserting the literal seeded value is the point: a renderer
		// that fell back to a product name would still show *a* title.
		await expect(page.getByTestId('site-title')).toHaveText('Open Tilburg')

		const menu = page.getByTestId('site-menu')
		await expect(menu.getByRole('link', { name: 'Home' })).toBeVisible()
		await expect(menu.getByRole('link', { name: 'Over ons' })).toBeVisible()
		await expect(menu.getByRole('link', { name: 'Begrippen' })).toBeVisible()
		// Two levels, one nested under 'Over ons'.
		await expect(menu.getByRole('link', { name: 'Contact' })).toBeVisible()

		// The home page is a GRID body: three markdown widgets in declared cells.
		const grid = page.getByTestId('widget-grid')
		await expect(grid).toBeVisible()
		await expect(page.getByTestId('widget-intro')).toContainText('Welkom bij Open Tilburg')
		await expect(page.getByTestId('widget-side')).toContainText('Actueel')
		await expect(page.getByTestId('widget-help')).toContainText('Hulp nodig?')

		// Grid geometry is the manifest's, not a portal variant: the two
		// half-width widgets sit side by side, so their vertical positions
		// match and their horizontal ones do not.
		const side = await page.getByTestId('widget-side').boundingBox()
		const help = await page.getByTestId('widget-help').boundingBox()
		expect(side).not.toBeNull()
		expect(help).not.toBeNull()
		expect(Math.abs((side!.y) - (help!.y))).toBeLessThan(4)
		expect(help!.x).toBeGreaterThan(side!.x)
	})

	test('S2: markdown is served as source and rendered with its structure intact', async ({ page, request }) => {
		const response = await request.get(`${API}/page?route=/over-ons`)
		expect(response.status()).toBe(200)
		const body = await response.json()

		expect(body.body.type).toBe('markdown')
		const markdown: string = body.body.markdown
		// SOURCE, not HTML. A consumer that renders markdown itself — a
		// Docusaurus build, most obviously — must not have to undo an HTML
		// conversion the API did on its behalf.
		expect(markdown).toContain('```')
		expect(markdown).toContain('| Kolom | Waarde |')
		expect(markdown).not.toContain('<p>')
		expect(markdown).not.toContain('<table')

		// And the renderer turns that same source into real structure.
		await page.goto(`${SITE}?route=/over-ons`)
		await expect(page.getByTestId('page-title')).toHaveText('Over ons')
		await expect(page.locator('#pq-main pre')).toBeVisible()
		await expect(page.locator('#pq-main table')).toBeVisible()
		await expect(page.locator('#pq-main table td').first()).toBeVisible()
	})

	test('S3: in-site navigation updates the page and marks the current item', async ({ page }) => {
		await page.goto(SITE)
		await expect(page.getByTestId('page-title')).toHaveText('Welkom')

		// Pin that this is a client-side transition: a full document
		// navigation would reset this marker.
		await page.evaluate(() => {
			;(window as unknown as Record<string, unknown>).__pqNoReload = true
		})

		await page.getByTestId('site-menu').getByRole('link', { name: 'Over ons' }).click()

		await expect(page.getByTestId('page-title')).toHaveText('Over ons')
		await expect(
			page.getByTestId('site-menu').getByRole('link', { name: 'Over ons' }),
		).toHaveAttribute('aria-current', 'page')

		const survived = await page.evaluate(
			() => (window as unknown as Record<string, unknown>).__pqNoReload === true,
		)
		expect(survived).toBe(true)
	})

	test('S1b: the glossary is reachable and rendered', async ({ page }) => {
		await page.goto(`${SITE}?route=/begrippen`)
		const terms = page.getByTestId('glossary-term')
		await expect(terms.first()).toBeVisible()
		await expect(page.getByTestId('site-glossary')).toContainText('Woo-verzoek')
		await expect(page.getByTestId('site-glossary')).toContainText('Wob-verzoek')
	})
})
