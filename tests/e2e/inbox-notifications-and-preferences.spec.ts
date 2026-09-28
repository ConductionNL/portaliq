/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * inbox-notifications-and-preferences: a resident's notice choices survive a
 * reload, and a record link opens the portal on that record after sign-in.
 * The change message itself needs a case app declaring a change rule, which
 * portaliq's own seed does not; that path is pinned by
 * PortalRecordChangeListenerTest with OpenRegister's real events.
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
 * A signed-in resident, returning their bearer.
 *
 * @param request The request fixture.
 * @param stamp A unique suffix.
 * @return The bearer.
 */
async function signedInResident(
	request: APIRequestContext,
	stamp: number,
): Promise<string> {
	const provisioned = await request.post('/apps/portaliq/api/accounts/provision', {
		headers: STAFF_HEADERS,
		data: {
			audience: 'client',
			organisation: 'dev-org',
			identityType: 'digid',
			identityRef: `bsn-${stamp}`,
			displayName: 'Inwoner Jansen',
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

test('switching e-mail off for case changes survives a reload', async ({
	request,
}) => {
	const token = await signedInResident(request, Date.now())
	const auth = { Authorization: `Bearer ${token}` }

	const before = await request.get(
		`${API_BASE}/identity/notification-preferences`,
		{ headers: auth },
	)
	expect(before.ok()).toBeTruthy()
	expect((await before.json()).preferences['case.updated'].email).toBe(true)

	const saved = await request.patch(
		`${API_BASE}/identity/notification-preferences`,
		{
			headers: auth,
			data: { preferences: { 'case.updated': { email: false } } },
		},
	)
	expect(saved.ok()).toBeTruthy()

	const after = await request.get(
		`${API_BASE}/identity/notification-preferences`,
		{ headers: auth },
	)
	const body = await after.json()
	expect(body.preferences['case.updated'].email).toBe(false)
	expect(body.preferences['message.created'].email).toBe(true)
})

test('a record link keeps its target through the sign-in and leaves the address bar', async ({
	page,
}) => {
	await page.goto(
		'/apps/portaliq/portal?org=dev-org#open=portaliq/berichten/some-record',
	)
	await expect(page).not.toHaveURL(/#open=/)
	const kept = await page.evaluate(() =>
		window.sessionStorage.getItem('portaliq.openRecord'),
	)
	expect(JSON.parse(kept || 'null')).toEqual({
		app: 'portaliq',
		collection: 'berichten',
		id: 'some-record',
	})
})
