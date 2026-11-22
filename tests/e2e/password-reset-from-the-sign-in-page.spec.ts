/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * password-reset-from-the-sign-in-page (REQ-PWR-001): on a portal that offers
 * the account route, the sign-in page shows "Wachtwoord vergeten", and it
 * leads to Nextcloud's own login page with its lost-password flow and a way
 * back to the portal's account route. A portal that offers only DigiD shows
 * no such link. Portaliq never asks for a password itself.
 *
 * The fixture is this file's own: two published portals, one offering the
 * account route (`nextcloud`) and one offering only DigiD, created through
 * OpenRegister's object API as the Nextcloud admin and deleted afterwards.
 * The seeded portals offer only `public`, so they show no sign-in routes.
 * The visitor reads anonymously in a fresh context.
 *
 * The scenario's last step (after the reset and sign-in the resident lands
 * back on the portal signed in) needs a mailbox for the reset mail, so this
 * spec stops at the return address the link carries and the live check (T04)
 * covers the round trip. Written, not run here:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test password-reset-from-the-sign-in-page
 *
 * @spec openspec/changes/password-reset-from-the-sign-in-page/specs/portal-ways-in/spec.md#requirement-the-sign-in-page-leads-to-nextclouds-own-password-reset-req-pwr-001
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'
import { ACCOUNT_ROUTE, SITE_PATH } from './portal-nav.ts'

const BASE = resolveBaseURL()
const PORTALS = `${BASE}/index.php/apps/openregister/api/objects/portaliq/portal`
const SITE_API = `${BASE}/index.php/apps/portaliq/api/content/site`

/** Nextcloud admin, as exported by the shared quality.yml Playwright step. */
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

const STAMP = Date.now()

/** The two portals of this run, by the ways in each offers. */
const ACCOUNT_PORTAL = `e2e-wachtwoord-${STAMP}`
const DIGID_PORTAL = `e2e-alleen-digid-${STAMP}`

/** The ids of the portals created for this run. */
const portalIds: string[] = []

/**
 * An admin API context, Basic auth with the OCS header so Nextcloud skips
 * its CSRF check. Seeding and cleanup only; the visitor never holds it.
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
 * Create one published portal offering the given ways in.
 *
 * @param api the admin context
 * @param slug the portal's slug
 * @param modes the authentication modes it offers
 */
async function createPortal(
	api: APIRequestContext,
	slug: string,
	modes: string[],
): Promise<void> {
	const created = await api.post(PORTALS, {
		data: {
			slug,
			title: `Wachtwoord-test ${slug}`,
			status: 'published',
			organisation: 'dev-org',
			locales: ['nl', 'en'],
			authentication: { modes },
		},
	})
	expect(
		created.ok(),
		`creating portal ${slug}: ${created.status()} ${await created.text()}`,
	).toBeTruthy()
	const body = await created.json()
	const id = String(body.id ?? body.uuid ?? body['@self']?.id ?? '')
	expect(id, `portal ${slug} has an id`).not.toBe('')
	portalIds.push(id)
}

/**
 * The site address of the account route on one portal.
 *
 * @param slug the portal's slug
 * @return the address
 */
function signInAddress(slug: string): string {
	const params = new URLSearchParams({ portal: slug, route: ACCOUNT_ROUTE })
	return `${SITE_PATH}?${params.toString()}`
}

test.describe('password-reset-from-the-sign-in-page', () => {
	test.beforeAll(async () => {
		const api = await adminApi()
		try {
			await createPortal(api, ACCOUNT_PORTAL, ['nextcloud'])
			await createPortal(api, DIGID_PORTAL, ['digid'])
		} finally {
			await api.dispose()
		}
	})

	test.afterAll(async () => {
		const api = await adminApi()
		try {
			for (const id of portalIds) {
				await api.delete(`${PORTALS}/${id}`)
			}
		} finally {
			await api.dispose()
		}
	})

	test('the site read serves the reset address only with the account route', async () => {
		const visitor = await request.newContext({ baseURL: BASE })
		try {
			const account = await visitor.get(SITE_API, {
				params: { portal: ACCOUNT_PORTAL },
			})
			expect(account.status()).toBe(200)
			const accountSite = await account.json()
			const lost = String(accountSite.lostPasswordUrl ?? '')
			expect(lost).toContain('/login')
			// The way back is the portal's account route, never an address the
			// request supplied.
			const back = new URL(lost).searchParams.get('redirect_url') ?? ''
			expect(back).toContain(ACCOUNT_PORTAL)

			const digid = await visitor.get(SITE_API, {
				params: { portal: DIGID_PORTAL },
			})
			expect(digid.status()).toBe(200)
			expect((await digid.json()).lostPasswordUrl ?? '').toBe('')
		} finally {
			await visitor.dispose()
		}
	})

	test("A forgotten password: the link opens Nextcloud's own login page", async ({
		browser,
	}) => {
		const context = await browser.newContext()
		const page = await context.newPage()
		try {
			await page.goto(signInAddress(ACCOUNT_PORTAL))
			await expect(
				page.getByTestId('site-account-signin-route').first(),
			).toBeVisible()

			const link = page.getByTestId('site-signin-lost-password')
			await expect(link).toHaveCount(1)
			await expect(link).toHaveText(
				/Wachtwoord vergeten|Forgot your password\?/,
			)

			// Portaliq asks for no password: the sign-in page holds no
			// password field of its own.
			await expect(page.locator('input[type="password"]')).toHaveCount(0)

			await link.click()
			await page.waitForURL(/\/login/)
			expect(
				new URL(page.url()).searchParams.get('redirect_url') ?? '',
			).toContain(ACCOUNT_PORTAL)

			// Nextcloud's login page, with its own way to reset the password.
			await expect(page.locator('input[name="user"]')).toBeVisible()
			await expect(
				page
					.locator(
						'#lost-password, a[href*="lostpassword"], .login-form__lost-password, button:has-text("Forgot password")',
					)
					.first(),
			).toBeVisible()
		} finally {
			await context.close()
		}
	})

	test('A DigiD-only portal shows no "Wachtwoord vergeten"', async ({
		browser,
	}) => {
		const context = await browser.newContext()
		const page = await context.newPage()
		try {
			await page.goto(signInAddress(DIGID_PORTAL))
			await expect(
				page.getByTestId('site-account-signin-route').first(),
			).toBeVisible()
			await expect(page.getByTestId('site-signin-lost-password')).toHaveCount(
				0,
			)
		} finally {
			await context.close()
		}
	})
})
