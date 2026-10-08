/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * operate-show-per-case-type: an administrator hides a case type in one
 * portal from the "Case types" widget on the portal's page, and it comes
 * back when shown again.
 *
 * Runs on the seed of seed-cms.sh (the portals open-tilburg and
 * open-venray). The resident half needs a case app whose `cases` collection
 * holds cases of two types for one resident; name them with
 * E2E_CASE_TYPE_HIDDEN and E2E_CASE_TYPE_SHOWN plus E2E_CASE_RESIDENT_TOKEN
 * (a bearer). Without them those tests skip and say why. The 404 on a
 * hidden case's address is pinned by
 * ContributionControllerTest::testHiddenCaseTypeIs404.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	ADMIN_HEADERS,
	APP,
	ENABLED,
	login,
	OR_OBJECTS,
	portalRecord,
} from './lib/traffic.ts'

const OTHER = 'open-venray'
const HIDDEN = process.env.E2E_CASE_TYPE_HIDDEN ?? ''
const SHOWN = process.env.E2E_CASE_TYPE_SHOWN ?? ''
const RESIDENT = process.env.E2E_CASE_RESIDENT_TOKEN ?? ''

/**
 * Save a portal's hidden case types through the admin route.
 *
 * @param request The request context.
 * @param slug The portal slug.
 * @param hidden The case type ids to hide.
 */
async function hide(
	request: APIRequestContext,
	slug: string,
	hidden: string[],
): Promise<void> {
	const res = await request.put(`${APP}/api/portals/${slug}/case-types`, {
		headers: ADMIN_HEADERS,
		data: { hiddenCaseTypes: hidden.map((typeId) => ({ typeId })) },
	})
	expect(res.status()).toBe(200)
}

/**
 * The case type ids in a resident's "My cases" as one portal serves them.
 *
 * @param request The request context.
 * @param slug The portal slug.
 * @return The case type ids listed.
 */
async function listedTypes(
	request: APIRequestContext,
	slug: string,
): Promise<string[]> {
	const res = await request.get(`${APP}/portal/api/my-cases`, {
		headers: { Authorization: `Bearer ${RESIDENT}`, 'X-Portaliq-Portal': slug },
	})
	expect(res.status()).toBe(200)
	const { cases } = await res.json()
	return (cases as Array<Record<string, unknown>>).map((row) =>
		String(row.caseType ?? ''),
	)
}

test.describe('operate-show-per-case-type', () => {
	let bindingId = ''

	test.beforeAll(async ({ request }) => {
		// A published request form binding makes the portal name the type.
		const res = await request.post(`${OR_OBJECTS}/portalFormBinding`, {
			headers: ADMIN_HEADERS,
			data: {
				portal: ENABLED,
				route: '/e2e/handhaving',
				status: 'published',
				audience: 'client',
				intakeKind: 'hosted',
				typeRegister: 'e2e',
				typeSchema: 'caseType',
				typeId: 'e2e-internal',
			},
		})
		expect(res.ok()).toBeTruthy()
		const body = await res.json()
		bindingId = String(body.id ?? body.uuid ?? body['@self']?.id ?? '')
	})

	test.afterAll(async ({ request }) => {
		await hide(request, ENABLED, [])
		await hide(request, OTHER, [])
		if (bindingId !== '') {
			await request.delete(`${OR_OBJECTS}/portalFormBinding/${bindingId}`, {
				headers: ADMIN_HEADERS,
			})
		}
	})

	// @e2e portal-case-type-visibility::an-administrator-hides-an-internal-case-type
	test('an administrator hides a case type and it stays hidden after a reload', async ({
		page,
		request,
	}) => {
		await hide(request, ENABLED, [])
		const portal = await portalRecord(request)
		const id = String(
			(portal['@self'] as Record<string, unknown>)?.id ?? portal.id,
		)

		await login(page)
		await page.goto(`${APP}/portals/${id}`)
		const toggle = page
			.getByTestId('case-type-e2e-internal')
			.getByRole('switch', { name: 'Show in this portal' })
		await expect(toggle).toBeChecked()

		// The switch's own label is what a person clicks: NcCheckboxRadioSwitch
		// lays it over the input, so a click aimed at the input lands on it.
		await page
			.getByTestId('case-type-e2e-internal')
			.getByText('Show in this portal')
			.click()
		await expect(page.getByTestId('case-types-warning')).toHaveText(
			'Residents with a case of this type will no longer see it here.',
		)
		await page.getByTestId('case-types-save').click()
		await expect(page.getByText('Your choices are saved.')).toBeVisible()

		await page.reload()
		await expect(
			page
				.getByTestId('case-type-e2e-internal')
				.getByRole('switch', { name: 'Show in this portal' }),
		).not.toBeChecked()
	})

	test.describe('the resident side', () => {
		test.skip(
			HIDDEN === '' || SHOWN === '' || RESIDENT === '',
			'needs E2E_CASE_TYPE_HIDDEN, E2E_CASE_TYPE_SHOWN and E2E_CASE_RESIDENT_TOKEN',
		)

		// @e2e portal-case-type-visibility::the-resident-no-longer-sees-the-hidden-type
		test('the resident no longer sees the hidden type', async ({ request }) => {
			await hide(request, ENABLED, [HIDDEN])
			const types = await listedTypes(request, ENABLED)
			expect(types).toContain(SHOWN)
			expect(types).not.toContain(HIDDEN)
		})

		// @e2e portal-case-type-visibility::another-portal-is-not-affected
		test('another portal of the organisation still lists both', async ({
			request,
		}) => {
			await hide(request, ENABLED, [HIDDEN])
			const types = await listedTypes(request, OTHER)
			expect(types).toEqual(expect.arrayContaining([SHOWN, HIDDEN]))
		})

		// @e2e portal-case-type-visibility::showing-it-again-brings-the-cases-back
		test('showing it again brings the cases back', async ({ request }) => {
			await hide(request, ENABLED, [HIDDEN])
			await hide(request, ENABLED, [])
			expect(await listedTypes(request, ENABLED)).toContain(HIDDEN)
		})
	})
})
