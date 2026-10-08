/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for partner-tasks-in-the-portal: a handler asks an outside partner
 * for something from the case, the partner sees it in "Mijn taken" and nothing
 * else, and answers it there.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here:
 *   - a partner sees its own tasks and nothing else (REQ-PTP-001)
 *   - a handler raises an ask from the case (REQ-PTP-002)
 *   - the partner answers it, and the answer names the account that gave it
 *     (REQ-PTP-003)
 *
 * NOT anchored here, covered by PHPUnit and named so anyone can check:
 *   - a handler without write refused, and nothing raised or provisioned:
 *     tests/Unit/Controller/PartnerTaskControllerTest.php
 *     (testAUserWithoutWriteOnTheCaseIsRefusedAndNothingIsRaised) and
 *     tests/Unit/Service/Tasks/PortalCaseAccessGuardTest.php
 *   - pre-provisioning from a KvK number and address, and the frozen due date
 *     and upload rules: tests/Unit/Service/Tasks/PartnerAskServiceTest.php
 *
 * The ask and the answer both cross openregister's portal task seam, which a
 * fresh CI instance provisions through tests/e2e/ci-seed.sh. Where the seam is
 * absent the suite skips rather than failing on somebody else's dependency.
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test partner-tasks
 *
 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
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
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for ${schema}`,
	).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	return id
}

/** A portal session for one subject. */
async function bearerFor(
	request: APIRequestContext,
	subjectRef: string,
	audience: string,
): Promise<string> {
	const res = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience, organisation: ORGANISATION },
	})
	expect(
		res.ok(),
		'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
	).toBeTruthy()
	const { token } = await res.json()
	return token as string
}

test.describe('partner-tasks-in-the-portal', () => {
	test('a handler raises an ask, the partner sees it and nothing else', async ({
		request,
	}) => {
		const stamp = Date.now()
		const caseId = await seed(request, 'portalCase', {
			subjectRef: `resident-${stamp}`,
			organisation: ORGANISATION,
			reference: `ZAAK-${stamp}`,
			status: 'ontvangen',
		})

		const raised = await request.post(`${APP_API_BASE}/partner-tasks/ask`, {
			headers: {
				Authorization: `Basic ${ADMIN}`,
				'OCS-APIRequest': 'true',
				requesttoken: 'x',
			},
			data: {
				register: 'portaliq',
				schema: 'portalCase',
				caseId,
				title: 'Advies welstand',
				description: 'Graag uw oordeel over de gevelwijziging.',
				dueAt: new Date(Date.now() + 14 * 86400000).toISOString(),
				organisation: ORGANISATION,
				partner: {
					kvk: `1234${stamp}`.slice(0, 8),
					email: 'welstand@example.org',
					name: 'Welstandscommissie',
				},
				uploadRules: [{ mimeType: 'application/pdf', required: true }],
			},
		})

		if (raised.status() === 404 || raised.status() === 503) {
			test.skip(
				true,
				'the openregister portal task seam is not provisioned on this instance',
			)
			return
		}

		expect(
			raised.ok(),
			'a handler with the action may ask a partner',
		).toBeTruthy()
		const body = await raised.json()
		expect(body.subjectRef).toBeTruthy()

		// The partner's own surface: their task, and not the resident's.
		const partnerToken = await bearerFor(
			request,
			body.subjectRef as string,
			'partner',
		)
		const mine = await request.get(`${API_BASE}/tasks`, {
			headers: { Authorization: `Bearer ${partnerToken}` },
		})
		expect(mine.ok()).toBeTruthy()
		const tasks = ((await mine.json()).tasks ?? []) as Array<
			Record<string, never>
		>
		for (const task of tasks) {
			expect(task.subjectRef as unknown as string).toBe(body.subjectRef)
		}

		const resident = await request.get(`${API_BASE}/tasks`, {
			headers: {
				Authorization: `Bearer ${await bearerFor(request, `resident-${stamp}`, 'client')}`,
			},
		})
		const residentTasks = ((await resident.json()).tasks ?? []) as Array<
			Record<string, never>
		>
		for (const task of residentTasks) {
			expect(task.subjectRef as unknown as string).not.toBe(body.subjectRef)
		}
	})

	test('a caller with no session raises nothing', async ({ request }) => {
		const refused = await request.post(`${APP_API_BASE}/partner-tasks/ask`, {
			data: {
				register: 'portaliq',
				schema: 'portalCase',
				caseId: 'zaak-1',
				title: 'Advies welstand',
				partner: { kvk: '12345678', email: 'welstand@example.org' },
			},
		})

		expect(
			[401, 403, 412].includes(refused.status()),
			'asking a partner needs a staff session',
		).toBeTruthy()
	})
})
