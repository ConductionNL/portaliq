/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * case-outcome-on-the-case-page: a resident opens a case that has ended and
 * reads its outcome with the organisation's explanation above the status
 * steps (REQ-COC-001). The rendering rules (a reference is never an outcome,
 * a running case shows none) are pinned by tests/case-outcome.spec.mjs; this
 * spec walks the whole path: collection declaration, case screen projection
 * and the page.
 *
 * The browser context is a fresh one with an empty storage state: the
 * resident signs in through the portal's dev login, never as a Nextcloud
 * admin. The target comes from BASE_URL (see tests/e2e/base-url.ts).
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	accountLink,
	PORTAL_API,
	seedSiteSession,
	siteAddress,
} from './portal-nav.ts'

test.use({ storageState: { cookies: [], origins: [] } })

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from('admin:admin').toString('base64')
const ORGANISATION = 'dev-org'

const LABEL = 'Vergunning verleend'
const EXPLANATION = 'Uw aanvraag voldoet aan het bestemmingsplan.'

/**
 * What the current test seeded, removed after it. The portal reads ONE active
 * portalPage per audience and takes the lowest id when several are active, so
 * a page left behind can shadow the page the next test (or spec) seeds.
 */
const created: string[] = []

/**
 * Create one object through OpenRegister's own object API, as the dev admin
 * (seeding only; the browser never holds these credentials).
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
	const id = (body.id ?? body['@self']?.id) as string
	created.push(`${schema}/${id}`)
	return id
}

/**
 * A contribution whose cases collection declares its outcome fields, and one
 * case of a signed-in resident, opened on the case screen.
 *
 * @param request The request fixture.
 * @param page The page.
 * @param closedAt The case's closed marker, '' for a running case.
 * @return Nothing.
 */
async function openCase(
	request: APIRequestContext,
	page: Page,
	closedAt: string,
): Promise<void> {
	const caseType = await seed(request, 'portalCaseType', {
		title: `E2E zaaktype uitkomst ${Date.now()}`,
		portalWritable: [{ field: 'omschrijving', audiences: ['client'] }],
		portalAmendmentWindow: {
			openStatuses: ['ontvangen'],
			closedReason: 'In behandeling.',
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
				closedField: 'closedAt',
				fields: [
					'reference',
					'status',
					'omschrijving',
					'closedAt',
					'resultPublicLabel',
					'resultPublicDescription',
				],
				outcome: {
					labelField: 'resultPublicLabel',
					descriptionField: 'resultPublicDescription',
				},
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
	const row: Record<string, unknown> = {
		subjectRef,
		organisation: ORGANISATION,
		reference: `ZAAK-${Date.now()}`,
		caseType,
		status: closedAt === '' ? 'ontvangen' : 'afgehandeld',
		omschrijving: 'Aanvraag omgevingsvergunning',
		resultPublicLabel: LABEL,
		resultPublicDescription: EXPLANATION,
	}
	if (closedAt !== '') {
		row.closedAt = closedAt
	}
	await seed(request, 'portalCase', row)
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
	// The seeded "Mijn zaken" page, not the shell's own "Mijn zaken" section.
	await accountLink(page, 'portaliq/mijn-zaken').first().click()
	await page.getByTestId('collection-table-select').first().click()
	await expect(page.getByTestId('citizen-case')).toBeVisible()
}

test.describe('case-outcome-on-the-case-page', () => {
	test.afterEach(async ({ request }) => {
		for (const ref of created.splice(0).reverse()) {
			await request.delete(`${OR_OBJECTS_BASE}/portaliq/${ref}`, {
				headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
			})
		}
	})

	// @e2e citizen-writes-on-their-own-case::a-granted-permit
	test('an ended case shows its outcome and the explanation above the status steps', async ({
		page,
		request,
	}) => {
		await openCase(request, page, '2026-10-20T09:00:00+00:00')

		const outcome = page.getByTestId('case-outcome')
		await expect(outcome).toBeVisible()
		await expect(outcome.getByRole('heading')).toHaveText(/Uitkomst|Outcome/)
		await expect(page.getByTestId('case-outcome-label')).toHaveText(LABEL)
		await expect(page.getByTestId('case-outcome-description')).toHaveText(
			EXPLANATION,
		)

		// Above the status steps, when the screen shows them.
		const status = page.locator('.pq-case-status')
		if ((await status.count()) > 0) {
			const above = await outcome.evaluate(
				(node, other) =>
					Boolean(
						other
						&& node.compareDocumentPosition(other)
							& Node.DOCUMENT_POSITION_FOLLOWING,
					),
				await status.first().elementHandle(),
			)
			expect(above, 'the outcome comes before the status steps').toBe(true)
		}
	})

	// Guard for the same path: the running case carries the same fields but
	// has not ended, so the page shows no outcome yet.
	test('a running case with the same fields shows no outcome', async ({
		page,
		request,
	}) => {
		await openCase(request, page, '')
		await expect(page.getByTestId('case-outcome')).toHaveCount(0)
	})
})
