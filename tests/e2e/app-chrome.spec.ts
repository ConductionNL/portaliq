/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has already produced three
 * defects of exactly that shape: an unregistered icon name renders NO glyph
 * (no fallback, no console error, and four apps shipped it), an entry whose
 * `route` names a page the app does not host renders a row that goes nowhere,
 * and `nav.includePersonalSettings: false` silently removed the entry that
 * reaches the user's notification preferences in two apps.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP_BASE = '/index.php/apps/portaliq'
const ADMIN_USER = process.env.ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? 'admin'

/**
 * Sign in. This suite's config declares no `use.storageState`, so every spec
 * logs in for itself.
 *
 * @param page The page.
 */
async function login(page: Page): Promise<void> {
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(ADMIN_USER)
	await page.locator('input[name="password"]').fill(ADMIN_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 60_000 })
}

/**
 * Dismiss the first-run setup wizard if it is open.
 *
 * ⚠️ On a FRESH instance CnSetupWizard opens over the app and its modal
 * intercepts pointer events, so every nav click resolves its locator and then
 * times out after 30s — a failure that reads like the navigation is broken.
 * Tests that navigate by URL pass, which is what makes this so easy to miss:
 * only the click-through tests fail, and only on a clean install.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}
	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
		await page.goto(`${APP_BASE}/`)
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
		await dismissSetupWizard(page)
	})

	test('the footer reads Documentation, Reports, Features & roadmap, each with a glyph', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		// ORDER is the rule, not the numbers: ADR-114 fixes the sequence, and
		// openregister runs its footer at 1/2 while pipelinq runs 160/200/230.
		const seen = texts.filter((t) => /Documentation|Reports|roadmap/i.test(t))
		expect(seen.length).toBe(3)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Reports/i)
		expect(seen[2]).toMatch(/roadmap/i)

		// A glyph on every row — the assertion that would have caught the
		// unregistered-icon defect. ChartBoxOutline had to be added to
		// src/icons.js for this very entry.
		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('Traffic is a card on Reports, not a main-nav entry', async ({ page }) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		// ADR-112 Decision 2: a report is a card OR an entry, never both.
		// Traffic was a main-nav entry at order 50 before ADR-114.
		await expect(
			nav.locator('[data-testid="cn-nav-entry-Traffic"]'),
		).toHaveCount(0)

		await nav
			.locator('[data-testid="cn-nav-entry-ReportsMenu"] a')
			.first()
			.click()
		await expect(page).toHaveURL(/\/apps\/portaliq\/reports(\?|$)/, {
			timeout: 15_000,
		})
		await expect(
			page
				.locator('main, .app-content')
				.first()
				.getByText('Traffic', { exact: false })
				.first(),
		).toBeVisible({ timeout: 15_000 })
	})

	test('the Traffic page is still routable at its own path', async ({ page }) => {
		// Retiring a menu entry must not take the route with it — deep links and
		// the older specs address it by path (ADR-044 Decision 5).
		await page.goto(`${APP_BASE}/traffic`)
		await expect(page).toHaveURL(/\/traffic(\?|$)/, { timeout: 15_000 })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
	})

	test('Themes stays in the main navigation, because it is not a report', async ({
		page,
	}) => {
		// Deliberate asymmetry with Traffic. Themes configures how a portal
		// LOOKS; it is not a reading of what happened, and ADR-097 keeps a
		// domain of the work in the main nav. If a later sweep cards it, this
		// fails rather than passing review.
		const nav = page.locator('[data-testid="cn-nav"]')
		await expect(
			nav.locator('[data-testid="cn-nav-entry-Themes"]'),
		).toBeAttached({ timeout: 15_000 })
	})

	test('the settings foldout carries Personal settings, Admin settings and Flows', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached()
		await expect(
			nav.locator('[data-testid="cn-nav-entry-FlowsMenu"]'),
		).toBeAttached()

		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]')
		await expect(admin).toBeAttached()
		await expect(admin.locator('a').first()).toHaveAttribute(
			'href',
			/\/settings\/admin\/portaliq$/,
		)
	})
})
