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
import { PORTAL_API, seedSiteSession, siteAddress } from './portal-nav.ts'

const API_BASE = PORTAL_API

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
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
 * resident signed in on the site, on My cases.
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
	await seedSiteSession(page, token)
	await page.goto(siteAddress('/mijn/cases'))
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

/**
 * A company case collection read by a party field, one case of the company,
 * a colleague's own case, and a signed-in employee who holds (or not) a
 * mandate for the company. portalCase has no company field, so the fixture
 * lets `toelichting` carry the company number as the party field.
 *
 * @param request The request fixture.
 * @param page The page.
 * @param withMandate Whether the employee holds the mandate.
 * @return Nothing.
 */
async function signInAsEmployee(
	request: APIRequestContext,
	page: Page,
	withMandate: boolean,
): Promise<void> {
	const stamp = Date.now()
	const company = `kvk-${stamp}`
	await seed(request, 'portalPage', {
		label: 'Bedrijfszaken',
		audience: 'client',
		status: 'active',
		collections: [
			{
				id: `bedrijf-${stamp}`,
				kind: 'cases',
				label: 'Bedrijfszaken',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
				mandateField: 'toelichting',
			},
		],
		actions: [
			{
				id: 'amend-case',
				type: 'update',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
				fields: ['omschrijving'],
				citizenWrite: {
					typeField: 'caseType',
					typeRegister: 'portaliq',
					typeSchema: 'portalCaseType',
					statusField: 'status',
					recordField: 'portalWrites',
				},
			},
		],
		pages: [
			{
				id: 'bedrijfszaken',
				label: 'Bedrijfszaken',
				blocks: [
					{ type: 'collection', collection: `bedrijf-${stamp}` },
					{ type: 'citizenCase', collection: `bedrijf-${stamp}` },
				],
			},
		],
	})
	const employee = `employee-${stamp}-${Math.floor(Math.random() * 10000)}`
	await seed(request, 'portalCase', {
		subjectRef: `founder-${stamp}`,
		organisation: ORGANISATION,
		reference: 'Terrasvergunning',
		toelichting: company,
		status: 'ontvangen',
	})
	if (withMandate) {
		await seed(request, 'portalMandate', {
			subjectRef: employee,
			organisation: ORGANISATION,
			onBehalfOf: company,
			label: 'Bakkerij Jansen BV',
			status: 'active',
		})
	}
	const login = await request.post(`${API_BASE}/session/dev-login`, {
		data: {
			subjectRef: employee,
			audience: 'client',
			organisation: ORGANISATION,
		},
	})
	expect(login.ok(), 'dev-login must be enabled').toBeTruthy()
	const { token } = await login.json()
	await seedSiteSession(page, token)
	await page.goto(siteAddress('/mijn/cases'))
	await expect(page.getByTestId('my-cases')).toBeVisible()
}

test.describe('cases-my-cases-page acting for', () => {
	// @e2e portal-my-cases::switching-to-a-mandate
	// @e2e portal-my-cases::a-mandated-case-carries-its-label
	// @e2e portal-my-cases::a-mandated-case-is-read-not-changed
	test('an employee acts for the company, sees its case with the label, and reads it without changing it', async ({
		page,
		request,
	}) => {
		await signInAsEmployee(request, page, true)
		await expect(page.getByTestId('my-cases-empty')).toBeVisible()

		await page.getByLabel('Namens').selectOption({ label: 'Bakkerij Jansen BV' })
		const row = page.getByTestId('my-cases-row')
		await expect(row).toContainText('Terrasvergunning')
		await expect(page.getByTestId('my-cases-mandate')).toHaveText(
			'Bakkerij Jansen BV',
		)

		await page.reload()
		await expect(page.getByLabel('Namens')).toHaveValue(/.+/)
		await expect(page.getByTestId('my-cases-row')).toContainText(
			'Terrasvergunning',
		)

		await page.getByRole('button', { name: 'Terrasvergunning' }).click()
		await expect(page.getByTestId('case-window-closed')).toContainText(
			'Bakkerij Jansen BV',
		)
		await expect(page.getByTestId('case-withdraw')).toHaveCount(0)
	})

	// @e2e portal-my-cases::switching-to-a-mandate
	test('a colleague without the mandate sees neither the switcher nor the case', async ({
		page,
		request,
	}) => {
		await signInAsEmployee(request, page, false)
		await expect(page.getByTestId('acting-for')).toHaveCount(0)
		await expect(page.getByTestId('my-cases-empty')).toBeVisible()
	})

	// @e2e portal-my-cases::too-large-to-list
	test('a group past the bound is refused with its sentence', async ({
		page,
		request,
	}) => {
		await page.route('**/portal/api/my-cases?mandate=mandate-big', (route) =>
			route.fulfill({
				status: 409,
				contentType: 'application/json',
				body: JSON.stringify({
					error: 'group_too_large',
					bound: { maxDepth: 4, pageSize: 100 },
				}),
			}),
		)
		await page.addInitScript(() => {
			window.sessionStorage.setItem('portaliq.actingFor', 'mandate-big')
		})
		await signInAsEmployee(request, page, true)
		await expect(page.getByTestId('my-cases-error')).toContainText(
			'Deze organisatie heeft te veel zaken om hier te tonen.',
		)
		await expect(page.getByTestId('my-cases-list')).toHaveCount(0)
	})
})
