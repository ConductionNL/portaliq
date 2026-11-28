/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * intake-pay-on-submit: a request with a fee names what it costs, asks a
 * visitor without a session to sign in first, offers "Pay now" after
 * sending, and on return says what the destination object holds about the
 * payment, never what the address says (REQ-IPS-001, REQ-IPS-002,
 * REQ-IPS-005).
 *
 * The spec seeds its own destination register and schema (with a reference,
 * a subject and a payment field), a case type with a `portalFee`, a
 * published form through buildiq's form API, a binding on this run's own
 * route and a site page with an `intakeForm` widget pinned to that route.
 * Everything it seeds is deleted again afterwards.
 *
 * What runs on any instance with dev-login:
 *   - an anonymous visitor is asked to sign in, with the amount, before any
 *     question (REQ-IPS-002);
 *   - the status route reads the payment state from the destination object
 *     and ignores a status in the query string (REQ-IPS-005).
 *
 * What needs a session at level `substantial`, which dev-login does not mint
 * (it signs `low`): a fee-bearing form opens only to such a session, so the
 * sent request with its fee line and the return page are run only when
 * PORTALIQ_E2E_SUBSTANTIAL_BEARER holds a bearer from a real DigiD (test)
 * sign-in and PORTALIQ_E2E_SUBSTANTIAL_SUBJECT its subjectRef. Without them
 * those tests are skipped and say why.
 *
 * Environment (all optional): PORTALIQ_E2E_PORTAL (default open-tilburg),
 * PORTALIQ_E2E_ADMIN (default admin:admin), PORTALIQ_E2E_ORGANISATION
 * (default dev-org).
 *
 * Written, not yet run: run in the live pass (decision 139).
 *
 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { devLogin, PORTAL_API, seedSiteSession, SITE_PORTAL, siteAddress, someOf } from './portal-nav.ts'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from(process.env.PORTALIQ_E2E_ADMIN || 'admin:admin').toString('base64')
const ADMIN_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }

/** A real `substantial` session, when the run has one. */
const SUBSTANTIAL_BEARER = process.env.PORTALIQ_E2E_SUBSTANTIAL_BEARER || ''
const SUBSTANTIAL_SUBJECT = process.env.PORTALIQ_E2E_SUBSTANTIAL_SUBJECT || ''
const NO_SUBSTANTIAL = 'needs PORTALIQ_E2E_SUBSTANTIAL_BEARER and PORTALIQ_E2E_SUBSTANTIAL_SUBJECT: a fee-bearing form opens only at level substantial, and dev-login signs low'

/** The fee the case type declares: 38.50 EUR. */
const FEE = { amount: '38.50', currency: 'EUR', description: 'Parkeervergunning', payAction: 'create-payment' }

/** The amount as the site writes it, in Dutch or English. */
const AMOUNT = '€\\s?38[,.]50'

/** What this run created, as `register/schema/id`, to delete afterwards. */
const created: string[] = []

/** The run's own destination. */
const DESTINATION = `e2e-parkeren-${Date.now()}`
const destinationIds: { schema?: number, register?: number } = {}

/**
 * Create one object through OpenRegister's own object API, as the admin.
 *
 * @param request The request fixture.
 * @param register The register.
 * @param schema The schema.
 * @param data The object.
 * @return The id.
 */
async function seed(
	request: APIRequestContext,
	register: string,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await request.post(`${OR_OBJECTS_BASE}/${register}/${schema}`, {
		headers: ADMIN_HEADERS,
		data,
	})
	expect(res.ok(), `OpenRegister objects#create must be reachable for ${register}/${schema}: ${await res.text()}`).toBeTruthy()
	const body = await res.json()
	const id = String(body.id ?? body['@self']?.id ?? '')
	expect(id).not.toBe('')
	created.push(`${register}/${schema}/${id}`)
	return id
}

/**
 * Store a published form through buildiq's own form API, the way its form
 * designer does; forms live in buildiq's `registrationForm`.
 *
 * @param request The request fixture.
 * @param form The form.
 */
async function seedRegistrationForm(
	request: APIRequestContext,
	form: Record<string, unknown>,
): Promise<void> {
	const saved = await request.put('/apps/buildiq/api/registration-forms', {
		headers: ADMIN_HEADERS,
		data: form,
	})
	expect(saved.ok(), `buildiq must store the form: ${await saved.text()}`).toBeTruthy()
	const found = await request.get(`${OR_OBJECTS_BASE}/buildiq/registrationForm`, {
		headers: ADMIN_HEADERS,
		params: { name: String(form.name), _limit: '1' },
	})
	const rows = ((await found.json()).results ?? []) as Array<Record<string, unknown>>
	const id = String(rows[0]?.id ?? '')
	expect(id, `the stored form ${String(form.name)} is listed`).not.toBe('')
	created.push(`buildiq/registrationForm/${id}`)
}

/**
 * Create the destination register and schema, once for the run: a marked
 * reference, the subject the binding stamps, and the payment field the case
 * app writes the payment's status into (decision 181).
 *
 * @param request The request fixture.
 */
async function seedDestination(request: APIRequestContext): Promise<void> {
	if (destinationIds.register) {
		return
	}
	const schema = await request.post('/apps/openregister/api/schemas', {
		headers: ADMIN_HEADERS,
		data: {
			title: `E2E ${DESTINATION}`,
			slug: DESTINATION,
			properties: {
				caseType: { type: 'string' },
				kenteken: { type: 'string' },
				reference: { type: 'string', 'x-openregister': { reference: true } },
				subjectRef: { type: 'string' },
				paymentStatus: { type: 'string' },
			},
			authorization: { create: ['public'], read: ['admin'] },
		},
	})
	expect(schema.ok(), `the destination schema must be created: ${await schema.text()}`).toBeTruthy()
	destinationIds.schema = (await schema.json()).id
	const register = await request.post('/apps/openregister/api/registers', {
		headers: ADMIN_HEADERS,
		data: { title: `E2E ${DESTINATION}`, slug: DESTINATION, schemas: [destinationIds.schema] },
	})
	expect(register.ok(), `the destination register must be created: ${await register.text()}`).toBeTruthy()
	destinationIds.register = (await register.json()).id
}

/**
 * A fee-bearing case type, a published form, a binding on its own route,
 * and a page that shows it.
 *
 * @param request The request fixture.
 * @return The binding's route and the page's route.
 */
async function seedFeeForm(
	request: APIRequestContext,
): Promise<{ route: string, pageRoute: string, caseType: string }> {
	const stamp = Date.now()
	const caseType = `parkeren-${stamp}`
	const route = `aanvragen/parkeren-${stamp}`
	const pageRoute = `/e2e/parkeren-${stamp}`
	await seedDestination(request)

	const typeId = await seed(request, 'portaliq', 'portalCaseType', {
		title: `Parkeervergunning ${stamp}`,
		portalFee: FEE,
	})
	await seedRegistrationForm(request, {
		targetApp: 'portaliq',
		typeProperty: 'caseType',
		typeValue: caseType,
		caseType,
		audience: 'client',
		name: `${caseType}-client`,
		status: 'published',
		register: DESTINATION,
		schema: DESTINATION,
		isPublic: true,
		fields: [{ name: 'kenteken', order: 1, required: true }],
	})
	await seed(request, 'portaliq', 'portalFormBinding', {
		portal: SITE_PORTAL,
		route,
		typeRegister: 'portaliq',
		typeSchema: 'portalCaseType',
		typeId,
		audience: 'client',
		formRegister: 'buildiq',
		formSchema: 'registrationForm',
		destination: {
			register: DESTINATION,
			schema: DESTINATION,
			subjectField: 'subjectRef',
			paymentField: 'paymentStatus',
		},
		intakeKind: 'hosted',
		status: 'published',
	})
	await seed(request, 'portaliq', 'page', {
		title: `Parkeervergunning ${stamp}`,
		route: pageRoute,
		portal: SITE_PORTAL,
		status: 'published',
		locale: 'nl',
		summary: 'Testpagina met een aanvraag die geld kost.',
		body: {
			type: 'grid',
			widgets: [
				{
					id: 'aanvraag',
					widgetKey: 'intakeForm',
					slot: 'body',
					gridX: 0,
					gridY: 0,
					gridWidth: 12,
					gridHeight: 6,
					props: { route },
				},
			],
		},
	})

	return { route, pageRoute, caseType }
}

/**
 * A request the resident sent earlier, with the payment status the case
 * app wrote on it.
 *
 * @param request The request fixture.
 * @param caseType The case type.
 * @param subjectRef Whose request it is.
 * @param paymentStatus The payment provider's status, e.g. `paid` or `canceled`.
 * @return The request's reference.
 */
async function seedSentRequest(
	request: APIRequestContext,
	caseType: string,
	subjectRef: string,
	paymentStatus: string,
): Promise<string> {
	const reference = `PARK-${Date.now()}-${paymentStatus}`
	await seed(request, DESTINATION, DESTINATION, {
		caseType,
		kenteken: '12-ABC-3',
		reference,
		subjectRef,
		paymentStatus,
	})
	return reference
}

/**
 * Open the form's page as the payment page sends the resident back: with
 * the reference, and a status in the address that the page must ignore.
 *
 * @param page The page.
 * @param pageRoute The page's route.
 * @param reference The reference.
 * @param addressStatus The status the address claims.
 */
async function returnFromPayment(page: Page, pageRoute: string, reference: string, addressStatus: string): Promise<void> {
	await page.goto(`${siteAddress(pageRoute)}&reference=${encodeURIComponent(reference)}&status=${addressStatus}`)
	await page.waitForLoadState('domcontentloaded')
}

test.describe('intake-pay-on-submit: a request that costs money', () => {
	test.afterAll(async ({ request }) => {
		for (const ref of created.splice(0).reverse()) {
			await request.delete(`${OR_OBJECTS_BASE}/${ref}`, { headers: ADMIN_HEADERS })
		}
		if (destinationIds.register) {
			await request.delete(`/apps/openregister/api/registers/${destinationIds.register}`, { headers: ADMIN_HEADERS })
		}
		if (destinationIds.schema) {
			await request.delete(`/apps/openregister/api/schemas/${destinationIds.schema}`, { headers: ADMIN_HEADERS })
		}
	})

	// @e2e portal-intake-payment::an-anonymous-visitor-is-asked-to-sign-in
	test('an anonymous visitor is asked to sign in, with the amount, before any question', async ({ page, request }) => {
		const { route, pageRoute } = await seedFeeForm(request)

		// The form route names the fee to a visitor without a session.
		const form = await request.get(
			`${PORTAL_API}/intake/form?route=${encodeURIComponent(route)}&portal=${encodeURIComponent(SITE_PORTAL)}`,
		)
		expect(form.ok(), 'the form route answers for a fee-bearing form').toBeTruthy()
		const render = await form.json()
		expect(render.fee?.amount).toBe(FEE.amount)
		expect(render.fee?.currency).toBe(FEE.currency)

		await page.goto(siteAddress(pageRoute))
		await page.waitForLoadState('domcontentloaded')
		const signIn = page.getByTestId('intake-form-sign-in')
		await expect(signIn).toBeVisible()
		await expect(signIn).toContainText(
			new RegExp(`(Voor deze aanvraag betaalt u ${AMOUNT} leges\\.|${AMOUNT})`),
		)
		await expect(signIn).toContainText(someOf('Log eerst in.', 'Log in first.', 'Sign in first.'))
		await expect(page.locator('[data-testid^="intake-field-"]')).toHaveCount(0)
	})

	// @e2e portal-intake-payment::a-resident-comes-back-after-paying
	test('the status route reads the payment from the request, not from the address', async ({ request }) => {
		const { caseType } = await seedFeeForm(request)
		const subjectRef = `e2e-parkeren-resident-${Date.now()}`
		const token = await devLogin(request, subjectRef, 'client', process.env.PORTALIQ_E2E_ORGANISATION || 'dev-org')
		const paid = await seedSentRequest(request, caseType, subjectRef, 'paid')
		const canceled = await seedSentRequest(request, caseType, subjectRef, 'canceled')

		const read = async (reference: string) => {
			const res = await request.get(`${PORTAL_API}/intake/status`, {
				headers: { Authorization: `Bearer ${token}` },
				params: { reference, portal: SITE_PORTAL, status: 'paid' },
			})
			expect(res.ok(), `the status route finds ${reference}: ${await res.text()}`).toBeTruthy()
			return res.json()
		}

		const paidStatus = await read(paid)
		expect(paidStatus.reference).toBe(paid)
		expect(paidStatus.payment?.state).toBe('paid')

		// The address says paid; the request says canceled, and that is what counts.
		const canceledStatus = await read(canceled)
		expect(canceledStatus.reference).toBe(canceled)
		expect(canceledStatus.payment?.state).toBe('failed')
	})

	// @e2e portal-intake-payment::a-resident-sees-the-declared-fee
	test('after sending, the confirmation names the fee and offers to pay it', async ({ page, request }) => {
		test.skip(SUBSTANTIAL_BEARER === '', NO_SUBSTANTIAL)
		const { pageRoute } = await seedFeeForm(request)

		await seedSiteSession(page, SUBSTANTIAL_BEARER)
		await page.goto(siteAddress(pageRoute))
		await page.waitForLoadState('domcontentloaded')
		await page.getByTestId('intake-field-kenteken').locator('input').first().fill('12-ABC-3')
		await page.getByTestId('intake-form-submit').click()

		await expect(page.getByTestId('intake-form-done')).toBeVisible()
		await expect(page.getByTestId('intake-form-reference')).not.toBeEmpty()
		await expect(page.getByTestId('intake-form-fee')).toContainText(
			new RegExp(`(Deze aanvraag kost|This request costs) ${AMOUNT}`),
		)
		await expect(page.getByTestId('intake-form-pay')).toContainText(
			new RegExp(`(Betaal ${AMOUNT} nu|Nu ${AMOUNT} betalen|Pay ${AMOUNT} now)`),
		)
	})

	// @e2e portal-intake-payment::a-resident-comes-back-after-paying
	test('back from paying, the page shows the reference and Paid, and no second payment', async ({ page, request }) => {
		test.skip(SUBSTANTIAL_BEARER === '' || SUBSTANTIAL_SUBJECT === '', NO_SUBSTANTIAL)
		const { pageRoute, caseType } = await seedFeeForm(request)
		const reference = await seedSentRequest(request, caseType, SUBSTANTIAL_SUBJECT, 'paid')

		await seedSiteSession(page, SUBSTANTIAL_BEARER)
		await returnFromPayment(page, pageRoute, reference, 'failed')

		await expect(page.getByTestId('intake-form-reference')).toHaveText(reference)
		await expect(page.getByTestId('intake-form-payment')).toContainText(someOf('Betaald', 'Paid'))
		await expect(page.getByTestId('intake-form-pay')).toHaveCount(0)
	})

	// @e2e portal-intake-payment::a-resident-who-cancelled-can-pay-again
	test('back from a cancelled payment, the page says it failed and offers Pay now again', async ({ page, request }) => {
		test.skip(SUBSTANTIAL_BEARER === '' || SUBSTANTIAL_SUBJECT === '', NO_SUBSTANTIAL)
		const { pageRoute, caseType } = await seedFeeForm(request)
		const reference = await seedSentRequest(request, caseType, SUBSTANTIAL_SUBJECT, 'canceled')

		await seedSiteSession(page, SUBSTANTIAL_BEARER)
		await returnFromPayment(page, pageRoute, reference, 'paid')

		await expect(page.getByTestId('intake-form-reference')).toHaveText(reference)
		await expect(page.getByTestId('intake-form-payment')).toContainText(
			someOf('De betaling is mislukt', 'The payment failed'),
		)
		await expect(page.getByTestId('intake-form-pay')).toBeVisible()
	})
})
