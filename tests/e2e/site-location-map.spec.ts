/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The organisation's places on a map (site-location-map, REQ-SLM-001).
 *
 * The site under test is the Zuiddrecht example site
 * (`occ portaliq:example-site:install zuiddrecht`), whose `/contact/locaties`
 * page carries the `nlLocationMap` block with the Stadskantoor, the
 * Milieustraat and the Bibliotheek. Name another portal with
 * `PORTALIQ_E2E_LOCATIONS_PORTAL` when the instance installed it under a
 * different slug.
 *
 * The open-now line reads the visitor's clock, so the second test fixes the
 * browser clock (Playwright's `page.clock`) to a Monday morning and to a
 * Sunday. Each test runs anonymously with an empty storage state; the base
 * URL comes from the environment through `tests/e2e/base-url.ts`.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
 */

import { expect, test } from '@playwright/test'
import { SITE_PATH } from './portal-nav.ts'

/** The portal that carries the example Locaties page. */
const PORTAL = process.env.PORTALIQ_E2E_LOCATIONS_PORTAL || 'zuiddrecht'

// A public page: no stored login.
test.use({ storageState: { cookies: [], origins: [] } })

/**
 * The site address of the Locaties page.
 *
 * @return the address
 */
function locationsPage(): string {
	const params = new URLSearchParams({ portal: PORTAL, route: '/contact/locaties' })
	return `${SITE_PATH}?${params.toString()}`
}

test('Three places of the municipality, and the Afval chip narrows list and map', async ({ page }) => {
	const foreign: string[] = []
	page.on('request', (request) => {
		const url = new URL(request.url())
		const base = new URL(page.url() || request.url())
		if (url.origin !== base.origin && url.protocol.startsWith('http')) {
			foreign.push(request.url())
		}
	})

	await page.goto(locationsPage())
	const block = page.getByTestId('nl-location-map')
	await expect(block.getByRole('heading', { level: 2, name: '3 locaties' })).toBeVisible()
	const map = block.getByRole('img', { name: 'Kaart met de genummerde locaties uit de lijst' })
	await expect(map).toBeVisible()
	for (const number of ['1', '2', '3']) {
		await expect(map.locator('text', { hasText: new RegExp(`^\\s*${number}\\s*$`) })).toHaveCount(1)
	}
	await expect(map.locator('.nl-location-map__marker-name')).toHaveText(['Stadskantoor', 'Milieustraat', 'Bibliotheek'])
	await expect(block.getByText('Kaart: schets, geen echte kaartgegevens')).toBeVisible()

	await block.getByRole('button', { name: 'Afval' }).click()
	await expect(block.getByRole('button', { name: 'Afval' })).toHaveAttribute('aria-pressed', 'true')
	await expect(block.getByRole('heading', { level: 2, name: '1 locaties' })).toBeVisible()
	await expect(block.getByRole('heading', { level: 3 })).toHaveText(['Milieustraat'])
	await expect(map.locator('.nl-location-map__marker')).toHaveCount(1)

	// Route is a button link that opens the route planner in a new window.
	const route = block.getByRole('link', { name: /^Route, Milieustraat/ })
	await expect(route).toHaveAttribute('target', '_blank')
	await expect(route).toHaveClass(/utrecht-button-link--secondary-action/)

	expect(foreign, 'the map requests nothing from another site').toEqual([])
})

test('A place says whether it is open now', async ({ page }) => {
	// Monday 23 November 2026, 10.15 in the visitor's clock.
	await page.clock.setFixedTime(new Date(2026, 10, 23, 10, 15))
	await page.goto(locationsPage())
	const kantoor = page.getByTestId('nl-location-map').getByRole('listitem').filter({ has: page.getByRole('heading', { level: 3, name: 'Stadskantoor' }) })
	await expect(kantoor.getByTestId('nl-location-map-open-now')).toHaveText('Nu open tot 17:00 uur')

	// Sunday 29 November 2026: the Stadskantoor has no hours on a Sunday.
	await page.clock.setFixedTime(new Date(2026, 10, 29, 10, 15))
	await page.reload()
	await expect(kantoor.getByTestId('nl-location-map-open-now')).toHaveText('Nu gesloten')
})
