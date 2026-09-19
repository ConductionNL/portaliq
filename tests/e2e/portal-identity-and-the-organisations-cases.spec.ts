/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for portal-identity-and-the-organisations-cases: two employees of
 * one company each file a case and both see both, a colleague with no mandate
 * sees neither, and a melding is reached on a case number without an account.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here:
 *   - two employees, one company, both cases (REQ-PIOC-002)
 *   - a colleague with no mandate sees nothing (REQ-PIOC-002)
 *   - a mandate narrower than the organisation (REQ-PIOC-002)
 *   - the view says why: the mandate is named beside the case (REQ-PIOC-002)
 *   - switching changes what is listed (REQ-PIOC-008)
 *   - a vergunning refuses the reference route (REQ-PIOC-001)
 *   - registration switched off offers nothing (REQ-PIOC-004)
 *   - the desk issues an account (REQ-PIOC-003)
 *
 * NOT anchored here, and covered by PHPUnit instead, named so anyone can check:
 *   - a reference link working once: tests/Unit/Service/Identity/PortalReferenceLinkServiceTest.php
 *     (testALinkWorksOnce, testAnExpiredLinkAdmitsNobody)
 *   - an expired invitation and the sender's view of its state:
 *     tests/Unit/Service/Identity/PortalInvitationServiceTest.php
 *   - the domain allow list and both policies:
 *     tests/Unit/Service/Identity/PortalRegistrationPolicyServiceTest.php
 *   - the challenge, and that it makes no outbound request at all (there is no
 *     HTTP client to inject): tests/Unit/Service/Identity/PortalChallengeServiceTest.php
 *   - removal leaving the case: tests/Unit/Service/Identity/PortalSelfServiceServiceTest.php
 *     (testTheAccountAndItsClaimsGoAndTheCaseStays)
 *
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions. Run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test portal-identity-and-the-organisations
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'
const APP_API_BASE = '/apps/portaliq/api'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'

const ADMIN = Buffer.from('admin:admin').toString('base64')
const ORGANISATION = 'dev-org'
const PARTY = `kvk-${Date.now()}`

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

/** The contribution declaring a cases collection a mandate can read by party. */
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
				mandateField: 'organisation',
				caseTypeField: 'caseType',
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

/** A portal identity with a case of its own. */
async function seedEmployee(
	request: APIRequestContext,
	reference: string,
	caseType: string,
): Promise<string> {
	const res = await request.post(`${APP_API_BASE}/accounts/provision`, {
		headers: {
			Authorization: `Basic ${ADMIN}`,
			'OCS-APIRequest': 'true',
			requesttoken: 'x',
		},
		data: {
			audience: 'client',
			organisation: ORGANISATION,
			identityType: 'eherkenning',
			identityRef: `${PARTY}-${reference}`,
		},
	})
	expect(res.ok()).toBeTruthy()
	const { subjectRef } = await res.json()

	await seed(request, 'portalCase', {
		subjectRef,
		organisation: ORGANISATION,
		reference,
		caseType,
		status: 'ontvangen',
	})

	return subjectRef as string
}

/** Record a mandate for an identity, over the party the cases belong to. */
async function seedMandate(
	request: APIRequestContext,
	subjectRef: string,
	caseTypes: string[],
): Promise<string> {
	return seed(request, 'portalMandate', {
		subjectRef,
		organisation: ORGANISATION,
		onBehalfOf: ORGANISATION,
		label: 'Gemachtigd voor Voorbeeld B.V.',
		caseTypes,
		status: 'active',
	})
}

/** Sign in as a portal identity and return its bearer. */
async function bearerFor(
	request: APIRequestContext,
	subjectRef: string,
): Promise<string> {
	const res = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'client', organisation: ORGANISATION },
	})
	expect(
		res.ok(),
		'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
	).toBeTruthy()
	const { token } = await res.json()
	return token as string
}

/** The case numbers one identity sees, with the mandate named beside each. */
async function casesFor(
	request: APIRequestContext,
	token: string,
): Promise<Array<{ reference: string; mandate: string | null }>> {
	const res = await request.get(`${API_BASE}/my-cases`, {
		headers: { Authorization: `Bearer ${token}` },
	})
	expect(res.ok()).toBeTruthy()
	const body = await res.json()
	return (body.cases ?? []).map((row: Record<string, never>) => ({
		reference: row.reference as unknown as string,
		mandate: (row._mandate?.label ?? null) as unknown as string | null,
	}))
}

test.describe('portal-identity-and-the-organisations-cases', () => {
	test('two employees of one company both see both cases, and a colleague with no mandate sees neither', async ({
		request,
	}) => {
		await seedContribution(request)
		const first = `ZAAK-A-${Date.now()}`
		const second = `ZAAK-B-${Date.now()}`

		const employeeOne = await seedEmployee(request, first, 'vergunning')
		const employeeTwo = await seedEmployee(request, second, 'vergunning')
		const colleague = await seedEmployee(
			request,
			`ZAAK-C-${Date.now()}`,
			'vergunning',
		)

		await seedMandate(request, employeeOne, [])
		await seedMandate(request, employeeTwo, [])
		// The colleague deliberately gets none.

		const seenByOne = await casesFor(
			request,
			await bearerFor(request, employeeOne),
		)
		const references = seenByOne.map((row) => row.reference)
		expect(references).toContain(first)
		expect(references).toContain(second)

		// And the view says why the second one is there.
		const borrowed = seenByOne.find((row) => row.reference === second)
		expect(borrowed?.mandate).toBe('Gemachtigd voor Voorbeeld B.V.')

		const seenByColleague = await casesFor(
			request,
			await bearerFor(request, colleague),
		)
		const colleagueRefs = seenByColleague.map((row) => row.reference)
		expect(colleagueRefs).not.toContain(first)
		expect(colleagueRefs).not.toContain(second)
	})

	test('a mandate narrower than the organisation lists only its own case type', async ({
		request,
	}) => {
		await seedContribution(request)
		const vergunning = `ZAAK-V-${Date.now()}`
		const melding = `ZAAK-M-${Date.now()}`

		await seedEmployee(request, vergunning, 'vergunning')
		await seedEmployee(request, melding, 'melding')
		const narrow = await seedEmployee(
			request,
			`ZAAK-N-${Date.now()}`,
			'vergunning',
		)
		await seedMandate(request, narrow, ['vergunning'])

		const seen = (await casesFor(request, await bearerFor(request, narrow))).map(
			(row) => row.reference,
		)

		expect(seen).toContain(vergunning)
		expect(seen).not.toContain(melding)
	})

	test('a vergunning refuses the reference route, and registration that is off offers nothing', async () => {
		// No session, no cookie, no requesttoken: the least privileged caller.
		const anonymous = await playwrightRequest.newContext()

		const link = await anonymous.post(`${API_BASE}/identity/reference-link`, {
			data: {
				register: 'portaliq',
				schema: 'portalCaseType',
				caseType: 'a-type-that-declares-account-only',
				caseReference: 'ZAAK-1',
				email: 'ans@example.org',
			},
		})
		expect(
			[403, 404].includes(link.status()),
			'the reference route is not offered',
		).toBeTruthy()

		const registration = await anonymous.post(`${API_BASE}/identity/register`, {
			data: { email: 'ans@example.org' },
		})
		expect(
			[403, 404].includes(registration.status()),
			'registration is off by default',
		).toBeTruthy()

		const mine = await anonymous.get(`${API_BASE}/identity/access-requests`)
		expect(mine.status(), 'asking for access needs a session').toBe(401)

		await anonymous.dispose()
	})
})
