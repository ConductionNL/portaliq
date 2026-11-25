/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-headless-content-api task 4 (Test), the live half: the anonymous
 * read path is throttled, a cache hit is not an exemption, and an outside
 * consumer can rebuild a site's navigation, pages and glossary from the
 * public content API alone. The static half (every content route is
 * PublicPage + AnonRateLimit, every URL the portal reads is routed) is
 * tests/Unit/Controller/HeadlessConformanceTest.php.
 *
 * The walk uses nothing but the public `/api/content/*` URLs, with no session
 * and no Portaliq internal. A walk that needed one would not be testing
 * headlessness.
 *
 * Env: PORTALIQ_E2E_HEADLESS_PORTAL (default `open-tilburg`, which
 * tests/e2e/fixtures/seed-cms.sh seeds); ADMIN_USER / ADMIN_PASSWORD read the
 * metrics route for the second throttle discriminator.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const API = `${BASE}/index.php/apps/portaliq/api/content`
const PORTAL = process.env.PORTALIQ_E2E_HEADLESS_PORTAL ?? 'open-tilburg'
const ADMIN_USER = process.env.ADMIN_USER ?? 'admin'
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD ?? 'admin'

/**
 * The anonymous limit of the content routes, read from the controller that
 * enforces it, so this spec and the attribute cannot drift apart.
 *
 * @return {{limit: number, period: number}} The limit and its period in seconds.
 */
function contentRateLimit(): { limit: number, period: number } {
	const source = readFileSync(
		join(__dirname, '..', '..', 'lib', 'Controller', 'ContentController.php'),
		'utf8',
	)
	const match = source.match(/#\[AnonRateLimit\(limit:\s*(\d+),\s*period:\s*(\d+)\)\]/)
	if (match === null) {
		throw new Error('ContentController.php declares no #[AnonRateLimit(limit: n, period: n)]; the throttle test cannot read its limit.')
	}
	return { limit: Number(match[1]), period: Number(match[2]) }
}

/**
 * The content cache counters from the metrics route (admin only).
 *
 * @param {APIRequestContext} admin A context signed in as an admin.
 * @return {Promise<{hit: number, miss: number}>} The counters.
 */
async function cacheCounters(admin: APIRequestContext): Promise<{ hit: number, miss: number }> {
	const res = await admin.get(`${BASE}/index.php/apps/portaliq/api/metrics`, {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(res.status(), 'the metrics route answers the admin').toBe(200)
	const text = await res.text()
	const read = (outcome: string): number => {
		const m = text.match(new RegExp(`^portaliq_content_cache_total\\{outcome="${outcome}"\\}\\s+(\\d+)`, 'm'))
		return m === null ? Number.NaN : Number(m[1])
	}
	return { hit: read('hit'), miss: read('miss') }
}

/**
 * Every internal link of a menu tree, depth first.
 *
 * @param {Array<object>} items Menu items with `link` and nested `items`.
 * @return {string[]} The in-site routes.
 */
function menuRoutes(items: Array<{ link?: string, items?: unknown[] }>): string[] {
	const routes: string[] = []
	for (const item of items ?? []) {
		const link = typeof item.link === 'string' ? item.link : ''
		if (link.startsWith('/') && !link.startsWith('//')) {
			routes.push(link.split('#')[0].split('?')[0] || '/')
		}
		routes.push(...menuRoutes((item.items ?? []) as Array<{ link?: string, items?: unknown[] }>))
	}
	return routes
}

test.describe('headless content API — live', () => {
	// @e2e portal-headless-content-api::an-external-consumer-reproduces-a-site
	test('an outside consumer rebuilds navigation, pages and glossary from the public API alone', async () => {
		const anon = await playwrightRequest.newContext()

		const site = await anon.get(`${API}/site?portal=${PORTAL}`)
		expect(site.status()).toBe(200)
		expect((await site.json()).slug).toBe(PORTAL)

		const menusRes = await anon.get(`${API}/menus?portal=${PORTAL}`)
		expect(menusRes.status()).toBe(200)
		const menus = (await menusRes.json()).menus as Array<{ title: string, items: Array<{ link?: string, items?: unknown[] }> }>
		expect(menus.length, 'the portal has at least one menu').toBeGreaterThan(0)

		const routes = [...new Set(menus.flatMap((menu) => menuRoutes(menu.items)))]
		expect(routes.length, 'the menus link at least one in-site page').toBeGreaterThan(0)

		// Every page the navigation links is served by the API with a title and
		// a body. A route that answers 404 here is a page only the built-in
		// renderer could reach, named in the failure.
		const unreachable: string[] = []
		for (const route of routes) {
			const res = await anon.get(`${API}/page?portal=${PORTAL}&route=${encodeURIComponent(route)}`)
			if (res.status() !== 200) {
				unreachable.push(`${route} (${res.status()})`)
				continue
			}
			const page = await res.json()
			if (typeof page.title !== 'string' || page.title === '' || page.body === undefined) {
				unreachable.push(`${route} (no title or body)`)
			}
		}
		expect(unreachable, 'menu routes the public API does not serve').toEqual([])

		const glossary = await anon.get(`${API}/glossary?portal=${PORTAL}`)
		expect(glossary.status()).toBe(200)

		await anon.dispose()
	})

	// @e2e portal-headless-content-api::excess-anonymous-reads-are-refused
	// @e2e portal-headless-content-api::a-cache-hit-does-not-bypass-the-limit
	test('anonymous reads past the limit are refused before the cached read runs', async () => {
		const { limit } = contentRateLimit()
		// An explicit Authorization header: Nextcloud answers 401 without a
		// Basic challenge, so httpCredentials would never be sent.
		const admin = await playwrightRequest.newContext({
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${ADMIN_USER}:${ADMIN_PASSWORD}`).toString('base64')}`,
			},
		})
		const anon = await playwrightRequest.newContext()
		// The FAQ route: the other specs read site, menus and pages, so spending
		// this route's window here leaves them alone.
		const url = `${API}/faq?portal=${PORTAL}`

		const start = await cacheCounters(admin)
		const first = await anon.get(url)
		expect(first.status(), 'the route answers before the limit').toBe(200)
		const warm = await anon.get(url)
		expect(warm.status()).toBe(200)
		const afterWarm = await cacheCounters(admin)
		// Without a memory cache nothing is cached, so the cache-hit scenario
		// cannot be shown here. Say so rather than pass on an empty counter.
		test.skip(
			!(afterWarm.hit > start.hit),
			'this instance caches no content reads (no memcache), so a refused cache hit cannot be told apart',
		)

		// Spend the rest of the window. The route is warm, so every one of
		// these is a cache hit, which is exactly what must still be counted.
		let ok = 2
		let refused = 0
		for (let i = 0; i < limit + 5 && refused === 0; i++) {
			const res = await anon.get(url)
			if (res.status() === 200) {
				ok++
			} else if (res.status() === 429) {
				refused++
			} else {
				throw new Error(`unexpected status ${res.status()} while spending the window`)
			}
		}
		// Discriminator 1: the server answers 429 Too Many Requests, after at
		// most `limit` successes in the window.
		expect(refused, 'a read past the limit is refused').toBe(1)
		expect(ok, 'successes in the window').toBeLessThanOrEqual(limit)

		// Discriminator 2: refused reads never reach the content read. The
		// cache counters stand still while 20 more reads are refused, so the
		// refusal is the throttle, not an empty or failing read.
		const atLimit = await cacheCounters(admin)
		for (let i = 0; i < 20; i++) {
			expect((await anon.get(url)).status()).toBe(429)
		}
		const afterRefused = await cacheCounters(admin)
		expect(afterRefused).toEqual(atLimit)

		await anon.dispose()
		await admin.dispose()
	})
})
