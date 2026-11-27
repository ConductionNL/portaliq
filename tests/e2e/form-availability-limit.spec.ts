/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * form-governance-availability-retention-and-routing (T11): a form at its
 * limit shows situation 3 of the FormulierNietBeschikbaar board ("Er kunnen nu
 * geen aanvragen meer bij", with the reopening date) instead of the form, and
 * the server refuses a submission to it (REQ-FGV-001).
 *
 * The retry half of T11 is gone: decision 179 removed REQ-FGV-003, so nothing
 * is queued and nothing is retried (see the change's proposal).
 *
 * The spec seeds its own published form, a binding on this run's own route
 * with `availability.limit` of one submission in total and a reopening date,
 * one destination object so the limit is reached, and a site page with an
 * `intakeForm` widget pinned to that route. Everything it seeds is deleted
 * again afterwards. The limit counts the destination's objects of the period
 * (PortalFormAvailability::counter), so the seeded case is enough to fill it
 * whatever else the instance holds.
 *
 * Environment (all optional): PORTALIQ_E2E_PORTAL (default open-tilburg),
 * PORTALIQ_E2E_ADMIN (default admin:admin).
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/tasks.md#T11
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#scenario-the-150-places-are-taken
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { devLogin, PORTAL_API, seedSiteSession, SITE_PORTAL, siteAddress, someOf } from './portal-nav.ts'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from(process.env.PORTALIQ_E2E_ADMIN || 'admin:admin').toString(
	'base64',
)
const ADMIN_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }

/**
 * A resident signed in through dev-login: the intake routes and the intake
 * form answer only a signed-in resident (PortalProtected).
 *
 * @param request The request fixture.
 * @return The resident's bearer.
 */
async function residentBearer(request: APIRequestContext): Promise<string> {
	return devLogin(
		request,
		`e2e-voortuin-resident-${Date.now()}`,
		'client',
		process.env.PORTALIQ_E2E_ORGANISATION || 'dev-org',
	)
}

/** The reopening date; 5 January 2027 is a Tuesday. */
const REOPENS_ON = '2027-01-05'

/** What this run created, as `register/schema/id`, to delete afterwards. */
const created: string[] = []

/**
 * Create one portaliq object through OpenRegister's own object API, as the admin.
 *
 * @param request The request fixture.
 * @param schema The portaliq schema.
 * @param data The object.
 * @return The id.
 */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/${schema}`, {
		headers: ADMIN_HEADERS,
		data,
	})
	expect(res.ok(), `OpenRegister objects#create must be reachable for ${schema}: ${await res.text()}`).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	created.push(`portaliq/${schema}/${id}`)
	return id
}

/**
 * Store a published form through buildiq's own form API, the way its form
 * designer does. Forms live in buildiq's `registrationForm` now, not in
 * portaliq; buildiq refuses a published form whose fields its destination
 * does not declare, so the field is one `portalCase` has.
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
 * The register and schema the answers go to. A portal submit is an anonymous
 * write, and both buildiq and the binding refuse a published form whose
 * destination a public visitor may not create in, so the spec makes its own
 * destination (portalCase is not one) and removes it afterwards. The limit
 * counts the objects in this destination.
 */
const DESTINATION = `e2e-voortuin-${Date.now()}`
const destinationIds: { schema?: number, register?: number } = {}

/**
 * Create the destination register and schema, once for the run.
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
				omschrijving: { type: 'string' },
				reference: { type: 'string', 'x-openregister': { reference: true } },
				subjectRef: { type: 'string' },
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
 * A published form, a full binding on its own route, and a page that shows it.
 *
 * @param request The request fixture.
 * @return The binding's route and the page's route.
 */
async function seedFullForm(
	request: APIRequestContext,
): Promise<{ route: string; pageRoute: string }> {
	const stamp = Date.now()
	const caseType = `voortuin-${stamp}`
	const route = `aanvragen/voortuin-${stamp}`
	const pageRoute = `/e2e/voortuin-${stamp}`
	await seedDestination(request)

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
		fields: [{ name: 'omschrijving', order: 1, required: true }],
	})
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
		status: 'published',
		availability: {
			limit: { count: 1, per: 'total' },
			reopensOn: REOPENS_ON,
		},
	})
	// One destination object, so the limit of one is reached.
	const filled = await request.post(`${OR_OBJECTS_BASE}/${DESTINATION}/${DESTINATION}`, {
		headers: ADMIN_HEADERS,
		data: { caseType, omschrijving: `Voortuinsubsidie ${stamp}`, subjectRef: `e2e-voortuin-${stamp}` },
	})
	expect(filled.ok(), `the destination must take one object: ${await filled.text()}`).toBeTruthy()
	const filledBody = await filled.json()
	created.push(`${DESTINATION}/${DESTINATION}/${String(filledBody.id ?? filledBody['@self']?.id)}`)
	await seed(request, 'page', {
		title: `Voortuinsubsidie ${stamp}`,
		route: pageRoute,
		portal: SITE_PORTAL,
		status: 'published',
		locale: 'nl',
		summary: 'Testpagina met een aanvraagformulier dat vol is.',
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

	return { route, pageRoute }
}

test.describe('form-governance: a form at its limit', () => {
	test.afterAll(async ({ request }) => {
		for (const ref of created.splice(0).reverse()) {
			await request.delete(`${OR_OBJECTS_BASE}/${ref}`, {
				headers: ADMIN_HEADERS,
			})
		}
		if (destinationIds.register) {
			await request.delete(`/apps/openregister/api/registers/${destinationIds.register}`, { headers: ADMIN_HEADERS })
		}
		if (destinationIds.schema) {
			await request.delete(`/apps/openregister/api/schemas/${destinationIds.schema}`, { headers: ADMIN_HEADERS })
		}
	})

	// @e2e portal-form-governance::the-150-places-are-taken
	test('the resident sees that no more applications can be made, and when they can again', async ({
		page,
		request,
	}) => {
		const { route, pageRoute } = await seedFullForm(request)
		const token = await residentBearer(request)

		// The form route answers with the full situation instead of fields.
		const form = await request.get(
			`${PORTAL_API}/intake/form?route=${encodeURIComponent(route)}&portal=${encodeURIComponent(SITE_PORTAL)}`,
			{ headers: { Authorization: `Bearer ${token}` } },
		)
		expect(form.ok(), 'the form route answers for a full form').toBeTruthy()
		const render = await form.json()
		expect(render.resolvesToNoForm).toBe(true)
		expect(render.unavailable?.situation).toBe('full')
		expect(render.unavailable?.reopensOn).toBe(REOPENS_ON)
		expect(render.fields).toEqual([])

		// The page shows situation 3 of FormulierNietBeschikbaar, and no form.
		await seedSiteSession(page, token)
		await page.goto(siteAddress(pageRoute))
		await page.waitForLoadState('domcontentloaded')
		const unavailable = page.getByTestId('form-unavailable')
		await expect(unavailable).toBeVisible()
		await expect(unavailable).toHaveAttribute('data-situation', 'full')
		await expect(unavailable).toContainText(
			someOf(
				'Er kunnen nu geen aanvragen meer bij',
				'No more applications can be made right now',
			),
		)
		// The reopening date, with its weekday worked out from the date.
		await expect(unavailable).toContainText(
			someOf('dinsdag 5 januari 2027', 'Tuesday 5 January 2027', 'Tuesday, 5 January 2027'),
		)
		await expect(page.getByTestId('widget-aanvraag').locator('form')).toHaveCount(0)
	})

	// @e2e portal-form-governance::the-150-places-are-taken
	test('the server refuses a submission to a full form', async ({ request }) => {
		const { route } = await seedFullForm(request)
		const token = await residentBearer(request)

		const res = await request.post(`${PORTAL_API}/intake/submit`, {
			headers: { Authorization: `Bearer ${token}` },
			data: { route, portal: SITE_PORTAL, answers: { omschrijving: 'Een voortuin vol planten' } },
		})
		expect(res.status()).toBe(409)
		const body = await res.json()
		expect(body.error).toBe('form_unavailable')
		expect(body.unavailable?.situation).toBe('full')
	})
})
