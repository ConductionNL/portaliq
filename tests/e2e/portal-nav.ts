/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Selecting a KNOWN page in the portal, instead of trusting where it lands.
 *
 * The portal opens on the first content entry of its navigation, and that
 * navigation is built by iterating every installed app's contribution in
 * `IAppManager::getInstalledApps()` order. Three specs relied on the landing
 * page being Portaliq's own demo page, which held only while Portaliq was the
 * one app contributing anything.
 *
 * It stopped holding the moment CI installed a second contributing app so that
 * portal-cross-app-contributions.spec.ts had something to assert against.
 * Measured on a dev instance with dossiq installed, the supplier navigation is:
 *
 *     dossiq   Aanbestedingen   <- the portal now opens here
 *     dossiq   Contracten
 *     dossiq   Facturen
 *     dossiq   Berichten
 *     portaliq Voorbeeld        <- what those specs actually wanted
 *     portaliq Berichten
 *     shillinq Purchase orders
 *     shillinq My invoices
 *
 * So the specs were filling a form on a page that was no longer showing, and
 * failed on a `getByLabel('Onderwerp')` timeout that reads like a broken form
 * rather than like navigation.
 *
 * The fix is not to make the portal land somewhere fixed. Landing on the first
 * contribution is correct behaviour, and a test that depends on being the only
 * app in the fleet is the same fleet-of-one assumption that let a whole app go
 * dark unnoticed in the first place. A spec that wants a particular page should
 * say which page it wants.
 *
 * `Voorbeeld` is not a hard-coded English or Dutch string chosen here: it is the
 * label on the seeded `portalPage` row in `lib/Settings/portaliq_register.json`,
 * the same seed that provides the `Onderwerp` and `Aanmaken` labels these specs
 * already use. It travels with the fixture, so it is identical in CI.
 */

import type { Page } from '@playwright/test'

import { expect } from '@playwright/test'

/** The label of Portaliq's own demo page, from the seeded `portalPage` row. */
export const PORTALIQ_DEMO_PAGE = 'Voorbeeld'

/**
 * Open Portaliq's own demo page, whatever else is installed and contributing.
 *
 * Waits for the create form to be visible, so a caller that goes on to fill it
 * fails here, naming the navigation, rather than later on a field timeout.
 *
 * @param page the Playwright page, already on the portal and loaded
 * @return nothing
 */
export async function openPortaliqDemoPage(page: Page): Promise<void> {
	const navItem = page.getByRole('button', {
		name: PORTALIQ_DEMO_PAGE,
		exact: true,
	})
	await expect(
		navItem,
		`the portal navigation has no "${PORTALIQ_DEMO_PAGE}" entry — Portaliq's own `
			+ 'contribution did not reach this subject, which is a discovery failure, not a UI one',
	).toBeVisible()
	await navItem.click()

	await expect(
		page.getByLabel('Onderwerp'),
		`clicked "${PORTALIQ_DEMO_PAGE}" but its create form did not render`,
	).toBeVisible()
}
