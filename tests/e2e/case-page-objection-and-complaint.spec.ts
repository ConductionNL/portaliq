/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * case-page-objection-and-complaint: a resident files a bezwaar from the case
 * page. The button sits in "More actions for this case", the form does not
 * ask which case, the server fills the case in, and the receipt shows on the
 * case page. Past the window the button is replaced by the sentence.
 *
 * Written with the change; it needs a running instance (tests/e2e/ci-seed.sh).
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
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
 * A contribution whose bezwaar action sits on the case page, and one case of
 * a signed-in resident whose decision was sent on `decisionSentAt`.
 *
 * @param request The request fixture.
 * @param page The page.
 * @param decisionSentAt The case's decision date, `Y-m-d`.
 * @return The case id.
 */
async function openCase(
	request: APIRequestContext,
	page: Page,
	decisionSentAt: string,
): Promise<string> {
	const caseType = await seed(request, 'portalCaseType', {
		title: `E2E zaaktype ${Date.now()}`,
		portalWritable: [],
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
				id: 'createBezwaar',
				type: 'create',
				label: 'Bezwaar maken',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
				fields: ['omschrijving', 'againstCaseId'],
				crossRefs: {
					againstCaseId: {
						register: 'portaliq',
						schema: 'portalCase',
						scopeField: 'subjectRef',
					},
				},
				onCase: {
					crossRef: 'againstCaseId',
					order: 10,
					window: { from: 'decisionSentAt', days: 42 },
					closedText: 'De termijn voor bezwaar is voorbij.',
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
	const caseId = await seed(request, 'portalCase', {
		subjectRef,
		organisation: ORGANISATION,
		reference: `ZAAK-${Date.now()}`,
		caseType,
		status: 'besloten',
		omschrijving: 'Aanvraag kapvergunning',
		decisionSentAt,
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
	await accountLink(page, 'portaliq/mijn-zaken').first().click()
	await page.getByTestId('collection-table-select').first().click()
	await expect(page.getByTestId('citizen-case')).toBeVisible()
	return caseId
}

/**
 * A day relative to today, as `Y-m-d`.
 *
 * @param offset Days from today, negative in the past.
 * @return The day.
 */
function day(offset: number): string {
	const date = new Date()
	date.setDate(date.getDate() + offset)
	return date.toISOString().slice(0, 10)
}

test.describe('case-page-objection-and-complaint', () => {
	// @e2e citizen-case-objection-and-complaint::a-resident-sees-bezwaar-maken-on-a-decided-case
	// @e2e citizen-case-objection-and-complaint::the-case-is-filled-in
	// @e2e citizen-case-objection-and-complaint::a-klacht-is-received
	test('a resident files a bezwaar from the case page and reads the receipt', async ({
		page,
		request,
	}) => {
		const caseId = await openCase(request, page, day(-10))
		const group = page.getByRole('group', { name: 'Meer acties voor deze zaak' })
		await expect(group).toBeVisible()
		await group.getByRole('button', { name: 'Bezwaar maken' }).click()

		const form = page.getByTestId('case-action-form-createBezwaar')
		await expect(form.locator('h3')).toBeFocused()
		await expect(form.locator('[name="againstCaseId"]')).toHaveCount(0)

		const sent: string[] = []
		page.on('request', (r) => {
			if (r.method() === 'POST' && r.url().includes('onCase=')) {
				sent.push(r.url())
			}
		})
		await form
			.locator('textarea, input[type="text"]')
			.first()
			.fill('Het besluit is onjuist.')
		await form.getByRole('button', { name: /verstuur|versturen|send/i }).click()

		await expect(page.getByTestId('case-action-receipt')).toContainText(
			'Mijn zaken',
		)
		expect(sent).toHaveLength(1)
		expect(decodeURIComponent(sent[0])).toContain(
			`onCase=portaliq/portalCase/${caseId}`,
		)
	})

	// @e2e citizen-case-objection-and-complaint::the-six-weeks-have-passed
	test('past the window the button is replaced by the sentence', async ({
		page,
		request,
	}) => {
		await openCase(request, page, day(-60))
		await expect(
			page.getByRole('button', { name: 'Bezwaar maken' }),
		).toHaveCount(0)
		await expect(page.getByTestId('case-actions')).toContainText(
			'De termijn voor bezwaar is voorbij.',
		)
	})
})
