/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * identity-access-requests: a portal user asks for access to a party's cases
 * and follows the answer, and the owner refuses with a reason the asker reads.
 * Driven over the same HTTP the portal page (AccessRequestsPage.jsx) and the
 * staff Access requests page call.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'

const ADMIN = Buffer.from('admin:admin').toString('base64')

const STAFF_HEADERS = {
	Authorization: `Basic ${ADMIN}`,
	'OCS-APIRequest': 'true',
	requesttoken: 'x',
}

/**
 * A signed-in portal business user, returning their bearer.
 *
 * @param request The request fixture.
 * @param stamp A unique suffix.
 * @return The bearer.
 */
async function signedInBookkeeper(
	request: APIRequestContext,
	stamp: number,
): Promise<string> {
	const provisioned = await request.post('/apps/portaliq/api/accounts/provision', {
		headers: STAFF_HEADERS,
		data: {
			audience: 'client',
			organisation: 'dev-org',
			identityType: 'eherkenning',
			identityRef: `kvk-${stamp}`,
			displayName: 'Boekhouder Jansen',
		},
	})
	expect(provisioned.ok()).toBeTruthy()
	const { subjectRef } = await provisioned.json()

	const login = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'client', organisation: 'dev-org' },
	})
	expect(
		login.ok(),
		'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
	).toBeTruthy()
	const { token } = await login.json()
	return token
}

/**
 * The asker's own requests.
 *
 * @param request The request fixture.
 * @param token The asker's bearer.
 * @return The requests.
 */
async function myRequests(
	request: APIRequestContext,
	token: string,
): Promise<Array<Record<string, string>>> {
	const res = await request.get(`${API_BASE}/identity/access-requests`, {
		headers: { Authorization: `Bearer ${token}` },
	})
	expect(res.ok()).toBeTruthy()
	return (await res.json()).requests
}

test.describe('identity-access-requests', () => {
	// @e2e portal-access-requests::a-bookkeeper-asks-for-a-clients-cases
	test("a bookkeeper asks for a client's cases and sees the request as pending", async ({
		request,
	}) => {
		const stamp = Date.now()
		const token = await signedInBookkeeper(request, stamp)
		const party = `8765${String(stamp).slice(-4)}`

		const withoutReason = await request.post(
			`${API_BASE}/identity/access-requests`,
			{
				headers: { Authorization: `Bearer ${token}` },
				data: { onBehalfOf: party, reason: '' },
			},
		)
		expect(withoutReason.status()).toBe(400)

		const asked = await request.post(`${API_BASE}/identity/access-requests`, {
			headers: { Authorization: `Bearer ${token}` },
			data: {
				onBehalfOf: party,
				reason: 'Ik doe de boekhouding van dit bedrijf.',
			},
		})
		expect(asked.ok()).toBeTruthy()

		const mine = await myRequests(request, token)
		const row = mine.find((r) => r.onBehalfOf === party)
		expect(row, "the request is in the asker's own list").toBeTruthy()
		expect(row?.state).toBe('pending')
	})

	// @e2e portal-access-requests::a-refusal-shows-its-reason
	test('a refusal shows its reason to the asker', async ({ request }) => {
		const stamp = Date.now()
		const token = await signedInBookkeeper(request, stamp)
		const party = `1122${String(stamp).slice(-4)}`

		await request.post(`${API_BASE}/identity/access-requests`, {
			headers: { Authorization: `Bearer ${token}` },
			data: { onBehalfOf: party, reason: 'Ik ben de accountant.' },
		})
		const row = (await myRequests(request, token)).find(
			(r) => r.onBehalfOf === party,
		)
		const id = row?.id || row?.uuid
		expect(id, 'the request carries an id').toBeTruthy()

		// @e2e portal-access-requests::a-clerk-refuses-without-a-reason
		const withoutReason = await request.post(
			`/apps/portaliq/api/access-requests/${id}/refuse`,
			{
				headers: STAFF_HEADERS,
				data: { organisation: 'dev-org', reason: '' },
			},
		)
		expect(withoutReason.status()).toBe(400)
		const stillPending = (await myRequests(request, token)).find(
			(r) => r.onBehalfOf === party,
		)
		expect(stillPending?.state).toBe('pending')

		const refused = await request.post(
			`/apps/portaliq/api/access-requests/${id}/refuse`,
			{
				headers: STAFF_HEADERS,
				data: {
					organisation: 'dev-org',
					reason: 'No authorisation from the company',
				},
			},
		)
		expect(refused.ok()).toBeTruthy()

		const after = (await myRequests(request, token)).find(
			(r) => r.onBehalfOf === party,
		)
		expect(after?.state).toBe('refused')
		expect(after?.decisionReason).toBe('No authorisation from the company')
	})
})
