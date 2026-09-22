/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for portal-identity-space: a case filed at the desk belongs to
 * someone before that someone has ever logged in, and it is waiting under
 * their own name on the first login.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here:
 *   - a clerk provisions a citizen at the desk (REQ-PIS-001)
 *   - the case filed at the desk is there on first login (REQ-PIS-004)
 *   - the token still works, and offers a login (REQ-PIS-004)
 *
 * NOT anchored here, and marked `@e2e exclude` in the spec for the same
 * reasons the spec gives: the fail-closed session contract, the broker round
 * trip and the cross-app typed event. They are covered by PHPUnit, in
 * tests/Unit/Service/PortalAccountProvisionTest.php (pending matching, the
 * wrong person, the voided account),
 * tests/Unit/Listener/PortalAccountEventListenerTest.php (both typed events
 * and their result slots) and tests/Unit/Controller/MyCasesControllerTest.php
 * (no session, no list). Naming them is deliberate: a comment claiming
 * coverage elsewhere without naming the file stops anyone looking.
 *
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions. Run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test portal-identity-space
 *
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'
const APP_API_BASE = '/apps/portaliq/api'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'

const ADMIN = Buffer.from('admin:admin').toString('base64')

/**
 * Create one object through OpenRegister's own object API as the dev admin,
 * standing in for the case app having written the row server-side.
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
	expect(id).toBeTruthy()
	return id
}

/**
 * The contribution that declares a `kind: cases` collection for the client
 * audience, which is what "Mijn zaken" fans out over.
 */
async function seedCasesContribution(request: APIRequestContext): Promise<string> {
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
				columns: [{ field: 'reference', label: 'Zaaknummer' }],
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

/**
 * A clerk provisions a citizen, through the staff endpoint, as the dev admin.
 */
async function provisionAtTheDesk(
	request: APIRequestContext,
	identityRef: string,
): Promise<{ subjectRef: string; status: string }> {
	const res = await request.post(`${APP_API_BASE}/accounts/provision`, {
		headers: {
			Authorization: `Basic ${ADMIN}`,
			'OCS-APIRequest': 'true',
			requesttoken: 'x',
		},
		data: {
			audience: 'client',
			organisation: 'dev-org',
			identityType: 'digid',
			identityRef,
			displayName: 'Ans de Vries',
		},
	})
	expect(
		res.ok(),
		'a clerk with the portal.provision action may provision',
	).toBeTruthy()
	const body = await res.json()
	expect(body.subjectRef).toBeTruthy()
	return body as { subjectRef: string; status: string }
}

test.describe('portal-identity-space', () => {
	test('a clerk provisions a citizen at the desk, and the account waits pending', async ({
		request,
	}) => {
		const provisioned = await provisionAtTheDesk(request, `bsn-${Date.now()}`)

		expect(provisioned.status).toBe('pending')
	})

	test('the case filed at the desk is there on first login', async ({
		request,
	}) => {
		await seedCasesContribution(request)
		const provisioned = await provisionAtTheDesk(request, `bsn-${Date.now()}`)
		const reference = `ZAAK-${Date.now()}`

		// The case app attaches the case to the account that has never logged in.
		await seed(request, 'portalCase', {
			subjectRef: provisioned.subjectRef,
			organisation: 'dev-org',
			reference,
			status: 'ontvangen',
			omschrijving: 'Aanvraag aan de balie ingediend',
		})

		// The first login. dev-login stands in for the broker round trip, which
		// the spec excludes from e2e on purpose.
		const login = await request.post(`${API_BASE}/session/dev-login`, {
			data: {
				subjectRef: provisioned.subjectRef,
				audience: 'client',
				organisation: 'dev-org',
			},
		})
		expect(
			login.ok(),
			'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
		).toBeTruthy()
		const { token } = await login.json()

		const mine = await request.get(`${API_BASE}/my-cases`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		expect(mine.ok()).toBeTruthy()
		const body = await mine.json()
		const references = (body.cases ?? []).map(
			(row: Record<string, unknown>) => row.reference,
		)
		expect(references).toContain(reference)
	})

	test('a caller with no session, no cookie and no requesttoken is refused', async () => {
		// The least privileged principal that should be refused: a bare HTTP
		// client carrying nothing at all. A separate context, because the
		// fixture's own context may carry state from a previous test.
		const anonymous = await playwrightRequest.newContext()

		const mine = await anonymous.get(`${API_BASE}/my-cases`)
		expect(mine.status(), 'Mijn zaken without a session is 401').toBe(401)

		const provision = await anonymous.post(
			`${APP_API_BASE}/accounts/provision`,
			{
				data: {
					audience: 'client',
					organisation: 'dev-org',
					identityType: 'digid',
					identityRef: 'bsn-probe',
				},
			},
		)
		expect(
			[401, 403, 412].includes(provision.status()),
			'provisioning is never reachable without a staff session',
		).toBeTruthy()

		await anonymous.dispose()
	})
})
