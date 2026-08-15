/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Scenarios S5–S7 — one installation serving several portals, resolved by
 * host, with domain verification deciding which hosts serve at all.
 *
 * These run against the API rather than the rendered page: host resolution is
 * a server-side decision, and a browser cannot send an arbitrary Host header.
 *
 * See tests/e2e/scenarios/portal-phase-two.md.
 */

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url'

const BASE = resolveBaseURL()
const API = `${BASE}/index.php/apps/portaliq/api/content`
const SITE = `${BASE}/index.php/apps/portaliq/site`

/**
 * Whether this instance can be reached under an alternate Host header at all.
 *
 * Nextcloud rejects a host outside `trusted_domains` BEFORE any app code runs,
 * with its own error page. On such an instance a host-resolution spec does not
 * test host resolution — it tests Nextcloud's trusted-domain check, and reports
 * the wrong cause when it fails.
 *
 * So the host-header specs check this first and skip with a reason rather than
 * failing. The requirement itself is not left uncovered: `resolveByHost()` is
 * unit-tested in tests/Unit/Service/PortalResolverTest.php across eight cases
 * including the verified/unverified pair and the single-configured-site trap.
 *
 * @param request The Playwright request context.
 * @return true when an alternate Host reaches the app.
 */
async function alternateHostsAreTrusted(request: {
	get: (
		url: string,
		opts?: object,
	) => Promise<{ status: () => number; text: () => Promise<string> }>
}): Promise<boolean> {
	const probe = await request.get(`${API}/site`, {
		headers: { Host: 'venray.localhost' },
	})
	// 200 (resolved) or a JSON 404 (resolved to nothing) both mean app code
	// ran. Nextcloud's untrusted-domain refusal is an HTML page.
	if (probe.status() === 200) {
		return true
	}
	const body = await probe.text()
	return body.trim().startsWith('{')
}

test.describe('site renderer — multi-site', () => {
	// @e2e portaliq-cms::two-portals-publishing-the-same-route-do-not-leak
	test('S5: two portals publish the same route and do not leak into each other', async ({
		request,
	}) => {
		// Requested by explicit slug so the assertion does not depend on the
		// runner's own hostname. Both sites really do publish `/over-ons`;
		// a fixture with only one would pass even if scoping did not exist.
		const tilburg = await request.get(
			`${API}/page?route=/over-ons&portal=open-tilburg`,
		)
		const venray = await request.get(
			`${API}/page?route=/over-ons&portal=open-venray`,
		)

		expect(tilburg.status()).toBe(200)
		expect(venray.status()).toBe(200)

		expect((await tilburg.json()).title).toBe('Over ons')
		expect((await venray.json()).title).toBe('Over Venray')
	})

	test('S5b: a page listing carries only its own site', async ({ request }) => {
		const tilburg = await (
			await request.get(`${API}/pages?portal=open-tilburg`)
		).json()
		const venray = await (
			await request.get(`${API}/pages?portal=open-venray`)
		).json()

		const tilburgTitles = tilburg.pages.map((p: { title: string }) => p.title)
		const venrayTitles = venray.pages.map((p: { title: string }) => p.title)

		expect(tilburgTitles).toContain('Over ons')
		expect(tilburgTitles).not.toContain('Over Venray')
		expect(venrayTitles).toContain('Over Venray')
		expect(venrayTitles).not.toContain('Over ons')
	})

	// Both directions live in ONE test, so both anchors point here.
	// @e2e portaliq-cms::an-unverified-domain-does-not-serve
	// @e2e portaliq-cms::a-verified-domain-does-serve
	test('S6: a domain serves once verified and not before — both directions', async ({
		request,
	}) => {
		test.skip(
			!(await alternateHostsAreTrusted(request)),
			'venray.localhost / unverified.localhost are not in trusted_domains on this instance; '
				+ 'host resolution is covered by PortalResolverTest',
		)

		// The REFUSAL. `unverified.localhost` is bound to open-venray with
		// verified:false; DNS resolving here is not consent.
		const refused = await request.get(`${API}/site`, {
			headers: { Host: 'unverified.localhost' },
		})
		expect(refused.status()).toBe(404)

		// The ACCEPTANCE, on the SAME portal. Without this half, a verifier
		// that refuses everything would pass the test above and look correct.
		const served = await request.get(`${API}/site`, {
			headers: { Host: 'venray.localhost' },
		})
		expect(served.status()).toBe(200)
		expect((await served.json()).slug).toBe('open-venray')
	})

	// @e2e portaliq-cms::an-unknown-host-reveals-nothing
	test('S7: an unknown host resolves to nothing and reveals nothing', async ({
		request,
	}) => {
		test.skip(
			!(await alternateHostsAreTrusted(request)),
			'alternate hosts are not in trusted_domains on this instance; '
				+ 'host resolution is covered by PortalResolverTest',
		)

		const response = await request.get(`${API}/site`, {
			headers: { Host: 'unknown.localhost' },
		})

		expect(response.status()).toBe(404)

		// A fallback to "the first portal" would return 200 and look entirely
		// correct on screen. Assert on the body, not only the status: no site's
		// identity may appear in a miss.
		const text = await response.text()
		expect(text).not.toContain('open-tilburg')
		expect(text).not.toContain('open-venray')
		expect(text).not.toContain('Open Tilburg')
		expect(text).not.toContain('Gemeente Venray')
	})

	// @e2e portaliq-cms::a-named-portal-that-does-not-exist-does-not-fall-through-to-the-host
	test('S7b: a named site that does not exist does not fall through to the host', async ({
		request,
	}) => {
		// `?portal=typo` must not quietly serve whichever site owns the hostname
		// the request happened to arrive on.
		const response = await request.get(`${API}/site?portal=does-not-exist`)
		expect(response.status()).toBe(404)
	})

	test('S6b: the API reports a distinct theme REFERENCE per site', async ({
		request,
	}) => {
		// SCOPE: this asserts only that the API returns different theme
		// STRINGS. It says nothing about what a visitor sees, and for a while
		// that gap was the whole story — both portals computed
		// `rgb(26,26,26)` because the renderer set a `<variant>-theme` class
		// that no tokens defined.
		//
		// That is now fixed, and S20 below is the test that would have caught
		// it: it asserts the COMPUTED colour. This one is kept because the
		// reference and the rendering are separate claims — the API can be
		// right while the stylesheet wiring is wrong, and knowing which of the
		// two broke is worth one extra test.
		const tilburg = await (
			await request.get(`${API}/site?portal=open-tilburg`)
		).json()
		const venray = await (
			await request.get(`${API}/site?portal=open-venray`)
		).json()

		expect(tilburg.theme).toBe('vng')
		expect(venray.theme).toBe('venray')
		expect(tilburg.theme).not.toBe(venray.theme)
	})

	// @e2e portaliq-cms::two-portals-compute-different-styles
	test('S20: two portals compute DIFFERENT styles, not just different class names', async ({
		page,
	}) => {
		// THE ASSERTION IS ON THE COMPUTED VALUE, AND THAT IS THE WHOLE TEST.
		// Before the theme bridge existed, the renderer put a `<theme>-theme`
		// class on its root and nothing ever defined tokens for it — so two
		// differently-themed portals were pixel-identical while a test that
		// asserted the CLASS STRING passed. That test read as covering
		// theming for weeks. This one cannot pass that way.
		const sample = async (portal: string) => {
			await page.goto(`${SITE}?portal=${portal}`)
			await expect(page.getByTestId('site-title')).toBeVisible()

			return page.evaluate(() => {
				const title = document.querySelector('[data-testid="site-title"]')
				const root = document.querySelector('[data-testid="site-root"]')
				return {
					heading: getComputedStyle(title as Element).color,
					text: getComputedStyle(root as Element).color,
					stylesheets: Array.from(document.styleSheets)
						.map((s) => s.href || '')
						.filter((h) => h.includes('/tokens/'))
						.map((h) => (h.split('/').pop() as string).split('?')[0]),
				}
			})
		}

		const tilburg = await sample('open-tilburg')
		const venray = await sample('open-venray')

		// Each portal loads its OWN token file, and only its own.
		expect(tilburg.stylesheets).toContain('vng.css')
		expect(tilburg.stylesheets).not.toContain('venray.css')
		expect(venray.stylesheets).toContain('venray.css')
		expect(venray.stylesheets).not.toContain('vng.css')

		// And it makes a difference a visitor can see.
		expect(
			tilburg.heading,
			'two portals with different themes must not compute the same heading colour',
		).not.toBe(venray.heading)
	})
})
