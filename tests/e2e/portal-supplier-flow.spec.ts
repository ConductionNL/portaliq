/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-controller-http-test-coverage, tasks 4.1–4.4 (supplier-portal T13):
 * the supplier flow through the real router — dev-login, the session, the
 * contributions aggregate, an entitled collection read and its rows in the
 * DOM, a refusal for a (register, schema) the subject is not entitled to,
 * and a `type: create` action whose new row appears after the reload.
 *
 * WHAT IS REAL, ALL OF IT. Every call goes to portaliq's own controllers:
 * SessionController (`POST /portal/api/session/dev-login`,
 * `GET /portal/api/session`), ContributionController
 * (`GET /portal/api/contributions`,
 * `GET|POST /portal/api/collections/{register}/{schema}`), and the site's
 * signed-in area. The demo contribution is portaliq's own seeded
 * `portalPage` "Voorbeeld" (lib/Settings/portaliq_register.json, slug
 * `dev-supplier-demo`): collection `exampleCollection` over
 * `portaliq/exampleDocument` and create action `createExample`. No sibling
 * app takes part, so nothing is stubbed with page.route.
 *
 * Requires dev-login on the target (debug mode or `dev_login_enabled`);
 * devLogin() fails with the reason otherwise. Each test runs in a fresh
 * context with an empty storage state and a fresh subject, so no row of an
 * earlier run is in view. The base URL comes from tests/e2e/base-url.ts.
 *
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#4.1
 * @spec openspec/changes/portal-controller-http-test-coverage/specs/supplier-portal/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	devLogin,
	openPortaliqDemoPage,
	PORTAL_API,
	seedSiteSession,
	siteAddress,
} from './portal-nav.ts'

test.use({ storageState: { cookies: [], origins: [] } })

const AUDIENCE = 'supplier'
const ORGANISATION = 'e2e-org'

/** The demo contribution's entitled collection and its create action. */
const REGISTER = 'portaliq'
const SCHEMA = 'exampleDocument'
const COLLECTION = 'exampleCollection'
const CREATE_ACTION = 'createExample'

/** A combination the spec's IDOR scenario names; no contribution declares it. */
const FOREIGN = { register: 'otherRegister', schema: 'otherSchema' }

type Aggregate = { contributions?: Array<Record<string, any>> }

/**
 * A fresh subject reference, unique per call.
 *
 * @param label what the subject is for
 * @return the reference
 */
function freshSubject(label: string): string {
	return `e2e-supplier-${label}-${Date.now()}-${Math.floor(Math.random() * 10000)}`
}

/**
 * The bearer header for a minted token.
 *
 * @param token the bearer
 * @return the headers
 */
function bearer(token: string): Record<string, string> {
	return { Authorization: `Bearer ${token}`, Accept: 'application/json' }
}

/**
 * Every (register, schema) the aggregate lists as a collection, as
 * `register/schema`.
 *
 * @param aggregate the contributions aggregate
 * @return the pairs
 */
function collectionPairs(aggregate: Aggregate): string[] {
	return (aggregate.contributions ?? []).flatMap((contribution) =>
		(contribution.collections ?? []).map(
			(collection: Record<string, string>) => `${collection.register}/${collection.schema}`,
		),
	)
}

/**
 * The titles of the subject's rows in the entitled collection, read through
 * the API.
 *
 * @param request the request fixture
 * @param token the subject's bearer
 * @return the titles
 */
async function titlesOf(request: APIRequestContext, token: string): Promise<string[]> {
	const response = await request.get(
		`${PORTAL_API}/collections/${REGISTER}/${SCHEMA}?collection=${COLLECTION}`,
		{ headers: bearer(token) },
	)
	expect(response.status()).toBe(200)
	const body = await response.json()
	return (body.objects ?? []).map((row: Record<string, string>) => row.title)
}

test.describe('portal-supplier-flow', () => {
	// Task 4.1 — Scenario: End-to-end supplier flow is automated (dev-login, session, contributions)
	test('dev-login yields an authenticated session, and the aggregate lists the demo contribution', async ({
		request,
	}) => {
		const subjectRef = freshSubject('session')
		const token = await devLogin(request, subjectRef, AUDIENCE, ORGANISATION)

		const session = await request.get(`${PORTAL_API}/session`, { headers: bearer(token) })
		expect(session.status()).toBe(200)
		expect(await session.json()).toMatchObject({
			authenticated: true,
			subjectRef,
			audience: AUDIENCE,
			organisation: ORGANISATION,
		})

		// Without a bearer the same route says so, rather than guessing a subject.
		const anonymous = await request.get(`${PORTAL_API}/session`)
		expect((await anonymous.json()).authenticated).toBe(false)

		const contributions = await request.get(`${PORTAL_API}/contributions`, { headers: bearer(token) })
		expect(contributions.status()).toBe(200)
		const aggregate = (await contributions.json()) as Aggregate
		const demo = (aggregate.contributions ?? []).find((contribution) =>
			(contribution.collections ?? []).some((collection: Record<string, string>) => collection.id === COLLECTION),
		)
		expect(demo, 'the aggregate lists the demo "Voorbeeld" contribution').toBeTruthy()
		expect(demo?.app).toBe('portaliq')
		expect(
			(demo?.actions ?? []).some(
				(action: Record<string, string>) => action.id === CREATE_ACTION && action.type === 'create',
			),
		).toBe(true)
	})

	// Task 4.2 — an entitled collection answers 200 and its rows render in the DOM.
	// Also the spec's "Ownership stamping is regression-tested": a smuggled subjectRef is ignored.
	test('an entitled collection answers 200, its row renders, and a smuggled subjectRef is not honoured', async ({
		page,
		request,
	}) => {
		const subjectRef = freshSubject('reader')
		const other = freshSubject('other')
		const token = await devLogin(request, subjectRef, AUDIENCE, ORGANISATION)
		const otherToken = await devLogin(request, other, AUDIENCE, ORGANISATION)

		// A row of this subject, created through the real create action with a
		// subjectRef that names someone else in the body.
		const title = `E2E supplier row ${Date.now()}`
		const created = await request.post(
			`${PORTAL_API}/collections/${REGISTER}/${SCHEMA}?actionId=${CREATE_ACTION}`,
			{ headers: bearer(token), data: { title, subjectRef: other } },
		)
		expect(created.ok(), `the create answered ${created.status()}`).toBe(true)

		// The server stamped its own subject: the row is the caller's, not the other's.
		expect(await titlesOf(request, token)).toContain(title)
		expect(await titlesOf(request, otherToken)).not.toContain(title)

		// And the site draws it.
		await seedSiteSession(page, token)
		await page.goto(siteAddress())
		await openPortaliqDemoPage(page)
		await expect(
			page.getByTestId('collection-table-row').filter({ hasText: title }),
		).toBeVisible({ timeout: 20_000 })
	})

	// Task 4.3 — Scenario: IDOR guard is regression-tested (a non-entitled combination is refused).
	test('a (register, schema) outside the subject\'s contributions is refused with 403, read or write', async ({
		request,
	}) => {
		const token = await devLogin(request, freshSubject('idor'), AUDIENCE, ORGANISATION)

		const aggregate = (await (
			await request.get(`${PORTAL_API}/contributions`, { headers: bearer(token) })
		).json()) as Aggregate
		const pairs = collectionPairs(aggregate)
		expect(pairs, 'the subject is entitled to the demo collection').toContain(`${REGISTER}/${SCHEMA}`)

		// The spec's own example, and a real portaliq schema that no
		// contribution of this subject lists: both are refused.
		const foreign = [FOREIGN, { register: 'portaliq', schema: 'portalFormBinding' }]
		for (const target of foreign) {
			expect(pairs, `${target.register}/${target.schema} must not be entitled`).not.toContain(
				`${target.register}/${target.schema}`,
			)
			const read = await request.get(
				`${PORTAL_API}/collections/${target.register}/${target.schema}`,
				{ headers: bearer(token) },
			)
			expect(read.status(), `reading ${target.register}/${target.schema}`).toBe(403)
			expect(await read.json()).toEqual({ error: 'forbidden' })
		}

		// No create action is declared for the foreign pair either.
		const write = await request.post(
			`${PORTAL_API}/collections/${FOREIGN.register}/${FOREIGN.schema}`,
			{ headers: bearer(token), data: { title: 'Mag niet' } },
		)
		expect(write.status()).toBe(403)
	})

	// Task 4.4 — a `type: create` action adds a row that appears after the collection reload.
	test('the demo create action adds a row that appears in the collection after the reload', async ({
		page,
		request,
	}) => {
		const token = await devLogin(request, freshSubject('create'), AUDIENCE, ORGANISATION)
		await seedSiteSession(page, token)
		await page.goto(siteAddress())
		await openPortaliqDemoPage(page)

		const title = `E2E supplier create ${Date.now()}`
		const posted = page.waitForResponse(
			(response) =>
				response.request().method() === 'POST'
				&& response.url().includes(`/portal/api/collections/${REGISTER}/${SCHEMA}`),
		)
		await page.getByLabel('Onderwerp').fill(title)
		await page.getByRole('button', { name: 'Aanmaken' }).click()
		const response = await posted
		expect(response.ok(), `the create answered ${response.status()}`).toBe(true)
		expect(response.url()).toContain(`actionId=${CREATE_ACTION}`)

		await expect(page.getByText('Voorbeeld aangemaakt')).toBeVisible({ timeout: 20_000 })
		await expect(
			page.getByTestId('collection-table-row').filter({ hasText: title }),
		).toBeVisible({ timeout: 20_000 })

		// And it is really there, not only drawn: the API lists it too.
		expect(await titlesOf(request, token)).toContain(title)
	})
})
