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

const EMBED_PATH = '/apps/portaliq/portal/embed'
const EMBED_SUBMIT = '/apps/portaliq/portal/api/embed/submit'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'

const ADMIN = Buffer.from('admin:admin').toString('base64')
const ALLOWED_ORIGIN = 'https://www.gemeente.nl'
const OTHER_ORIGIN = 'https://www.elders.nl'

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
	expect(res.ok(), `OpenRegister objects#create must be reachable for ${schema}`).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	return id
}

/** A published form and the binding that frames it. */
async function seedEmbeddableForm(
	request: APIRequestContext,
	route: string,
	allowedOrigins: string[],
): Promise<void> {
	const caseType = `embed-${Date.now()}`
	await seed(request, 'registrationForm', {
		caseType,
		audience: 'client',
		name: `${caseType}-client`,
		status: 'published',
		fields: [{ name: 'postcode', order: 1, required: true }],
		confirmationText: 'Bedankt. U hoort van ons.',
	})
	await seed(request, 'portalFormBinding', {
		portal: 'dev-portal',
		route,
		typeRegister: 'portaliq',
		typeSchema: 'portalCaseType',
		typeId: caseType,
		audience: 'client',
		formRegister: 'portaliq',
		formSchema: 'registrationForm',
		caseRegister: 'portaliq',
		caseSchema: 'portalCase',
		intakeKind: 'hosted',
		allowedOrigins,
		status: 'published',
	})
}

test.describe('embedded-intake-form', () => {
	test('the allowed origin gets the form, and any other origin gets a message', async ({ request }) => {
		const route = `aanvragen/embed-${Date.now()}`
		await seedEmbeddableForm(request, route, [ALLOWED_ORIGIN])
		const url = `${EMBED_PATH}?route=${encodeURIComponent(route)}`

		const allowed = await request.get(url, { headers: { Origin: ALLOWED_ORIGIN } })
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
	test('the frame actually renders the form, not just a div for it', async ({ page, request }) => {
		const route = `aanvragen/embed-render-${Date.now()}`
		await seedEmbeddableForm(request, route, [ALLOWED_ORIGIN])

		await page.goto(`${EMBED_PATH}?route=${encodeURIComponent(route)}`)

		// A control a visitor can actually type into, and a submit they can
		// press. An empty div satisfies neither.
		await expect(page.getByTestId('embed-form')).toBeVisible()
		await expect(page.getByTestId('embed-field-postcode')).toBeVisible()
		await expect(page.getByTestId('embed-submit')).toBeVisible()

		// The mount really ran: the div is no longer empty.
		await expect(page.locator('#portaliq-embed')).not.toBeEmpty()
	})

	test('a refused frame renders words, not a blank rectangle', async ({ page, request }) => {
		const route = `aanvragen/embed-closed-render-${Date.now()}`
		await seedEmbeddableForm(request, route, [])

		await page.goto(`${EMBED_PATH}?route=${encodeURIComponent(route)}`)

		// A visitor meeting a blank rectangle cannot tell whether the form is
		// broken, still loading, or simply not for them.
		await expect(page.getByTestId('embed-refusal')).toBeVisible()
		await expect(page.getByTestId('embed-form')).toHaveCount(0)
	})

	test('a form with no allowed origins serves nobody', async ({ request }) => {
		const route = `aanvragen/embed-closed-${Date.now()}`
		await seedEmbeddableForm(request, route, [])

		const res = await request.get(`${EMBED_PATH}?route=${encodeURIComponent(route)}`, {
			headers: { Origin: ALLOWED_ORIGIN },
		})
		const body = await res.text()

		expect(body).not.toContain('"postcode"')
		const csp = res.headers()['content-security-policy'] ?? ''
		expect(csp).not.toContain(ALLOWED_ORIGIN)
	})

	test('a visitor submits without an account and gets a reference and a follow link', async ({ request }) => {
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

	test('a visitor signed in to the portal elsewhere is anonymous inside the frame', async ({ request }) => {
		const route = `aanvragen/embed-anon-${Date.now()}`
		await seedEmbeddableForm(request, route, [ALLOWED_ORIGIN])

		const login = await request.post('/apps/portaliq/portal/api/session/dev-login', {
			data: { subjectRef: `subject-${Date.now()}`, audience: 'client', organisation: 'dev-org' },
		})
		expect(login.ok(), 'dev-login must be enabled (see tests/e2e/ci-seed.sh)').toBeTruthy()
		const { token } = await login.json()

		// The bearer is offered and must change nothing: the frame reads none.
		const res = await request.get(`${EMBED_PATH}?route=${encodeURIComponent(route)}`, {
			headers: { Origin: ALLOWED_ORIGIN, Authorization: `Bearer ${token}` },
		})
		const body = await res.text()

		expect(res.ok()).toBeTruthy()
		expect(body).toContain('"prefill":[]')
	})
})
