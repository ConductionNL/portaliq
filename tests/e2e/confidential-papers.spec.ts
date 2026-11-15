/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-member-voting-record-and-confidential-papers T6 (REQ-SCR-004): a
 * resident signed in below the level a collection needs opens its page and
 * reads which login reaches it, with only those sign-in cards, each coming
 * back to the same page; a portal without such a login says it is not
 * available here yet. Needs an instance with dev-login (debug mode). The
 * aggregate's `stepUp` and the portal's sign-in modes are added to the real
 * answers through page.route, in the shapes PortalContributionRegistryTest
 * and signInRoutes pin, so decidiq does not have to be installed. Written,
 * run in the live pass (decision 139):
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test confidential-papers
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/specs/portal-contribution-contract/spec.md#requirement-a-page-whose-collection-needs-a-higher-login-must-say-so-and-offer-it-req-scr-004
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { siteAddress } from './portal-nav.ts'

const PAPERS = {
	app: 'decidiq',
	collection: 'confidentialPapers',
	label: 'Vertrouwelijke stukken',
	minTrust: 'substantial',
}

/**
 * Add `stepUp` to the aggregate and the given modes to the portal.
 *
 * @param page The page.
 * @param modes The sign-in modes the portal declares.
 */
async function withStepUp(page: Page, modes: string[]) {
	await page.route('**/portal/api/contributions', async (route) => {
		const response = await route.fetch()
		const json = await response.json()
		await route.fulfill({ response, json: { ...json, stepUp: [PAPERS] } })
	})
	await page.route('**/api/content/site*', async (route) => {
		const response = await route.fetch()
		const json = await response.json()
		const authentication = { ...(json.authentication || {}), modes }
		await route.fulfill({ response, json: { ...json, authentication } })
	})
}

test.describe('confidential papers ask for a higher login', () => {
	test('A password account opens the confidential papers page', async ({
		page,
	}) => {
		// @e2e portal-contribution-contract::a-password-account-opens-the-confidential-papers-page
		await withStepUp(page, ['nextcloud', 'digid', 'eherkenning'])
		await page.goto(siteAddress())
		await page.getByTestId('site-devlogin').click()
		await expect(page.getByTestId('site-signout')).toBeVisible()

		await page.getByRole('link', { name: 'Vertrouwelijke stukken' }).click()
		await expect(page).toHaveURL(/decidiq\/confidentialPapers/)
		await expect(page.getByTestId('site-step-up-text')).toHaveText(
			/Voor deze stukken moet u inloggen met DigiD of eHerkenning\.|For these papers you must log in with DigiD or eHerkenning\./,
		)
		const routes = page.getByTestId('site-step-up-route')
		await expect(routes).toHaveCount(2)
		const back = encodeURIComponent(
			new URL(page.url()).pathname + new URL(page.url()).search,
		)
		for (const href of await routes.evaluateAll((links) =>
			links.map((link) => link.getAttribute('href') || ''),
		)) {
			expect(href).toContain(`returnTo=${back}`)
		}
		await expect(page.getByTestId('collection-table')).toHaveCount(0)
	})

	test('No login reaches the level here', async ({ page }) => {
		await withStepUp(page, ['nextcloud'])
		await page.goto(siteAddress())
		await page.getByTestId('site-devlogin').click()
		await page.getByRole('link', { name: 'Vertrouwelijke stukken' }).click()
		await expect(page.getByTestId('site-step-up-none')).toHaveText(
			/Inloggen met DigiD is hier nog niet beschikbaar\.|Logging in with DigiD is not available here yet\./,
		)
		await expect(page.getByTestId('site-step-up-route')).toHaveCount(0)
	})
})
