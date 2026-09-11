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

/**
 * Failure toasts in the ADMIN window.
 *
 * `@nextcloud/dialogs` 7.5.0 renders each toast with CSS-module class names
 * hashed per build — `_toast_v43ag_11 _toast_info_v43ag_33` — so the
 * documented `.toast-info` / `.toastify` classes match nothing on this
 * instance and an assertion using them alone passes vacuously. The union
 * covers both spellings.
 *
 * It deliberately does NOT match the `_toastContainer_…` host. That element is
 * created lazily on the first toast and then never removed (the library caches
 * it on `window.__nc_toast_container__`; dismissing a toast only calls
 * `wrapper.remove()`), so a container clause would turn this into "no toast
 * has ever appeared on this page" — wider than any one scenario, and it would
 * blame this handler for an unrelated toast from earlier in the test.
 *
 * Because the toast itself auto-dismisses after 7s, an assertion built on this
 * locator only means something while the toast would still be up: assert it
 * right after the tab opens, never after a long `toContainText` wait.
 *
 * Measured on the rig on 2026-09-11, at exactly the position this locator is
 * asserted from: the pre-fix bundle yields 1 (the assertion fails, with the
 * pop-up message as its text) and the fixed bundle yields 0. So the narrow
 * form bites on its own — no container clause needed to keep it honest.
 *
 * @param page the admin page to inspect
 * @return the locator for any toast in that window
 */
function adminToasts(page: Page) {
	return page.locator('[class*="_toast_"], .toastify, .toast-info, .toast-error')
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
	// The second tag is carried by the toast assertion and the two opener
	// reads below: a real browser returns null from a `noopener` open, so a
	// handler that reads that value reports failure on every open that worked.
	// Only a real browser can fail those — an injected opener answers whatever
	// the test tells it to, and it cannot show that the browser honoured the
	// features it was handed. That scenario's remaining clause, the handler
	// RETURNING the address, is unobservable from here and is asserted in
	// tests/open-portal-site.spec.mjs instead.
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

		// NO failure toast in the admin window — asserted FIRST, while the
		// toast would still be on screen. `window.open(url, '_blank',
		// 'noopener,noreferrer')` returns null for a SUCCESSFUL open, because
		// `noopener` severs the WindowProxy, so a handler that reads that value
		// as success tells the administrator the site could not be opened every
		// single time it could. That was this file's implementation in #513
		// review round 2. Only a real browser can fail this: the unit spec's
		// opener answers whatever the test hands it.
		await expect(adminToasts(page)).toHaveCount(0)

		// The tab really is shielded. This is the other half of the scenario
		// this test is tagged with, and it is only observable here — the unit
		// spec can assert that the features were PASSED, not that the browser
		// honoured them. Same origin, so both reads are allowed.
		expect(await popup.evaluate(() => window.opener === null)).toBe(true)
		expect(await popup.evaluate(() => document.referrer)).toBe('')

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
