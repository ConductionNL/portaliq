/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-mijn-description-list: a selected row's detail card shows its fields
 * as a description list (site-mijn-omgeving-components wave 4, REQ-SMO-005).
 * The documents and timeline blocks need an app's provider, which CI does
 * not have; tests/mijn-documents.spec.mjs covers them in node.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { pageFixture } from './mijn-fixtures.ts'
import { PORTAL_API, seedSiteSession, siteAddress } from './portal-nav.ts'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from('admin:admin').toString('base64')
const ORGANISATION = 'dev-org'

/**
 * Create one object through OpenRegister's own object API, as the dev admin.
 *
 * @param request The request fixture.
 * @param schema The portaliq schema.
 * @param data The object.
 * @return Nothing.
 */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<void> {
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/${schema}`, {
		headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
		data,
	})
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for ${schema}`,
	).toBeTruthy()
}

test.describe('site-mijn-description-list', () => {
	test('a selected case shows its fields as a description list', async ({
		page,
		request,
	}) => {
		const stamp = Date.now()
		const label = `Vergunningen ${stamp}`
		await seed(
			request,
			'portalPage',
			pageFixture('description-list', stamp, 'client'),
		)
		const subjectRef = `subject-dl-${stamp}`
		await seed(request, 'portalCase', {
			subjectRef,
			organisation: ORGANISATION,
			reference: 'Kapvergunning Lindelaan',
		})
		const login = await request.post(`${PORTAL_API}/session/dev-login`, {
			data: { subjectRef, audience: 'client', organisation: ORGANISATION },
		})
		expect(
			login.ok(),
			'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
		).toBeTruthy()
		const { token } = await login.json()
		await seedSiteSession(page, token)
		await page.goto(siteAddress())
		await page
			.getByTestId('site-resident-menu-link')
			.filter({ hasText: label })
			.first()
			.click()
		await page
			.getByRole('button', { name: /Kapvergunning Lindelaan/ })
			.first()
			.click()
		const list = page
			.getByTestId('detail-card')
			.getByTestId('mijn-description-list')
		await expect(list).toBeVisible()
		await expect(list.locator('dt').first()).toBeVisible()
		await expect(list).toContainText('Kapvergunning Lindelaan')
	})
})
