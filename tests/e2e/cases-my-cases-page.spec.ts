/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * cases-my-cases-page: a signed-in resident sees every case in one list on
 * "My cases", open and closed on their own tabs, and opens a case on the
 * page it lives on. Seeds through OpenRegister's own object API, as the
 * withdraw-screen spec does.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const PORTAL_PATH = '/apps/portaliq/portal?org=dev-org'
const ADMIN = Buffer.from('admin:admin').toString('base64')
const ORGANISATION = 'dev-org'

/**
 * Create one object through OpenRegister's own object API, as the dev admin.
 *
 * @param request The request fixture.
 * @param schema The portaliq schema.
 * @param data The object.
 * @return The id.
 */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/${schema}`, {
		headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
		data,
	})
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for ${schema}`,
	).toBeTruthy()
	const body = await res.json()
	return (body.id ?? body['@self']?.id) as string
}

/**
 * Two case collections (one of them marking closed cases by `withdrawnAt`, a field the
 * portalCase schema has), a
 * page showing the first, the given cases for a fresh resident, and that
 * resident signed in on the portal.
 *
 * @param request The request fixture.
 * @param page The page.
 * @param cases The cases, each with the collection it belongs to.
 * @return Nothing.
 */
async function signIn(
	request: APIRequestContext,
	page: Page,
	cases: Array<Record<string, unknown>>,
): Promise<void> {
	const stamp = Date.now()
	await seed(request, 'portalPage', {
		label: 'Mijn vergunningen',
		audience: 'client',
		status: 'active',
		collections: [
			{
				id: `vergunningen-${stamp}`,
				kind: 'cases',
				label: 'Vergunningen',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
				closedField: 'withdrawnAt',
				filter: { omschrijving: 'vergunning' },
			},
			{
				id: `meldingen-${stamp}`,
				kind: 'cases',
				label: 'Meldingen',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
				filter: { omschrijving: 'melding' },
			},
		],
		pages: [
			{
				id: 'mijn-vergunningen',
				label: 'Mijn vergunningen',
				blocks: [
					{ type: 'collection', collection: `vergunningen-${stamp}` },
					{ type: 'detail', collection: `vergunningen-${stamp}` },
				],
			},
		],
	})
	const subjectRef = `subject-${stamp}-${Math.floor(Math.random() * 10000)}`
	for (const row of cases) {
		await seed(request, 'portalCase', {
			subjectRef,
			organisation: ORGANISATION,
			...row,
		})
	}
	const login = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'client', organisation: ORGANISATION },
	})
	expect(
		login.ok(),
		'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
	).toBeTruthy()
	const { token } = await login.json()
	await page.addInitScript((t) => {
		window.localStorage.setItem('portaliq_token', t)
	}, token)
	await page.goto(PORTAL_PATH)
	await expect(page.getByTestId('my-cases')).toBeVisible()
}

test.describe('cases-my-cases-page', () => {
	// @e2e portal-my-cases::cases-from-two-apps-in-one-list
	// @e2e portal-my-cases::a-decided-case-moves-to-closed
	test('every case in one list, newest first, and a decided case under Closed', async ({
		page,
		request,
	}) => {
		await signIn(request, page, [
			{
				reference: 'Kapvergunning',
				omschrijving: 'vergunning',
			},
			{
				reference: 'Losse stoeptegel',
				omschrijving: 'melding',
			},
			{
				reference: 'Oude dakkapel',
				omschrijving: 'vergunning',
				withdrawnAt: '2026-03-01T10:00:00+00:00',
			},
		])
		const rows = page.getByTestId('my-cases-row')
		await expect(rows).toHaveCount(2)
		await expect(rows.nth(0)).toContainText('Losse stoeptegel')
		await expect(rows.nth(1)).toContainText('Kapvergunning')
		await expect(page.getByTestId('my-cases-tab-open')).toContainText('(2)')

		await page.getByTestId('my-cases-tab-closed').click()
		await expect(page.getByTestId('my-cases-row')).toHaveCount(1)
		await expect(page.getByTestId('my-cases-row')).toContainText('Oude dakkapel')
	})

	// @e2e portal-my-cases::nothing-to-show
	test('a resident without cases reads "No cases yet."', async ({
		page,
		request,
	}) => {
		await signIn(request, page, [])
		await expect(page.getByTestId('my-cases-empty')).toBeVisible()
	})

	// @e2e portal-my-cases::opening-a-case-from-the-list
	test('a case opens on the page it lives on', async ({ page, request }) => {
		await signIn(request, page, [
			{ reference: 'Kapvergunning', omschrijving: 'vergunning' },
		])
		await page.getByRole('button', { name: 'Kapvergunning' }).click()
		await expect(page.getByTestId('my-cases')).toHaveCount(0)
		await expect(page.getByText('Kapvergunning').first()).toBeVisible()
	})
})
