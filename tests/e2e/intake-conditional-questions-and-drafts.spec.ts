/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for intake-conditional-questions-and-drafts on the public site:
 * a site page carries the intake widget bound to a request form, a question
 * appears only while it applies (REQ-ICQ-001), a hidden required question does
 * not stop the request (REQ-ICQ-002), and sending shows the reference
 * (REQ-ICQ-004).
 *
 * NOT anchored here, covered elsewhere and named so anyone can check:
 *   - the condition grammar, case by case: tests/visible-when-local.spec.mjs
 *     and tests/Unit/Service/Intake/VisibleWhenLocalTest.php over one fixture
 *   - the answers sent and the challenge proof: tests/intake-conditional-site.spec.mjs
 *   - a condition the portal cannot check refuses the form (REQ-ICQ-003):
 *     tests/Unit/Service/Intake/PortalFormBindingResolverTest.php
 *     (testNonLocalConditionResolvesToNoForm)
 *   - saving and resuming a draft (REQ-ICQ-005): not built, waits on
 *     openregister or-form-and-journey-registry (tasks T06, T07)
 *
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions. Run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test intake-conditional
 *
 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const OBJECTS = `${BASE}/index.php/apps/openregister/api/objects/portaliq`

const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

const PORTAL = 'open-tilburg'
const PAGE_ROUTE = '/e2e-samenwonen'
const BINDING_ROUTE = 'e2e-samenwonen'

const created: Array<{ schema: string; id: string }> = []

/**
 * An admin API context for OpenRegister's object API.
 *
 * @return the context
 */
async function adminApi(): Promise<APIRequestContext> {
	const basic = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	return request.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Authorization: `Basic ${basic}`,
			'Content-Type': 'application/json',
		},
	})
}

/**
 * Create one portaliq object and remember it for the clean-up.
 *
 * @param api the admin context
 * @param schema the portaliq schema
 * @param data the object
 * @return the id
 */
async function seed(
	api: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await api.post(`${OBJECTS}/${schema}`, { data })
	expect(res.ok(), await res.text()).toBe(true)
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	created.push({ schema, id })
	return id
}

test.beforeAll(async () => {
	const api = await adminApi()
	const caseType = await seed(api, 'portalCaseType', {
		name: 'E2E samenwonen',
		status: 'published',
	})
	await seed(api, 'registrationForm', {
		caseType,
		audience: 'client',
		name: 'Samenwonen melden',
		status: 'published',
		fields: [
			{
				name: 'together',
				label: 'Woont u samen?',
				options: ['ja', 'nee'],
				required: true,
			},
			{
				name: 'partner',
				label: 'Naam van uw partner',
				type: 'text',
				required: true,
				visibleWhen: { field: 'together', op: 'eq', value: 'ja' },
			},
		],
	})
	await seed(api, 'portalFormBinding', {
		portal: PORTAL,
		route: BINDING_ROUTE,
		typeRegister: 'portaliq',
		typeSchema: 'portalCaseType',
		typeId: caseType,
		audience: 'client',
		formRegister: 'portaliq',
		formSchema: 'registrationForm',
		caseRegister: 'portaliq',
		caseSchema: 'portalCase',
		intakeKind: 'hosted',
		status: 'published',
	})
	await seed(api, 'page', {
		title: 'Samenwonen melden',
		route: PAGE_ROUTE,
		portal: PORTAL,
		status: 'published',
		locale: 'nl',
		body: {
			type: 'grid',
			widgets: [
				{
					id: 'e2e-intake',
					widgetKey: 'intakeForm',
					slot: 'body',
					gridX: 0,
					gridY: 0,
					gridWidth: 12,
					gridHeight: 6,
					props: { route: BINDING_ROUTE },
				},
			],
		},
	})
	await api.dispose()
})

test.afterAll(async () => {
	const api = await adminApi()
	for (const { schema, id } of created.reverse()) {
		await api.delete(`${OBJECTS}/${schema}/${id}`)
	}
	await api.dispose()
})

test.describe('intake-conditional-questions-and-drafts', () => {
	test('a question appears when it applies and disappears when it does not', async ({
		page,
	}) => {
		await page.goto(`${SITE}?route=${PAGE_ROUTE}`)
		const together = page.getByTestId('intake-field-together')
		await expect(together).toBeVisible()
		await expect(page.getByTestId('intake-field-partner')).toHaveCount(0)

		await together.selectOption('ja')
		await expect(page.getByTestId('intake-field-partner')).toBeVisible()

		await together.selectOption('nee')
		await expect(page.getByTestId('intake-field-partner')).toHaveCount(0)
	})

	test('a resident submits from a site page and reads the reference; the hidden question does not stop them', async ({
		page,
	}) => {
		await page.goto(`${SITE}?route=${PAGE_ROUTE}`)
		await page.getByTestId('intake-field-together').selectOption('nee')
		await page.getByTestId('intake-form-submit').click()
		await expect(page.getByTestId('intake-form-reference')).not.toBeEmpty()
	})
})
