/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-mijn-omgeving-live: the mijn omgeving parts that no app declares yet,
 * seeded by the spec itself so it runs on CI and on a demo instance alike
 * (site-mijn-omgeving-components waves 2 to 5). One portalPage contribution
 * holds a home page with a `tasks`, a `cases` and an `inbox` block, and a
 * `menu: false` page. The cases collection uses portalCase's own fields as
 * stand-ins: `withdrawnAt` as the answer date, `toelichting` as whose turn
 * it is, worded through `fieldConfigs.valueLabels`. Steps need an app's
 * provider, so they are covered in node (tests/mijn-cases.spec.mjs).
 *
 * Everything it seeds is deleted again afterwards, so a shared demo
 * instance keeps no test pages on its residents' home.
 *
 * Environment (all optional): PORTALIQ_E2E_PORTAL (default open-tilburg),
 * PORTALIQ_E2E_ORG (default dev-org), PORTALIQ_E2E_AUDIENCE (default
 * client), PORTALIQ_E2E_ADMIN (default admin:admin).
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

/** A day so many days from now, as an ISO date-time at noon. */
function inDays(days: number): string {
	const day = new Date(Date.now() + days * 24 * 60 * 60 * 1000)
	day.setHours(12, 0, 0, 0)
	return day.toISOString()
}

/**
 * The contribution, its records, and the resident signed in on the site.
 *
 * @param request The request fixture.
 * @param page The page.
 * @return The ids the tests look for.
 */
async function signIn(
	request: APIRequestContext,
	page: Page,
): Promise<{ stamp: number; token: string; home: string; hidden: string }> {
	const stamp = Date.now()
	const home = `overzicht-${stamp}`
	const hidden = `archief-${stamp}`
	await seed(request, 'portalPage', {
		label: `Mijn omgeving ${stamp}`,
		audience: AUDIENCE,
		status: 'active',
		collections: [
			{
				id: `zaken-${stamp}`,
				kind: 'cases',
				label: 'Zaken',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
				fields: ['reference', 'status', 'toelichting', 'withdrawnAt'],
				dueField: 'withdrawnAt',
				turnField: 'toelichting',
				fieldConfigs: {
					toelichting: {
						valueLabels: {
							resident: 'U bent aan zet',
							organisation: 'De gemeente is aan zet',
						},
					},
				},
			},
			{
				id: `vragen-${stamp}`,
				label: 'Vragen aan u',
				register: 'portaliq',
				schema: 'portalMessage',
				scopeField: 'subjectRef',
				fields: ['subject', 'term'],
			},
			{
				id: `berichten-${stamp}`,
				kind: 'inbox',
				label: 'Berichten',
				register: 'portaliq',
				schema: 'portalMessage',
				scopeField: 'subjectRef',
			},
		],
		pages: [
			{
				id: home,
				label: `Overzicht ${stamp}`,
				icon: 'HomeOutline',
				home: true,
				blocks: [
					{
						type: 'tasks',
						collection: `vragen-${stamp}`,
						dueField: 'term',
						titleFields: ['subject'],
						label: 'Dit moet u nog doen',
					},
					{
						type: 'cases',
						collection: `zaken-${stamp}`,
						open: true,
						label: 'Lopende zaken',
					},
					{
						type: 'inbox',
						collection: `berichten-${stamp}`,
						label: 'Nieuwe berichten',
					},
				],
			},
			{
				id: hidden,
				label: `Archief ${stamp}`,
				menu: false,
				blocks: [{ type: 'collection', collection: `zaken-${stamp}` }],
			},
		],
	})
	const subjectRef = `subject-live-${stamp}`
	const mine = { subjectRef, organisation: ORGANISATION }
	await seed(request, 'portalCase', {
		...mine,
		reference: 'Woo-verzoek bomenkap Lindelaan',
		status: 'In behandeling',
		toelichting: 'resident',
		withdrawnAt: inDays(27),
	})
	await seed(request, 'portalMessage', {
		...mine,
		subject: 'Beantwoord onze vraag over uw Woo-verzoek',
		term: inDays(9),
		read: false,
		receivedAt: new Date().toISOString(),
	})
	const login = await request.post(`${PORTAL_API}/session/dev-login`, {
		data: { subjectRef, audience: AUDIENCE, organisation: ORGANISATION },
	})
	expect(login.ok(), 'dev-login must be enabled (debug mode)').toBeTruthy()
	const { token } = await login.json()
	await seedSiteSession(page, token)
	return { stamp, token, home, hidden }
}

test.describe('site-mijn-omgeving-live', () => {
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

	// @e2e site-mijn-omgeving::sanne-s-overview-in-dossiqoverview-dc-html
	test('/mijn opens the home: greeting, what is still to do, case cards, new messages', async ({
		page,
		request,
	}) => {
		const { home } = await signIn(request, page)
		await page.goto(siteAddress('/mijn'))
		await expect(page.getByTestId('mijn-home')).toBeVisible()
		const section = page.locator(`[data-page="${home}"]`)

		const tasks = section.getByTestId('mijn-tasks-block')
		await expect(tasks).toContainText(
			'Beantwoord onze vraag over uw Woo-verzoek',
		)
		await expect(tasks.getByTestId('mijn-data-badge')).toHaveText(
			/Voor|Nog|Before|left/,
		)

		const card = section.getByTestId('mijn-case-card').first()
		await expect(card).toContainText('Woo-verzoek bomenkap Lindelaan')
		await expect(card).toContainText('In behandeling')
		await expect(card).toContainText(/Antwoord uiterlijk|Answer by/)
		await expect(card).toContainText('U bent aan zet')
		await expect(card.locator('.denhaag-case-card__background')).toHaveCount(1)

		const inbox = section.getByTestId('mijn-inbox-block')
		await expect(inbox.getByRole('link').first()).toHaveAccessibleName(
			/Nieuw|New/,
		)
	})

	// @e2e site-mijn-omgeving::old-routes-stay-the-menu-shrinks
	test('a menu: false page is not in the menu, and its route still opens it', async ({
		page,
		request,
	}) => {
		const { stamp, token, hidden } = await signIn(request, page)
		await page.goto(siteAddress('/mijn'))
		const menu = page.getByTestId('site-resident-menu')
		await expect(menu).toContainText(`Overzicht ${stamp}`)
		await expect(menu).not.toContainText(`Archief ${stamp}`)

		const answer = await request.get(`${PORTAL_API}/contributions`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		const contributions = (await answer.json()).contributions as Array<{
			app: string
			pages?: Array<{ id: string }>
		}>
		const owner = contributions.find((c) =>
			(c.pages || []).some((p) => p.id === hidden),
		)
		expect(owner, 'the seeded contribution is served').toBeTruthy()
		await page.goto(siteAddress(`/mijn/${owner!.app}/${hidden}`))
		await expect(page.getByTestId('contribution-page')).toHaveAttribute(
			'data-page',
			hidden,
		)
	})
})
