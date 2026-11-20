/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Parity of the authored publication site with the vendored WOO bundle
 * (retire-the-vendored-woo-bundle, task 5).
 *
 * WHAT IS DRIVEN. The `open-publicaties` example site
 * (`lib/Settings/sites/open-publicaties.json`, installed with
 * `occ portaliq:example-site:install open-publicaties`) is the authored
 * replacement for the bundle's public surface. This spec walks it as an
 * anonymous visitor would: the home page, the themes page, the search page and
 * the page of one publication reached from a search result, and asserts that
 * each renders its authored blocks with real content.
 *
 * IT FAILS, IT NEVER SKIPS. This spec is the gate on deleting the bundle
 * (task 6). A skip when the portal content or the publication register is
 * absent would let that deletion proceed on silence, so an absent portal, an
 * absent page or a search with no results is a failure with a message that
 * says what is missing.
 *
 * WHAT IT LEAVES. Nothing: it only reads.
 *
 * @spec openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-parity-must-be-demonstrated-before-the-bundle-is-removed
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const APP = `${BASE}/index.php/apps/portaliq`

/** The authored replacement of the bundle's public surface. */
const PORTAL = process.env.PORTALIQ_WOO_PORTAL ?? 'open-publicaties'

/** The authored pages this spec walks, with the blocks each must render. */
const PAGES: Record<string, string[]> = {
	'/': ['hero', 'nlFeaturedSubjects', 'nlLinkList'],
	'/onderwerpen': ['nlHeading', 'nlParagraph', 'nlFeaturedSubjects'],
	'/zoeken': ['federatedSearch'],
	'/publicatie': ['publicationDetail'],
}

const INSTALL_HINT = `install it with \`occ portaliq:example-site:install ${PORTAL}\``

// Anonymous visitors: no Nextcloud session may leak into these pages.
test.use({ storageState: { cookies: [], origins: [] } })

/**
 * An anonymous API context: the public site's own reads, nothing more.
 *
 * @return the request context
 */
async function anonymousApi(): Promise<APIRequestContext> {
	return await request.newContext({
		baseURL: BASE,
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: { Accept: 'application/json' },
	})
}

/**
 * The address of one route of the authored portal.
 *
 * @param route the in-site route
 * @return the absolute URL
 */
function siteUrl(route: string): string {
	return `${APP}/site?portal=${encodeURIComponent(PORTAL)}&route=${encodeURIComponent(route)}`
}

/**
 * Open a route and wait until its authored blocks are on the page.
 *
 * @param page the page
 * @param route the in-site route
 */
async function openRoute(page: Page, route: string): Promise<void> {
	const response = await page.goto(siteUrl(route))
	expect(response?.status(), `the site must answer ${route}`).toBeLessThan(400)
	await expect(
		page.locator('[data-widget-key]').first(),
		`${route} on ${PORTAL} rendered no blocks at all`,
	).toBeVisible({ timeout: 60_000 })
}

test.describe('Authored WOO site reaches parity with the bundle', () => {
	// @e2e portaliq-cms::absent-content-fails-the-parity-test
	test('the portal and every walked page exist as content', async () => {
		const api = await anonymousApi()

		const site = await api.get(`${APP}/api/content/site?portal=${encodeURIComponent(PORTAL)}`)
		expect(site.status(), `portal ${PORTAL} is not served; ${INSTALL_HINT}`).toBe(200)
		expect((await site.json()).slug, `portal ${PORTAL} is not served; ${INSTALL_HINT}`).toBe(PORTAL)

		for (const route of Object.keys(PAGES)) {
			const answer = await api.get(
				`${APP}/api/content/page?portal=${encodeURIComponent(PORTAL)}&route=${encodeURIComponent(route)}`,
			)
			expect(answer.status(), `page ${route} of ${PORTAL} is missing; ${INSTALL_HINT}`).toBe(200)
		}

		await api.dispose()
	})

	for (const route of ['/', '/onderwerpen']) {
		test(`${route} renders its authored blocks with content`, async ({ page }) => {
			await openRoute(page, route)

			for (const key of PAGES[route]) {
				const block = page.locator(`[data-widget-key="${key}"]`).first()
				await expect(block, `${route} must render a ${key} block`).toBeVisible()
				expect(
					((await block.innerText()) ?? '').trim().length,
					`the ${key} block on ${route} rendered empty`,
				).toBeGreaterThan(0)
			}
		})
	}

	// @e2e portaliq-cms::search-returns-real-publications-with-facets
	test('search returns real publications and one opens on its own page', async ({ page }) => {
		await openRoute(page, '/zoeken')

		const search = page.getByTestId('federated-search')
		await expect(search, '/zoeken must render the federated search block').toBeVisible()

		// Run the empty search: every publication the register serves.
		await page.locator('#pq-federated-search').fill('')
		await page.locator('#pq-federated-search').press('Enter')

		const results = search.getByTestId('federated-search-result')
		await expect(
			results.first(),
			'the search found no publications: the publication register is empty or not reachable, '
				+ 'and parity cannot be shown against no data',
		).toBeVisible({ timeout: 60_000 })

		// Record what "it works" was measured against.
		const count = await results.count()
		test.info().annotations.push({ type: 'publications on first page', description: String(count) })
		const total = (await search.getByTestId('federated-search-count').innerText().catch(() => '')).trim()
		if (total !== '') {
			test.info().annotations.push({ type: 'publications found', description: total })
		}

		await results.first().getByTestId('federated-search-result-link').first().click()

		const detail = page.getByTestId('publication-detail')
		await expect(detail, 'a search result must open the publication page').toBeVisible({ timeout: 60_000 })
		await expect(page.getByTestId('publication-detail-error')).toHaveCount(0)
		const title = page.getByTestId('publication-detail-title')
		await expect(title).toBeVisible()
		expect((await title.innerText()).trim().length, 'the publication rendered without a title').toBeGreaterThan(0)
		await expect(page.getByTestId('publication-detail-fields')).toBeVisible()
	})

	// @e2e portaliq-cms::an-absent-publication-reveals-nothing
	test('an unknown publication answers not found', async ({ page }) => {
		await openRoute(page, '/publicatie/00000000-0000-4000-8000-000000000000')

		await expect(page.getByTestId('publication-detail-error')).toBeVisible({ timeout: 60_000 })
		await expect(page.getByTestId('publication-detail-title')).toHaveCount(0)
	})
})
