/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * identity-ways-in-screens on the Vue site: a visitor without an account
 * creates one and follows the activation link, follows one case with its case
 * number, and accepts an invitation; a portal without the e-mail sign-in shows
 * no "Create an account" door. The mailed secrets are not readable from a
 * browser, so the link steps answer the redeem routes through page.route with
 * the shapes PortalIdentityControllerTest pins.
 *
 * Needs: a published portal `e2e-ways-in` on `dev-org` with registration
 * policy `activation`, a `generic` sign-in provider, and a form binding to a
 * case type admitting `reference`; a portal `e2e-digid-only` with policy
 * `approval` and DigiD only; and a portal `e2e-registration-off` with policy
 * `off` and a `generic` provider.
 *
 * @spec openspec/changes/identity-ways-in-screens/specs/portal-ways-in/spec.md#requirement-you-can-create-an-account-where-the-portal-allows-it-req-iwi-002
 * @e2e openspec/changes/identity-ways-in-screens/specs/portal-ways-in/spec.md
 */

import { expect, test } from '@playwright/test'

function site(portal: string, route = '/mijn') {
	return `/apps/portaliq/site?portal=${portal}&route=${route}`
}

test.describe('identity-ways-in-screens', () => {
	test('Registration under activation', async ({ page }) => {
		await page.goto(site('e2e-ways-in'))
		const door = page.getByTestId('way-in-register')
		await door
			.getByLabel(/E-mail address|E-mailadres/)
			.fill(`e2e-${Date.now()}@example.org`)
		await door
			.getByRole('button', { name: /Create an account|Account aanmaken/ })
			.click()
		await expect(page.getByTestId('way-in-register-result')).toContainText(
			/activate your account|uw account te activeren/,
		)

		await page.route('**/portal/api/identity/activate', (route) =>
			route.fulfill({ status: 200, json: { activated: true } }),
		)
		await page.goto(`${site('e2e-ways-in', '/')}#activate=mailed-secret`)
		await expect(page.getByTestId('way-in-link-result')).toContainText(
			/Your account is ready|Uw account is klaar/,
		)
		await expect(page).not.toHaveURL(/#activate=/)
	})

	test('Registration is off', async ({ page }) => {
		await page.goto(site('e2e-registration-off'))
		await expect(page.getByTestId('site-account-signin')).toBeVisible()
		await expect(page.getByTestId('way-in-register')).toHaveCount(0)
	})

	test('No e-mail based sign-in, no registration', async ({ page }) => {
		await page.goto(site('e2e-digid-only'))
		await expect(page.getByTestId('site-account-signin')).toBeVisible()
		await expect(page.getByTestId('way-in-register')).toHaveCount(0)
	})

	test('A reference link arrives by mail and opens one case read only', async ({
		page,
	}) => {
		await page.goto(site('e2e-ways-in'))
		const door = page.getByTestId('way-in-reference')
		await door.getByLabel(/Case number|Zaaknummer/).fill('Z-2026-0001')
		await door
			.getByLabel(/E-mail address|E-mailadres/)
			.fill('resident@example.org')
		await door
			.getByRole('button', { name: /Send me a link|Stuur mij een link/ })
			.click()
		await expect(page.getByTestId('way-in-reference-result')).toContainText(
			/It works once|één keer/,
		)

		await page.route('**/portal/api/identity/reference-link/redeem', (route) =>
			route.fulfill({
				status: 200,
				json: { caseReference: 'Z-2026-0001', bearer: 'reference-bearer' },
			}),
		)
		await page.route('**/portal/api/identity/reference-case', (route) =>
			route.fulfill({
				status: 200,
				json: {
					case: { title: 'Parkeervergunning', status: 'In behandeling' },
					caseReference: 'Z-2026-0001',
					readOnly: true,
				},
			}),
		)
		await page.goto(`${site('e2e-ways-in', '/')}#reference=mailed-secret`)
		const opened = page.getByTestId('reference-case')
		await expect(opened).toContainText('Z-2026-0001')
		await expect(opened).toContainText('Parkeervergunning')
		await expect(opened.locator('input, textarea, button')).toHaveCount(0)
	})

	test('An invitation is accepted once', async ({ page }) => {
		let accepted = 0
		await page.route('**/portal/api/identity/invitation/accept', (route) => {
			accepted += 1
			return accepted === 1
				? route.fulfill({ status: 200, json: { status: 'pending' } })
				: route.fulfill({
						status: 403,
						json: { error: 'invitation_not_valid' },
					})
		})

		await page.goto(`${site('e2e-ways-in', '/')}#invitation=mailed-secret`)
		await page.getByTestId('way-in-accept').click()
		await expect(page.getByTestId('way-in-link-result')).toContainText(
			/Your account is ready|Uw account is klaar/,
		)

		await page.goto(`${site('e2e-ways-in', '/')}#invitation=mailed-secret`)
		await page.getByTestId('way-in-accept').click()
		await expect(page.getByTestId('way-in-link-result')).toContainText(
			/This invitation is no longer valid|Deze uitnodiging is niet meer geldig/,
		)
	})
})
