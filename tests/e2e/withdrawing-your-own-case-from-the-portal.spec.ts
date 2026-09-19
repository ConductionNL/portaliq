/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for withdrawing-your-own-case-from-the-portal: an applicant ends
 * their own request from the portal, without phoning the desk, and finds it
 * withdrawn and read-only afterwards.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here:
 *   - a case type that allows it offers the action (REQ-WOC-001)
 *   - a case type that does not allow it offers nothing (REQ-WOC-001)
 *   - a closed window says why (REQ-WOC-001)
 *   - a reason travels with the withdrawal (REQ-WOC-003)
 *   - the withdrawn request is still readable, and no undo is offered
 *     (REQ-WOC-005)
 *
 * NOT anchored here, covered by PHPUnit and named so anyone can check:
 *   - a stranger and a read-only colleague refused, a tampered status ignored,
 *     a second withdrawal refused, and the event raised exactly once:
 *     tests/Unit/Controller/CitizenCaseControllerTest.php
 *     (testSomebodyElsesCaseCannotBeWithdrawn,
 *     testAWithdrawalLandsOnTheStatusTheCaseTypeDeclares,
 *     testAWithdrawalRaisesItsOwnEventOnceWithTheReason) and
 *     tests/Unit/Service/CitizenWithdrawalResolutionTest.php
 *     (testAnAlreadyWithdrawnRequestIsNeverOpenAgain)
 *   - that an internal status change raises nothing: the event is raised only
 *     from this controller path, asserted in the same controller test.
 *
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions. Run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test withdrawing-your-own-case
 *
 * @spec openspec/changes/withdrawing-your-own-case-from-the-portal/specs/withdrawing-your-own-case/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'

const ADMIN = Buffer.from('admin:admin').toString('base64')
const ORGANISATION = 'dev-org'

/** Create one object through OpenRegister's own object API, as the dev admin. */
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
	expect(id).toBeTruthy()
	return id
}

/** A case type, with or without a withdrawal declaration. */
async function seedCaseType(
	request: APIRequestContext,
	withdrawal: Record<string, unknown> | null,
): Promise<string> {
	const data: Record<string, unknown> = {
		title: `E2E zaaktype ${Date.now()}`,
		portalWritable: [{ field: 'omschrijving', audiences: ['client'] }],
		portalAmendmentWindow: {
			openStatuses: ['ontvangen'],
			closedReason: 'In behandeling.',
		},
		portalDocumentWindow: {
			openStatuses: ['ontvangen'],
			closedReason: 'Geen stukken meer.',
		},
	}
	if (withdrawal !== null) {
		data.portalWithdrawal = withdrawal
	}

	return seed(request, 'portalCaseType', data)
}

/** The contribution declaring the citizen write action for that case type. */
async function seedContribution(request: APIRequestContext): Promise<string> {
	return seed(request, 'portalPage', {
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
				blocks: [{ type: 'collection', collection: 'cases' }],
			},
		],
	})
}

/** A citizen with one case of the given type and status. */
async function seedCase(
	request: APIRequestContext,
	caseType: string,
	status: string,
): Promise<{ token: string; caseId: string }> {
	const subjectRef = `subject-${Date.now()}-${Math.floor(Math.random() * 10000)}`
	const caseId = await seed(request, 'portalCase', {
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

	return { token: token as string, caseId }
}

test.describe('withdrawing-your-own-case-from-the-portal', () => {
	test('a request whose type allows it can be withdrawn, with a reason, and stays readable', async ({
		request,
	}) => {
		await seedContribution(request)
		const caseType = await seedCaseType(request, {
			openStatuses: ['ontvangen'],
			closedReason: 'Uw aanvraag is al beoordeeld.',
			targetStatus: 'ingetrokken',
			confirmText: 'Als u intrekt, stopt de behandeling.',
		})
		const { token, caseId } = await seedCase(request, caseType, 'ontvangen')
		const headers = { Authorization: `Bearer ${token}` }
		const casePath = `${API_BASE}/citizen/cases/portaliq/portalCase/${caseId}`

		const before = await request.get(casePath, { headers })
		expect(before.ok()).toBeTruthy()
		const offered = await before.json()
		expect(offered.withdrawal.open).toBe(true)
		expect(offered.withdrawal.confirmText).toBe(
			'Als u intrekt, stopt de behandeling.',
		)

		const withdrawn = await request.post(`${casePath}/withdraw`, {
			headers,
			data: { reason: 'Ik ben toch niet verhuisd.' },
		})
		expect(withdrawn.ok()).toBeTruthy()
		const body = await withdrawn.json()
		expect(body.case.status).toBe('ingetrokken')
		expect(body.case.withdrawalReason).toBe('Ik ben toch niet verhuisd.')

		// Reopened: still readable, withdrawn, and with no way back.
		const after = await request.get(casePath, { headers })
		const reopened = await after.json()
		expect(reopened.case.omschrijving).toBe('Aanvraag verhuizing')
		expect(reopened.withdrawal.open).toBe(false)
		expect(reopened.case.withdrawnAt).toBeTruthy()

		const again = await request.post(`${casePath}/withdraw`, {
			headers,
			data: {},
		})
		expect(again.status(), 'a withdrawn request is not withdrawn twice').toBe(
			409,
		)
	})

	test('a case type that declares no withdrawal offers none and accepts none', async ({
		request,
	}) => {
		await seedContribution(request)
		const caseType = await seedCaseType(request, null)
		const { token, caseId } = await seedCase(request, caseType, 'ontvangen')
		const headers = { Authorization: `Bearer ${token}` }
		const casePath = `${API_BASE}/citizen/cases/portaliq/portalCase/${caseId}`

		const page = await request.get(casePath, { headers })
		expect((await page.json()).withdrawal.declared).toBe(false)

		const refused = await request.post(`${casePath}/withdraw`, {
			headers,
			data: {},
		})
		expect(refused.status()).toBe(409)
	})

	test('a closed window says why, and the request is untouched', async ({
		request,
	}) => {
		await seedContribution(request)
		const caseType = await seedCaseType(request, {
			openStatuses: ['concept'],
			closedReason: 'Uw aanvraag is al beoordeeld.',
			targetStatus: 'ingetrokken',
		})
		const { token, caseId } = await seedCase(request, caseType, 'besloten')
		const headers = { Authorization: `Bearer ${token}` }
		const casePath = `${API_BASE}/citizen/cases/portaliq/portalCase/${caseId}`

		const page = await request.get(casePath, { headers })
		const state = await page.json()
		expect(state.withdrawal.open).toBe(false)
		expect(state.withdrawal.reason).toBe('Uw aanvraag is al beoordeeld.')

		const refused = await request.post(`${casePath}/withdraw`, {
			headers,
			data: {},
		})
		expect(refused.status()).toBe(409)

		const after = await request.get(casePath, { headers })
		expect((await after.json()).case.status).toBe('besloten')
	})
})
