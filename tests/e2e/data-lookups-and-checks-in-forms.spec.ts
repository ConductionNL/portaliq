/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * data-lookups-and-checks-in-forms (T07): the address block fills in street
 * and town from postcode and house number (REQ-DIF-001), a wrong IBAN is
 * refused in the form and a BSN that fails the elfproef by the server
 * (REQ-DIF-002), and the family field offers its list only to a DigiD
 * resident and the server refuses a person the BRP does not link to the
 * resident (REQ-DIF-004).
 *
 * The spec seeds its own published form (an `addressNL` field, an IBAN and a
 * BSN field, and a `familyMembers` field), its binding with `addressLookup`
 * on, and a site page with the intake widget, all deleted afterwards. The
 * visitor is a resident signed in through dev-login (low trust, no DigiD):
 * the intake form renders only for a signed-in resident.
 *
 * WHAT IS REAL AND WHAT IS STUBBED.
 * - "No match" runs against the real address route: a postcode no BAG
 *   address has answers 404, whatever the instance's BAG register holds.
 * - "Sanne's address" needs OpenRegister's BAG register filled with her
 *   address, which a CI instance does not have, so the address route's
 *   answer is stubbed in the browser for 3311 AB 12 only. The BAG read itself
 *   is pinned by tests/Unit/Service/Intake/PortalAddressLookupTest.php.
 * - The server-side refusals are driven through the real submit, with the
 *   browser's request changed on its way out, as a tampered request would be.
 * - "Moving with the family" needs a DigiD session and a BRP that answers,
 *   and "A plate with dashes" reads the stored value on a destination that
 *   declares the plate; both are left to the live check (T08) and PHPUnit
 *   (DutchFormatsTest, the BRP recheck in PortalSubmissionChecksTest).
 *
 * Written, not run here (needs a live instance):
 *
 *     PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test data-lookups-and-checks-in-forms
 *
 * Environment (optional): PORTALIQ_E2E_PORTAL, PORTALIQ_E2E_ADMIN.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/specs/portal-form-data/spec.md
 */

import type { APIRequestContext, Browser, Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'
import { devLogin, seedSiteSession, SITE_PORTAL, siteAddress } from './portal-nav.ts'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from(process.env.PORTALIQ_E2E_ADMIN || 'admin:admin').toString(
	'base64',
)
const ADMIN_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }
const EMPTY_STATE = { cookies: [], origins: [] }

const STAMP = Date.now()
const ROUTE = `aanvragen/verhuizing-${STAMP}`
const PAGE_ROUTE = `/e2e/verhuizing-${STAMP}`

/** A valid IBAN, a wrong one (the scenario's) and a BSN that fails the elfproef. */
const GOOD_IBAN = 'NL91ABNA0417164300'
const WRONG_IBAN = 'NL91 ABNA 0417 1643 01'
const BAD_BSN = '123456780'
const GOOD_BSN = '111222333'

/** What this run created, as `register/schema/id`, to delete afterwards. */
const created: string[] = []

/**
 * Create one portaliq object through OpenRegister's own object API, as the admin.
 *
 * @param request The request fixture.
 * @param schema The portaliq schema.
 * @param data The object.
 */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
	register = 'portaliq',
): Promise<void> {
	const res = await request.post(`${OR_OBJECTS_BASE}/${register}/${schema}`, {
		headers: ADMIN_HEADERS,
		data,
	})
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for ${schema}: ${await res.text()}`,
	).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	created.push(`${register}/${schema}/${id}`)
}

/**
 * The register and schema the answers go to. A portal submit is an anonymous
 * write, so the form points at a schema that lets a public visitor create;
 * the spec makes its own and removes it afterwards. (A registrationForm's
 * `register` and `schema` also name where OpenRegister writes the form row
 * itself, so they cannot point at portalCase, which requires a subject.)
 */
const DESTINATION = `e2e-verhuizing-${STAMP}`
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
				adres: { type: 'object' },
				iban: { type: 'string' },
				bsn: { type: 'string' },
				meeverhuizers: { type: 'array' },
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
 * The moving form, its binding and the page that shows it.
 *
 * @param request The request fixture.
 */
async function seedForm(request: APIRequestContext): Promise<void> {
	const caseType = `verhuizing-${STAMP}`
	await seedDestination(request)
	// The published form lives in buildiq, the binding resolver's default
	// form register; portaliq declares no registrationForm schema.
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
		fields: [
			{
				name: 'adres',
				label: 'Nieuw adres',
				type: 'addressNL',
				order: 1,
				required: true,
			},
			{
				name: 'iban',
				label: 'IBAN',
				format: 'iban',
				order: 2,
				required: true,
			},
			{ name: 'bsn', label: 'BSN', format: 'bsn', order: 3, required: true },
			{
				name: 'meeverhuizers',
				label: 'Wie verhuist er mee',
				type: 'familyMembers',
				order: 4,
			},
		],
	}, 'buildiq')
	await seed(request, 'portalFormBinding', {
		portal: SITE_PORTAL,
		route: ROUTE,
		typeRegister: 'portaliq',
		typeSchema: 'portalCaseType',
		typeId: caseType,
		audience: 'client',
		formRegister: 'buildiq',
		formSchema: 'registrationForm',
		destination: { register: DESTINATION, schema: DESTINATION, subjectField: 'subjectRef' },
		intakeKind: 'hosted',
		addressLookup: true,
		status: 'published',
	})
	await seed(request, 'page', {
		title: `Verhuizing ${STAMP}`,
		route: PAGE_ROUTE,
		portal: SITE_PORTAL,
		status: 'published',
		locale: 'nl',
		summary: 'Testpagina met een verhuisformulier.',
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
					props: { route: ROUTE },
				},
			],
		},
	})
}

/**
 * The resident's bearer for the run: today's intake form renders only for a
 * signed-in resident (an anonymous visitor is asked to sign in first), so the
 * spec signs one in through dev-login, at low trust and without DigiD.
 */
let residentToken = ''

/**
 * A page in a fresh context with only the resident's site session, opened on
 * the form.
 *
 * @param browser The browser fixture.
 * @return The page.
 */
async function openForm(browser: Browser): Promise<Page> {
	const context = await browser.newContext({
		baseURL: BASE_URL,
		storageState: EMPTY_STATE,
	})
	const page = await context.newPage()
	await seedSiteSession(page, residentToken)
	await page.goto(siteAddress(PAGE_ROUTE))
	await expect(page.getByTestId('intake-form')).toBeVisible({ timeout: 60_000 })
	return page
}

/**
 * Fill postcode and house number and leave the number field.
 *
 * @param page The page.
 * @param postcode The postcode.
 * @param number The house number.
 */
async function enterAddress(
	page: Page,
	postcode: string,
	number: string,
): Promise<void> {
	await page.getByTestId('intake-field-adres-postcode').fill(postcode)
	await page.getByTestId('intake-field-adres-number').fill(number)
	await page.getByTestId('intake-field-adres-number').press('Tab')
}

/**
 * Answer the whole form validly, the address typed by hand.
 *
 * @param page The page.
 */
async function answerValidly(page: Page): Promise<void> {
	await enterAddress(page, '9999 ZZ', '1')
	await page.getByTestId('intake-field-adres-street').fill('Teststraat')
	await page.getByTestId('intake-field-adres-town').fill('Teststad')
	await page.getByTestId('intake-field-iban').fill(GOOD_IBAN)
	await page.getByTestId('intake-field-bsn').fill(GOOD_BSN)
}

/**
 * Change the submit on its way out and record the server's answer.
 *
 * @param page The page.
 * @param tamper Changes the answers.
 * @return Reads the answer once it came.
 */
async function tamperedSubmit(
	page: Page,
	tamper: (answers: Record<string, unknown>) => void,
): Promise<() => { status: number; errors: Record<string, string> } | null> {
	let answered: { status: number; errors: Record<string, string> } | null = null
	await page.route('**/portal/api/intake/submit**', async (route: Route) => {
		const body = route.request().postDataJSON() as {
			answers: Record<string, unknown>
		}
		tamper(body.answers)
		const response = await route.fetch({ postData: JSON.stringify(body) })
		const parsed = await response.json().catch(() => ({}))
		answered = {
			status: response.status(),
			errors: (parsed.errors ?? {}) as Record<string, string>,
		}
		await route.fulfill({ response })
	})
	return () => answered
}

test.describe('data-lookups-and-checks-in-forms', () => {
	test.beforeAll(async ({ request }) => {
		await seedForm(request)
		residentToken = await devLogin(
			request,
			`e2e-verhuizing-${STAMP}`,
			'client',
			process.env.PORTALIQ_E2E_ORGANISATION || 'dev-org',
		)
	})

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

	// @e2e portal-form-data::sanne-s-address
	test("Sanne's address: street and town are filled in and stay editable", async ({
		browser,
	}) => {
		const page = await openForm(browser)
		await page.route('**/portal/api/intake/address**', async (route: Route) => {
			const url = new URL(route.request().url())
			const postcode = (url.searchParams.get('postcode') ?? '')
				.replace(/\s+/g, '')
				.toUpperCase()
			if (postcode === '3311AB' && url.searchParams.get('number') === '12') {
				await route.fulfill({
					json: { street: 'Lindelaan', town: 'Zuiddrecht' },
				})
				return
			}
			await route.fulfill({
				status: 404,
				json: { error: 'address_not_found' },
			})
		})

		await enterAddress(page, '3311 AB', '12')
		await expect(page.getByTestId('intake-field-adres-street')).toHaveValue(
			'Lindelaan',
		)
		await expect(page.getByTestId('intake-field-adres-town')).toHaveValue(
			'Zuiddrecht',
		)
		await expect(page.getByTestId('address-found')).toContainText(
			'Wij vonden dit adres bij uw postcode en huisnummer.',
		)

		await page.getByTestId('intake-field-adres-street').fill('Lindelaan-Oost')
		await expect(page.getByTestId('intake-field-adres-street')).toHaveValue(
			'Lindelaan-Oost',
		)

		await page.context().close()
	})

	// @e2e portal-form-data::no-match
	test('No match: street and town stay empty and editable', async ({
		browser,
	}) => {
		const page = await openForm(browser)
		const looked = page.waitForResponse((response) =>
			response.url().includes('/portal/api/intake/address'),
		)

		await enterAddress(page, '9999 ZZ', '1')
		expect((await looked).status()).toBe(404)

		await expect(page.getByTestId('address-found')).toHaveCount(0)
		const street = page.getByTestId('intake-field-adres-street')
		const town = page.getByTestId('intake-field-adres-town')
		await expect(street).toHaveValue('')
		await expect(town).toHaveValue('')
		await expect(street).toBeEditable()
		await street.fill('Teststraat')
		await expect(street).toHaveValue('Teststraat')

		await page.context().close()
	})

	// @e2e portal-form-data::a-wrong-iban
	test('A wrong IBAN: the field says the IBAN is not right and nothing is sent', async ({
		browser,
	}) => {
		const page = await openForm(browser)
		const submits: string[] = []
		page.on('request', (sent) => {
			if (
				sent.method() === 'POST'
				&& sent.url().includes('/portal/api/intake/submit')
			) {
				submits.push(sent.url())
			}
		})

		await answerValidly(page)
		await page.getByTestId('intake-field-iban').fill(WRONG_IBAN)
		await page.getByTestId('intake-form-submit').click()

		await expect(page.getByTestId('intake-form')).toContainText(
			'Dit IBAN klopt niet. Controleer de cijfers.',
		)
		await expect(page.getByTestId('intake-field-iban')).toHaveAttribute(
			'aria-invalid',
			'true',
		)
		expect(submits).toHaveLength(0)

		await page.context().close()
	})

	// @e2e portal-form-data::a-bsn-that-fails-the-elfproef
	test('A BSN that fails the elfproef: the server refuses it and names the field', async ({
		browser,
	}) => {
		const page = await openForm(browser)
		await answerValidly(page)

		const answer = await tamperedSubmit(page, (answers) => {
			answers.bsn = BAD_BSN
		})
		await page.getByTestId('intake-form-submit').click()

		await expect.poll(answer).not.toBeNull()
		expect(answer()?.status).toBe(400)
		expect(Object.keys(answer()?.errors ?? {})).toContain('bsn')
		await expect(page.getByTestId('intake-form-done')).toHaveCount(0)

		await page.context().close()
	})

	// @e2e portal-form-data::a-forged-person
	test('A forged person: without a DigiD session no list is offered, and a sent person is refused', async ({
		browser,
	}) => {
		const page = await openForm(browser)
		await expect(page.getByTestId('intake-field-meeverhuizers')).toBeVisible()
		await expect(page.getByTestId('family-unavailable')).toBeVisible()
		await expect(page.getByTestId('family-intro')).toHaveCount(0)

		await answerValidly(page)
		const answer = await tamperedSubmit(page, (answers) => {
			answers.meeverhuizers = ['brp-ref-not-linked-to-this-resident']
		})
		await page.getByTestId('intake-form-submit').click()

		await expect.poll(answer).not.toBeNull()
		expect(answer()?.status).toBe(400)
		expect(Object.keys(answer()?.errors ?? {})).toContain('meeverhuizers')
		await expect(page.getByTestId('intake-form-done')).toHaveCount(0)

		await page.context().close()
	})
})
