/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for intake-conditional-questions-and-drafts: a question that
 * shows only when another answer applies, on the site's intake widget and in
 * the embedded form, and a submission from a site page that is accepted
 * without the hidden required question.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here:
 *   - a question appears when it applies and disappears again (REQ-ICQ-001)
 *   - the embedded form behaves the same (REQ-ICQ-001)
 *   - a hidden required question does not block submitting (REQ-ICQ-002)
 *   - a resident submits from a site page and sees the reference (REQ-ICQ-004)
 *
 * NOT anchored here:
 *   - a hidden answer never reaches the case: PortalFormValidatorTest
 *     (testHiddenAnswerIsDropped)
 *   - a condition the portal cannot check: PortalFormBindingResolverTest
 *     (testNonLocalConditionResolvesToNoForm)
 *   - save and continue later (REQ-ICQ-005): tasks T06 and T07 wait on
 *     openregister's journey run service; their scenarios are added here when
 *     the draft routes exist.
 *
 * Every visitor here is anonymous: each test opens its own browser context
 * with an empty storage state. The spec seeds its own published form, binding
 * and site page, and deletes them afterwards. The instance comes from the
 * environment (BASE_URL / PLAYWRIGHT_BASE_URL, see base-url.ts).
 *
 * Environment (optional): PORTALIQ_E2E_PORTAL, PORTALIQ_E2E_ADMIN.
 *
 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md
 */

import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'
import { oneOf, SITE_PORTAL, siteAddress } from './portal-nav.ts'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const EMBED_PATH = '/apps/portaliq/portal/embed'
const ADMIN = Buffer.from(process.env.PORTALIQ_E2E_ADMIN || 'admin:admin').toString(
	'base64',
)
const ADMIN_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }
const EMPTY_STATE = { cookies: [], origins: [] }

/** The condition under test: the partner's name only for those who live together. */
const LIVES_TOGETHER = { field: 'samenwonen', op: 'eq', value: 'Ja' }

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
	expect(res.ok(), `OpenRegister objects#create must be reachable for ${schema}`).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	created.push(`${schema}/${id}`)
	return id
}

/**
 * A published form with a conditional required question, its binding (also
 * embeddable), and a site page with the intake widget on that binding.
 *
 * @param request The request fixture.
 * @return The binding's route and the page's route.
 */
async function seedConditionalForm(
	request: APIRequestContext,
): Promise<{ route: string; pageRoute: string }> {
	const stamp = Date.now()
	const caseType = `samenwonen-${stamp}`
	const route = `aanvragen/samenwonen-${stamp}`
	const pageRoute = `/e2e/samenwonen-${stamp}`

	await seed(request, 'registrationForm', {
		caseType,
		audience: 'client',
		name: `${caseType}-client`,
		status: 'published',
		fields: [
			{
				name: 'samenwonen',
				label: 'Woont u samen?',
				order: 1,
				required: true,
				options: ['Ja', 'Nee'],
			},
			{
				name: 'partnerNaam',
				label: 'Naam van uw partner',
				order: 2,
				required: true,
				visibleWhen: LIVES_TOGETHER,
			},
		],
	})
	await seed(request, 'portalFormBinding', {
		portal: SITE_PORTAL,
		route,
		typeRegister: 'portaliq',
		typeSchema: 'portalCaseType',
		typeId: caseType,
		audience: 'client',
		formRegister: 'portaliq',
		formSchema: 'registrationForm',
		destination: { register: 'portaliq', schema: 'portalCase' },
		intakeKind: 'hosted',
		allowedOrigins: ['https://www.gemeente.nl'],
		status: 'published',
	})
	await seed(request, 'page', {
		title: `Samenwonen ${stamp}`,
		route: pageRoute,
		portal: SITE_PORTAL,
		status: 'published',
		locale: 'nl',
		summary: 'Testpagina met een aanvraagformulier met een voorwaardelijke vraag.',
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

/**
 * A page in a fresh anonymous context.
 *
 * @param browser The browser fixture.
 * @return The page.
 */
async function anonymousPage(browser: Browser): Promise<Page> {
	const context = await browser.newContext({ baseURL: BASE_URL, storageState: EMPTY_STATE })
	return context.newPage()
}

test.describe('intake-conditional-questions-and-drafts', () => {
	test.afterAll(async ({ request }) => {
		for (const ref of created.splice(0).reverse()) {
			await request.delete(`${OR_OBJECTS_BASE}/portaliq/${ref}`, {
				headers: ADMIN_HEADERS,
			})
		}
	})

	// @e2e portal-intake-form::a-question-appears-when-it-applies
	test('a question appears when it applies, and disappears again', async ({
		browser,
		request,
	}) => {
		const { pageRoute } = await seedConditionalForm(request)
		const page = await anonymousPage(browser)

		await page.goto(siteAddress(pageRoute))
		const form = page.getByTestId('intake-form')
		await expect(form).toBeVisible()
		const livesTogether = page.getByTestId('intake-field-samenwonen')
		const partner = page.getByTestId('intake-field-partnerNaam')

		// Nothing answered yet: the condition is false, the question is not asked.
		await expect(livesTogether).toBeVisible()
		await expect(partner).toHaveCount(0)

		await livesTogether.selectOption('Ja')
		await expect(partner).toBeVisible()

		await livesTogether.selectOption('Nee')
		await expect(partner).toHaveCount(0)

		await page.context().close()
	})

	// @e2e portal-intake-form::the-embedded-form-behaves-the-same
	test('the embedded form behaves the same', async ({ browser, request }) => {
		const { route } = await seedConditionalForm(request)
		const page = await anonymousPage(browser)

		await page.goto(`${EMBED_PATH}?route=${encodeURIComponent(route)}`)
		await expect(page.getByTestId('embed-form')).toBeVisible()
		const livesTogether = page.getByTestId('embed-field-samenwonen')
		const partner = page.getByTestId('embed-field-partnerNaam')

		await livesTogether.fill('Nee')
		await expect(partner).toHaveCount(0)

		await livesTogether.fill('Ja')
		await expect(partner).toBeVisible()

		await page.context().close()
	})

	// @e2e portal-intake-form::a-hidden-required-question-does-not-block-submitting
	// @e2e portal-intake-form::a-resident-submits-a-request-from-a-site-page
	test('a resident submits from a site page without the hidden question, and sees the reference', async ({
		browser,
		request,
	}) => {
		const { pageRoute } = await seedConditionalForm(request)
		const page = await anonymousPage(browser)

		await page.goto(siteAddress(pageRoute))
		await expect(page.getByTestId('intake-form')).toBeVisible()
		await page.getByTestId('intake-field-samenwonen').selectOption('Nee')
		await expect(page.getByTestId('intake-field-partnerNaam')).toHaveCount(0)

		await page.getByTestId('intake-form-submit').click()

		// The hidden required question raised no error, and the case got a reference.
		await expect(page.getByTestId('intake-form-done')).toBeVisible()
		await expect(page.getByTestId('intake-form-reference')).not.toBeEmpty()
		await expect(page.getByTestId('intake-form-error')).toHaveCount(0)
		await expect(page.getByTestId('intake-form-done')).not.toContainText(
			oneOf('Naam van uw partner is verplicht', 'Name of your partner is required'),
		)

		await page.context().close()
	})
})
