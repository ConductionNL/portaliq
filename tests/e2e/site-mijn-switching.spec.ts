/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-mijn-switching: a guardian switches child on a `records` page, and
 * Linda's acting-for bar on a phone width (site-mijn-omgeving-components
 * REQ-SMO-008). Seeds its own contribution, records and mandate, and
 * deletes them afterwards, so it runs on CI and on a demo instance alike.
 * The children are portalCase rows standing in for a learner collection.
 *
 * Environment (all optional): PORTALIQ_E2E_PORTAL, PORTALIQ_E2E_ORG,
 * PORTALIQ_E2E_AUDIENCE, PORTALIQ_E2E_ADMIN, as in
 * site-mijn-omgeving-live.spec.ts.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { PORTAL_API, seedSiteSession, siteAddress } from './portal-nav.ts'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from(process.env.PORTALIQ_E2E_ADMIN || 'admin:admin').toString(
	'base64',
)
const ORGANISATION = process.env.PORTALIQ_E2E_ORG || 'dev-org'
const AUDIENCE = process.env.PORTALIQ_E2E_AUDIENCE || 'client'

/** What this run created, as `schema/id`, to delete afterwards. */
const created: string[] = []

/**
 * Create one object through OpenRegister's own object API, as the admin.
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
		headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
		data,
	})
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for ${schema}`,
	).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	created.push(`${schema}/${id}`)
	return id
}

/**
 * A contribution with a cases collection and a `records` page over it, two
 * "children" and the resident signed in.
 *
 * @param request The request fixture.
 * @param page The page.
 * @return What the tests look for.
 */
async function signIn(
	request: APIRequestContext,
	page: Page,
): Promise<{
	stamp: number
	token: string
	subjectRef: string
	pageId: string
	sami: string
}> {
	const stamp = Date.now()
	const pageId = `kinderen-${stamp}`
	await seed(request, 'portalPage', {
		label: `Kinderen ${stamp}`,
		audience: AUDIENCE,
		status: 'active',
		collections: [
			{
				id: `kind-${stamp}`,
				kind: 'cases',
				label: 'Kinderen',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
			},
		],
		pages: [
			{
				id: pageId,
				label: `Kinderen ${stamp}`,
				records: { collection: `kind-${stamp}`, titleFields: ['reference'] },
				blocks: [{ type: 'detail', collection: `kind-${stamp}` }],
			},
		],
	})
	const subjectRef = `subject-switch-${stamp}`
	const mine = { subjectRef, organisation: ORGANISATION }
	await seed(request, 'portalCase', { ...mine, reference: 'Vera' })
	const sami = await seed(request, 'portalCase', { ...mine, reference: 'Sami' })
	const login = await request.post(`${PORTAL_API}/session/dev-login`, {
		data: { subjectRef, audience: AUDIENCE, organisation: ORGANISATION },
	})
	expect(login.ok(), 'dev-login must be enabled (debug mode)').toBeTruthy()
	const { token } = await login.json()
	await seedSiteSession(page, token)
	return { stamp, token, subjectRef, pageId, sami }
}

/**
 * The route of a seeded page, read from what the auth edge serves.
 *
 * @param request The request fixture.
 * @param token The resident's bearer.
 * @param pageId The page id.
 * @return The route.
 */
async function routeOf(
	request: APIRequestContext,
	token: string,
	pageId: string,
): Promise<string> {
	const answer = await request.get(`${PORTAL_API}/contributions`, {
		headers: { Authorization: `Bearer ${token}` },
	})
	const contributions = (await answer.json()).contributions as Array<{
		app: string
		pages?: Array<{ id: string }>
	}>
	const owner = contributions.find((c) =>
		(c.pages || []).some((p) => p.id === pageId),
	)
	expect(owner, 'the seeded contribution is served').toBeTruthy()
	return `/mijn/${owner!.app}/${pageId}`
}

test.describe('site-mijn-switching', () => {
	test.afterAll(async ({ request }) => {
		for (const entry of created.splice(0)) {
			await request.delete(`${OR_OBJECTS_BASE}/portaliq/${entry}`, {
				headers: {
					Authorization: `Basic ${ADMIN}`,
					'OCS-APIRequest': 'true',
				},
			})
		}
	})

	// @e2e site-mijn-omgeving::a-guardian-switches-child-main-dc-html
	test('the guardian switches child: the route names the child and the page shows it', async ({
		page,
		request,
	}) => {
		const { token, pageId, sami } = await signIn(request, page)
		const route = await routeOf(request, token, pageId)
		await page.goto(siteAddress(route))
		const switcher = page.getByTestId('mijn-record-switcher')
		await expect(switcher.getByRole('radio')).toHaveCount(2)
		await expect(switcher.getByRole('radio', { name: /Vera/ })).toBeChecked()

		await switcher.getByText('Sami', { exact: true }).click()
		await expect(switcher.getByRole('radio', { name: /Sami/ })).toBeChecked()
		await expect(page.getByTestId('record-head')).toContainText('Sami')
		await expect(page.getByTestId('record-head').locator('h2')).toBeFocused()
		await expect(page).toHaveURL(
			new RegExp(`route=${encodeURIComponent(`${route}/${sami}`)}`),
		)
	})

	// @e2e site-mijn-omgeving::linda-acts-for-her-father-dossiqphone-dc-html
	test('Linda acts for her father: the bar names him on every page, on a phone', async ({
		page,
		request,
	}) => {
		await page.setViewportSize({ width: 375, height: 812 })
		const { subjectRef } = await signIn(request, page)
		await seed(request, 'portalMandate', {
			subjectRef,
			organisation: ORGANISATION,
			onBehalfOf: `subject:vader-${Date.now()}`,
			label: 'uw vader, H. Bakker',
			status: 'active',
		})
		await page.goto(siteAddress('/mijn/cases'))
		await expect(page.getByTestId('my-cases')).toBeVisible()
		await page
			.locator('#pq-acting-for')
			.selectOption({ label: 'uw vader, H. Bakker' })

		const bar = page.getByTestId('mijn-acting-for-bar')
		await expect(bar).toContainText(
			/U regelt nu zaken voor uw vader, H\. Bakker|You are now acting for uw vader, H\. Bakker/,
		)
		await expect(
			page.getByRole('region', {
				name: /Namens wie u werkt|On whose behalf you act/,
			}),
		).toBeVisible()
		const box = await bar.boundingBox()
		expect(box && box.width <= 375, 'the bar fits the phone width').toBeTruthy()

		await page.goto(siteAddress('/mijn/inbox'))
		await expect(page.getByTestId('mijn-acting-for-bar')).toBeVisible()
		await page.getByTestId('mijn-acting-for-bar-back').click()
		await expect(page.getByTestId('mijn-acting-for-bar')).toHaveCount(0)
	})
})
