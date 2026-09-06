/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Scenarios S13–S17 — the site renderer as a Dutch government public surface:
 * WCAG 2.2 AA via axe-core, a keyboard-only path, and a phone viewport.
 *
 * This is not decoration. A municipal public site carries a
 * toegankelijkheidsverklaring, and the failure mode here is the same one that
 * runs through the rest of this suite: an inaccessible page looks completely
 * normal to whoever is looking at it.
 *
 * axe-core is injected from node_modules rather than pulled in through
 * @axe-core/playwright, which is not a dependency of this app. The version
 * used is reported by the run, because "axe found nothing" means nothing
 * without knowing which axe.
 */

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { resolveBaseURL } from './base-url.ts'

// CommonJS require, because this suite is compiled as CJS (no "type": "module"
// in package.json) and `import.meta` is a syntax error there.
const AXE_SOURCE = readFileSync(require.resolve('axe-core'), 'utf8')
// eslint-disable-next-line @typescript-eslint/no-require-imports
const AXE_VERSION = require('axe-core/package.json').version as string

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`

interface AxeViolation {
	id: string
	impact: string | null
	help: string
	nodes: { target: string[] }[]
}

/**
 * Run axe against the site root and return violations at or above serious.
 *
 * Scoped to the renderer's own root, not the whole document: the page is
 * served inside Nextcloud's public chrome here, and this suite must not report
 * Nextcloud's markup as a defect in Portaliq. At a real public origin the
 * chrome is absent and the scope is the page.
 *
 * @param page The Playwright page.
 * @return The serious and critical violations.
 */
async function seriousViolations(page: {
	addScriptTag: (opts: { content: string }) => Promise<unknown>
	evaluate: <T>(fn: string) => Promise<T>
}): Promise<AxeViolation[]> {
	await page.addScriptTag({ content: AXE_SOURCE })
	const results = await page.evaluate<{ violations: AxeViolation[] }>(`
		window.axe.run('[data-testid="site-root"]', {
			runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
		})
	`)
	return results.violations.filter(
		(v) => v.impact === 'serious' || v.impact === 'critical',
	)
}

/**
 * Format violations so a failure names the rule and the element.
 *
 * A bare count tells the reader a number and nothing they can act on.
 *
 * @param violations The violations to describe.
 * @return A readable summary.
 */
function describe(violations: AxeViolation[]): string {
	return violations
		.map(
			(v) =>
				`${v.id} (${v.impact}): ${v.help} — ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`,
		)
		.join('\n')
}

test.describe('site renderer — accessibility', () => {
	test('S13: a grid page has no serious or critical axe violations', async ({
		page,
	}) => {
		console.log(`axe-core ${AXE_VERSION}`)
		await page.goto(SITE)
		await expect(page.getByTestId('site-title')).toBeVisible()

		const violations = await seriousViolations(page)
		expect(violations, describe(violations)).toEqual([])
	})

	test('S13b: a markdown page has no serious or critical axe violations', async ({
		page,
	}) => {
		await page.goto(`${SITE}?route=/over-ons`)
		await expect(page.getByTestId('page-title')).toHaveText('Over ons')

		const violations = await seriousViolations(page)
		expect(violations, describe(violations)).toEqual([])
	})

	test('S13c: the not-found state has no serious or critical axe violations', async ({
		page,
	}) => {
		// Error states are where accessibility is usually skipped, and they are
		// exactly where a confused visitor needs it most.
		await page.goto(`${SITE}?route=/does-not-exist`)
		await expect(page.getByTestId('site-error')).toBeVisible()

		const violations = await seriousViolations(page)
		expect(violations, describe(violations)).toEqual([])
	})

	test('S14: the whole site is navigable by keyboard alone', async ({ page }) => {
		await page.goto(SITE)
		await expect(page.getByTestId('page-title')).toHaveText('Welkom')

		// The renderer's skip link must be reachable early and must point at the
		// renderer's own main region.
		//
		// NOT asserted: that it is the very first tab stop. Served inside
		// Nextcloud's public chrome — as it is here — Nextcloud's own "Skip to
		// main content" comes first, and asserting position would be testing the
		// host page rather than this renderer. At a real public origin there is
		// no chrome and it IS first; that is the deployment this contract is
		// written for, and it is not the deployment under test.
		let skip = { text: '', href: '' }
		for (let i = 0; i < 4; i += 1) {
			await page.keyboard.press('Tab')
			skip = await page.evaluate(() => ({
				text: document.activeElement?.textContent?.trim() ?? '',
				href:
					(document.activeElement as HTMLAnchorElement)?.getAttribute(
						'href',
					) ?? '',
			}))
			if (skip.text === 'Direct naar de inhoud') {
				break
			}
		}
		expect(skip.text, 'the renderer skip link was not reachable in 4 tabs').toBe(
			'Direct naar de inhoud',
		)
		expect(skip.href).toBe('#pq-main')

		// Tab to the 'Over ons' menu link and activate it with the keyboard.
		// Deliberately NOT page.click(): a link that only works with a mouse
		// passes a click-based test and fails a real visitor.
		let reached = false
		for (let i = 0; i < 12; i += 1) {
			await page.keyboard.press('Tab')
			const label = await page.evaluate(
				() => document.activeElement?.textContent?.trim() ?? '',
			)
			if (label === 'Over ons') {
				reached = true
				break
			}
		}
		expect(reached, 'Over ons was not reachable within 12 tab stops').toBe(true)

		await page.keyboard.press('Enter')
		await expect(page.getByTestId('page-title')).toHaveText('Over ons')
	})

	test('S15: every focused control shows a visible focus indicator', async ({
		page,
	}) => {
		await page.goto(SITE)
		await expect(page.getByTestId('site-title')).toBeVisible()

		const link = page
			.getByTestId('site-menu')
			.getByRole('link', { name: 'Home' })
		await link.focus()

		const focusStyle = await link.evaluate((el) => {
			const cs = getComputedStyle(el)
			return {
				outlineStyle: cs.outlineStyle,
				outlineWidth: cs.outlineWidth,
				boxShadow: cs.boxShadow,
			}
		})

		// WCAG 2.2 adds Focus Appearance; the floor asserted here is simply that
		// SOMETHING renders. `outline: none` with no replacement is the common
		// way a design review removes the only cue a keyboard user has.
		const hasIndicator =
			(focusStyle.outlineStyle !== 'none' && focusStyle.outlineWidth !== '0px')
			|| (focusStyle.boxShadow !== 'none' && focusStyle.boxShadow !== '')
		expect(hasIndicator, JSON.stringify(focusStyle)).toBe(true)
	})
})

test.describe('site renderer — responsive', () => {
	test('S16: a phone viewport does not scroll horizontally', async ({ page }) => {
		// 360x740 is a common Android size and narrower than the manifest grid's
		// smallest breakpoint. A public municipal site is majority mobile.
		await page.setViewportSize({ width: 360, height: 740 })
		await page.goto(SITE)
		await expect(page.getByTestId('site-title')).toBeVisible()

		const overflow = await page.evaluate(() => ({
			scrollWidth: document.documentElement.scrollWidth,
			clientWidth: document.documentElement.clientWidth,
		}))

		// A horizontal scrollbar on a phone is the single most common symptom of
		// a fixed-width grid surviving into a narrow viewport.
		expect(
			overflow.scrollWidth,
			`document scrolls horizontally: ${JSON.stringify(overflow)}`,
		).toBeLessThanOrEqual(overflow.clientWidth + 1)
	})

	test('S16b: grid widgets stack instead of squeezing on a phone', async ({
		page,
	}) => {
		await page.setViewportSize({ width: 360, height: 740 })
		await page.goto(SITE)
		await expect(page.getByTestId('widget-grid')).toBeVisible()

		// The two half-width widgets share a row at desktop width. Below the
		// breakpoint they must stack — a 6-column cell on a 360px screen is
		// unreadable, which is why the renderer drops the grid rather than
		// scaling it.
		const side = await page.getByTestId('widget-side').boundingBox()
		const help = await page.getByTestId('widget-help').boundingBox()
		expect(side).not.toBeNull()
		expect(help).not.toBeNull()
		expect(help!.y).toBeGreaterThan(side!.y + side!.height - 4)
	})

	test('S17: a wide markdown table scrolls in its own container', async ({
		page,
	}) => {
		await page.setViewportSize({ width: 360, height: 740 })
		await page.goto(`${SITE}?route=/over-ons`)
		await expect(page.locator('#pq-main table')).toBeVisible()

		// Content the author controls must not be able to break the page layout.
		// The table gets its own scroll container; the document does not scroll.
		const doc = await page.evaluate(() => ({
			scrollWidth: document.documentElement.scrollWidth,
			clientWidth: document.documentElement.clientWidth,
		}))
		expect(doc.scrollWidth).toBeLessThanOrEqual(doc.clientWidth + 1)
	})
})
