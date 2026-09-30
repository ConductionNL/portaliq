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

import { expect, test } from '@playwright/test'

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
	 * Open the portal signed in through dev-login under a fake clock.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 */
	async function signIn(page) {
		await page.clock.install()
		await page.goto('/apps/portaliq/portal')
		await page.locator('.portaliq-devlogin').click()
		await expect(page.locator('.portaliq-logout')).toBeVisible()
	}

	test('The warning opens two minutes before expiry and Stay signed in extends it', async ({
		page,
	}) => {
		await signIn(page)
		await page.clock.fastForward('03:01')
		const dialog = page.getByRole('alertdialog')
		await expect(dialog).toBeVisible()
		await expect(page.getByTestId('idle-stay')).toBeFocused()
		const refreshed = page.waitForResponse(
			(r) => r.url().includes('/session/refresh') && r.ok(),
		)
		await page.getByTestId('idle-stay').click()
		await refreshed
		await expect(dialog).toBeHidden()
	})

	test('Typing keeps the session, idling does not', async ({ page }) => {
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
		await signIn(page)
		await page.clock.fastForward('05:01')
		await expect(page.getByTestId('idle-signed-out')).toBeVisible()
		await expect(page.locator('.portaliq-logout')).toBeHidden()
	})
})
