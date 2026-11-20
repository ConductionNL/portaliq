/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for resident-identity-in-forms: a form can ask for a signature,
 * a verified e-mail address, a second person who co-signs, a Yivi sign-in
 * that shares only what the form needs, and an employee who fills it in for a
 * resident.
 *
 * Every request below runs as a resident or a visitor: the request context
 * starts with an empty storage state, and a resident's portal session is
 * minted per test through dev-login (trust `low`). Only seeding talks to
 * OpenRegister and the Nextcloud user API as the instance administrator.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here:
 *   - an empty required signature is refused and named, a PNG is accepted
 *     (REQ-RIF-001 @e2e)
 *   - a verified e-mail field refuses an address without a code, sends a code
 *     with a 60 s resend wait, and refuses a wrong code (REQ-RIF-002 @e2e)
 *   - a form that needs co-signing holds the request in `awaiting-cosign`
 *     with the co-signer's name, lists it on Mijn zaken, and a co-signer
 *     without a DigiD session is sent to sign in (REQ-RIF-003 @e2e)
 *   - the sign-in choice of a Yivi form lists the attributes it asks for, and
 *     a session below the form's level does not open it (REQ-RIF-004 @e2e)
 *   - a Nextcloud user outside the desk group is refused staff mode, and a
 *     resident who names an applicant is refused (REQ-RIF-005 @e2e)
 *
 * NOT anchored here, because they need a system this suite does not run:
 *   - signing and refusing as a co-signer, and the same-subject refusal: a
 *     session at level substantial, i.e. a real DigiD sign-in.
 *     PHPUnit tests/Unit/Service/Intake/PortalCosignTest.php and
 *     tests/Unit/BackgroundJob/CosignDeadlineJobTest.php cover them.
 *   - the right code from the mail: the mailbox. PHPUnit
 *     tests/Unit/Service/Intake/PortalEmailVerificationTest.php.
 *   - a disclosed Yivi envelope and its prefill: the broker. PHPUnit
 *     tests/Unit/Service/Signin/YiviAttributesTest.php.
 *   - the desk employee's BRP/KvK lookup and its audit line: the BRP and KvK
 *     providers. PHPUnit tests/Unit/Controller/PortalIntakeControllerTest.php.
 *   - the drawing box and the typed-name alternative in the browser:
 *     `node --test tests/identity-in-forms.spec.mjs`.
 *
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions (dev-login on,
 * portal `dev-portal`). Run with the target named, e.g.:
 *
 *     PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test resident-identity-in-forms
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'

const ADMIN = Buffer.from(
	`${process.env.NC_ADMIN_USER ?? 'admin'}:${process.env.NC_ADMIN_PASSWORD ?? 'admin'}`,
).toString('base64')

/** The smallest PNG there is: one transparent pixel. */
const ONE_PIXEL_PNG =
	'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='

test.use({ storageState: { cookies: [], origins: [] } })

/** Create one object through OpenRegister's own object API, as the administrator. */
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

/**
 * A published form and the binding that serves it on a fresh route.
 *
 * @return the route
 */
async function seedFormOnRoute(
	request: APIRequestContext,
	slug: string,
	fields: Array<Record<string, unknown>>,
	binding: Record<string, unknown> = {},
): Promise<string> {
	const stamp = `${slug}-${Date.now()}`
	await seed(request, 'registrationForm', {
		caseType: stamp,
		audience: 'client',
		name: `${stamp}-client`,
		status: 'published',
		fields,
	})
	const route = `aanvragen/${stamp}`
	await seed(request, 'portalFormBinding', {
		portal: 'dev-portal',
		route,
		typeRegister: 'portaliq',
		typeSchema: 'portalCaseType',
		typeId: stamp,
		audience: 'client',
		formRegister: 'portaliq',
		formSchema: 'registrationForm',
		caseRegister: 'portaliq',
		caseSchema: 'portalCase',
		intakeKind: 'hosted',
		status: 'published',
		...binding,
	})
	return route
}

/** A resident's portal session at level low, minted through dev-login. */
async function residentToken(request: APIRequestContext, name: string): Promise<string> {
	const stamp = Date.now()
	const provisioned = await request.post('/apps/portaliq/api/accounts/provision', {
		headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true', requesttoken: 'x' },
		data: {
			audience: 'client',
			organisation: 'dev-org',
			identityType: 'digid',
			identityRef: `bsn-${stamp}`,
			displayName: name,
		},
	})
	expect(provisioned.ok()).toBeTruthy()
	const { subjectRef } = await provisioned.json()

	const login = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'client', organisation: 'dev-org' },
	})
	expect(login.ok(), 'dev-login must be enabled (see tests/e2e/ci-seed.sh)').toBeTruthy()
	return (await login.json()).token as string
}

/** Send a form, optionally as a signed-in resident. */
async function submit(
	request: APIRequestContext,
	data: Record<string, unknown>,
	token = '',
) {
	const headers: Record<string, string> = {}
	if (token !== '') {
		headers.Authorization = `Bearer ${token}`
	}
	return request.post(`${API_BASE}/intake/submit`, { headers, data })
}

test.describe('resident-identity-in-forms', () => {
	test('REQ-RIF-001 @e2e: an empty required signature is refused, a signature image is accepted', async ({
		request,
	}) => {
		const route = await seedFormOnRoute(request, 'straatfeest', [
			{ name: 'organisator', order: 1, required: true },
			{ name: 'handtekening', order: 2, type: 'signature', required: true },
		])

		const empty = await submit(request, {
			route,
			answers: { organisator: 'Sanne de Vries', handtekening: '' },
		})
		expect(empty.status()).toBe(400)
		expect((await empty.json()).errors).toHaveProperty('handtekening')

		const notAnImage = await submit(request, {
			route,
			answers: {
				organisator: 'Sanne de Vries',
				handtekening: 'data:text/plain;base64,U2FubmUgZGUgVnJpZXM=',
			},
		})
		expect(notAnImage.status()).toBe(400)
		expect((await notAnImage.json()).errors.handtekening).toBe('The signature must be an image.')

		// A drawn signature and a typed name both reach the server as a PNG.
		const signed = await submit(request, {
			route,
			answers: { organisator: 'Sanne de Vries', handtekening: ONE_PIXEL_PNG },
		})
		expect(signed.ok()).toBeTruthy()
		const body = await signed.json()
		expect(body.reference).toBeTruthy()
	})

	test('REQ-RIF-002 @e2e: a verified e-mail field refuses an unchecked address and a wrong code', async ({
		request,
	}) => {
		const route = await seedFormOnRoute(request, 'woo-mail', [
			{ name: 'email', order: 1, type: 'email', verify: true, required: true },
		])
		const address = `sanne-${Date.now()}@example.org`

		// An unverified address: the server refuses it and names the field.
		const unverified = await submit(request, { route, answers: { email: address } })
		expect(unverified.status()).toBe(400)
		expect((await unverified.json()).errors).toHaveProperty('email')

		const sent = await request.post(`${API_BASE}/intake/email-code`, {
			data: { route, email: address },
		})
		expect(sent.ok(), 'the code mail goes out').toBeTruthy()
		expect(await sent.json()).toEqual({ sent: true, resendAfter: 60 })

		// A new code only after 60 seconds.
		const again = await request.post(`${API_BASE}/intake/email-code`, {
			data: { route, email: address },
		})
		expect(again.status()).toBe(429)

		const wrong = await request.post(`${API_BASE}/intake/email-code/check`, {
			data: { route, email: address, code: '000000' },
		})
		expect(wrong.status()).toBe(400)
		expect((await wrong.json()).verified).toBeUndefined()

		// A forged proof is no proof.
		const forged = await submit(request, {
			route,
			answers: { email: address },
			verifiedEmails: { [address]: 'not-a-proof' },
		})
		expect(forged.status()).toBe(400)
		expect((await forged.json()).errors).toHaveProperty('email')
	})

	test('a form without a verified e-mail field sends no code', async ({ request }) => {
		const route = await seedFormOnRoute(request, 'zonder-code', [
			{ name: 'email', order: 1, type: 'email' },
		])

		const res = await request.post(`${API_BASE}/intake/email-code`, {
			data: { route, email: 'sanne@example.org' },
		})
		expect(res.status()).toBe(404)
	})

	test('REQ-RIF-003 @e2e: a form that needs co-signing holds the request for its co-signer', async ({
		request,
	}) => {
		const route = await seedFormOnRoute(
			request,
			'woo-cosign',
			[
				{ name: 'onderwerp', order: 1, required: true },
				{ name: 'partner', order: 2, type: 'email', cosigner: true, required: true },
				{ name: 'partnerNaam', order: 3, cosignerName: true },
			],
			{ cosign: { required: true, deadlineDays: 7 } },
		)
		const sanne = await residentToken(request, 'Sanne de Vries')

		// Without a co-signer address the request is not held: the field is named.
		const noCosigner = await submit(
			request,
			{ route, answers: { onderwerp: 'Besluiten over de Dorpsstraat', partner: 'geen-adres' } },
			sanne,
		)
		expect(noCosigner.status()).toBe(400)
		expect((await noCosigner.json()).errors).toHaveProperty('partner')

		const held = await submit(
			request,
			{
				route,
				answers: {
					onderwerp: 'Besluiten over de Dorpsstraat',
					partner: `henk-${Date.now()}@example.org`,
					partnerNaam: 'Henk',
				},
			},
			sanne,
		)
		expect(held.ok()).toBeTruthy()
		const ack = await held.json()
		expect(ack.state).toBe('awaiting-cosign')
		expect(ack.cosign.state).toBe('awaiting')
		expect(ack.cosign.name).toBe('Henk')
		expect(Date.parse(ack.cosign.deadline)).toBeGreaterThan(Date.now())

		// The confirmation's status line reads the same waiting state.
		const status = await request.get(
			`${API_BASE}/intake/status?reference=${encodeURIComponent(ack.reference)}`,
		)
		expect(status.ok()).toBeTruthy()
		expect((await status.json()).state).toBe('awaiting-cosign')

		// Mijn zaken lists Sanne's held request, and only to her.
		const mine = await request.get(`${API_BASE}/cosign/held`, {
			headers: { Authorization: `Bearer ${sanne}` },
		})
		expect(mine.ok()).toBeTruthy()
		const requests = (await mine.json()).requests as Array<Record<string, unknown>>
		expect(requests.map((row) => row.reference)).toContain(ack.reference)

		const other = await residentToken(request, 'Ans Jansen')
		const theirs = await request.get(`${API_BASE}/cosign/held`, {
			headers: { Authorization: `Bearer ${other}` },
		})
		expect(theirs.ok()).toBeTruthy()
		expect(
			((await theirs.json()).requests as Array<Record<string, unknown>>).map((row) => row.reference),
		).not.toContain(ack.reference)

		const anonymous = await request.get(`${API_BASE}/cosign/held`)
		expect(anonymous.status()).toBe(401)

		// A co-signer without a DigiD session is sent to sign in at substantial,
		// and sees nothing of the request.
		for (const token of ['', other]) {
			const headers: Record<string, string> = token === '' ? {} : { Authorization: `Bearer ${token}` }
			const opened = await request.get(`${API_BASE}/cosign?code=ABC-DEF`, { headers })
			expect(opened.status()).toBe(401)
			const body = await opened.json()
			expect(body).toEqual({ error: 'sign_in_required', minTrust: 'substantial' })
		}
	})

	test('REQ-RIF-004 @e2e: a Yivi form lists what it asks for, and a session below its level does not open it', async ({
		request,
	}) => {
		const route = await seedFormOnRoute(
			request,
			'yivi',
			[
				{ name: 'applicantName', order: 1 },
				{ name: 'email', order: 2, type: 'email' },
			],
			{
				minTrust: 'substantial',
				yiviAttributes: [
					{ attribute: 'pbdf.gemeente.personalData.fullname', field: 'applicantName' },
					{ attribute: 'pbdf.sidn-pbdf.email.email', field: 'email' },
				],
			},
		)

		// The sign-in choice lists the attributes before the Yivi app opens.
		const visitor = await request.get(
			`${API_BASE}/intake/form?route=${encodeURIComponent(route)}`,
		)
		expect(visitor.status()).toBe(401)
		const choice = await visitor.json()
		expect(choice.error).toBe('sign_in_required')
		expect(choice.minTrust).toBe('substantial')
		expect(choice.yiviAttributes).toEqual([
			'pbdf.gemeente.personalData.fullname',
			'pbdf.sidn-pbdf.email.email',
		])

		// A session at basic (low) does not open a form that needs substantial,
		// and the answer says which level it needs.
		const low = await residentToken(request, 'Sanne de Vries')
		const below = await request.get(
			`${API_BASE}/intake/form?route=${encodeURIComponent(route)}`,
			{ headers: { Authorization: `Bearer ${low}` } },
		)
		expect(below.status()).toBe(403)
		const refused = await below.json()
		expect(refused.minTrust).toBe('substantial')
		expect(refused.fields).toBeUndefined()

		const sent = await submit(request, { route, answers: { applicantName: 'Sanne' } }, low)
		expect(sent.status()).toBe(403)
	})

	test('REQ-RIF-005 @e2e: a user outside the desk group is refused staff mode, and a resident cannot name an applicant', async ({
		request,
	}) => {
		const route = await seedFormOnRoute(
			request,
			'balie',
			[{ name: 'onderwerp', order: 1, required: true }],
			{ staffMayFill: true },
		)

		// The sign-in choice offers "Voor medewerkers" on this form.
		const visitor = await request.get(
			`${API_BASE}/intake/form?route=${encodeURIComponent(route)}`,
		)
		if (visitor.status() === 401) {
			expect((await visitor.json()).staffMayFill).toBe(true)
		}

		// A Nextcloud user in no group at all.
		const uid = `e2e-niet-balie-${Date.now()}`
		const password = `Pw-${Date.now()}-niet-balie!`
		const created = await request.post('/ocs/v2.php/cloud/users?format=json', {
			headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
			form: { userid: uid, password },
		})
		expect(created.ok(), 'the Nextcloud user API must accept a new user').toBeTruthy()

		try {
			const desk = await request.get(
				`${API_BASE}/session/desk?portal=dev-portal&route=${encodeURIComponent(route)}`,
				{
					headers: {
						Authorization: `Basic ${Buffer.from(`${uid}:${password}`).toString('base64')}`,
					},
					maxRedirects: 0,
				},
			)
			expect(desk.status()).toBe(403)
			expect(await desk.json()).toEqual({ error: 'not_desk_staff' })
		} finally {
			await request.delete(`/ocs/v2.php/cloud/users/${uid}?format=json`, {
				headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
			})
		}

		// A resident who names an applicant is refused, not ignored.
		const sanne = await residentToken(request, 'Sanne de Vries')
		const named = await submit(
			request,
			{
				route,
				answers: { onderwerp: 'Paspoort' },
				onBehalf: { kind: 'bsn', value: '999993653' },
			},
			sanne,
		)
		expect(named.status()).toBe(403)
		expect(await named.json()).toEqual({ error: 'staff_mode_refused' })

		// The BSN lookup is staff-only too.
		const lookUp = await request.post(`${API_BASE}/intake/on-behalf`, {
			headers: { Authorization: `Bearer ${sanne}` },
			data: { route, kind: 'bsn', value: '999993653' },
		})
		expect(lookUp.status()).toBe(403)
		expect(await lookUp.json()).toEqual({ error: 'staff_mode_refused' })
	})
})
