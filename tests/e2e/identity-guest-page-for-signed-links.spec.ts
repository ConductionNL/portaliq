/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * identity-guest-page-for-signed-links: a signed link opens the site's guest
 * page for its one act. The contributing app's preview and act are answered
 * through page.route with the shapes GuestActionControllerTest pins, because
 * no fixture app on the CI instance declares a guest action yet.
 *
 * Needs: a published portal `e2e-guest` whose site is reachable at
 * /apps/portaliq/site?portal=e2e-guest.
 *
 * @spec openspec/changes/identity-guest-page-for-signed-links/specs/portal-guest-actions/spec.md#requirement-the-page-shows-the-apps-answer-req-gst-004
 * @e2e openspec/changes/identity-guest-page-for-signed-links/specs/portal-guest-actions/spec.md
 */

import { expect, test } from '@playwright/test'

const SITE = '/apps/portaliq/site?portal=e2e-guest'
const LINK = '#guest/shillinq/pay-invoice/signed-token-1'

test.describe('identity-guest-page-for-signed-links', () => {
	test('A customer pays from the invoice mail', async ({ page }) => {
		await page.route(
			'**/portal/api/guest/shillinq/pay-invoice/preview',
			(route) =>
				route.fulfill({
					status: 200,
					json: {
						preview: {
							available: true,
							summary: 'Invoice 2026-0042, 120 euro',
						},
						action: {
							label: 'Pay now',
							confirmText: 'You will go to the payment page.',
							fields: [],
						},
					},
				}),
		)
		await page.route('**/portal/api/guest/shillinq/pay-invoice', (route) =>
			route.fulfill({
				status: 200,
				json: { message: 'Taking you to the payment page' },
			}),
		)

		await page.goto(`${SITE}${LINK}`)
		await expect(page.getByTestId('guest-summary')).toContainText(
			'Invoice 2026-0042',
		)
		await expect(page).not.toHaveURL(/#guest\//)
		await page.getByTestId('guest-act').click()
		await page.getByTestId('guest-confirm-yes').click()
		await expect(page.getByTestId('guest-outcome')).toContainText(
			'Taking you to the payment page',
		)
	})

	test('A booking that can no longer be withdrawn', async ({ page }) => {
		await page.route('**/portal/api/guest/**/preview', (route) =>
			route.fulfill({
				status: 200,
				json: {
					preview: {
						available: false,
						reason: 'This booking has already started.',
					},
					action: {},
				},
			}),
		)

		await page.goto(`${SITE}#guest/bookings/withdraw/signed-token-2`)
		await expect(page.getByTestId('guest-reason')).toContainText(
			'already started',
		)
		await expect(page.getByTestId('guest-act')).toHaveCount(0)
	})

	test('A probe for an unknown action', async ({ page }) => {
		// The real routes: an unknown action answers the same 404 for the
		// preview and the act, so the page offers a plain button and then
		// refuses, naming nothing.
		await page.goto(`${SITE}#guest/nothing/here/not-a-token`)
		await page.getByTestId('guest-act').click()
		await page.getByTestId('guest-confirm-yes').click()
		await expect(page.getByTestId('guest-outcome')).toContainText(
			/This link cannot be used\.|Deze link kan niet worden gebruikt\./,
		)
	})
})
