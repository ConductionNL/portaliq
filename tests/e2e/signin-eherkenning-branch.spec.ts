/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * signin-eherkenning-branch T05/T06: a whole-company business session asks
 * for its branches and chooses one. Driven over the same HTTP the portal
 * header (BranchSwitcher.jsx) calls. The CI instance configures no kvk
 * source, so the company's branch list is empty there and every branch is
 * refused (fail closed); the whole company can always be chosen. Narrowing
 * to a real branch is covered by tests/Unit/Service/Branch/BranchChoiceTest.php
 * and tests/Unit/Service/PortalSessionServiceTest.php.
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
 * A signed-in business user for the whole company, returning their bearer.
 *
 * @param request The request fixture.
 * @return The bearer.
 */
async function wholeCompany(request: APIRequestContext): Promise<string> {
	const provisioned = await request.post('/apps/portaliq/api/accounts/provision', {
		headers: STAFF_HEADERS,
		data: {
			audience: 'client',
			organisation: 'dev-org',
			identityType: 'eherkenning',
			identityRef: '12345678',
			displayName: 'Bakkerij',
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
	return (await login.json()).token
}

test.describe('signin-eherkenning-branch', () => {
	test('without a bearer there is no branch list and no choice', async ({
		request,
	}) => {
		expect((await request.get(`${API_BASE}/session/branches`)).status()).toBe(
			401,
		)
		expect(
			(
				await request.post(`${API_BASE}/session/branch`, {
					data: { branch: '' },
				})
			).status(),
		).toBe(401)
	})

	test('a branch the company list does not hold is refused, and the old bearer keeps working', async ({
		request,
	}) => {
		const token = await wholeCompany(request)
		const headers = { Authorization: `Bearer ${token}` }

		const list = await request.get(`${API_BASE}/session/branches`, { headers })
		expect(list.ok()).toBeTruthy()
		expect(await list.json()).toMatchObject({ branch: '', restricted: false })

		const refused = await request.post(`${API_BASE}/session/branch`, {
			headers,
			data: { branch: '000099999999' },
		})
		expect(refused.status()).toBe(403)
		expect(
			(await request.get(`${API_BASE}/session`, { headers })).ok(),
		).toBeTruthy()
	})

	test('the whole company can always be chosen, and the answer is a new bearer', async ({
		request,
	}) => {
		const token = await wholeCompany(request)
		const chosen = await request.post(`${API_BASE}/session/branch`, {
			headers: { Authorization: `Bearer ${token}` },
			data: { branch: '' },
		})
		expect(chosen.ok()).toBeTruthy()
		const body = await chosen.json()
		expect(body.token).not.toBe(token)
		expect(body.branch).toBe('')
	})
})
