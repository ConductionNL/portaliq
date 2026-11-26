/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for embedded-intake-form: a municipality frames a portal intake
 * form on its own website, a visitor submits without an account and gets a
 * reference, and a page on any other origin gets a message instead of a form.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here:
 *   - a disallowed origin gets no form (REQ-EIF-002)
 *   - a form with no allowed origins serves nobody (REQ-EIF-001)
 *   - a submission arrives as a case and records its origin (REQ-EIF-003)
 *   - validation is the schema's, not the frame's (REQ-EIF-003)
 *   - a visitor submits without an account and gets a reference and a follow
 *     link, and is never asked to sign in (REQ-EIF-004)
 *   - a signed-in visitor is anonymous inside the frame (REQ-EIF-005)
 *   - the frame RENDERS the form, in a real browser (embed-frame-renders-the-form)
 *   - a frame without Origin is judged by its Referer's origin (REQ-EIF-006)
 *
 * NOT anchored here, covered by PHPUnit and named so anyone can check:
 *   - that the refusal reads no schema:
 *     tests/Unit/Controller/PortalEmbedControllerTest.php
 *     (testADisallowedOriginIsRefusedBeforeTheFormIsRead)
 *   - that the frame sets no cookie and carries no session at all:
 *     same file (testTheFrameSetsNoCookie, testTheControllerHasNoSessionToRead)
 *   - the throttle: tests/Unit/Service/Intake/PortalEmbedThrottleTest.php
 *
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions. Run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test embedded-intake-form
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { SITE_PORTAL } from './portal-nav.ts'

const EMBED_PATH = '/apps/portaliq/portal/embed'
const EMBED_SUBMIT = '/apps/portaliq/portal/api/embed/submit'
const OR_API = '/apps/openregister/api'
const OR_OBJECTS_BASE = `${OR_API}/objects`

const ADMIN = Buffer.from('admin:admin').toString('base64')
const ALLOWED_ORIGIN = 'https://www.gemeente.nl'
const OTHER_ORIGIN = 'https://www.elders.nl'
const ADMIN_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }

/** The organisation a resident's session belongs to: CI seeds `dev-org`. */
const ORGANISATION = process.env.PORTALIQ_E2E_ORGANISATION || 'dev-org'

/**
 * The register and schema the answers go to. An embedded submit is an
 * anonymous write, so the binding only publishes against a schema that lets a
 * public visitor create; the spec makes its own (and removes it afterwards)
 * rather than opening a product schema to anonymous writes.
 */
const DESTINATION = `e2e-embed-${Date.now()}`

/** What this run created, to remove afterwards. */
const created: string[] = []
const destinationIds: { schema?: number, register?: number } = {}

/**
 * Create one object through OpenRegister's own object API, as the dev admin.
 * The published form lives in buildiq, the binding resolver's default form
 * register; portaliq declares no `registrationForm` schema.
 */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
	register = 'portaliq',
): Promise<string> {
	const res = await request.post(`${OR_OBJECTS_BASE}/${register}/${schema}`, {
		headers: ADMIN_HEADERS,
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

/** The destination register and schema, made once for the run. */
async function seedDestination(request: APIRequestContext): Promise<void> {
	if (destinationIds.register) {
		return
	}
	const schema = await request.post(`${OR_API}/schemas`, {
		headers: ADMIN_HEADERS,
		data: {
			title: `E2E embed ${DESTINATION}`,
			slug: DESTINATION,
			properties: { caseType: { type: 'string' }, postcode: { type: 'string' }, origin: { type: 'string' } },
			required: ['postcode'],
			authorization: { create: ['public'], read: ['admin'] },
		},
	})
	expect(schema.ok(), `the destination schema must be created: ${await schema.text()}`).toBeTruthy()
	destinationIds.schema = (await schema.json()).id
	const register = await request.post(`${OR_API}/registers`, {
		headers: ADMIN_HEADERS,
		data: { title: `E2E embed ${DESTINATION}`, slug: DESTINATION, schemas: [destinationIds.schema] },
	})
	expect(register.ok(), `the destination register must be created: ${await register.text()}`).toBeTruthy()
	destinationIds.register = (await register.json()).id
}

/** A published form and the binding that frames it. */
async function seedEmbeddableForm(
	request: APIRequestContext,
	route: string,
	allowedOrigins: string[],
): Promise<void> {
	await seedDestination(request)
	const caseType = `embed-${Date.now()}`
	await seed(request, 'registrationForm', {
		targetApp: 'portaliq',
		register: DESTINATION,
		schema: DESTINATION,
		typeProperty: 'caseType',
		typeValue: caseType,
		isPublic: true,
		audience: 'client',
		name: `${caseType}-client`,
		status: 'published',
		fields: [{ name: 'postcode', order: 1, required: true }],
		confirmationText: 'Bedankt. U hoort van ons.',
	}, 'buildiq')
	await seed(request, 'portalFormBinding', {
		portal: SITE_PORTAL,
		route,
		typeRegister: 'portaliq',
		typeSchema: 'portalCaseType',
		typeId: caseType,
		audience: 'client',
		formRegister: 'buildiq',
		formSchema: 'registrationForm',
		destination: { register: DESTINATION, schema: DESTINATION },
		intakeKind: 'hosted',
		allowedOrigins,
		status: 'published',
	})
}

test.describe('embedded-intake-form', () => {
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

	test('the allowed origin gets the form, and any other origin gets a message', async ({
		request,
	}) => {
		const route = `aanvragen/embed-${Date.now()}`
		await seedEmbeddableForm(request, route, [ALLOWED_ORIGIN])
		const url = `${EMBED_PATH}?route=${encodeURIComponent(route)}`

		const allowed = await request.get(url, {
			headers: { Origin: ALLOWED_ORIGIN },
		})
		expect(allowed.ok()).toBeTruthy()
		// The frame names its one ancestor, and never a wildcard.
		const csp = allowed.headers()['content-security-policy'] ?? ''
		expect(csp).toContain(ALLOWED_ORIGIN)
		expect(csp).not.toContain('frame-ancestors *')

		const refused = await request.get(url, { headers: { Origin: OTHER_ORIGIN } })
		const refusedBody = await refused.text()
		expect(refusedBody).not.toContain('"postcode"')
	})

	// 🔴 THIS TEST REPLACES ONE THAT COULD NOT FAIL FOR THE THING IT CHECKED.
	// It used to fetch the page with `request.get` and assert the HTML
	// contained the string 'portaliq-embed'. That is a raw HTTP call: no
	// browser, no JavaScript. It proved the route served a div and nothing
	// whatsoever about a form appearing, which is why the frame shipped
	// rendering an empty div with its route, its policy and its refusals all
	// correct and all green.
	//
	// Everything below needs a real page, because the defect was invisible to
	// anything that did not run the bundle.
	// @e2e embedded-intake-form::a-frame-on-an-allowed-site-renders-the-form
	test('the frame actually renders the form, not just a div for it', async ({
		page,
		request,
	}) => {
		const route = `aanvragen/embed-render-${Date.now()}`
		await seedEmbeddableForm(request, route, [ALLOWED_ORIGIN])

		// A browser sends no Origin on an iframe's document GET, only the
		// Referer of the framing page: its origin is judged by the same list
		// (embed-frame-reads-the-referer-origin, REQ-EIF-006).
		await page.goto(`${EMBED_PATH}?route=${encodeURIComponent(route)}`, {
			referer: `${ALLOWED_ORIGIN}/loket/verhuizen`,
		})

		// A control a visitor can actually type into, and a submit they can
		// press. An empty div satisfies neither.
		await expect(page.getByTestId('embed-form')).toBeVisible()
		await expect(page.getByTestId('embed-field-postcode')).toBeVisible()
		await expect(page.getByTestId('embed-submit')).toBeVisible()

		// The mount really ran: the div is no longer empty.
		await expect(page.locator('#portaliq-embed')).not.toBeEmpty()
	})

	// @e2e embedded-intake-form::a-frame-on-another-site-gets-a-message
	test('a frame on a page of another origin gets words, judged by its Referer', async ({
		page,
		request,
	}) => {
		const route = `aanvragen/embed-other-referer-${Date.now()}`
		await seedEmbeddableForm(request, route, [ALLOWED_ORIGIN])

		await page.goto(`${EMBED_PATH}?route=${encodeURIComponent(route)}`, {
			referer: `${OTHER_ORIGIN}/www.gemeente.nl`,
		})

		await expect(page.getByTestId('embed-refusal')).toBeVisible()
		await expect(page.getByTestId('embed-field-postcode')).toHaveCount(0)
	})

	test('a refused frame renders words, not a blank rectangle', async ({
		page,
		request,
	}) => {
		const route = `aanvragen/embed-closed-render-${Date.now()}`
		await seedEmbeddableForm(request, route, [])

		await page.goto(`${EMBED_PATH}?route=${encodeURIComponent(route)}`)

		// A visitor meeting a blank rectangle cannot tell whether the form is
		// broken, still loading, or simply not for them.
		await expect(page.getByTestId('embed-refusal')).toBeVisible()
		await expect(page.getByTestId('embed-form')).toHaveCount(0)
	})

	// @e2e embedded-intake-form::no-origin-and-no-referer-serves-nobody
	test('without Origin or Referer the frame serves nobody', async ({ request }) => {
		const route = `aanvragen/embed-no-origin-${Date.now()}`
		await seedEmbeddableForm(request, route, [ALLOWED_ORIGIN])

		const res = await request.get(`${EMBED_PATH}?route=${encodeURIComponent(route)}`)

		expect(await res.text()).not.toContain('"postcode"')
	})

	// @e2e embedded-intake-form::an-origin-header-wins-over-the-referer
	test('an Origin header that is sent wins over an allowed Referer', async ({ request }) => {
		const route = `aanvragen/embed-origin-wins-${Date.now()}`
		await seedEmbeddableForm(request, route, [ALLOWED_ORIGIN])

		const res = await request.get(`${EMBED_PATH}?route=${encodeURIComponent(route)}`, {
			headers: { Origin: OTHER_ORIGIN, Referer: `${ALLOWED_ORIGIN}/loket/verhuizen` },
		})

		expect(await res.text()).not.toContain('"postcode"')
	})

	// @e2e embedded-intake-form::a-submission-records-the-origin-not-the-page
	test('a submission without Origin records the Referer origin, not the page', async ({ request }) => {
		const route = `aanvragen/embed-referer-submit-${Date.now()}`
		await seedEmbeddableForm(request, route, [ALLOWED_ORIGIN])
		const postcode = `${1000 + Math.floor(Math.random() * 8999)} RF`

		const accepted = await request.post(EMBED_SUBMIT, {
			headers: { Referer: `${ALLOWED_ORIGIN}/loket/verhuizen?bsn=1` },
			data: { route, answers: { postcode } },
		})
		expect(accepted.ok(), await accepted.text()).toBeTruthy()

		const sent = await request.get(`${OR_OBJECTS_BASE}/${DESTINATION}/${DESTINATION}?_limit=100`, { headers: ADMIN_HEADERS })
		const rows = ((await sent.json()).results ?? []) as Array<{ postcode?: string, origin?: string }>
		const row = rows.find((candidate) => candidate.postcode === postcode)
		expect(row?.origin).toBe(ALLOWED_ORIGIN)
	})

	test('a form with no allowed origins serves nobody', async ({ request }) => {
		const route = `aanvragen/embed-closed-${Date.now()}`
		await seedEmbeddableForm(request, route, [])

		const res = await request.get(
			`${EMBED_PATH}?route=${encodeURIComponent(route)}`,
			{
				headers: { Origin: ALLOWED_ORIGIN },
			},
		)
		const body = await res.text()

		expect(body).not.toContain('"postcode"')
		const csp = res.headers()['content-security-policy'] ?? ''
		expect(csp).not.toContain(ALLOWED_ORIGIN)
	})

	test('a visitor submits without an account and gets a reference and a follow link', async ({
		request,
	}) => {
		const route = `aanvragen/embed-submit-${Date.now()}`
		await seedEmbeddableForm(request, route, [ALLOWED_ORIGIN])

		// Validation is the schema's, not the frame's.
		const refused = await request.post(EMBED_SUBMIT, {
			headers: { Origin: ALLOWED_ORIGIN },
			data: { route, answers: { toelichting: 'Ik verhuis.' } },
		})
		expect(refused.status()).toBe(400)
		expect((await refused.json()).errors).toHaveProperty('postcode')

		const accepted = await request.post(EMBED_SUBMIT, {
			headers: { Origin: ALLOWED_ORIGIN },
			data: { route, answers: { postcode: '1234 AB' } },
		})
		expect(accepted.ok()).toBeTruthy()
		const body = await accepted.json()
		expect(body.reference).toBeTruthy()
		expect(body.followUrl).toContain(body.reference)
		// Nothing asked the visitor to sign in, and nothing handed them a session.
		expect(accepted.headers()['set-cookie']).toBeUndefined()

		const fromElsewhere = await request.post(EMBED_SUBMIT, {
			headers: { Origin: OTHER_ORIGIN },
			data: { route, answers: { postcode: '1234 AB' } },
		})
		expect(fromElsewhere.status()).toBe(403)
	})

	test('a visitor signed in to the portal elsewhere is anonymous inside the frame', async ({
		request,
	}) => {
		const route = `aanvragen/embed-anon-${Date.now()}`
		await seedEmbeddableForm(request, route, [ALLOWED_ORIGIN])

		const login = await request.post(
			'/apps/portaliq/portal/api/session/dev-login',
			{
				data: {
					subjectRef: `subject-${Date.now()}`,
					audience: 'client',
					organisation: ORGANISATION,
				},
			},
		)
		expect(
			login.ok(),
			'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
		).toBeTruthy()
		const { token } = await login.json()

		// The bearer is offered and must change nothing: the frame reads none.
		const res = await request.get(
			`${EMBED_PATH}?route=${encodeURIComponent(route)}`,
			{
				headers: {
					Origin: ALLOWED_ORIGIN,
					Authorization: `Bearer ${token}`,
				},
			},
		)
		const body = await res.text()

		expect(res.ok()).toBeTruthy()
		expect(body).toContain('"prefill":[]')
	})
})
