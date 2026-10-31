/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * landing-page-provisioning: a visitor submits a landing page's form with no
 * portal session, and the submit creates the form's destination object in
 * that request (decision 179). Nothing is stored in portaliq on the way.
 *
 * The fixture (tests/e2e/fixtures/seed-cms.sh) seeds the RESULT
 * `LandingPageProvisioningService` would have written: a `form` object that
 * names its destination, and a `page` at /campagne/e2e-form-test with a bound
 * `form` widget. The destination is the fixture's own `e2efixture/signup`
 * (tests/e2e/fixtures/e2e-signup-register.json), standing in for the lead of
 * the app that asked for the page, since no other app is installed here.
 *
 * Every test READS THE DESTINATION OBJECT BACK as an administrator. The
 * success message on the page only says the server answered; the object is
 * the proof that the visitor's answers arrived where staff will work on them.
 *
 * See tests/e2e/scenarios/ for the wider phase-two scenario catalogue this
 * file extends.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const PORTAL_API = `${BASE}/index.php/apps/portaliq/portal/api`
const OBJECTS = `${BASE}/index.php/apps/openregister/api/objects`
const ROUTE = '/campagne/e2e-form-test'

/** The address the public form posts to. Only an address: nothing is stored there. */
const SUBMIT_ADDRESS = `${PORTAL_API}/collections/portaliq/landingPageSubmission`

/** Where a submit of the fixture form must arrive. */
const DESTINATION = { register: 'e2efixture', schema: 'signup' }

/** Nextcloud admin, as exported by the shared quality.yml Playwright step. */
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

type Stored = Record<string, unknown> & { '@self'?: { id?: string } }

/**
 * An admin API context with its own cookie jar.
 *
 * @return the context; the caller disposes it
 */
async function adminApi(): Promise<APIRequestContext> {
	const basic = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')

	return await request.newContext({
		baseURL: BASE,
		// Nextcloud short-circuits its CSRF check on this header, and a
		// Basic-auth request carries no session cookie, so the precondition
		// for that short-circuit holds.
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Authorization: `Basic ${basic}`,
		},
	})
}

/**
 * Every object of a schema, read as an administrator.
 *
 * `_drafts=any` because a list hides drafts unless asked (decision 201): a
 * count that left them out could not tell "nothing was stored" from "it was
 * stored as a draft". The filter is applied HERE, not in the query string:
 * OpenRegister ignores a filter key it does not know, and an ignored filter
 * returns every row looking exactly like a correct result.
 *
 * @param admin the admin context
 * @param register the register slug
 * @param schema the schema slug
 * @param where keeps a row
 * @return the matching rows
 */
async function stored(
	admin: APIRequestContext,
	register: string,
	schema: string,
	where: (row: Stored) => boolean,
): Promise<Stored[]> {
	const response = await admin.get(
		`${OBJECTS}/${register}/${schema}?_limit=500&_drafts=any`,
	)
	expect(
		response.status(),
		`${register}/${schema} must be readable by the administrator; a 404 here means the fixture register was not imported (tests/e2e/fixtures/seed-cms.sh)`,
	).toBe(200)
	const body = await response.json()
	expect(
		Array.isArray(body.results),
		`${register}/${schema} answered no result list`,
	).toBe(true)

	return (body.results as Stored[]).filter(where)
}

/**
 * The fixture form, with the id the public action is named after.
 *
 * Fails with the reason when the form on this instance names no destination:
 * seed-cms.sh only creates and never updates, so a long-lived instance keeps
 * the form an older seed made, and such a form cannot be submitted.
 *
 * @param admin the admin context
 * @return the form's id
 */
async function fixtureFormId(admin: APIRequestContext): Promise<string> {
	const forms = await stored(
		admin,
		'portaliq',
		'form',
		(row) => row.pageRoute === ROUTE && row.portal === 'open-tilburg',
	)
	expect(forms.length, `exactly one form is seeded at ${ROUTE}`).toBe(1)
	const destination = forms[0].destination as
		{ register?: string; schema?: string } | undefined
	expect(
		destination,
		`the form at ${ROUTE} names no destination: a stale fixture from before decision 179. Delete that form and its page, then re-run tests/e2e/fixtures/seed-cms.sh`,
	).toMatchObject(DESTINATION)

	return String(forms[0]['@self']?.id ?? forms[0].id)
}

/**
 * How many objects sit at the form's public address.
 *
 * @param admin the admin context
 * @return the count
 */
async function heldAtTheAddress(admin: APIRequestContext): Promise<number> {
	return (await stored(admin, 'portaliq', 'landingPageSubmission', () => true))
		.length
}

test.describe('site renderer: landing page form submission', () => {
	let admin: APIRequestContext

	test.beforeAll(async () => {
		admin = await adminApi()
	})

	test.afterAll(async () => {
		await admin.dispose()
	})

	// @e2e landing-page-provisioning::a-visitor-submits-the-landing-pages-form-anonymously
	// @e2e portaliq-cms::a-landing-page-renders-its-bound-form-and-accepts-a-submission
	test('a visitor submits the landing page form and the destination object exists', async ({
		page,
	}) => {
		const formId = await fixtureFormId(admin)
		const heldBefore = await heldAtTheAddress(admin)
		// Unique per run, so the read-back finds this submit and no earlier one.
		const email = `visitor-${Date.now()}@example.org`

		await page.goto(
			`${SITE}?route=${ROUTE}&utm_campaign=e2e&utm_source=playwright&utm_medium=test`,
		)

		const form = page.getByTestId('site-form')
		await expect(form).toBeVisible()

		// The rendered fields, submitLabel and consentText all come from the
		// fixture's `form` widget props, proving the page's authored config
		// is what the visitor sees, not a hard-coded placeholder.
		await expect(
			form.getByText('Ik ga akkoord met de verwerking van mijn gegevens.'),
		).toBeVisible()

		await form.getByTestId('form-field-name').fill('J. de Vries')
		await form.getByTestId('form-field-email').fill(email)
		await form.getByTestId('form-submit').click()

		await expect(page.getByTestId('form-status-success')).toBeVisible()

		// The object staff will work on, read back from the destination.
		const created = await stored(
			admin,
			DESTINATION.register,
			DESTINATION.schema,
			(row) => row.email === email,
		)
		expect(created.length, 'one submit creates one destination object').toBe(1)
		const signup = created[0]
		expect(signup.name).toBe('J. de Vries')
		// Fixed by the form's destination, not typed by the visitor.
		expect(signup.source).toBe('landing-page')
		// Written by the page, where the destination declares a property for them.
		expect(signup.formId).toBe(formId)
		expect(signup.pageRoute).toBe(ROUTE)
		expect(signup.portal).toBe('open-tilburg')
		expect(String(signup.nonce ?? '')).not.toBe('')
		expect(Number.isNaN(Date.parse(String(signup.submittedAt)))).toBe(false)
		expect(
			(signup.utmLastTouch as { source?: string } | undefined)?.source,
		).toBe('playwright')

		// Nothing was stored in between: the public address holds what it held.
		expect(
			await heldAtTheAddress(admin),
			"a submit stores nothing at the form's public address",
		).toBe(heldBefore)
	})

	// @e2e landing-page-provisioning::first-touch-is-captured-once-last-touch-is-overwritten
	test('first touch is captured once and last touch is overwritten', async ({
		page,
	}) => {
		await page.goto(
			`${SITE}?route=${ROUTE}&utm_campaign=e2e&utm_source=first-link&utm_medium=email`,
		)
		await page.goto(
			`${SITE}?route=${ROUTE}&utm_campaign=e2e&utm_source=second-link&utm_medium=social`,
		)

		const kept = await page.evaluate(() => {
			const read = (key: string) => {
				const raw = window.sessionStorage.getItem(key)
				return raw ? JSON.parse(raw) : null
			}
			return {
				first: read('portaliq:campaign:open-tilburg:first'),
				last: read('portaliq:campaign:open-tilburg:last'),
			}
		})

		expect(kept.first?.source).toBe('first-link')
		expect(kept.last?.source).toBe('second-link')
	})

	// @e2e landing-page-provisioning::a-field-not-declared-on-the-form-is-dropped-never-persisted
	test('a field not declared on the form never reaches the destination object', async ({
		request: anonymous,
	}) => {
		const formId = await fixtureFormId(admin)
		const email = `api-${Date.now()}@example.org`

		// Direct API call (anonymous, no session). It proves the WHITELIST on
		// the wire, which a UI assertion cannot: the site's own form never
		// offers a way to send an undeclared field, so driving this through
		// the browser would prove the UI is well-behaved, not that the server
		// refuses a hand-crafted body.
		const response = await anonymous.post(SUBMIT_ADDRESS, {
			data: {
				actionId: `submit-${formId}`,
				name: 'API test',
				email,
				isAdmin: true,
				source: 'client-cannot-override-this',
				formId: 'client-cannot-override-this',
			},
		})

		expect(response.status(), await response.text()).toBe(200)
		const answer = (await response.json()).object
		// The answer names the destination object, not a row in portaliq.
		expect(answer.objects).toHaveLength(1)
		expect(answer.objects[0].register).toBe(DESTINATION.register)
		expect(answer.objects[0].schema).toBe(DESTINATION.schema)
		expect(String(answer.id ?? '')).not.toBe('')

		// Read the stored object, not the answer: the answer is what the
		// server says it did, the object is what it did.
		const created = await stored(
			admin,
			DESTINATION.register,
			DESTINATION.schema,
			(row) => row.email === email,
		)
		expect(created.length).toBe(1)
		expect(String(created[0]['@self']?.id ?? created[0].id)).toBe(
			String(answer.id),
		)
		expect(created[0].name).toBe('API test')
		expect(created[0].isAdmin).toBeUndefined()
		// The form's fixed value and the page's own value win over the client's.
		expect(created[0].source).toBe('landing-page')
		expect(created[0].formId).toBe(formId)
	})

	// @e2e landing-page-provisioning::a-submit-the-destination-refuses-stores-nothing
	test('a submit the destination refuses stores nothing and names the field', async ({
		request: anonymous,
	}) => {
		const formId = await fixtureFormId(admin)
		const before = (
			await stored(admin, DESTINATION.register, DESTINATION.schema, () => true)
		).length

		const response = await anonymous.post(SUBMIT_ADDRESS, {
			data: {
				actionId: `submit-${formId}`,
				name: 'No address',
				email: 'not-an-email-address',
			},
		})

		// The destination schema says `email` is an email address; the form
		// only says it is required. So this refusal is the destination's.
		expect(response.status(), await response.text()).toBe(422)
		expect(JSON.stringify(await response.json())).toContain('email')
		expect(
			(
				await stored(
					admin,
					DESTINATION.register,
					DESTINATION.schema,
					() => true,
				)
			).length,
		).toBe(before)
	})
})
