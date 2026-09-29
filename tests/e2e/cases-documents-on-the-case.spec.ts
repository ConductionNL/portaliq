/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * cases-documents-on-the-case: the case screen lists what the resident sent,
 * opens it, and says so when a case has no documents. What a case app
 * publishes needs a case app with a `documents` method, which portaliq's own
 * seed has none of; that path is pinned by CitizenCaseControllerTest.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const PORTAL_PATH = '/apps/portaliq/portal?org=dev-org'
const ADMIN = Buffer.from('admin:admin').toString('base64')

/**
 * Create one portaliq object as the dev admin.
 *
 * @param request The request fixture.
 * @param schema The schema.
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
 * A resident with one case whose document window is open, signed in, on the case screen.
 *
 * @param request The request fixture.
 * @param page The page.
 * @return Nothing.
 */
async function openCase(request: APIRequestContext, page: Page): Promise<void> {
	const caseType = await seed(request, 'portalCaseType', {
		title: `E2E zaaktype ${Date.now()}`,
		portalDocumentWindow: {
			openStatuses: ['ontvangen'],
			closedReason: 'Geen stukken meer.',
		},
	})
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
				fields: [],
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
		organisation: 'dev-org',
		reference: `ZAAK-${Date.now()}`,
		caseType,
		status: 'ontvangen',
	})
	const login = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'client', organisation: 'dev-org' },
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
	await page.getByText('Mijn zaken', { exact: true }).first().click()
	await page.locator('.portaliq-row-clickable').first().click()
	await expect(page.getByTestId('citizen-case')).toBeVisible()
}

test.describe('cases-documents-on-the-case', () => {
	// @e2e citizen-case-documents::an-empty-case
	// @e2e citizen-case-documents::the-resident-finds-their-own-upload
	test('an empty case says so, and a sent document is listed under "Sent by you" and opens', async ({
		page,
		request,
	}) => {
		await openCase(request, page)
		await expect(page.getByTestId('case-documents-empty')).toBeVisible()

		await page.getByTestId('case-add-document').setInputFiles({
			name: 'foto-schade.jpg',
			mimeType: 'image/jpeg',
			buffer: Buffer.from('jpeg'),
		})
		await expect(page.getByTestId('case-notice')).toBeVisible()
		const sent = page.locator('.portaliq-case-documents-yours')
		await expect(sent).toContainText('foto-schade.jpg')

		const download = page.waitForEvent('download')
		await sent.getByRole('button', { name: 'foto-schade.jpg' }).click()
		expect((await download).suggestedFilename()).toBe('foto-schade.jpg')
	})
})
