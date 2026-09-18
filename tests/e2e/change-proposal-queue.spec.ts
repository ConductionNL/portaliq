/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for change-proposal-queue: a citizen proposes a field change on
 * their case, the proposal queues on the record, and a reviewer with write
 * rights accepts it so the record carries the new value.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here:
 *   - a citizen proposes a new value on a listed property (REQ-CPQ-002)
 *   - a property the contribution does not list is refused (REQ-CPQ-002)
 *   - a handler accepts a proposal and the record carries the value (REQ-CPQ-003)
 *   - the queue on a record lists the proposal with its diff (REQ-CPQ-004,
 *     the data half; the widget itself is the leaf work named in tasks.md)
 *
 * NOT anchored here, covered by PHPUnit and named so anyone can check:
 *   - the snapshot and the allow-list:
 *     tests/Unit/Service/Proposals/ProposalServiceTest.php
 *     (testAProposalRecordsWhatItSaw, testAPropertyTheContributionDoesNotListIsRefused)
 *   - a reviewer without write rights refused, and a drifted snapshot stopping
 *     the accept: same file plus
 *     tests/Unit/Controller/ProposalControllerTest.php
 *     (testAReviewerWithoutWriteRightsCanNeitherAcceptNorReject,
 *     testADriftedAcceptAnswersAConflictWithBothValues)
 *
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions. Run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test change-proposal-queue
 *
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'
const APP_API_BASE = '/apps/portaliq/api'
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
	expect(res.ok(), `OpenRegister objects#create must be reachable for ${schema}`).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	return id
}

/** The contribution declaring one property proposable on the case schema. */
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
				id: 'propose-change',
				type: 'propose-change',
				label: 'Wijziging voorstellen',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
				proposable: ['toelichting'],
			},
		],
		pages: [{ id: 'mijn-zaken', label: 'Mijn zaken', blocks: [{ type: 'collection', collection: 'cases' }] }],
	})
}

test.describe('change-proposal-queue', () => {
	test('a citizen proposes a change, it queues, and a reviewer accepts it onto the record', async ({ request }) => {
		await seedContribution(request)
		const stamp = Date.now()
		const subjectRef = `subject-${stamp}`
		const caseId = await seed(request, 'portalCase', {
			subjectRef,
			organisation: ORGANISATION,
			reference: `ZAAK-${stamp}`,
			status: 'ontvangen',
			toelichting: 'Oude toelichting',
		})

		const login = await request.post(`${API_BASE}/session/dev-login`, {
			data: { subjectRef, audience: 'client', organisation: ORGANISATION },
		})
		expect(login.ok(), 'dev-login must be enabled (see tests/e2e/ci-seed.sh)').toBeTruthy()
		const { token } = await login.json()

		const proposed = await request.post(`${API_BASE}/proposals`, {
			headers: { Authorization: `Bearer ${token}` },
			data: {
				register: 'portaliq',
				schema: 'portalCase',
				id: caseId,
				changes: [{ property: 'toelichting', proposedValue: 'Nieuwe toelichting' }],
				note: 'Mijn situatie is gewijzigd.',
			},
		})
		expect(proposed.ok(), 'a citizen may propose a listed property').toBeTruthy()

		// A property the contribution does not list is refused, and nothing is
		// written on the record either way.
		const refused = await request.post(`${API_BASE}/proposals`, {
			headers: { Authorization: `Bearer ${token}` },
			data: {
				register: 'portaliq',
				schema: 'portalCase',
				id: caseId,
				changes: [{ property: 'status', proposedValue: 'afgehandeld' }],
			},
		})
		expect(refused.status()).toBe(422)

		// The queue on the record, as a reviewer sees it.
		const queue = await request.get(
			`${APP_API_BASE}/proposals?register=portaliq&schema=portalCase&id=${encodeURIComponent(caseId)}`,
			{ headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true', requesttoken: 'x' } },
		)
		expect(queue.ok()).toBeTruthy()
		const proposals = ((await queue.json()).proposals ?? []) as Array<Record<string, never>>
		expect(proposals.length).toBeGreaterThan(0)

		const queued = proposals[0]
		const changes = queued.changes as unknown as Array<Record<string, string>>
		expect(changes[0].currentValue).toBe('Oude toelichting')
		expect(changes[0].proposedValue).toBe('Nieuwe toelichting')

		const accepted = await request.post(
			`${APP_API_BASE}/proposals/${encodeURIComponent((queued.id ?? queued.uuid) as unknown as string)}/accept`,
			{ headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true', requesttoken: 'x' }, data: {} },
		)
		expect(accepted.ok(), 'a reviewer with write rights may accept').toBeTruthy()

		const after = await request.get(`${OR_OBJECTS_BASE}/portaliq/portalCase/${caseId}`, {
			headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
		})
		expect((await after.json()).toelichting).toBe('Nieuwe toelichting')
	})

	test('a caller with no session proposes nothing and decides nothing', async ({ request }) => {
		const proposed = await request.post(`${API_BASE}/proposals`, {
			data: {
				register: 'portaliq',
				schema: 'portalCase',
				id: 'zaak-1',
				changes: [{ property: 'toelichting', proposedValue: 'x' }],
			},
		})
		expect(proposed.status()).toBe(401)

		const decided = await request.post(`${APP_API_BASE}/proposals/proposal-1/accept`, { data: {} })
		expect([401, 403, 412].includes(decided.status()), 'deciding needs a staff session').toBeTruthy()
	})
})
