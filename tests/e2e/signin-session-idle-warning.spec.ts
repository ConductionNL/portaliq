/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for signin-session-idle-warning-and-sso T03-T05: with a 300
 * second idle window and a fake clock, typing keeps the session, idling opens
 * the warning two minutes before expiry, "Stay signed in" extends it, and after
 * expiry the login screen names inactivity. Needs an instance with dev-login
 * (debug mode). Written, run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test signin-session-idle-warning
 *
 * @spec openspec/specs/portal-session-idle-and-sso/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { oneOf, PORTAL_API, readSiteSession, siteAddress } from './portal-nav.ts'

const ADMIN = Buffer.from('admin:admin').toString('base64')
const OCS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }
const IDLE_KEY =
	'/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/portaliq/session_idle_timeout'

test.describe('signin-session-idle-warning', () => {
	test.beforeAll(async ({ request }) => {
		await request.post(IDLE_KEY, { headers: OCS, form: { value: '300' } })
	})

	test.afterAll(async ({ request }) => {
		await request.delete(IDLE_KEY, { headers: OCS })
	})

	/**
	 * Open the site's signed-in area signed in through dev-login under a fake
	 * clock.
	 *
	 * @param page The page.
	 */
	async function signIn(page: Page) {
		await page.clock.install()
		await page.goto(siteAddress())
		await page.getByTestId('site-devlogin').click()
		await expect(page.getByTestId('site-signout')).toBeVisible()
	}

	test('The warning opens two minutes before expiry and Stay signed in extends it', async ({
		// @e2e portal-session-idle-and-sso::one-click-keeps-the-resident-signed-in
		page,
	}) => {
		await signIn(page)
		await page.clock.fastForward('03:01')
		const dialog = page.getByRole('alertdialog')
		await expect(dialog).toBeVisible()
		await expect(page.getByTestId('site-idle-stay')).toBeFocused()
		const refreshed = page.waitForResponse(
			(r) => r.url().includes('/session/refresh') && r.ok(),
		)
		await page.getByTestId('site-idle-stay').click()
		await refreshed
		await expect(dialog).toBeHidden()
	})

	test('Typing keeps the session, idling does not', async ({ page }) => {
		// @e2e portal-session-idle-and-sso::typing-keeps-a-resident-signed-in
		// @e2e portal-session-idle-and-sso::an-open-tab-alone-does-not-keep-a-session
		await signIn(page)
		await page.clock.fastForward('02:40')
		const refreshed = page.waitForResponse(
			(r) => r.url().includes('/session/refresh') && r.ok(),
		)
		await page.keyboard.press('Shift')
		await refreshed
		await page.clock.fastForward('02:40')
		await expect(page.getByRole('alertdialog')).toBeHidden()
	})

	test('After expiry the login screen names inactivity', async ({ page }) => {
		// @e2e portal-session-idle-and-sso::the-login-screen-says-why
		// @e2e portal-session-idle-and-sso::an-unattended-bearer-stops-working
		await signIn(page)
		await page.clock.fastForward('05:01')
		await expect(page.getByTestId('site-idle-signed-out')).toBeVisible()
		await expect(page.getByTestId('site-signout')).toBeHidden()
	})
	test('The session reports when it ends', async ({ page }) => {
		// @e2e portal-session-idle-and-sso::the-session-reports-when-it-ends
		await page.goto(siteAddress())
		await page.getByTestId('site-devlogin').click()
		await expect(page.getByTestId('site-signout')).toBeVisible()
		const token = await readSiteSession(page)
		const answer = await page.request.get(`${PORTAL_API}/session`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		const body = await answer.json()
		expect(body.idleTimeout).toBe(300)
		expect(body.hardExpiresAt).toBeGreaterThan(body.expiresAt)
	})

	test('Sign out from the warning', async ({ page }) => {
		// @e2e portal-session-idle-and-sso::the-resident-signs-out-from-the-warning
		await signIn(page)
		await page.clock.fastForward('03:01')
		await page
			.getByRole('alertdialog')
			.getByRole('button', { name: oneOf('Uitloggen', 'Sign out') })
			.click()
		await expect(page.getByTestId('site-signout')).toBeHidden()
		await expect(page.getByTestId('site-idle-signed-out')).toBeHidden()
	})

	// The site keeps its bearer per tab in sessionStorage (src/site/lib/
	// idleTracker.js, design D4): a second tab is not signed in, so there is
	// no shared session for its activity to keep. The React portal shared one
	// bearer across tabs through localStorage; the site deliberately does not.
	test.fixme('Activity in another tab keeps this one', async ({ context }) => {
		// @e2e portal-session-idle-and-sso::activity-in-another-tab-counts
		const first = await context.newPage()
		await signIn(first)
		const second = await context.newPage()
		await second.clock.install()
		await second.goto(siteAddress())
		await expect(second.getByTestId('site-signout')).toBeVisible()
		await first.clock.fastForward('02:40')
		await second.clock.fastForward('02:40')
		const refreshed = second.waitForResponse(
			(r) => r.url().includes('/session/refresh') && r.ok(),
		)
		await second.keyboard.press('Shift')
		await refreshed
		await first.clock.fastForward('01:00')
		await expect(first.getByRole('alertdialog')).toBeHidden()
	})

	test('Near the cap there is nothing to extend', async ({ page, request }) => {
		// @e2e portal-session-idle-and-sso::near-the-cap-there-is-nothing-to-extend
		const MAX =
			'/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/portaliq/session_max_lifetime'
		await request.post(MAX, { headers: OCS, form: { value: '120' } })
		try {
			await signIn(page)
			await page.clock.fastForward('03:01')
			await expect(page.getByTestId('site-idle-sign-in-again')).toBeVisible()
			await expect(page.getByTestId('site-idle-stay')).toHaveCount(0)
		} finally {
			await request.delete(MAX, { headers: OCS })
		}
	})
})
