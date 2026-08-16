/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The site renderer's adoption of the NL Design System, asserted in a real
 * browser.
 *
 * WHY THIS FILE IS SEPARATE FROM THE OTHER SITE SPECS
 *
 * Every defect below shipped, and NOT ONE of them broke anything a functional
 * test looks at. The page rendered, the links worked, the API returned the
 * right data, and the existing suite stayed green throughout:
 *
 *   - Webfonts 404'd, so the whole portal drew in Arial. The only symptom was
 *     that boxes sized from text came out a pixel short.
 *   - The footer's two bands rendered as one, because the CSS selects on
 *     `section:first-of-type` / `:last-of-type:not(:only-of-type)` and a lone
 *     section is BOTH — it took the first band's styling and was excluded by
 *     the guard from the rule whose class name it carried.
 *   - Footer links computed rgb(0, 68, 136) on a rgb(0, 69, 137) band. Present,
 *     focusable, clickable, and invisible.
 *   - The submenu was tagged with the TOP-LEVEL list class, so it rendered as
 *     an extra in-flow row and doubled the navigation bar's height. It went
 *     unnoticed because the portal it was checked against had no child items.
 *
 * What they have in common is that they are only observable through COMPUTED
 * STYLE and GEOMETRY. So that is what this file asserts, against the values
 * measured on the reference implementation.
 *
 * These are intentionally EXACT. A tolerance wide enough to absorb a missing
 * webfont is wide enough to absorb the bug.
 */

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`

/** The reference implementation's secondary navigation bar height, in px. */
const NAV_BAR_HEIGHT = 55

test.describe('site renderer — NL Design System adoption', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(SITE)
		await expect(page.getByTestId('site-menu')).toBeVisible()
		// Geometry measured before the webfonts settle is geometry of the
		// fallback face — which is exactly the bug this file exists to catch.
		await page.evaluate('document.fonts.ready')
	})

	// @e2e portaliq-cms::a-portals-theme-must-change-what-a-visitor-sees
	test('the declared webfonts actually load', async ({ page }) => {
		const faces = await page.evaluate<{ family: string; status: string }[]>(`
			Array.from(document.fonts).map((f) => ({ family: f.family, status: f.status }))
		`)

		// A FAILED face reports 'error' and the page keeps rendering in the
		// fallback, so nothing else in this suite would notice.
		expect(faces.filter((f) => f.status === 'error')).toEqual([])

		const roboto = faces.filter((f) => f.family === 'Roboto')
		expect(roboto.length).toBeGreaterThan(0)
		expect(roboto.some((f) => f.status === 'loaded')).toBe(true)
	})

	// @e2e portaliq-cms::a-portals-theme-must-change-what-a-visitor-sees
	test('a submenu is a dropdown, so it never changes the navigation bar height', async ({
		page,
	}) => {
		const bar = page.locator('.ac-header__navigation-secondary')
		const closed = await bar.boundingBox()
		expect(Math.round(closed!.height)).toBe(NAV_BAR_HEIGHT)

		// 'Over ons' is seeded with one child ('Contact'), so this portal is
		// the one that exercises the nested case at all.
		const parent = page.locator('.ac-c-navigation__li', {
			has: page.getByTestId('site-menu-dropdown'),
		})
		await expect(parent).toHaveCount(1)

		const dropdown = page.getByTestId('site-menu-dropdown')
		await expect(dropdown).toBeHidden()

		await parent.hover()
		await expect(dropdown).toBeVisible()

		// THE ASSERTION THAT MATTERS: open or closed, the bar is unchanged.
		// An in-flow submenu doubles it.
		const opened = await bar.boundingBox()
		expect(Math.round(opened!.height)).toBe(NAV_BAR_HEIGHT)

		// Out of flow, and announced.
		await expect(dropdown).toHaveCSS('position', 'absolute')
		await expect(parent.locator('a[aria-expanded]').first()).toHaveAttribute(
			'aria-expanded',
			'true',
		)
	})

	// @e2e portaliq-cms::a-portals-theme-must-change-what-a-visitor-sees
	test('the footer renders both bands, not one', async ({ page }) => {
		const sections = page.locator('.ac-footer > section')
		await expect(sections).toHaveCount(2)

		// The bands are distinguishable by design — different colour, different
		// padding. A single section takes the first band's styling and looks
		// plausible, which is how this shipped.
		const first = sections.first()
		const last = sections.last()
		await expect(first).toHaveCSS('padding-top', '96px')
		await expect(last).toHaveCSS('padding-top', '28px')

		const firstBg = await first.evaluate(
			(el) => getComputedStyle(el).backgroundColor,
		)
		const lastBg = await last.evaluate(
			(el) => getComputedStyle(el).backgroundColor,
		)
		expect(firstBg).not.toBe(lastBg)
	})

	// @e2e portaliq-cms::a-portals-theme-must-change-what-a-visitor-sees
	test('footer text is readable against the band it sits on', async ({ page }) => {
		const band = page.locator('.ac-footer > section').first()

		const { bg, fg } = await band.evaluate((el) => {
			const anchor = el.querySelector('a')
			return {
				bg: getComputedStyle(el).backgroundColor,
				fg: getComputedStyle(anchor ?? el).color,
			}
		})

		// Not a full contrast-ratio computation — axe covers that in
		// site-accessibility.spec.ts. This catches the specific failure that
		// shipped: link colour one step away from the background it is drawn
		// on, from a portal-wide override that could not know the background.
		expect(fg).not.toBe(bg)

		const distance = (a: string, b: string): number => {
			const nums = (s: string) => (s.match(/\d+/g) ?? []).map(Number)
			const [x, y] = [nums(a), nums(b)]
			return (
				Math.abs(x[0] - y[0]) + Math.abs(x[1] - y[1]) + Math.abs(x[2] - y[2])
			)
		}

		expect(distance(fg, bg)).toBeGreaterThan(150)
	})

	// @e2e portaliq-cms::a-portals-theme-must-change-what-a-visitor-sees
	test('main content sits in the same column as the navigation and footer', async ({
		page,
	}) => {
		const columns = await page.evaluate<(number | null)[]>(`
			[
				'.ac-header__navigation-secondary .container',
				'.ac-app-main .container',
				'.ac-footer .container',
			].map((s) => {
				const el = document.querySelector(s)
				return el ? Math.round(el.getBoundingClientRect().left) : null
			})
		`)

		// A missing container reads as null rather than as a coincidental
		// match — main had none at all, and its content started at the
		// viewport edge while every other band began inset.
		expect(columns).not.toContain(null)
		expect(new Set(columns).size).toBe(1)
	})
})
