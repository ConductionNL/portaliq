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
import { resolveBaseURL } from './base-url.ts'

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
	test('the design system token layer is actually present', async ({ page }) => {
		// THE PAGE RENDERS WITHOUT TOKENS. Every `ac-*` and `utrecht-*` rule
		// resolves its `var(--utrecht-…)` to the declaration's own fallback, so
		// the markup is intact, the text is readable, nothing errors — and the
		// site is unstyled. There is no console message and no failed request
		// to notice.
		//
		// This has happened once already: this app shipped its own copy of the
		// VNG token set, the copy was removed in favour of the `nldesign` app
		// owning it, and for a while NEITHER supplied them. A check of eleven
		// individual computed properties would have caught it — but only if it
		// were re-run, and a page that is merely unstyled looks like a page
		// that loaded slowly.
		//
		// So assert the LAYER, not a symptom of it: a handful of tokens the
		// component CSS actually reads, each required to be non-empty.
		const tokens = await page.evaluate<Record<string, string>>(`
			(() => {
				const root = document.querySelector('[data-testid="site-root"]')
				const s = getComputedStyle(root)
				const names = [
					'--utrecht-document-font-family',
					'--utrecht-heading-2-font-size',
					'--utrecht-heading-2-color',
					'--tilburg-color-blue-500',
				]
				return Object.fromEntries(names.map((n) => [n, s.getPropertyValue(n).trim()]))
			})()
		`)

		for (const [name, value] of Object.entries(tokens)) {
			expect(
				value,
				`${name} resolved to nothing — the token layer is not loaded`,
			).not.toBe('')
		}

		// And the tokens must reach the PAGE, not merely be declared: a set
		// scoped to a selector the renderer does not use would satisfy the
		// check above on `:root` and style nothing.
		//
		// ASSERTED ON THE NAVIGATION BAR, not the hero. The hero was the first
		// choice and it was wrong: this suite's default portal has no hero
		// block, so the locator never resolved and the guard failed on a
		// perfectly styled page. A token check that depends on optional page
		// CONTENT tests the fixture, not the token layer.
		//
		// The secondary navigation bar exists on every portal and takes its
		// colour straight from the token set.
		await expect(page.locator('.ac-header__navigation-secondary')).toHaveCSS(
			'background-color',
			'rgb(0, 120, 200)',
		)
	})

	// @e2e portaliq-cms::a-portals-theme-must-change-what-a-visitor-sees
	test('the webfonts this repository ships actually load', async ({ page }) => {
		const faces = await page.evaluate<{ family: string; status: string }[]>(`
			Array.from(document.fonts).map((f) => ({ family: f.family, status: f.status }))
		`)

		// SCOPED TO WHAT WE SHIP, and the scope is the point.
		//
		// The first version of this asserted that NO face reports 'error'. That
		// was false on a correct deployment: the reference application's font
		// set is mostly commercial (Avenir LT W01, Gill Sans W01, Tisa Sans
		// Pro) and those files are deliberately NOT in this repository, so the
		// vendored sheets' faces for them could not resolve. Asserting no
		// errors at all encoded "a licensed deployment" as the only correct
		// one, and failed on the very configuration this repository ships.
		//
		// The unlicensed case is no longer an error at all — it is silent.
		// S24 caught what "an error BY DESIGN" actually cost: two red console
		// entries on every anonymous page load, a 401 for the empty drop-in
		// slot and a 404 for the vendored fallback behind it. `nlds-fonts.css`
		// now declares Avenir as `local(), …, url(roboto-400.woff2)`, so the
		// source list resolves without a download the deployment cannot serve,
		// and `nlds-fonts-licensed.css` is linked only when the licensed file
		// is really on disk.
		//
		// This assertion stays scoped to Roboto regardless, because that is the
		// face we DO ship — it carries the body copy and the navigation, and
		// its absence silently redraws the whole portal in Arial.
		const roboto = faces.filter((f) => f.family === 'Roboto')
		expect(roboto.length).toBeGreaterThan(0)
		expect(roboto.filter((f) => f.status === 'error')).toEqual([])
		expect(roboto.some((f) => f.status === 'loaded')).toBe(true)

		// And the metrics are REAL, not just a matching family name.
		// `getComputedStyle().fontFamily` returns the declared string whether or
		// not the file ever arrived, so it reports "Roboto" just as confidently
		// while the page renders in Arial. Measuring rendered text is what
		// tells the two apart.
		//
		// COMPARED AGAINST THE FALLBACK, NOT AGAINST A CONSTANT. Pinning the
		// absolute width was the first attempt and it is not portable: the same
		// string in the same declared font measured 294.27 in one Chromium and
		// 296 in Playwright's, because glyph rasterisation and the available
		// system faces differ per browser build. A constant would have to be
		// re-measured per environment, and the first thing anyone would do with
		// a failing magic number is widen it until it passed — which is exactly
		// the tolerance that would let a missing webfont through.
		//
		// The difference between "the stack as declared" and "the fallback
		// alone" is stable in a way the absolute number is not: it is non-zero
		// if and only if a real webfont is being used.
		const { resolved, fallback } = await page.evaluate<{
			resolved: number
			fallback: number
		}>(`
			(() => {
				const el = document.querySelector('.ac-c-navigation__label')
				const c = getComputedStyle(el)
				const probe = 'Softwarecatalogus Hamburgefonstiv 123'
				const measure = (family) => {
					const ctx = document.createElement('canvas').getContext('2d')
					ctx.font = c.fontStyle + ' ' + c.fontWeight + ' ' + c.fontSize + '/' + c.lineHeight + ' ' + family
					return ctx.measureText(probe).width
				}
				return { resolved: measure(c.fontFamily), fallback: measure('arial, sans-serif') }
			})()
		`)

		// Both must actually measure something — a broken canvas probe returns
		// 0 for everything, and 0 !== 0 is false, so the inequality below would
		// pass on an instrument that measured nothing at all.
		expect(resolved).toBeGreaterThan(0)
		expect(fallback).toBeGreaterThan(0)
		expect(Math.abs(resolved - fallback)).toBeGreaterThan(1)
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
	test('an open dropdown never survives navigation and blocks the page', async ({
		page,
	}) => {
		// THE BUG THIS PINS, AND IT IS A KEYBOARD BUG. Activating a parent item
		// leaves focus on its anchor, and `focusin` is one of the two things
		// that opens the submenu — so after activation the dropdown stayed
		// open. Being `position: absolute` it then sat OVER the content:
		// measured 110x55 at (119, 151), with elementFromPoint at its centre
		// returning the dropdown's own label instead of the page beneath.
		//
		// A MOUSE user escapes it, because moving the pointer off the item
		// fires `mouseleave` and that clears the state. A KEYBOARD user has no
		// pointer to move: they press Enter, land on the new page, and a
		// rectangle in the top-left of the content silently swallows clicks
		// with nothing able to dismiss it.
		//
		// So this test activates by KEYBOARD. Driving it with `.click()` would
		// prove nothing — Playwright leaves the real pointer hovering the item
		// afterwards, so the menu stays open for the legitimate reason and the
		// assertion fails against correct code.
		const parent = page.locator('.ac-c-navigation__li', {
			has: page.getByTestId('site-menu-dropdown'),
		})
		const dropdown = page.getByTestId('site-menu-dropdown')

		const parentLink = parent.locator('a').first()
		await parentLink.focus()
		await expect(dropdown).toBeVisible()

		await parentLink.press('Enter')
		await expect(dropdown).toBeHidden()

		// And the space it occupied belongs to the page again. Asserted by
		// HIT TESTING rather than by visibility, because "hidden" and "not
		// intercepting clicks" are different claims and only the second one is
		// what the visitor experienced.
		const blocked = await page.evaluate(`
			(() => {
				const el = document.elementFromPoint(174, 178)
				return el ? el.closest('[data-testid="site-menu-dropdown"]') !== null : false
			})()
		`)
		expect(blocked).toBe(false)
	})

	// @e2e portaliq-cms::a-portals-theme-must-change-what-a-visitor-sees
	test('Escape dismisses an open dropdown', async ({ page }) => {
		// A separate test on a FRESH page, deliberately. Re-focusing the same
		// anchor at the end of the test above would not reopen the menu: it
		// still holds focus after Enter, so `.focus()` is a no-op and fires no
		// `focusin`. That is correct behaviour — activating an item should not
		// spring its submenu back open — but it means the reopen has to start
		// from a page where the item has not been focused yet.
		//
		// Opened by FOCUS rather than hover, and the two are not
		// interchangeable: the handler sits on the <nav> and a key event goes
		// to document.activeElement, so after a hover (focus still on <body>)
		// the keydown never reaches it. A hover-opened menu is dismissed by
		// moving the pointer, which is the affordance that context has.
		const parentLink = page
			.locator('.ac-c-navigation__li', {
				has: page.getByTestId('site-menu-dropdown'),
			})
			.locator('a')
			.first()
		const dropdown = page.getByTestId('site-menu-dropdown')

		await parentLink.focus()
		await expect(dropdown).toBeVisible()

		await parentLink.press('Escape')
		await expect(dropdown).toBeHidden()
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
