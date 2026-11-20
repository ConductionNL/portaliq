/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * case-actions-row-inputs-and-conditions: an endpoint row action on a
 * resident's own row asks for the fields it declares (a reason select from a
 * static options provider) and the inputs its row declares, is offered only
 * on the rows its `availableWhen` names (the reason shows elsewhere), and
 * shows what the target answered.
 *
 * The fixture contribution, its rows and the normaliser are real; the
 * contributing app (shillinq, filinq) is not on the test instance, so the
 * one request this spec stubs is the browser's call to the portal's row
 * forward, which records what the page sent and answers as the target
 * would. The server-side refusals (an undeclared input dropped, a 409 on an
 * unavailable row) are pinned by ContributionControllerTest.
 *
 * The browser context is a fresh one with an empty storage state: the
 * resident signs in through the portal's dev login, never as a Nextcloud
 * admin. The target comes from BASE_URL (see tests/e2e/base-url.ts).
 */

import type { APIRequestContext, Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	accountLink,
	PORTAL_API,
	seedSiteSession,
	siteAddress,
} from './portal-nav.ts'

test.use({ storageState: { cookies: [], origins: [] } })

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from('admin:admin').toString('base64')
const ORGANISATION = 'dev-org'

const BLOCKED = 'The withdrawal period ended on 4 October 2026.'

/**
 * Create one object through OpenRegister's own object API, as the dev admin
 * (seeding only; the browser never holds these credentials).
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
	return (body.id ?? body['@self']?.id) as string
}

/**
 * A contribution with one endpoint row action that declares a reason
 * select, row inputs and an availability field; two rows of one resident,
 * one where the action is offered and one where it is not; the page open.
 *
 * @param request The request fixture.
 * @param page The page.
 * @return Nothing.
 */
async function openRows(request: APIRequestContext, page: Page): Promise<void> {
	const stamp = Date.now()
	await seed(request, 'portalPage', {
		label: 'Mijn abonnementen',
		audience: 'client',
		status: 'active',
		collections: [
			{
				id: 'subscriptions',
				label: 'Mijn abonnementen',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
				fields: [
					'reference',
					'omschrijving',
					'cancellable',
					'blockedReason',
					'cancelInputs',
				],
				columns: [
					{ field: 'reference', label: 'Nummer' },
					{ field: 'omschrijving', label: 'Omschrijving' },
				],
				rowActions: ['cancel-subscription'],
			},
		],
		actions: [
			{
				id: 'cancel-subscription',
				type: 'endpoint-forward',
				label: 'Cancel subscription',
				endpoint: '/apps/portaliq/e2e/cancel-subscription',
				rowField: 'subscriptionId',
				fields: ['reasonCode'],
				fieldConfigs: { reasonCode: { label: 'Reason' } },
				optionsProviders: {
					reasonCode: {
						type: 'static',
						options: [
							{ value: 'too-expensive', label: 'Too expensive' },
							{ value: 'switched-provider', label: 'Switched provider' },
						],
					},
				},
				rowInputs: { from: 'cancelInputs', into: 'inputs' },
				availableWhen: { field: 'cancellable', equals: true },
				unavailableReasonField: 'blockedReason',
				confirmText: 'You cancel this subscription.',
			},
		],
		pages: [
			{
				id: 'mijn-abonnementen',
				label: 'Mijn abonnementen',
				blocks: [{ type: 'collection', collection: 'subscriptions' }],
			},
		],
	})
	const subjectRef = `subject-${stamp}-${Math.floor(Math.random() * 10000)}`
	await seed(request, 'portalCase', {
		subjectRef,
		organisation: ORGANISATION,
		reference: `ABO-${stamp}-1`,
		status: 'actief',
		omschrijving: 'Open abonnement',
		cancellable: true,
		cancelInputs: [{ name: 'endDate', label: 'End date', required: true }],
	})
	await seed(request, 'portalCase', {
		subjectRef,
		organisation: ORGANISATION,
		reference: `ABO-${stamp}-2`,
		status: 'actief',
		omschrijving: 'Gesloten abonnement',
		cancellable: false,
		blockedReason: BLOCKED,
	})
	const login = await request.post(`${PORTAL_API}/session/dev-login`, {
		data: { subjectRef, audience: 'client', organisation: ORGANISATION },
	})
	expect(
		login.ok(),
		'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
	).toBeTruthy()
	const { token } = await login.json()
	await seedSiteSession(page, token)
	await page.goto(siteAddress())
	await accountLink(page, 'portaliq/mijn-abonnementen').first().click()
	await expect(page.getByText('Open abonnement')).toBeVisible()
}

/**
 * Stub the portal's row forward for the action: record each body the page
 * sends and answer with `reply(n)` for the n-th call.
 *
 * @param page The page.
 * @param reply The answer per call: status and body.
 * @return The recorded bodies.
 */
async function stubForward(
	page: Page,
	reply: (n: number) => { status: number, body: Record<string, unknown> },
): Promise<Array<Record<string, unknown>>> {
	const sent: Array<Record<string, unknown>> = []
	await page.route('**/actions/cancel-subscription?**', async (route: Route) => {
		sent.push(route.request().postDataJSON() ?? {})
		const { status, body } = reply(sent.length)
		await route.fulfill({
			status,
			contentType: 'application/json',
			body: JSON.stringify(body),
		})
	})
	return sent
}

test.describe('case-actions-row-inputs-and-conditions', () => {
	// @e2e portal-row-action-inputs::the-withdrawal-period-has-ended
	// @e2e portal-row-action-inputs::the-withdrawal-button-reads-as-the-law-asks
	test('the action shows only on the row that offers it, the reason on the other', async ({
		page,
		request,
	}) => {
		await openRows(request, page)

		const actions = page.getByTestId('collection-table-action')
		await expect(actions).toHaveCount(1)
		await expect(actions.first()).toHaveText('Cancel subscription')
		await expect(page.getByTestId('collection-table-reason')).toHaveText(BLOCKED)
	})

	// @e2e portal-row-action-inputs::a-member-cancels-and-says-why
	// @e2e portal-row-action-inputs::the-cancellation-date-is-shown
	test('a member picks a reason, fills in the row input and reads the answer', async ({
		page,
		request,
	}) => {
		await openRows(request, page)
		const sent = await stubForward(page, () => ({
			status: 200,
			body: { message: 'Your subscription ends on 31 October 2026.' },
		}))

		await page.getByTestId('collection-table-action').first().click()
		const dialog = page.getByTestId('rowaction-confirm')
		await expect(dialog.getByTestId('rowaction-confirm-text')).toHaveText(
			'You cancel this subscription.',
		)
		await dialog.getByLabel('Reason').selectOption('switched-provider')
		await dialog.getByTestId('rowaction-input-endDate').fill('2026-10-31')
		await dialog.getByTestId('rowaction-continue').click()

		await expect(dialog.getByTestId('rowaction-status')).toHaveText(
			'Your subscription ends on 31 October 2026.',
		)
		expect(sent).toEqual([
			{ reasonCode: 'switched-provider', inputs: { endDate: '2026-10-31' } },
		])
	})

	// @e2e portal-row-action-inputs::a-member-cancels-without-a-reason
	// @e2e portal-row-action-inputs::a-wrong-iban-is-refused-on-its-field
	test('a member leaves the reason empty; a refusal on an input stays open under it', async ({
		page,
		request,
	}) => {
		await openRows(request, page)
		const sent = await stubForward(page, (n) =>
			n === 1
				? { status: 422, body: { error: 'invalid', errors: { endDate: 'This date has passed.' } } }
				: { status: 200, body: {} },
		)

		await page.getByTestId('collection-table-action').first().click()
		const dialog = page.getByTestId('rowaction-confirm')
		await dialog.getByTestId('rowaction-input-endDate').fill('2020-01-01')
		await dialog.getByTestId('rowaction-continue').click()

		await expect(dialog.getByTestId('rowaction-error-endDate')).toHaveText(
			'This date has passed.',
		)
		await expect(dialog.getByTestId('rowaction-continue')).toBeVisible()

		await dialog.getByTestId('rowaction-input-endDate').fill('2026-10-31')
		await dialog.getByTestId('rowaction-continue').click()
		await expect(dialog.getByTestId('rowaction-continue')).toHaveCount(0)

		// No reason was picked, so no reasonCode travels, on either try.
		expect(sent).toEqual([
			{ inputs: { endDate: '2020-01-01' } },
			{ inputs: { endDate: '2026-10-31' } },
		])
	})
})
