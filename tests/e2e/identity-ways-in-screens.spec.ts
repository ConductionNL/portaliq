/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * identity-ways-in-screens: a visitor without an account creates one and
 * follows the activation link, follows one case with its case number, and
 * accepts an invitation; a portal without the e-mail sign-in shows no
 * "Create an account" door. The mailed secrets are not readable from a
 * browser, so the link steps answer the redeem routes through page.route
 * with the shapes PortalIdentityControllerTest pins.
 *
 * Needs: a published portal `e2e-ways-in` on `dev-org` with registration
 * policy `activation`, a `generic` sign-in provider, and a form binding to a
 * case type admitting `reference`; and a portal `e2e-digid-only` with policy
 * `approval` and DigiD only; and a portal `e2e-registration-off` with policy
 * `off` and a `generic` provider.
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-you-can-create-an-account-where-the-portal-allows-it-req-iwi-002
 * @e2e openspec/specs/portal-ways-in/spec.md
 */

import { expect, test } from '@playwright/test'

const PORTAL = '/apps/portaliq/portal?portal=e2e-ways-in'

test.describe('identity-ways-in-screens', () => {
	test('Registration under activation', async ({ page }) => {
		await page.goto(PORTAL)
		const door = page.getByTestId('way-in-register')
		await door.getByLabel('E-mail address').fill(`e2e-${Date.now()}@example.org`)
		await door.getByRole('button', { name: 'Create an account' }).click()
		await expect(page.getByTestId('way-in-register-result')).toContainText(
			'Follow the link in it to activate your account.',
		)

		await page.route('**/portal/api/identity/activate', (route) =>
			route.fulfill({ status: 200, json: { activated: true } }),
		)
		await page.goto(`${PORTAL}#activate=mailed-secret`)
		await expect(page.getByTestId('way-in-link-result')).toContainText(
			'Your account is ready. Sign in with',
		)
		await expect(page).not.toHaveURL(/#activate=/)
	})

	test('Registration is off', async ({ page }) => {
		await page.goto('/apps/portaliq/portal?portal=e2e-registration-off')
		await expect(page.getByTestId('way-in-register')).toHaveCount(0)
	})

	test('No e-mail based sign-in, no registration', async ({ page }) => {
		await page.goto('/apps/portaliq/portal?portal=e2e-digid-only')
		await expect(page.getByRole('heading', { name: 'Create an account' })).toHaveCount(0)
	})

	test('A reference link arrives by mail', async ({ page }) => {
		await page.goto(PORTAL)
		const door = page.getByTestId('way-in-reference')
		await door.getByLabel('Case number').fill('Z-2026-0042')
		await door.getByLabel('E-mail address').fill('anna@example.nl')
		await door.getByRole('button', { name: 'Send me a link' }).click()
		await expect(page.getByTestId('way-in-reference-result')).toContainText(
			'we sent a link to that address',
		)
	})

	test('A resident follows their case without an account', async ({ page }) => {
		await page.route('**/portal/api/identity/reference-link/redeem', (route) =>
			route.fulfill({ status: 200, json: { bearer: 'reference-bearer', caseReference: 'Z-2026-0042' } }),
		)
		await page.route('**/portal/api/identity/reference-case', (route) =>
			route.fulfill({
				status: 200,
				json: { case: { status: 'In behandeling' }, caseReference: 'Z-2026-0042', readOnly: true },
			}),
		)
		await page.goto(`${PORTAL}#reference=mailed-secret`)
		const view = page.getByTestId('reference-case')
		await expect(view).toContainText('Z-2026-0042')
		await expect(view).toContainText('In behandeling')
		await expect(view.locator('button, form, input')).toHaveCount(0)
	})

	test('The reference session cannot reach another case', async ({ request }) => {
		const answer = await request.get('/apps/portaliq/portal/api/identity/reference-case', {
			headers: { Authorization: 'Bearer not-a-reference-session' },
		})
		expect(answer.status()).toBe(401)
	})

	test('A link works once', async ({ page }) => {
		await page.goto(`${PORTAL}#reference=already-spent`)
		await expect(page.getByTestId('way-in-link-result')).toContainText('This link is no longer valid.')
	})

	test('An invited supplier accepts', async ({ page }) => {
		await page.route('**/portal/api/identity/invitation/accept', (route) =>
			route.fulfill({ status: 200, json: { accepted: true } }),
		)
		await page.goto(`${PORTAL}#invitation=mailed-secret`)
		await page.getByRole('button', { name: 'Accept' }).click()
		await expect(page.getByTestId('way-in-link-result')).toContainText(
			'Your account is ready. Sign in with',
		)
	})
})
