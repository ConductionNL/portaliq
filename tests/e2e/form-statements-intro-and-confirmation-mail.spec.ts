/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * form-statements-intro-and-confirmation-mail (T07): a resident goes from a
 * form's introduction to its confirmation page by keyboard only. The form
 * opens with "Voordat u begint" and "Start" (REQ-FCI-001), cannot be sent
 * while a required statement is not ticked (REQ-FCI-002), and ends on the
 * confirmation page the binding declares, with the reference, the next steps
 * and the address the confirmation mail went to; a sentence whose value is
 * empty, the decision date, is left out (REQ-FCI-003).
 *
 * The spec seeds its own portal (with the wording of both statements), a
 * published form with a name and an e-mail field, its binding (introduction,
 * both statements required, the confirmation page and mail) and a site page
 * with the intake widget on that binding. Everything is deleted afterwards.
 * The visitor is a dev-login resident (today's intake form renders only for a
 * signed-in resident), in an otherwise empty-state context, and uses the keyboard
 * only: focus moves by Tab and controls are used with Enter and Space.
 *
 * WHAT IS LEFT TO OTHER TESTS, AND WHY.
 * - "The record": the destination here is `portalCase`, which declares no
 *   `statements` property, so the accepted statements are not stored on it.
 *   This spec asserts the submit carries both statement keys; the record with
 *   text version and time is pinned by PHPUnit on PortalSubmissionChecks.
 * - "A full mailbox": making the mail server refuse needs a broken transport.
 *   It is left to the live check (T08).
 * - The PDF: this app has no PDF writer (T05), so neither page nor mail has one.
 * - The scenario's error summary: the missing statement is named under the
 *   statement itself (`statement-error-privacy`), which is what is asserted.
 *
 * The confirmation mail needs a working mail transport, as
 * identity-staff-account-screens.spec.ts does. Written, not run here (needs a
 * live instance):
 *
 *     PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test form-statements-intro-and-confirmation-mail
 *
 * Environment (optional): PORTALIQ_E2E_ADMIN.
 *
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/specs/portal-form-statements/spec.md
 */

import type {
	APIRequestContext,
	Browser,
	Locator,
	Page,
	Request,
} from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'
import { devLogin, seedSiteSession, SITE_PATH } from './portal-nav.ts'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from(process.env.PORTALIQ_E2E_ADMIN || 'admin:admin').toString(
	'base64',
)
const ADMIN_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }
const EMPTY_STATE = { cookies: [], origins: [] }

const STAMP = Date.now()

/** This run's own portal, carrying the statement wording. */
const PORTAL = `e2e-verklaringen-${STAMP}`

/** The binding's route and the page that shows it. */
const ROUTE = `aanvragen/woo-verzoek-${STAMP}`
const PAGE_ROUTE = `/e2e/woo-verzoek-${STAMP}`

/** The statement wording, as the portal owns it. */
const TRUTH = 'Ik heb dit formulier naar waarheid ingevuld.'
const PRIVACY = 'Ik ga akkoord met de privacyverklaring.'

/** The resident's address. */
const EMAIL = `sanne.devries.${STAMP}@example.nl`

/** What this run created, as `schema/id`, to delete afterwards. */
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
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for ${schema}: ${await res.text()}`,
	).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	created.push(`portaliq/${schema}/${id}`)
	return id
}

/**
 * The register and schema the answers go to: a portal submit is an anonymous
 * write, so the form points at a schema that lets a public visitor create.
 * The spec makes its own and removes it afterwards.
 */
const DESTINATION = `e2e-verklaringen-${STAMP}`
const destinationIds: { schema?: number, register?: number } = {}

/** The resident's bearer: today's intake form renders only for a signed-in resident. */
let residentToken = ''

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
				naam: { type: 'string' },
				email: { type: 'string' },
				statements: { type: 'array' },
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
 * Store a published form through buildiq's own form API and remember its id.
 * Not through OpenRegister's objects route: a JSON body's `register` and
 * `schema` replace the route's own parameters in Nextcloud's request, so the
 * form row would land in the destination register.
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
 * The portal, the form, its binding and the page.
 *
 * @param request The request fixture.
 */
async function seedForm(request: APIRequestContext): Promise<void> {
	const caseType = `woo-verzoek-${STAMP}`

	await seed(request, 'portal', {
		slug: PORTAL,
		title: `Verklaringen ${STAMP}`,
		status: 'published',
		organisation: 'dev-org',
		locales: ['nl'],
		authentication: { modes: ['public'] },
		statementTexts: {
			truth: { text: TRUTH, version: '2026-1' },
			privacy: { text: PRIVACY, version: '2026-2' },
		},
	})
	await seedDestination(request)
	await seedRegistrationForm(request, {
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
			{ name: 'naam', label: 'Naam', order: 1, required: true },
			{
				name: 'email',
				label: 'E-mailadres',
				type: 'email',
				order: 2,
				required: true,
			},
		],
	})
	await seed(request, 'portalFormBinding', {
		portal: PORTAL,
		route: ROUTE,
		typeRegister: 'portaliq',
		typeSchema: 'portalCaseType',
		typeId: caseType,
		audience: 'client',
		formRegister: 'buildiq',
		formSchema: 'registrationForm',
		destination: { register: DESTINATION, schema: DESTINATION, subjectField: 'subjectRef' },
		intakeKind: 'hosted',
		status: 'published',
		intro: {
			enabled: true,
			lead: 'Vraag documenten van de gemeente op.',
			blocks: [
				{
					title: 'Wat heeft u nodig?',
					text: 'Een omschrijving van wat u zoekt.',
					items: ['Uw e-mailadres'],
				},
			],
		},
		statements: { truth: { required: true }, privacy: { required: true } },
		confirmation: {
			title: 'Uw verzoek is verstuurd',
			body: 'Uw kenmerk is {reference}. Wij beslissen uiterlijk op {deadline}.',
			next: [
				{
					title: 'Wij bekijken uw verzoek',
					text: 'U hoort binnen twee weken van ons.',
				},
			],
		},
		confirmationMail: { enabled: true },
	})
	await seed(request, 'page', {
		title: `Woo-verzoek ${STAMP}`,
		route: PAGE_ROUTE,
		portal: PORTAL,
		status: 'published',
		locale: 'nl',
		summary: 'Testpagina met een formulier met een introductie en verklaringen.',
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
 * A page in a fresh anonymous context, opened on the form's site page.
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
	const params = new URLSearchParams({ portal: PORTAL, route: PAGE_ROUTE })
	await page.goto(`${SITE_PATH}?${params.toString()}`)
	await expect(page.getByTestId('intake-form')).toBeVisible({ timeout: 60_000 })
	return page
}

/**
 * Press Tab until a control has the focus, as a keyboard user would.
 *
 * @param page The page.
 * @param target The control.
 */
async function tabTo(page: Page, target: Locator): Promise<void> {
	await expect(target).toBeVisible()
	for (let presses = 0; presses < 60; presses++) {
		if (await target.evaluate((element) => element === document.activeElement)) {
			return
		}
		await page.keyboard.press('Tab')
	}
	throw new Error('the control was not reached with Tab')
}

/**
 * Start the form from its introduction and fill both answers by keyboard.
 *
 * @param page The page.
 */
async function startAndAnswer(page: Page): Promise<void> {
	await tabTo(page, page.getByTestId('form-intro-start'))
	await page.keyboard.press('Enter')
	await tabTo(page, page.getByTestId('intake-field-naam'))
	await page.keyboard.type('Sanne de Vries')
	await tabTo(page, page.getByTestId('intake-field-email'))
	await page.keyboard.type(EMAIL)
}

test.describe('form-statements-intro-and-confirmation-mail', () => {
	test.beforeAll(async ({ request }) => {
		await seedForm(request)
		residentToken = await devLogin(request, `e2e-verklaringen-${STAMP}`, 'client', 'dev-org')
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

	// @e2e portal-form-statements::reading-before-starting
	test('Reading before starting: the introduction, then Start opens the form', async ({
		browser,
	}) => {
		const page = await openForm(browser)

		const intro = page.getByTestId('form-intro')
		await expect(intro).toContainText('Voordat u begint')
		await expect(page.getByTestId('form-intro-lead')).toHaveText(
			'Vraag documenten van de gemeente op.',
		)
		await expect(page.getByTestId('form-intro-block')).toContainText(
			'Wat heeft u nodig?',
		)
		await expect(page.getByTestId('intake-field-naam')).toHaveCount(0)

		await tabTo(page, page.getByTestId('form-intro-start'))
		await page.keyboard.press('Enter')
		await expect(intro).toHaveCount(0)
		await expect(page.getByTestId('intake-field-naam')).toBeVisible()

		await page.context().close()
	})

	// @e2e portal-form-statements::forgetting-the-privacy-consent
	test('Forgetting the privacy consent: the statement is named and nothing is sent', async ({
		browser,
	}) => {
		const page = await openForm(browser)
		const submits: Request[] = []
		page.on('request', (sent) => {
			if (
				sent.method() === 'POST'
				&& sent.url().includes('/portal/api/intake/submit')
			) {
				submits.push(sent)
			}
		})

		await startAndAnswer(page)
		const statements = page.getByTestId('form-statements')
		await expect(statements).toContainText('Verklaringen')
		await expect(statements).toContainText(TRUTH)
		await expect(statements).toContainText(PRIVACY)

		await tabTo(page, page.getByTestId('statement-truth'))
		await page.keyboard.press('Space')
		await expect(page.getByTestId('statement-truth')).toBeChecked()

		await tabTo(page, page.getByTestId('intake-form-submit'))
		await page.keyboard.press('Enter')

		await expect(page.getByTestId('statement-error-privacy')).toBeVisible()
		await expect(page.getByTestId('statement-error-truth')).toHaveCount(0)
		await expect(page.getByTestId('intake-form-done')).toHaveCount(0)
		expect(submits).toHaveLength(0)

		await page.context().close()
	})

	// @e2e portal-form-statements::the-record
	// @e2e portal-form-statements::mail-with-a-summary
	// @e2e portal-form-statements::no-decision-date
	test('Mail with a summary: both statements sent, the confirmation page and the address it went to', async ({
		browser,
	}) => {
		const page = await openForm(browser)

		await startAndAnswer(page)
		await tabTo(page, page.getByTestId('statement-truth'))
		await page.keyboard.press('Space')
		await tabTo(page, page.getByTestId('statement-privacy'))
		await page.keyboard.press('Space')

		const submitted = page.waitForRequest(
			(sent) =>
				sent.method() === 'POST'
				&& sent.url().includes('/portal/api/intake/submit'),
		)
		await tabTo(page, page.getByTestId('intake-form-submit'))
		await page.keyboard.press('Enter')

		// The submit carries both accepted statements (the stored record with
		// its text version and time is PHPUnit's, see the header).
		const body = (await submitted).postDataJSON() as { statements?: string[] }
		expect([...(body.statements ?? [])].sort()).toEqual(['privacy', 'truth'])

		const done = page.getByTestId('intake-form-done')
		await expect(done).toBeVisible({ timeout: 30_000 })
		await expect(page.getByTestId('intake-form-done-heading')).toHaveText(
			'Uw verzoek is verstuurd',
		)
		const reference = (
			await page.getByTestId('intake-form-reference').innerText()
		).trim()
		expect(reference).not.toBe('')
		await expect(page.getByTestId('intake-form-done-body')).toContainText(
			reference,
		)

		// No decision date exists at submit time, so its sentence is left out
		// and no placeholder leaks.
		await expect(done).not.toContainText('Wij beslissen uiterlijk')
		await expect(done).not.toContainText('{deadline}')

		await expect(page.getByTestId('intake-form-next-steps')).toContainText(
			'Wij bekijken uw verzoek',
		)
		await expect(page.getByTestId('intake-form-mailed')).toContainText(
			`Wij hebben een bevestiging gestuurd naar ${EMAIL}`,
		)
		await expect(page.getByTestId('intake-form-print')).toBeVisible()

		await page.context().close()
	})
})
