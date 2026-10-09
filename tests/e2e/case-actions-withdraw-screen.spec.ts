/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * case-actions-withdraw-screen: a resident withdraws their own request from
 * the portal case screen, by pressing the button, not through the API. The
 * API-level spec (withdrawing-your-own-case-from-the-portal.spec.ts) stays.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { SeededPortalPages } from './lib/seeded-portal-pages.ts'
import {
	accountLink,
	PORTAL_API,
	seedSiteSession,
	siteAddress,
} from './portal-nav.ts'

const API_BASE = PORTAL_API

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from('admin:admin').toString('base64')
const ORGANISATION = 'dev-org'

/** The portalPage rows this file seeded; see lib/seeded-portal-pages.ts. */
const seededPages = new SeededPortalPages()

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
	seededPages.track(schema, body)
	return (body.id ?? body['@self']?.id) as string
}

/**
 * A case type, a contribution showing the cases with the case screen, and one
 * case of that type for a signed-in resident.
 *
 * @param request The request fixture.
 * @param page The page.
 * @param withdrawal The case type's portalWithdrawal, or null.
 * @param status The case's status.
 * @return Nothing.
 */
async function openCase(
	request: APIRequestContext,
	page: Page,
	withdrawal: Record<string, unknown> | null,
	status: string,
): Promise<void> {
	const type: Record<string, unknown> = {
		title: `E2E zaaktype ${Date.now()}`,
		portalWritable: [{ field: 'omschrijving', audiences: ['client'] }],
		portalAmendmentWindow: {
			openStatuses: ['ontvangen'],
			closedReason: 'In behandeling.',
		},
	}
	if (withdrawal !== null) {
		type.portalWithdrawal = withdrawal
	}
	const caseType = await seed(request, 'portalCaseType', type)
	await seed(request, 'portalPage', {
		label: 'Mijn zaken',
		audience: 'client',
		status: 'active',
		collections: [
			{
				id: 'cases',
				kind: 'cases',
				label: 'Mijn zaken',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
			},
		],
		actions: [
			{
				id: 'amend-case',
				type: 'update',
				label: 'Mijn aanvraag wijzigen',
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
				id: 'mijn-zaken',
				label: 'Mijn zaken',
				blocks: [
					{ type: 'collection', collection: 'cases' },
					{ type: 'citizenCase', collection: 'cases' },
				],
			},
		],
	})
	const subjectRef = `subject-${Date.now()}-${Math.floor(Math.random() * 10000)}`
	await seed(request, 'portalCase', {
		subjectRef,
		organisation: ORGANISATION,
		reference: `ZAAK-${Date.now()}`,
		caseType,
		status,
		omschrijving: 'Aanvraag verhuizing',
	})
	const login = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'client', organisation: ORGANISATION },
	})
	expect(
		login.ok(),
		'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
	).toBeTruthy()
	const { token } = await login.json()
	await seedSiteSession(page, token)
	await page.goto(siteAddress())
	// The seeded "Mijn zaken" page, not the shell's own "Mijn zaken" section.
	await accountLink(page, 'portaliq/mijn-zaken').first().click()
	await page.getByTestId('collection-table-select').first().click()
	await expect(page.getByTestId('citizen-case')).toBeVisible()
}

const OPEN = {
	openStatuses: ['ontvangen'],
	closedReason: 'Uw aanvraag is al beoordeeld.',
	targetStatus: 'ingetrokken',
}

test.afterEach(async ({ request }) => {
	await seededPages.removeAll(request)
})

test.describe('case-actions-withdraw-screen', () => {
	// @e2e citizen-case-withdraw-screen::a-resident-sees-the-button
	// @e2e citizen-case-withdraw-screen::a-resident-withdraws-with-a-reason
	// @e2e citizen-case-withdraw-screen::the-withdrawn-request-after-a-reload
	test('a resident withdraws with a reason and reads the withdrawn request after a reload', async ({
		page,
		request,
	}) => {
		await openCase(request, page, OPEN, 'ontvangen')
		await page.getByTestId('case-withdraw').click()
		await expect(
			page.getByTestId('case-withdraw-confirm').locator('h4'),
		).toBeFocused()
		await page
			.getByTestId('case-withdraw-reason')
			.fill('Ik ben toch niet verhuisd.')
		await page.getByTestId('case-withdraw-submit').click()
		await expect(page.getByTestId('case-notice')).toBeVisible()

		await page.reload()
		await page.getByTestId('collection-table-select').first().click()
		const withdrawn = page.getByTestId('case-withdrawn')
		await expect(withdrawn).toContainText('Ik ben toch niet verhuisd.')
		await expect(page.getByTestId('case-withdraw')).toHaveCount(0)
	})

	// @e2e citizen-case-withdraw-screen::cancelling-changes-nothing
	test('cancelling sends nothing and the request is still running after a reload', async ({
		page,
		request,
	}) => {
		await openCase(request, page, OPEN, 'ontvangen')
		const sent: string[] = []
		page.on('request', (r) => {
			if (r.url().includes('/withdraw')) {
				sent.push(r.url())
			}
		})
		await page.getByTestId('case-withdraw').click()
		await page.getByTestId('case-withdraw-cancel').click()
		expect(sent).toEqual([])
		await page.reload()
		await page.getByTestId('collection-table-select').first().click()
		await expect(page.getByTestId('case-withdraw')).toBeVisible()
	})

	// @e2e citizen-case-withdraw-screen::a-type-without-withdrawal-shows-nothing
	test('a type without withdrawal shows nothing', async ({ page, request }) => {
		await openCase(request, page, null, 'ontvangen')
		await expect(page.getByTestId('case-withdraw')).toHaveCount(0)
		await expect(page.getByTestId('case-withdraw-closed')).toHaveCount(0)
	})

	// @e2e citizen-case-withdraw-screen::a-decided-request-says-why
	test('a decided request says why and offers no button', async ({
		page,
		request,
	}) => {
		await openCase(request, page, OPEN, 'besloten')
		await expect(page.getByTestId('case-withdraw-closed')).toHaveText(
			'Uw aanvraag is al beoordeeld.',
		)
		await expect(page.getByTestId('case-withdraw')).toHaveCount(0)
	})
})
