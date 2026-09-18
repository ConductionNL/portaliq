/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for portal-intake-form-as-an-object: a citizen finds the request
 * they need in the catalogue, fills in the form that belongs to it, and gets a
 * reference at once. The form is its own object, published for the case type,
 * and the portal renders whatever it says today.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here:
 *   - one case type carries two forms and only the bound audience's renders
 *     (REQ-PIFO-001)
 *   - an edited form reaches the portal without a portal change (REQ-PIFO-001)
 *   - the confirmation text is the form's (REQ-PIFO-001)
 *   - an external binding names its destination (REQ-PIFO-002)
 *   - a signed-in citizen does not retype their name, an anonymous visitor
 *     gets an empty block (REQ-PIFO-003)
 *   - a missing required answer is a field error (REQ-PIFO-004)
 *   - the citizen is not held on the page, and a failed create is visible
 *     (REQ-PIFO-005)
 *   - the entry point lists the published catalogue, and an entry starts the
 *     right form (REQ-PIFO-006)
 *
 * NOT anchored here, covered by PHPUnit and named so anyone can check:
 *   - that nothing is fetched from an external host, and that refusal happens
 *     before any create: tests/Unit/Controller/PortalIntakeControllerTest.php
 *     (testAnExternalBindingAcceptsNoSubmission,
 *     testAnInvalidSubmissionIsRefusedBeforeAnythingIsRecorded) and
 *     tests/Unit/Service/Intake/PortalFormBindingResolverTest.php
 *   - that prefill never reads another identity:
 *     tests/Unit/Service/Intake/PortalApplicantPrefillTest.php
 *     (testPrefillNeverReadsAnotherIdentity, testAReaderThatAnswersSomebodyElseIsIgnored)
 *   - the delivery job's failure paths:
 *     tests/Unit/BackgroundJob/PortalIntakeDeliveryJobTest.php
 *
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions. Run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test portal-intake-form
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'

const ADMIN = Buffer.from('admin:admin').toString('base64')

/** Create one object through OpenRegister's own object API, as the dev admin. */
async function seed(
	request: APIRequestContext,
	register: string,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await request.post(`${OR_OBJECTS_BASE}/${register}/${schema}`, {
		headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
		data,
	})
	expect(res.ok(), `OpenRegister objects#create must be reachable for ${schema}`).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	return id
}

/** A published form for one audience of one case type. */
async function seedForm(
	request: APIRequestContext,
	caseType: string,
	audience: string,
	fields: Array<Record<string, unknown>>,
	confirmationText = '',
): Promise<string> {
	return seed(request, 'portaliq', 'registrationForm', {
		caseType,
		audience,
		name: `${caseType}-${audience}`,
		status: 'published',
		fields,
		confirmationText,
	})
}

/** A published binding on a portal route. */
async function seedBinding(
	request: APIRequestContext,
	route: string,
	caseType: string,
	audience: string,
	extra: Record<string, unknown> = {},
): Promise<string> {
	return seed(request, 'portaliq', 'portalFormBinding', {
		portal: 'dev-portal',
		route,
		typeRegister: 'portaliq',
		typeSchema: 'portalCaseType',
		typeId: caseType,
		audience,
		formRegister: 'portaliq',
		formSchema: 'registrationForm',
		caseRegister: 'portaliq',
		caseSchema: 'portalCase',
		intakeKind: 'hosted',
		status: 'published',
		...extra,
	})
}

/** What the portal renders for one route. */
async function renderOf(
	request: APIRequestContext,
	route: string,
	token = '',
): Promise<Record<string, never>> {
	const headers: Record<string, string> = {}
	if (token !== '') {
		headers.Authorization = `Bearer ${token}`
	}

	const res = await request.get(`${API_BASE}/intake/form?route=${encodeURIComponent(route)}`, { headers })
	expect(res.ok(), `the form page ${route} must render`).toBeTruthy()
	return res.json()
}

test.describe('portal-intake-form-as-an-object', () => {
	test('a case type with two forms renders only the bound audience, and an edit reaches the portal', async ({ request }) => {
		const stamp = Date.now()
		const caseType = `verhuizing-${stamp}`
		const clientForm = await seedForm(request, caseType, 'client', [{ name: 'postcode', order: 1, required: true }])
		await seedForm(request, caseType, 'supplier', [{ name: 'kvk', order: 1 }])
		const route = `aanvragen/verhuizing-${stamp}`
		await seedBinding(request, route, caseType, 'client')

		const first = await renderOf(request, route)
		expect((first.fields as unknown as Array<Record<string, string>>).map((field) => field.name)).toEqual(['postcode'])

		// The form is edited where it lives; the portal page is not touched.
		const edit = await request.put(`${OR_OBJECTS_BASE}/portaliq/registrationForm/${clientForm}`, {
			headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
			data: {
				caseType,
				audience: 'client',
				name: `${caseType}-client`,
				status: 'published',
				fields: [
					{ name: 'postcode', order: 1, required: true },
					{ name: 'huisnummer', order: 2 },
				],
			},
		})
		expect(edit.ok()).toBeTruthy()

		const second = await renderOf(request, route)
		expect((second.fields as unknown as Array<Record<string, string>>).map((field) => field.name))
			.toEqual(['postcode', 'huisnummer'])
	})

	test('a missing answer is a field error, and a valid one comes back with a reference at once', async ({ request }) => {
		const stamp = Date.now()
		const caseType = `melding-${stamp}`
		await seedForm(request, caseType, 'client', [{ name: 'postcode', order: 1, required: true }], 'Bedankt, u hoort binnen vijf werkdagen van ons.')
		const route = `aanvragen/melding-${stamp}`
		await seedBinding(request, route, caseType, 'client')

		const refused = await request.post(`${API_BASE}/intake/submit`, {
			data: { route, answers: { toelichting: 'Er ligt afval op straat.' } },
		})
		expect(refused.status()).toBe(400)
		expect((await refused.json()).errors).toHaveProperty('postcode')

		const accepted = await request.post(`${API_BASE}/intake/submit`, {
			data: { route, answers: { postcode: '1234 AB' } },
		})
		expect(accepted.ok()).toBeTruthy()
		const body = await accepted.json()
		expect(body.reference).toBeTruthy()
		expect(body.state).toBe('queued')
		expect(body.confirmationText).toBe('Bedankt, u hoort binnen vijf werkdagen van ons.')

		// The reference page reads the submission's real state, and says
		// nothing about a case that does not exist yet.
		const status = await request.get(`${API_BASE}/intake/status?reference=${encodeURIComponent(body.reference)}`)
		expect(status.ok()).toBeTruthy()
		const state = await status.json()
		expect(['queued', 'registered', 'failed']).toContain(state.state)
		if (state.state !== 'registered') {
			expect(state.caseId).toBe('')
		}
	})

	test('an external binding names its destination and offers no fields', async ({ request }) => {
		const stamp = Date.now()
		const route = `aanvragen/extern-${stamp}`
		await seedBinding(request, route, `extern-${stamp}`, 'client', {
			intakeKind: 'external',
			externalUrl: 'https://formulieren.example.org/verhuizing',
		})

		const render = await renderOf(request, route)

		expect(render.kind).toBe('external')
		expect(render.destination).toBe('formulieren.example.org')
		expect(render.fields).toBeUndefined()
	})

	test('an anonymous visitor gets an empty applicant block, and a signed-in citizen does not retype their name', async ({ request }) => {
		const stamp = Date.now()
		const caseType = `aanvraag-${stamp}`
		await seedForm(request, caseType, 'client', [
			{ name: 'applicantName', order: 1 },
			{ name: 'postcode', order: 2 },
		])
		const route = `aanvragen/prefill-${stamp}`
		await seedBinding(request, route, caseType, 'client')

		const anonymous = await renderOf(request, route)
		expect(anonymous.prefill).toEqual({})

		const provisioned = await request.post('/apps/portaliq/api/accounts/provision', {
			headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true', requesttoken: 'x' },
			data: {
				audience: 'client',
				organisation: 'dev-org',
				identityType: 'digid',
				identityRef: `bsn-${stamp}`,
				displayName: 'Ans de Vries',
			},
		})
		expect(provisioned.ok()).toBeTruthy()
		const { subjectRef } = await provisioned.json()

		const login = await request.post(`${API_BASE}/session/dev-login`, {
			data: { subjectRef, audience: 'client', organisation: 'dev-org' },
		})
		expect(login.ok(), 'dev-login must be enabled (see tests/e2e/ci-seed.sh)').toBeTruthy()
		const { token } = await login.json()

		const signedIn = await renderOf(request, route, token)
		expect((signedIn.prefill as unknown as Record<string, string>).applicantName).toBe('Ans de Vries')
	})

	test('the entry point lists the published catalogue, and an entry names the route that starts its form', async ({ request }) => {
		const stamp = Date.now()
		const route = `aanvragen/catalogus-${stamp}`
		await seed(request, 'portaliq', 'publication', {
			portal: 'dev-portal',
			topic: `Wonen ${stamp}`,
			title: 'Verhuizing doorgeven',
			route,
			status: 'published',
		})

		const res = await request.get(`${API_BASE}/intake/catalogue`)
		expect(res.ok()).toBeTruthy()
		const topics = (await res.json()).topics as Array<Record<string, never>>
		const mine = topics.find((topic) => (topic.topic as unknown as string) === `Wonen ${stamp}`)

		expect(mine, 'the seeded topic is listed').toBeTruthy()
		expect((mine?.entries as unknown as Array<Record<string, string>>)[0].route).toBe(route)
	})
})
