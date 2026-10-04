/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-mijn-action-rows: a contributed page with an `inbox` block shows the
 * resident's newest messages as Den Haag action rows, an unread one with
 * "Nieuw" read as part of its link, and a resident without messages reads a
 * sentence instead of an empty list (site-mijn-omgeving-components wave 2).
 * Seeds through OpenRegister's own object API, as portal-inbox.spec.ts does.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { PORTAL_API, seedSiteSession, siteAddress } from './portal-nav.ts'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from('admin:admin').toString('base64')
// A demo instance names its own organisation and audience (see
// site-mijn-omgeving-live.spec.ts for the variables).
const ORGANISATION = process.env.PORTALIQ_E2E_ORG || 'e2e-org'
const AUDIENCE = process.env.PORTALIQ_E2E_AUDIENCE || 'supplier'

/**
 * Create one object through OpenRegister's own object API, as the dev admin.
 *
 * @param request The request fixture.
 * @param schema The portaliq schema.
 * @param data The object.
 * @return Nothing.
 */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<void> {
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/${schema}`, {
		headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
		data,
	})
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for ${schema}`,
	).toBeTruthy()
}

/**
 * A page "Overzicht <stamp>" with an inbox block, the given messages for a
 * fresh supplier, and that supplier signed in on the site, on that page.
 *
 * @param request The request fixture.
 * @param page The page.
 * @param messages The messages.
 * @return Nothing.
 */
async function openOverview(
	request: APIRequestContext,
	page: Page,
	messages: Array<Record<string, unknown>>,
): Promise<void> {
	const stamp = Date.now()
	const label = `Overzicht ${stamp}`
	await seed(request, 'portalPage', {
		label,
		audience: AUDIENCE,
		status: 'active',
		collections: [],
		pages: [
			{
				id: `overzicht-${stamp}`,
				label,
				blocks: [
					{ type: 'richText', markdown: 'Welkom' },
					{ type: 'inbox', label: 'Nieuwe berichten', limit: 3 },
				],
			},
		],
	})
	const subjectRef = `e2e-rows-${stamp}`
	for (const message of messages) {
		await seed(request, 'portalMessage', {
			subjectRef,
			organisation: ORGANISATION,
			...message,
		})
	}
	const login = await request.post(`${PORTAL_API}/session/dev-login`, {
		data: { subjectRef, audience: AUDIENCE, organisation: ORGANISATION },
	})
	expect(
		login.ok(),
		'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
	).toBeTruthy()
	const { token } = await login.json()
	await seedSiteSession(page, token)
	await page.goto(siteAddress())
	await page
		.getByTestId('site-resident-menu-link')
		.filter({ hasText: label })
		.first()
		.click()
	await expect(page.getByTestId('mijn-inbox-block')).toBeVisible()
}

test.describe('site-mijn-action-rows', () => {
	// @e2e site-mijn-omgeving::an-unread-message
	test('an unread message carries "Nieuw" inside its link', async ({
		page,
		request,
	}) => {
		await openOverview(request, page, [
			{
				subject: 'Wij hebben een vraag over uw Woo-verzoek',
				read: false,
				receivedAt: new Date().toISOString(),
			},
			{
				subject: 'Ontvangstbevestiging',
				read: true,
				receivedAt: '2026-01-01T09:00:00Z',
			},
		])
		const rows = page.getByTestId('mijn-action-row')
		await expect(rows).toHaveCount(2)
		const unread = rows.nth(0).getByRole('link')
		await expect(unread).toHaveAccessibleName(
			/Wij hebben een vraag over uw Woo-verzoek.*(Nieuw|New)/,
		)
		await expect(rows.nth(1)).not.toContainText(/Nieuw|New/)
	})

	// @e2e site-mijn-omgeving::no-messages-yet
	test('no messages yet reads as a sentence, with no empty list', async ({
		page,
		request,
	}) => {
		await openOverview(request, page, [])
		const block = page.getByTestId('mijn-inbox-block')
		await expect(block.getByTestId('mijn-empty-state')).toContainText(
			/U heeft nog geen berichten\.|You have no messages yet\./,
		)
		await expect(block.locator('ul')).toHaveCount(0)
	})
})
