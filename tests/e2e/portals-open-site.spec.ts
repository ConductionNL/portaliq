/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Open portal" row action on the Portals overview.
 *
 * WHAT THIS COVERS THAT THE UNIT SPEC CANNOT. `tests/open-portal-site.spec.mjs`
 * asserts the URL — prefix, encoding, the absent-slug branch — against injected
 * collaborators. What it cannot see is whether the action is DECLARED
 * correctly: a manifest action whose `handler` string does not resolve in the
 * app's `customComponents` map has its handler stripped by the dispatcher and
 * renders as a menu entry that does nothing at all. That failure is invisible
 * to a unit test and to the JSON schema, and it is exactly the shape that put
 * three dead `@rowClick` listeners in the fleet. So this spec drives the real
 * menu in the real SPA.
 *
 * THE SEEDED PORTAL IS THE FIXTURE. `tests/e2e/fixtures/seed-cms.sh` provisions
 * `open-tilburg` (published), which every other site spec also relies on, so
 * this file creates nothing and deletes nothing.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portals-overview-must-open-a-portals-public-site
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()

/** Nextcloud admin, as exported by the shared quality.yml Playwright step. */
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** The seeded portal this spec opens (see fixtures/seed-cms.sh). */
const PORTAL_SLUG = 'open-tilburg'
const PORTAL_TITLE = 'Open Tilburg'

/**
 * Sign the browser in to Nextcloud.
 *
 * @param page the page to sign in
 * @param user the account name
 * @param pass the password
 */
async function loginToNextcloud(
	page: Page,
	user: string,
	pass: string,
): Promise<void> {
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
 * An instance that rewrites URLs serves the SPA at `/apps/portaliq`; one that
 * does not serves it at `/index.php/apps/portaliq`. A deep link built with the
 * wrong one does not 404 — the router matches nothing and its catch-all
 * redirects to the dashboard, which looks like the page failed to render.
 *
 * @param page the page to navigate
 * @return the base path, without a trailing slash
 */
async function spaBase(page: Page): Promise<string> {
	await page.goto(`${BASE}/index.php/apps/portaliq/`)
	await expect(page.locator('#content > *').first()).toBeVisible({
		timeout: 60_000,
	})
	const settled = new URL(page.url()).pathname
	expect(
		settled,
		'the app root must settle inside the router base, not on a login or error page',
	).toContain('/apps/portaliq')

	return settled.replace(/\/+$/, '')
}

test.describe('Portals overview — open a portal', () => {
	// @e2e portaliq-cms::an-administrator-opens-a-published-portal-from-the-overview
	// @e2e portaliq-cms::a-successful-open-is-never-reported-as-a-failure
	//
	// Those two scenarios and no more. The seeded slug (`open-tilburg`)
	// carries nothing that needs escaping, so tagging the encoding scenario
	// here would certify a branch this test cannot fail on — an implementation
	// with no `encodeURIComponent` at all would pass it. That scenario is
	// asserted in tests/open-portal-site.spec.mjs and marked `@e2e exclude` in
	// the spec.
	//
	// The second tag is carried by the toast assertion at the end: a real
	// browser returns null from a `noopener` open, so a handler that reads
	// that value reports failure on every open that worked. Only a real
	// browser can fail that one — an injected opener answers whatever the test
	// tells it to.
	test('the row action opens the portal site in a new tab', async ({
		page,
		context,
	}) => {
		await loginToNextcloud(page, ADMIN_USER, ADMIN_PASS)
		const app = await spaBase(page)
		await page.goto(`${app}/portals`)

		// The row for the seeded portal, found by its title rather than by
		// index: the overview is sorted by the object store, and a fixture
		// added later must not silently retarget this assertion.
		const row = page.locator('tr', { hasText: PORTAL_TITLE }).first()
		await expect(row).toBeVisible({ timeout: 60_000 })

		// The overflow menu, then the entry. `cn-action-item-open-portal` is
		// CnRowActions' slugified label, so a renamed label fails here loudly
		// instead of leaving the assertion matching nothing.
		await row.getByTestId('cn-row-actions').getByRole('button').first().click()
		const entry = page.getByTestId('cn-action-item-open-portal')
		await expect(entry).toBeVisible()

		const popupPromise = context.waitForEvent('page')
		await entry.click()
		const popup = await popupPromise
		await popup.waitForLoadState('domcontentloaded')

		// The address is the contract: the app's own site route, carrying this
		// row's slug as the `portal` parameter.
		const opened = new URL(popup.url())
		expect(opened.pathname).toMatch(/\/apps\/portaliq\/site$/)
		expect(opened.searchParams.get('portal')).toBe(PORTAL_SLUG)

		// And it resolves to THAT portal — the reason the parameter is there.
		await expect(popup.locator('body')).toContainText(PORTAL_TITLE, {
			timeout: 30_000,
		})
		await popup.close()

		// The admin window shows NO toast for the tab that just opened.
		// `window.open(url, '_blank', 'noopener,noreferrer')` returns null for
		// a SUCCESSFUL open — `noopener` severs the WindowProxy — so a handler
		// that treats the return value as a success signal tells the
		// administrator the site could not be opened every single time it
		// could. That was this file's implementation in #513 review round 2,
		// and only a real browser can fail this assertion: the unit spec's
		// opener answers whatever the test hands it.
		//
		// THE SELECTOR IS A UNION ON PURPOSE, AND IT WAS MEASURED. The
		// installed `@nextcloud/dialogs` renders its toast with CSS-module
		// class names hashed per build (`_toast_v43ag_11
		// _toast_info_v43ag_33`, inside `_toastContainer_1biev_1`), so the
		// documented `.toast-info` / `.toastify` classes match NOTHING on this
		// instance and an assertion using them alone would pass vacuously.
		// Verified on the rig on 2026-09-11: against the pre-fix bundle this
		// locator finds the toast (the assertion fails), against the fixed
		// bundle it finds none.
		const toast = page.locator(
			'[class*="_toast_"], [class*="toastContainer"], .toastify, .toast-info, .toast-error',
		)
		await expect(toast).toHaveCount(0)
	})

	// @e2e portaliq-cms::the-built-in-row-actions-survive-the-addition
	test('the pre-existing row actions still work alongside it', async ({
		page,
	}) => {
		await loginToNextcloud(page, ADMIN_USER, ADMIN_PASS)
		const app = await spaBase(page)
		await page.goto(`${app}/portals`)

		const row = page.locator('tr', { hasText: PORTAL_TITLE }).first()
		await expect(row).toBeVisible({ timeout: 60_000 })
		await row.getByTestId('cn-row-actions').getByRole('button').first().click()

		// The new entry is an ADDITION: the built-ins the page had before must
		// still be in the same menu. A `config.actions` that replaced them
		// instead would pass every assertion above and quietly remove the only
		// way to edit a portal.
		const menu = page.getByTestId('cn-row-actions')
		await expect(page.getByTestId('cn-action-item-open-portal')).toBeVisible()
		await expect(
			menu
				.getByRole('button', { name: /view|bekijk/i })
				.or(page.getByTestId('cn-action-item-view'))
				.first(),
		).toBeVisible()
	})
})
