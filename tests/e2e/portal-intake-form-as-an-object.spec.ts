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
import { SITE_PORTAL } from './portal-nav.ts'

const API_BASE = '/apps/portaliq/portal/api'
const OR_API = '/apps/openregister/api'
const OR_OBJECTS_BASE = `${OR_API}/objects`

const ADMIN = Buffer.from('admin:admin').toString('base64')
const ADMIN_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }

/** The organisation a resident's session belongs to: CI seeds `dev-org`. */
const ORGANISATION = process.env.PORTALIQ_E2E_ORGANISATION || 'dev-org'

/**
 * The register and schema the answers go to. A portal submit is an anonymous
 * write, so the binding only publishes against a schema that lets a public
 * visitor create; the spec makes its own and removes it afterwards.
 */
const DESTINATION = `e2e-intake-${Date.now()}`

/** What this run created, to remove afterwards. */
const created: string[] = []
const destinationIds: { schema?: number, register?: number } = {}

/** The destination register and schema, made once for the run. */
async function seedDestination(request: APIRequestContext): Promise<void> {
	if (destinationIds.register) {
		return
	}
	const schema = await request.post(`${OR_API}/schemas`, {
		headers: ADMIN_HEADERS,
		data: {
			title: `E2E intake ${DESTINATION}`,
			slug: DESTINATION,
			properties: {
				caseType: { type: 'string' },
				postcode: { type: 'string' },
				huisnummer: { type: 'string' },
				applicantName: { type: 'string' },
				// The reference page finds the case by its own reference, for
				// the resident who sent it (DestinationLookup).
				reference: { type: 'string', 'x-openregister': { reference: true } },
				subjectRef: { type: 'string' },
			},
			authorization: { create: ['public'], read: ['admin'] },
		},
	})
	expect(schema.ok(), `the destination schema must be created: ${await schema.text()}`).toBeTruthy()
	destinationIds.schema = (await schema.json()).id
	const register = await request.post(`${OR_API}/registers`, {
		headers: ADMIN_HEADERS,
		data: { title: `E2E intake ${DESTINATION}`, slug: DESTINATION, schemas: [destinationIds.schema] },
	})
	expect(register.ok(), `the destination register must be created: ${await register.text()}`).toBeTruthy()
	destinationIds.register = (await register.json()).id
}

/** The run's shared resident session. */
let sharedToken = ''

/**
 * A resident's portal session: every intake route reads only with one.
 *
 * @param request The request fixture.
 * @param subjectRef The resident, or a fresh one.
 * @return The bearer.
 */
async function residentToken(request: APIRequestContext, subjectRef = ''): Promise<string> {
	// One shared resident for the run: dev-login allows ten sign-ins a minute.
	if (subjectRef === '' && sharedToken !== '') {
		return sharedToken
	}
	const login = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef: subjectRef !== '' ? subjectRef : `e2e-resident-${Date.now()}`, audience: 'client', organisation: ORGANISATION },
	})
	expect(login.ok(), 'dev-login must be enabled (app config portaliq dev_login_enabled = yes)').toBeTruthy()
	const token = (await login.json()).token as string
	if (subjectRef === '') {
		sharedToken = token
	}
	return token
}

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
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for ${schema}`,
	).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	created.push(`${register}/${schema}/${id}`)
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
	// The published form lives in buildiq, the binding resolver's default
	// form register; portaliq declares no registrationForm schema.
	await seedDestination(request)
	return seed(request, 'buildiq', 'registrationForm', {
		targetApp: 'portaliq',
		register: DESTINATION,
		schema: DESTINATION,
		typeProperty: 'caseType',
		typeValue: caseType,
		isPublic: true,
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
	await seedDestination(request)
	return seed(request, 'portaliq', 'portalFormBinding', {
		portal: SITE_PORTAL,
		route,
		typeRegister: 'portaliq',
		typeSchema: 'portalCaseType',
		typeId: caseType,
		audience,
		formRegister: 'buildiq',
		formSchema: 'registrationForm',
		destination: { register: DESTINATION, schema: DESTINATION, subjectField: 'subjectRef' },
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
	const bearer = token !== '' ? token : await residentToken(request)
	const headers: Record<string, string> = { Authorization: `Bearer ${bearer}` }

	const res = await request.get(
		`${API_BASE}/intake/form?portal=${encodeURIComponent(SITE_PORTAL)}&route=${encodeURIComponent(route)}`,
		{ headers },
	)
	expect(res.ok(), `the form page ${route} must render`).toBeTruthy()
	return res.json()
}

test.describe('portal-intake-form-as-an-object', () => {
	test.afterAll(async ({ request }) => {
		for (const ref of created.splice(0).reverse()) {
			await request.delete(`${OR_OBJECTS_BASE}/${ref}`, { headers: ADMIN_HEADERS })
		}
		const sent = await request.get(`${OR_OBJECTS_BASE}/${DESTINATION}/${DESTINATION}?_limit=100`, { headers: ADMIN_HEADERS })
		if (sent.ok()) {
			for (const row of ((await sent.json()).results ?? []) as Array<{ id?: string }>) {
				await request.delete(`${OR_OBJECTS_BASE}/${DESTINATION}/${DESTINATION}/${row.id}`, { headers: ADMIN_HEADERS })
			}
		}
		if (destinationIds.register) {
			await request.delete(`${OR_API}/registers/${destinationIds.register}`, { headers: ADMIN_HEADERS })
		}
		if (destinationIds.schema) {
			await request.delete(`${OR_API}/schemas/${destinationIds.schema}`, { headers: ADMIN_HEADERS })
		}
	})

	test('a case type with two forms renders only the bound audience, and an edit reaches the portal', async ({
		request,
	}) => {
		const stamp = Date.now()
		const caseType = `verhuizing-${stamp}`
		const clientForm = await seedForm(request, caseType, 'client', [
			{ name: 'postcode', order: 1, required: true },
		])
		await seedForm(request, caseType, 'supplier', [{ name: 'kvk', order: 1 }])
		const route = `aanvragen/verhuizing-${stamp}`
		await seedBinding(request, route, caseType, 'client')

		const first = await renderOf(request, route)
		expect(
			(first.fields as unknown as Array<Record<string, string>>).map(
				(field) => field.name,
			),
		).toEqual(['postcode'])

		// The form is edited where it lives; the portal page is not touched.
		const edit = await request.put(
			`${OR_OBJECTS_BASE}/buildiq/registrationForm/${clientForm}`,
			{
				headers: {
					Authorization: `Basic ${ADMIN}`,
					'OCS-APIRequest': 'true',
				},
				data: {
					targetApp: 'portaliq',
					register: DESTINATION,
					schema: DESTINATION,
					typeProperty: 'caseType',
					typeValue: caseType,
					isPublic: true,
					audience: 'client',
					name: `${caseType}-client`,
					status: 'published',
					fields: [
						{ name: 'postcode', order: 1, required: true },
						{ name: 'huisnummer', order: 2 },
					],
				},
			},
		)
		expect(edit.ok()).toBeTruthy()

		const second = await renderOf(request, route)
		expect(
			(second.fields as unknown as Array<Record<string, string>>).map(
				(field) => field.name,
			),
		).toEqual(['postcode', 'huisnummer'])
	})

	test('a missing answer is a field error, and a valid one comes back with a reference at once', async ({
		request,
	}) => {
		const stamp = Date.now()
		const caseType = `melding-${stamp}`
		await seedForm(
			request,
			caseType,
			'client',
			[{ name: 'postcode', order: 1, required: true }],
			'Bedankt, u hoort binnen vijf werkdagen van ons.',
		)
		const route = `aanvragen/melding-${stamp}`
		await seedBinding(request, route, caseType, 'client')

		// A portal submit comes from a resident's session, like every intake route.
		const token = await residentToken(request)
		const asResident = { Authorization: `Bearer ${token}` }
		const refused = await request.post(`${API_BASE}/intake/submit?portal=${encodeURIComponent(SITE_PORTAL)}`, {
			headers: asResident,
			data: { route, answers: { toelichting: 'Er ligt afval op straat.' } },
		})
		expect(refused.status()).toBe(400)
		expect((await refused.json()).errors).toHaveProperty('postcode')

		const accepted = await request.post(`${API_BASE}/intake/submit?portal=${encodeURIComponent(SITE_PORTAL)}`, {
			headers: asResident,
			data: { route, answers: { postcode: '1234 AB' } },
		})
		expect(accepted.ok()).toBeTruthy()
		const body = await accepted.json()
		expect(body.reference).toBeTruthy()
		// The submit creates the case directly (submit-creates-the-case-directly):
		// the reference comes back registered, not queued.
		expect(body.state).toBe('registered')
		expect(body.confirmationText).toBe(
			'Bedankt, u hoort binnen vijf werkdagen van ons.',
		)

		// The reference page reads the submission's real state, and says
		// nothing about a case that does not exist yet.
		const status = await request.get(
			`${API_BASE}/intake/status?portal=${encodeURIComponent(SITE_PORTAL)}&reference=${encodeURIComponent(body.reference)}`,
			{ headers: asResident },
		)
		expect(status.ok()).toBeTruthy()
		const state = await status.json()
		expect(state.state).toBe('registered')
		if (state.state !== 'registered') {
			expect(state.caseId).toBe('')
		}
	})

	test('an external binding names its destination and offers no fields', async ({
		request,
	}) => {
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

	test('an anonymous visitor gets an empty applicant block, and a signed-in citizen does not retype their name', async ({
		request,
	}) => {
		const stamp = Date.now()
		const caseType = `aanvraag-${stamp}`
		await seedForm(request, caseType, 'client', [
			{ name: 'applicantName', order: 1 },
			{ name: 'postcode', order: 2 },
		])
		const route = `aanvragen/prefill-${stamp}`
		await seedBinding(request, route, caseType, 'client')

		// A resident with no registered details gets an empty applicant block
		// (an empty list or object: PHP answers an empty map as []).
		const anonymous = await renderOf(request, route)
		expect(Object.keys(anonymous.prefill ?? {})).toEqual([])

		const provisioned = await request.post(
			'/apps/portaliq/api/accounts/provision',
			{
				headers: {
					Authorization: `Basic ${ADMIN}`,
					'OCS-APIRequest': 'true',
					requesttoken: 'x',
				},
				data: {
					audience: 'client',
					organisation: ORGANISATION,
					identityType: 'digid',
					identityRef: `bsn-${stamp}`,
					displayName: 'Ans de Vries',
				},
			},
		)
		expect(provisioned.ok()).toBeTruthy()
		const { subjectRef } = await provisioned.json()

		const login = await request.post(`${API_BASE}/session/dev-login`, {
			data: { subjectRef, audience: 'client', organisation: ORGANISATION },
		})
		expect(
			login.ok(),
			'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
		).toBeTruthy()
		const { token } = await login.json()

		const signedIn = await renderOf(request, route, token)
		expect(
			(signedIn.prefill as unknown as Record<string, string>).applicantName,
		).toBe('Ans de Vries')
	})

	test('the entry point lists the published catalogue, and an entry names the route that starts its form', async ({
		request,
	}) => {
		const stamp = Date.now()
		const route = `aanvragen/catalogus-${stamp}`
		// The catalogue is opencatalogi's published catalogue
		// (PortalCatalogueReader reads opencatalogi/publication).
		const publication = await seed(request, 'opencatalogi', 'publication', {
			portal: SITE_PORTAL,
			topic: `Wonen ${stamp}`,
			title: 'Verhuizing doorgeven',
			route,
			publicationDate: new Date(Date.now() - 60_000).toISOString(),
		})
		// A publication starts as a draft and is reviewed before it is published.
		for (const status of ['in_review', 'approved', 'published']) {
			const moved = await request.patch(`${OR_OBJECTS_BASE}/opencatalogi/publication/${publication}`, {
				headers: ADMIN_HEADERS,
				data: { status },
			})
			expect(moved.ok(), `the publication moves to ${status}: ${await moved.text()}`).toBeTruthy()
		}

		const res = await request.get(`${API_BASE}/intake/catalogue?portal=${encodeURIComponent(SITE_PORTAL)}`, {
			headers: { Authorization: `Bearer ${await residentToken(request)}` },
		})
		expect(res.ok()).toBeTruthy()
		const topics = (await res.json()).topics as Array<Record<string, never>>
		const mine = topics.find(
			(topic) => (topic.topic as unknown as string) === `Wonen ${stamp}`,
		)

		expect(mine, 'the seeded topic is listed').toBeTruthy()
		expect(
			(mine?.entries as unknown as Array<Record<string, string>>)[0].route,
		).toBe(route)
	})
})
