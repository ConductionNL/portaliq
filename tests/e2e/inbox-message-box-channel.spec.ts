/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * inbox-berichtenbox-channel: the government message box is a choice only
 * where the organisation offers it, and a resident's choice to switch it off
 * survives a reload. The send itself needs integriq and a case app that names
 * a recipient method, which portaliq's own seed has neither of; that path is
 * pinned by MessageBoxDispatchJobTest and PortalDigitalPostDeliveredListenerTest
 * with integriq's real events.
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
 * A signed-in resident of dev-org, returning their bearer.
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

// @e2e portal-message-box-channel::an-organisation-without-the-channel
test('an organisation without a message box offers no choice for it', async ({
	request,
}) => {
	const token = await signedInResident(request, Date.now())
	const answer = await request.get(
		`${API_BASE}/identity/notification-preferences`,
		{ headers: { Authorization: `Bearer ${token}` } },
	)
	expect(answer.ok()).toBeTruthy()
	const body = await answer.json()
	expect(body.messageBox).toBeNull()
	expect(JSON.stringify(body)).not.toMatch(/sourceId/)
})

// @e2e portal-message-box-channel::a-resident-prefers-the-portal-only
test('switching letters to the message box off survives a reload', async ({
	request,
}) => {
	const token = await signedInResident(request, Date.now() + 1)
	const auth = { Authorization: `Bearer ${token}` }

	const saved = await request.patch(
		`${API_BASE}/identity/notification-preferences`,
		{ headers: auth, data: { preferences: { messageBox: { enabled: false } } } },
	)
	expect(saved.ok()).toBeTruthy()
	expect((await saved.json()).preferences.messageBox).toEqual({ enabled: false })

	const after = await request.get(
		`${API_BASE}/identity/notification-preferences`,
		{ headers: auth },
	)
	expect((await after.json()).preferences.messageBox).toEqual({ enabled: false })
})
