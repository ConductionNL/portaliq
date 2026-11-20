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

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { resolveBaseURL } from './base-url.ts'
import {
	openPortaliqDemoPage,
	PORTAL_API,
	seedSiteSession,
	siteAddress,
} from './portal-nav.ts'

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

/**
 * The reading room: the search page with results and facets, and a
 * publication's own page (public-site-meets-wcag-aa REQ-PWA-001).
 *
 * The catalogue's answers are ROUTED in the browser, the page is real: the
 * pages are the ones tests/e2e/fixtures/seed-cms.sh seeds (`/zoeken`,
 * `/publicatie`), and the federated search block and the publication detail
 * block render them exactly as they render a live answer from
 * opencatalogi's `/api/federation/publications`. That is what lets these
 * cases run on a fresh instance with no catalogue installed, and what they
 * check is the markup the two blocks draw, not the catalogue's data.
 *
 * Two rows from two catalogues and two facet groups, because a facet column
 * with one bare checkbox is not the page a visitor gets.
 *
 * @spec openspec/changes/public-site-meets-wcag-aa/specs/portaliq-cms/spec.md#requirement-the-public-site-meets-wcag-21-aa-on-every-page-state-it-serves-req-pwa-001
 */
const FEDERATION = '**/apps/opencatalogi/api/federation/publications**'
const PUBLICATION_ID = '7a1b2c3d-0000-4000-a000-00000000a11y'
const DOWNLOAD_URL = '/index.php/apps/opencatalogi/api/e2e-a11y/besluit.pdf'

const SEARCH_ANSWER = {
	results: [
		{
			id: PUBLICATION_ID,
			name: 'Besluit op Woo-verzoek fietspad Reeshof',
			summary: 'Het besluit en de documenten bij het Woo-verzoek over het fietspad.',
			wooCategory: 'besluit',
			organization: 'Gemeente Tilburg',
			published: '2026-10-01T09:00:00+00:00',
			'@self': { id: PUBLICATION_ID, schema: 'publication', directory: 'opencatalogi.nl' },
		},
		{
			id: '7a1b2c3d-0000-4000-a000-00000000a12y',
			name: 'Rapport verkeersveiligheid 2026',
			summary: 'Onderzoek naar de verkeersveiligheid op de fietsroutes.',
			wooCategory: 'onderzoeksrapport',
			organization: 'Gemeente Venray',
			published: '2026-09-15T09:00:00+00:00',
			'@self': {
				id: '7a1b2c3d-0000-4000-a000-00000000a12y',
				schema: 'publication',
				directory: 'venray.example.org',
			},
		},
	],
	total: 2,
	page: 1,
	pages: 1,
	facets: {
		wooCategory: {
			buckets: [
				{ key: 'besluit', label: 'Besluit', results: 1 },
				{ key: 'onderzoeksrapport', label: 'Onderzoeksrapport', results: 1 },
			],
		},
		organization: {
			buckets: [
				{ key: 'Gemeente Tilburg', results: 1 },
				{ key: 'Gemeente Venray', results: 1 },
			],
		},
	},
}

const ATTACHMENTS_ANSWER = {
	results: [
		{
			id: 'e2e-a11y-doc-1',
			title: 'Besluit fietspad Reeshof.pdf',
			type: 'application/pdf',
			size: 48213,
			downloadUrl: DOWNLOAD_URL,
		},
	],
	total: 1,
}

/**
 * Answer the catalogue's reads in the browser: the search, the publication by
 * id, its attachments and the download itself.
 *
 * @param page The Playwright page.
 */
async function routeTheCatalogue(page: Page): Promise<void> {
	await page.route(FEDERATION, async (route) => {
		const path = new URL(route.request().url()).pathname
		if (path.endsWith('/attachments')) {
			await route.fulfill({ json: ATTACHMENTS_ANSWER })
			return
		}
		if (path.endsWith(`/${PUBLICATION_ID}`)) {
			await route.fulfill({ json: SEARCH_ANSWER.results[0] })
			return
		}
		await route.fulfill({ json: SEARCH_ANSWER })
	})
	await page.route(`**${DOWNLOAD_URL}`, (route) =>
		route.fulfill({
			status: 200,
			contentType: 'application/pdf',
			headers: { 'Content-Disposition': 'attachment; filename="besluit.pdf"' },
			body: '%PDF-1.4\n%%EOF\n',
		}),
	)
}

/**
 * Whether the focused element paints a focus indicator: an outline or a
 * box-shadow, the same floor S15 asserts on the menu.
 *
 * @param page The Playwright page.
 * @return The computed focus style and whether it shows.
 */
async function focusIndicator(page: Page): Promise<{ shows: boolean, style: string }> {
	return page.evaluate(() => {
		const el = document.activeElement as HTMLElement | null
		if (!el) {
			return { shows: false, style: 'no focused element' }
		}
		const cs = getComputedStyle(el)
		const outline = cs.outlineStyle !== 'none' && cs.outlineWidth !== '0px'
		const shadow = cs.boxShadow !== 'none' && cs.boxShadow !== ''
		return {
			shows: outline || shadow,
			style: JSON.stringify({ outline: `${cs.outlineStyle} ${cs.outlineWidth}`, boxShadow: cs.boxShadow }),
		}
	})
}

test.describe('site renderer — the reading room', () => {
	test('S13d: the search page with results and facets has no serious or critical axe violations', async ({
		page,
	}) => {
		await routeTheCatalogue(page)
		await page.goto(`${SITE}?route=/zoeken&_search=fietspad`)
		await expect(page.getByTestId('federated-search-result')).toHaveCount(2)
		await expect(page.getByTestId('federated-search-facets')).toBeVisible()
		await expect(page.getByTestId('federated-search-sort')).toBeVisible()

		// Every facet checkbox and the sort select carry a name a screen
		// reader announces; axe checks it too, this names the control.
		for (const box of await page
			.getByTestId('federated-search-facets')
			.getByRole('checkbox')
			.all()) {
			expect(await box.evaluate((el) => (el as HTMLInputElement).labels?.length ?? 0)).toBeGreaterThan(0)
		}
		await expect(page.getByLabel('Sorteren')).toBeVisible()

		const violations = await seriousViolations(page)
		expect(violations, describe(violations)).toEqual([])
	})

	test('S13e: a publication page has no serious or critical axe violations', async ({
		page,
	}) => {
		await routeTheCatalogue(page)
		await page.goto(`${SITE}?route=/publicatie/${PUBLICATION_ID}`)
		await expect(page.getByTestId('publication-detail-title')).toHaveText(
			'Besluit op Woo-verzoek fietspad Reeshof',
		)
		await expect(page.getByTestId('publication-document')).toHaveCount(1)

		const violations = await seriousViolations(page)
		expect(violations, describe(violations)).toEqual([])
	})

	test('S14b: every link and download of a publication takes a visible focus and opens with Enter', async ({
		page,
	}) => {
		await routeTheCatalogue(page)
		await page.goto(`${SITE}?route=/publicatie/${PUBLICATION_ID}`)
		await expect(page.getByTestId('publication-document')).toHaveCount(1)

		const detail = page.getByTestId('publication-detail')
		const links = await detail.locator('a[href]').count()
		expect(links, 'the publication page offers no link at all').toBeGreaterThan(0)

		// Walk the page by Tab alone and record every control inside the block
		// that takes focus. Deliberately not .focus(): an element that only a
		// script can focus passes a focus() test and is skipped by a visitor.
		const reached = new Set<string>()
		for (let i = 0; i < 60 && reached.size < links; i += 1) {
			await page.keyboard.press('Tab')
			const inside = await page.evaluate(() => {
				const el = document.activeElement
				const block = document.querySelector('[data-testid="publication-detail"]')
				if (!el || !block || !block.contains(el) || el.tagName !== 'A') {
					return null
				}
				const all = [...block.querySelectorAll('a[href]')]
				return String(all.indexOf(el))
			})
			if (inside === null) {
				continue
			}
			const indicator = await focusIndicator(page)
			expect(indicator.shows, `link ${inside} shows no focus: ${indicator.style}`).toBe(true)
			reached.add(inside)
		}
		expect(reached.size, `${reached.size} of ${links} links were reached by Tab`).toBe(links)

		// Enter on the download starts the download: a link that only answers a
		// mouse click fails a keyboard visitor and passes a click-based test.
		const download = detail.getByTestId('publication-document').locator('a[download]').first()
		await download.focus()
		const started = page.waitForEvent('download')
		await page.keyboard.press('Enter')
		expect((await started).url()).toContain(DOWNLOAD_URL)
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

/**
 * Mint a low-trust supplier dev session and seed it into the site's token
 * slot before the app boots, as tests/e2e/portal-document-download.spec.ts
 * does.
 *
 * @param request The API request context.
 * @param page The Playwright page.
 * @param subjectRef The subject to sign in as.
 */
async function signInToThePortal(
	request: APIRequestContext,
	page: Page,
	subjectRef: string,
): Promise<void> {
	const res = await request.post(`${PORTAL_API}/session/dev-login`, {
		data: { subjectRef, audience: 'supplier', organisation: 'e2e-org' },
	})
	expect(
		res.ok(),
		'dev-login must be enabled on the target instance (system config debug: true)',
	).toBeTruthy()
	const token = (await res.json()).token as string
	expect(token).toBeTruthy()
	await seedSiteSession(page, token)
}

test.describe('signed-in portal — accessibility', () => {
	// portaliq#722: a table row used to open only on a mouse click on the
	// <tr>. Tab walked past it and Enter did nothing, and axe cannot see a
	// click handler on a row, so this drives the keyboard itself.
	test('S18: a keyboard user opens a row of a portal table with Enter', async ({
		page,
		request,
	}) => {
		await signInToThePortal(request, page, `e2e-keyboard-${Date.now()}`)
		await page.goto(siteAddress())
		await page.waitForLoadState('domcontentloaded')
		await openPortaliqDemoPage(page)

		const title = `E2E keyboard ${Date.now()}`
		await page.getByLabel('Onderwerp').fill(title)
		await page.getByRole('button', { name: 'Aanmaken' }).click()
		await expect(page.getByText('Voorbeeld aangemaakt')).toBeVisible({
			timeout: 20_000,
		})

		const row = page
			.getByTestId('collection-table-row')
			.filter({ hasText: title })
		await row.waitFor({ timeout: 20_000 })
		const open = row.getByTestId('collection-table-select')

		// A real button is in the tab order; the row itself never was.
		expect(await open.evaluate((el) => el.tabIndex)).toBeGreaterThanOrEqual(0)
		await open.focus()
		await expect(open).toBeFocused()
		await page.keyboard.press('Enter')

		// The open row is announced, and its detail renders.
		await expect(row).toHaveAttribute('aria-current', 'true')
		await expect(page.getByTestId('detail-card-upload')).toBeVisible()
	})
})

/**
 * site-accessibility-statement REQ-SAS-002: every portal links its statement
 * from the footer, the statement names the date of the measurement it rests
 * on, and the statement page itself passes the same axe check.
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
 */
test.describe('site renderer — accessibility statement', () => {
	test('the statement is linked from the footer and lists the measurement date', async ({
		page,
		request,
	}) => {
		const answer = await request.get(
			`${BASE}/index.php/apps/portaliq/api/content/accessibility`,
		)
		expect(answer.ok()).toBeTruthy()
		const { statement } = await answer.json()

		await page.goto(SITE)
		await expect(page.getByTestId('site-title')).toBeVisible()
		// The href is the site's own address for the route: `/toegankelijkheid`
		// in the path on a portal host, `?route=%2Ftoegankelijkheid` on the
		// app path (siteAddress.js), so the link is found by its name.
		const link = page
			.getByTestId('site-subfooter-menu')
			.getByRole('link', { name: 'Toegankelijkheid', exact: true })
		await expect(link).toHaveCount(1)
		await link.click()

		await expect(page.getByTestId('accessibility-statement')).toBeVisible()
		await expect(
			page.getByTestId('accessibility-statement-status'),
		).toBeVisible()
		if (statement.measurement) {
			await expect(
				page.getByTestId('accessibility-statement-evidence'),
			).toBeVisible()
		}
		// Without an audit the status is never A or B.
		if (statement.audit === null) {
			expect(['C', null]).toContain(statement.status)
		}

		const violations = await seriousViolations(page)
		expect(violations, describe(violations)).toEqual([])
	})
})
