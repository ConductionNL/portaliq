/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * identity-profile-page: the "My account" routes, driven over the same HTTP
 * the page (src/site/pages/e/AccountPage.vue) calls. The CI instance
 * captures no mail, so following the confirmation link is pinned by
 * tests/Unit/Service/Identity/PortalContactAddressServiceTest.php; here a
 * pending address stays pending and cannot be preferred.
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
 * A signed-in resident with or without an address in use, returning their bearer.
 *
 * @param request The request fixture.
 * @param email The address on the account, or ''.
 * @return The bearer.
 */
async function resident(request: APIRequestContext, email: string): Promise<string> {
	const provisioned = await request.post('/apps/portaliq/api/accounts/provision', {
		headers: STAFF_HEADERS,
		data: {
			audience: 'client',
			organisation: 'dev-org',
			identityType: 'digid',
			identityRef: `pairwise-${Date.now()}-${Math.random()}`,
			email,
			verifiedEmail: email !== '',
			displayName: 'Ans de Vries',
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

test.describe('identity-profile-page', () => {
	// @e2e portal-profile::the-endpoint-never-shows-another-account
	test('without a bearer no account is read', async ({ request }) => {
		const res = await request.get(`${API_BASE}/identity/details`)
		expect(res.status()).toBe(401)
	})

	// @e2e portal-profile::a-resident-opens-their-account
	test('a resident reads their name, addresses and channel, and no identity', async ({
		request,
	}) => {
		const token = await resident(request, 'old@example.nl')
		const res = await request.get(`${API_BASE}/identity/details`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		expect(res.ok()).toBeTruthy()
		const body = await res.json()
		expect(body.displayName).toBe('Ans de Vries')
		expect(body.contactChannel).toBe('portal')
		expect(body.contactAddresses).toEqual([
			{
				kind: 'email',
				value: 'old@example.nl',
				confirmed: true,
				preferred: true,
			},
		])
		expect(body).not.toHaveProperty('identityRef')
		expect(body).not.toHaveProperty('claims')
	})

	// @e2e portal-profile::a-changed-address-waits-for-the-link
	// @e2e portal-profile::an-unconfirmed-address-cannot-be-preferred
	test('an added address waits for its link and cannot be preferred yet', async ({
		request,
	}) => {
		const token = await resident(request, 'old@example.nl')
		const headers = { Authorization: `Bearer ${token}` }
		const added = await request.post(`${API_BASE}/identity/addresses`, {
			headers,
			data: { kind: 'email', value: 'new@example.nl' },
		})
		expect(added.ok()).toBeTruthy()
		expect(await added.json()).toMatchObject({
			added: true,
			confirmationPending: true,
		})

		const preferred = await request.post(
			`${API_BASE}/identity/addresses/preferred`,
			{
				headers,
				data: { kind: 'email', value: 'new@example.nl' },
			},
		)
		expect(preferred.status()).toBe(400)
		expect(await preferred.json()).toEqual({ error: 'confirm_first' })

		const details = await (
			await request.get(`${API_BASE}/identity/details`, { headers })
		).json()
		expect(details.email).toBe('old@example.nl')
		expect(details.pendingEmail).toBe('n***@example.nl')
	})

	// @e2e portal-profile::a-resident-chooses-post
	test('a resident chooses post', async ({ request }) => {
		const token = await resident(request, 'old@example.nl')
		const headers = { Authorization: `Bearer ${token}` }
		const chosen = await request.put(`${API_BASE}/identity/contact-channel`, {
			headers,
			data: { channel: 'post' },
		})
		expect(await chosen.json()).toEqual({ channel: 'post' })
		const details = await (
			await request.get(`${API_BASE}/identity/details`, { headers })
		).json()
		expect(details.contactChannel).toBe('post')
	})

	// @e2e portal-profile::a-first-digid-sign-in-without-an-e-mail-address
	test('a resident without an address is asked for one', async ({ request }) => {
		const token = await resident(request, '')
		const session = await (
			await request.get(`${API_BASE}/session`, {
				headers: { Authorization: `Bearer ${token}` },
			})
		).json()
		expect(session.contactPrompt).toBe(true)
	})

	// @e2e portal-profile::a-resident-removes-their-account
	test('a removed account reads as gone', async ({ request }) => {
		const token = await resident(request, 'old@example.nl')
		const headers = { Authorization: `Bearer ${token}` }
		const removed = await request.post(`${API_BASE}/identity/remove`, {
			headers,
		})
		expect(await removed.json()).toEqual({ removed: true })
		const details = await request.get(`${API_BASE}/identity/details`, {
			headers,
		})
		expect(details.status()).toBe(404)
	})
})
