/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-widget-palette: placing a widget from the palette, with a pointer and
 * without one (site-nlds-widget-palette REQ-SNW-001, REQ-SNW-002).
 *
 * NOT RUN BY THIS LANE. It needs an editor session on a live instance, so the
 * coordinator runs it on :8090 and reports what it finds; an e2e nobody runs
 * reports nothing, which is why it is written but not counted as done.
 *
 * Environment: PORTALIQ_E2E_PORTAL (default open-tilburg), PORTALIQ_E2E_ADMIN
 * (default admin:admin), PORTALIQ_E2E_PAGE (the page slug to edit, default
 * `proefpagina`).
 */

import { expect, test } from '@playwright/test'

const ADMIN = process.env.PORTALIQ_E2E_ADMIN || 'admin:admin'
const PORTAL = process.env.PORTALIQ_E2E_PORTAL || 'open-tilburg'
const PAGE = process.env.PORTALIQ_E2E_PAGE || 'proefpagina'

/** The designer, with the page open. */
function designerAddress(): string {
	const params = new URLSearchParams({ portal: PORTAL, page: PAGE })
	return `/index.php/apps/portaliq/#/pages/layout?${params.toString()}`
}

test.describe('site-widget-palette', () => {
	test.use({
		httpCredentials: {
			username: ADMIN.split(':')[0],
			password: ADMIN.split(':')[1],
		},
	})

	// @e2e site-nlds-widget-palette::an-editor-looks-for-a-heading
	test('the palette groups its widgets and says how many a search finds', async ({
		page,
	}) => {
		await page.goto(designerAddress())
		await page.getByTestId('designer-add-widget').click()
		await expect(page.getByTestId('widget-palette')).toBeVisible()

		// The six headings, in the order REQ-SNW-001 fixes, as far as the
		// registered widgets fill them.
		const headings = await page
			.getByTestId('widget-palette')
			.locator('.palette__group-heading')
			.allInnerTexts()
		expect(headings).toContain('Inhoud')
		expect(headings.indexOf('Inhoud')).toBeLessThan(headings.indexOf('Opmaak'))

		// An author types the word for the thing, not the component's name.
		await page.getByTestId('widget-palette-search').fill('kop')
		await expect(page.getByTestId('widget-palette-nlHeading')).toBeVisible()
		await expect(
			page.getByTestId('widget-palette-hits'),
			'the hit count is announced, so a narrow search is not read as a broken one',
		).toHaveText(/\d+ widget/)

		// A word nothing answers to finds nothing, and says so.
		await page.getByTestId('widget-palette-search').fill('parkeervergunning')
		await expect(page.getByTestId('widget-palette-empty')).toBeVisible()
	})

	// @e2e site-nlds-widget-palette::a-heading-dropped-at-the-top
	test('a widget can be dragged onto the grid', async ({ page }) => {
		await page.goto(designerAddress())
		await page.getByTestId('designer-add-widget').click()
		await page.getByTestId('widget-palette-search').fill('kop')

		const entry = page.getByTestId('widget-palette-nlHeading')
		const canvas = page.getByTestId('designer-canvas')
		await entry.dragTo(canvas, { targetPosition: { x: 40, y: 40 } })

		await expect(canvas.getByTestId('nl-heading')).toBeVisible()
	})

	// @e2e site-nlds-widget-palette::keyboard-only
	test('a widget can be placed with the keyboard alone', async ({ page }) => {
		await page.goto(designerAddress())

		// Everything from here on is keys: no click, no drag. If any step
		// needs a pointer, this test cannot pass, which is the point of it.
		await page.getByTestId('designer-add-widget').press('Enter')
		await expect(page.getByTestId('widget-palette')).toBeVisible()

		await page.getByTestId('widget-palette-search').fill('kop')
		await page.getByTestId('widget-palette-nlHeading').press('Enter')

		await expect(page.getByTestId('widget-palette')).toBeHidden()
		await expect(
			page.getByTestId('designer-canvas').getByTestId('nl-heading'),
			'the widget is on the canvas, placed below everything so the author can see it',
		).toBeVisible()
	})
})
