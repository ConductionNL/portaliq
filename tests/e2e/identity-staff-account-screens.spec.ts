/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * identity-staff-account-screens: a clerk issues an account at the desk and
 * is refused a duplicate, invites an address and withdraws the invitation,
 * sees "Withdraw this account" only on a pending account, and approves a
 * self-registration from the portal's Registration widget.
 *
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
 * @e2e openspec/specs/portal-account-administration/spec.md
 */

import { expect, test } from '@playwright/test'

const ADMIN = Buffer.from('admin:admin').toString('base64')

const STAFF_HEADERS = {
	Authorization: `Basic ${ADMIN}`,
	'OCS-APIRequest': 'true',
	requesttoken: 'x',
}

test.describe('identity-staff-account-screens', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/login')
		await page.fill('#user', 'admin')
		await page.fill('#password', 'admin')
		await page.click('button[type=submit]')
	})

	test('A clerk issues an account at the desk, and a duplicate identity is refused', async ({ page }) => {
		const ref = `9999${Date.now() % 100000}`
		await page.goto('/apps/portaliq/#/accounts')
		await page.getByRole('button', { name: 'Actions' }).click()
		await page.getByText('Issue an account').click()
		await page.getByTestId('issue-account-organisation').locator('input').fill('dev-org')
		await page.getByTestId('issue-account-identity-ref').locator('input').fill(ref)
		await page.getByTestId('issue-account-confirm').click()
		await expect(page.getByText('The account is issued.', { exact: false })).toBeVisible()

		await page.getByRole('button', { name: 'Actions' }).click()
		await page.getByText('Issue an account').click()
		await page.getByTestId('issue-account-organisation').locator('input').fill('dev-org')
		await page.getByTestId('issue-account-identity-ref').locator('input').fill(ref)
		await page.getByTestId('issue-account-confirm').click()
		await expect(page.getByTestId('issue-account-refusal')).toHaveText(
			'An account for this identity already exists, so no second account was made.',
		)
	})

	test('A withdrawn invitation stops working', async ({ page, request }) => {
		const email = `piet-${Date.now()}@leverancier.nl`
		const sent = await request.post('/apps/portaliq/api/invitations', {
			headers: STAFF_HEADERS,
			data: { email, organisation: 'dev-org', audience: 'client' },
		})
		expect(sent.ok(), 'the instance needs a working mail transport').toBeTruthy()
		expect(JSON.stringify(await sent.json())).not.toContain('token')

		await page.goto('/apps/portaliq/#/invitations')
		const row = page.getByRole('row', { name: new RegExp(email) })
		await expect(row).toContainText('sent')
		await row.getByRole('button', { name: 'Actions' }).click()
		await page.getByText('Withdraw invitation').click()
		await page.getByRole('button', { name: 'Withdraw invitation' }).click()
		await expect(page.getByRole('row', { name: new RegExp(email) })).toContainText('revoked')
	})

	test('Withdraw this account shows on a pending account only', async ({ page, request }) => {
		const provisioned = await request.post('/apps/portaliq/api/accounts/provision', {
			headers: STAFF_HEADERS,
			data: { audience: 'client', organisation: 'dev-org', email: `desk-${Date.now()}@example.org`, verifiedEmail: true },
		})
		const { subjectRef } = await provisioned.json()
		const found = await request.get(`/apps/openregister/api/objects/portaliq/portalAccount?subjectRef=${subjectRef}`, { headers: STAFF_HEADERS })
		const id = (await found.json()).results[0].id

		await page.goto(`/apps/portaliq/#/accounts/${id}`)
		await page.getByTestId('portal-account-withdraw-button').click()
		await page.getByTestId('void-account-reason').locator('textarea').fill('Wrong address')
		await page.getByTestId('void-account-confirm').click()
		await expect(page.getByTestId('portal-account-withdraw-button')).toHaveCount(0)
	})

	test('A registration is approved', async ({ page, request }) => {
		const email = `new-${Date.now()}@example.org`
		const portals = await request.get('/apps/openregister/api/objects/portaliq/portal?organisation=dev-org', { headers: STAFF_HEADERS })
		const portal = (await portals.json()).results[0]

		await page.goto(`/apps/portaliq/#/portals/${portal.id}`)
		await page.getByTestId('portal-registration-approval').click()
		await page.getByTestId('portal-registration-save').click()
		await expect(page.getByText('Your choices are saved.')).toBeVisible()

		const registered = await request.post(`/apps/portaliq/portal/api/identity/register?portal=${portal.slug}`, {
			data: { email, displayName: 'Nieuwe inwoner' },
		})
		expect(registered.ok(), 'registration needs the challenge off on this portal').toBeTruthy()

		await page.reload()
		const row = page.getByTestId('portal-registration-waiting').getByText(email)
		await expect(row).toBeVisible()
		await page.getByRole('button', { name: 'Approve' }).first().click()
		await expect(page.getByText('The registration is approved.')).toBeVisible()
		await expect(page.getByTestId('portal-registration-waiting').getByText(email)).toHaveCount(0)
	})
})
