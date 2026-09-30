/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * identity-registered-details: "My details" reads the caller's own BRP or KvK
 * record through OpenRegister. Driven over the same HTTP the portal page
 * (RegisteredDetailsPage.jsx) calls. The CI instance configures no
 * brp-haalcentraal source, so a resident with a BSN meets the unavailable
 * state; the mapped record itself is covered by
 * tests/Unit/Service/Identity/PortalRegisteredDetailsServiceTest.php.
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
 * A BSN that passes the eleven test (a test number).
 */
const TEST_BSN = '999993653'

/**
 * A signed-in portal user with one identity, returning their bearer.
 *
 * @param request The request fixture.
 * @param identityType The identity type.
 * @param identityRef The identity reference.
 * @return The bearer.
 */
async function signedIn(
	request: APIRequestContext,
	identityType: string,
	identityRef: string,
): Promise<string> {
	const provisioned = await request.post('/apps/portaliq/api/accounts/provision', {
		headers: STAFF_HEADERS,
		data: {
			audience: 'client',
			organisation: 'dev-org',
			identityType,
			identityRef,
			displayName: 'Inwoner',
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

test.describe('identity-registered-details', () => {
	test('without a bearer nothing is read', async ({ request }) => {
		const res = await request.get(`${API_BASE}/identity/registered-details`)
		expect(res.status()).toBe(401)
	})

	// @e2e registered-details::an-account-without-a-bsn-shows-why-nothing-is-there
	test('a pseudonymous sign-in is told why there is nothing to show', async ({
		request,
	}) => {
		const token = await signedIn(request, 'digid', `pairwise-${Date.now()}`)
		const res = await request.get(`${API_BASE}/identity/registered-details`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		expect(res.ok()).toBeTruthy()
		expect(await res.json()).toEqual({
			available: false,
			reason: 'no_registration_identifier',
		})
	})

	// @e2e registered-details::a-request-for-someone-elses-details-is-not-possible
	test("another person's BSN as a parameter is ignored", async ({ request }) => {
		const token = await signedIn(request, 'eherkenning', `kvk-${Date.now()}`)
		const res = await request.get(
			`${API_BASE}/identity/registered-details?bsn=${TEST_BSN}`,
			{
				headers: { Authorization: `Bearer ${token}` },
			},
		)
		expect(res.ok()).toBeTruthy()
		const body = await res.json()
		expect(body).toEqual({
			available: false,
			reason: 'no_registration_identifier',
		})
		expect(JSON.stringify(body)).not.toContain(TEST_BSN)
	})

	// @e2e registered-details::the-brp-source-is-down
	test('a resident with a BSN and no BRP source reads that the details cannot be shown', async ({
		request,
	}) => {
		const token = await signedIn(request, 'digid', TEST_BSN)
		const res = await request.get(`${API_BASE}/identity/registered-details`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		expect(res.ok()).toBeTruthy()
		const body = await res.json()
		expect(body).toEqual({ available: false, reason: 'source_unavailable' })
		expect(JSON.stringify(body)).not.toContain(TEST_BSN)
	})
})
