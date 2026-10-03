/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-mijn-case-cards: Mijn zaken and a `cases` block show cases as Den Haag
 * case cards, and a list whose read fails says so instead of reading as
 * empty (site-mijn-omgeving-components wave 3). The failed read is forced
 * with a routed 502, the answer seen live on /portal/api/tasks.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
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

/**
 * A cases collection with an overview page holding a `cases` block, the given
 * cases for a fresh resident, and that resident signed in on the site.
 *
 * @param request The request fixture.
 * @param page The page.
 * @param cases The cases.
 * @return The overview page's menu label.
 */
async function signIn(
	request: APIRequestContext,
	page: Page,
	cases: Array<Record<string, unknown>>,
): Promise<string> {
	const stamp = Date.now()
	const label = `Overzicht ${stamp}`
	await seed(request, 'portalPage', {
		label,
		audience: 'client',
		status: 'active',
		collections: [
			{
				id: `zaken-${stamp}`,
				kind: 'cases',
				label: 'Zaken',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
				closedField: 'withdrawnAt',
			},
		],
		pages: [
			{
				id: `overzicht-${stamp}`,
				label,
				blocks: [
					{
						type: 'cases',
						collection: `zaken-${stamp}`,
						open: true,
						label: 'Lopende zaken',
					},
				],
			},
		],
	})
	const subjectRef = `subject-cards-${stamp}`
	for (const row of cases) {
		await seed(request, 'portalCase', {
			subjectRef,
			organisation: ORGANISATION,
			...row,
		})
	}
	const login = await request.post(`${PORTAL_API}/session/dev-login`, {
		data: { subjectRef, audience: 'client', organisation: ORGANISATION },
	})
	expect(
		login.ok(),
		'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
	).toBeTruthy()
	const { token } = await login.json()
	await seedSiteSession(page, token)
	return label
}

test.describe('site-mijn-case-cards', () => {
	// @e2e site-mijn-omgeving::a-case-without-progress-data
	test('a cases block shows running cases as cards, without an empty progress bar', async ({
		page,
		request,
	}) => {
		const label = await signIn(request, page, [
			{ reference: 'Kapvergunning Lindelaan' },
			{ reference: 'Oude dakkapel', withdrawnAt: '2026-03-01T10:00:00+00:00' },
		])
		await page.goto(siteAddress())
		await page
			.getByTestId('site-resident-menu-link')
			.filter({ hasText: label })
			.first()
			.click()
		const block = page.getByTestId('mijn-cases-block')
		await expect(block.getByTestId('mijn-case-card')).toHaveCount(1)
		await expect(block).toContainText('Kapvergunning Lindelaan')
		await expect(block).not.toContainText('Oude dakkapel')
		await expect(block.locator('.pq-case-card__bar')).toHaveCount(0)
	})

	test('Mijn zaken lists its cases as case cards', async ({ page, request }) => {
		await signIn(request, page, [{ reference: 'Kapvergunning Lindelaan' }])
		await page.goto(siteAddress('/mijn/cases'))
		const row = page.getByTestId('my-cases-row')
		await expect(row).toHaveCount(1)
		await expect(row).toHaveClass(/pq-case-card-item/)
		await expect(row).toContainText('Kapvergunning Lindelaan')
	})

	// A failed list (REQ-SMO-009), as seen live: no spec scenario names it.
	test('My tasks: a 502 reads as an error with a retry, not as no open tasks', async ({
		page,
		request,
	}) => {
		await signIn(request, page, [])
		let answered = 0
		await page.route('**/portal/api/tasks', async (route) => {
			answered++
			await route.fulfill({
				status: 502,
				contentType: 'application/json',
				body: JSON.stringify({ code: 'task-service-unreachable' }),
			})
		})
		await page.goto(siteAddress('/mijn/tasks'))
		const alert = page.getByTestId('mijn-load-error')
		await expect(alert).toBeVisible()
		await expect(alert).toHaveAttribute('role', 'alert')
		await expect(
			page.getByText(/U heeft geen open taken|No open tasks/),
		).toHaveCount(0)
		const before = answered
		await page.getByTestId('mijn-load-error-retry').click()
		await expect.poll(() => answered).toBeGreaterThan(before)
	})
})
