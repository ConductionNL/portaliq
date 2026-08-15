/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Scenarios S4, S8–S11 — the properties that are dangerous when they quietly
 * stop holding: draft content leaking, a cache pooling visitors, markdown
 * executing, a non-public widget mounting anonymously, and the renderer
 * silently depending on Nextcloud.
 *
 * See tests/e2e/scenarios/portal-phase-two.md.
 */

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const API = `${BASE}/index.php/apps/portaliq/api/content`

test.describe('site renderer — security properties', () => {
	test('S4: a draft page and a non-existent route are indistinguishable', async ({ request }) => {
		const draft = await request.get(`${API}/page?route=/concept`)
		const missing = await request.get(`${API}/page?route=/does-not-exist`)

		expect(draft.status()).toBe(404)
		expect(missing.status()).toBe(404)
		// Byte-identical, not merely both-404: a different body would tell an
		// attacker which routes exist but are unreleased.
		expect(await draft.text()).toBe(await missing.text())

		// POSITIVE CONTROL. A `status` filter that hid everything would satisfy
		// both assertions above while breaking the site completely.
		const published = await request.get(`${API}/page?route=/over-ons`)
		expect(published.status()).toBe(200)
	})

	test('S8: anonymous and authenticated responses are marked for different caches', async ({ request }) => {
		const anonymous = await request.get(`${API}/menus`)
		const authenticated = await request.get(`${API}/menus`, {
			headers: { Authorization: 'Bearer irrelevant-for-this-assertion' },
		})

		expect(anonymous.headers()['cache-control']).toContain('public')
		// `no-store` on the authenticated variant is what stops a CDN pooling
		// one signed-in visitor's response across everybody — a leak that would
		// happen at the edge, where this instance's logs never see it.
		expect(authenticated.headers()['cache-control']).toContain('no-store')
		expect(authenticated.headers()['cache-control']).not.toContain('public')
	})

	test('S9: hostile markdown does not execute, and the safe prose survives', async ({ page }) => {
		const consoleErrors: string[] = []
		page.on('pageerror', (error) => consoleErrors.push(error.message))

		await page.goto(`${SITE}?route=/xss-probe`)
		await expect(page.getByTestId('page-title')).toHaveText('Sanitisatieproef')

		// Nothing ran.
		const executed = await page.evaluate(
			() => (window as unknown as Record<string, unknown>).__pqXssRan === true,
		)
		expect(executed).toBe(false)

		// Nothing survives in the DOM that could run on interaction either.
		const dangerous = await page.evaluate(() => {
			const main = document.querySelector('#pq-main')
			if (!main) {
				return { scripts: -1, jsHrefs: -1, onerror: -1 }
			}
			return {
				scripts: main.querySelectorAll('script').length,
				jsHrefs: Array.from(main.querySelectorAll('a')).filter((a) =>
					(a.getAttribute('href') || '').toLowerCase().startsWith('javascript:'),
				).length,
				onerror: main.querySelectorAll('[onerror]').length,
			}
		})
		expect(dangerous.scripts).toBe(0)
		expect(dangerous.jsHrefs).toBe(0)
		expect(dangerous.onerror).toBe(0)

		// POSITIVE CONTROL. A sanitiser that discarded the whole document would
		// satisfy every assertion above.
		await expect(page.locator('#pq-main')).toContainText('Veilige tekst blijft staan')
		await expect(page.locator('#pq-main')).toContainText('Einde van de pagina')

		expect(consoleErrors).toEqual([])
	})

	test('S10: a non-public widget degrades and the rest of the page survives', async ({ page }) => {
		await page.goto(`${SITE}?route=/widget-probe`)

		// The two public widgets render their content.
		await expect(page.getByTestId('widget-ok-one')).toContainText('Publieke widget een')
		await expect(page.getByTestId('widget-ok-two')).toContainText('Publieke widget twee')

		// The non-public one renders a placeholder, not its own content, and
		// crucially does not take the page down with it.
		const blocked = page.getByTestId('widget-blocked')
		await expect(blocked.getByTestId('widget-placeholder')).toBeVisible()
		await expect(blocked).not.toContainText('/geheim')
	})

	test('S11: the renderer works with the Nextcloud globals deleted', async ({ page }) => {
		// Removed BEFORE any bundle runs. This is the closest a
		// Nextcloud-hosted test gets to a public origin: without it, "does not
		// depend on Nextcloud" and "Nextcloud happened to be there" are the
		// same observation.
		await page.addInitScript(() => {
			Object.defineProperty(window, 'OC', { value: undefined, configurable: true })
			Object.defineProperty(window, 'OCA', { value: undefined, configurable: true })
			Object.defineProperty(window, 'OCP', { value: undefined, configurable: true })
		})

		await page.goto(SITE)

		await expect(page.getByTestId('site-title')).toHaveText('Open Tilburg')
		await expect(page.getByTestId('site-menu')).toBeVisible()
		await expect(page.getByTestId('page-title')).toHaveText('Welkom')
	})
})

test.describe('site renderer — cache invalidation', () => {
	test('S8b: creating a page clears the cached miss for its route', async ({ request }) => {
		// The read cache stores NEGATIVE results, so this is the case that
		// matters: a route 404s, its page is then created, and the route must
		// serve immediately rather than after the TTL. With expiry-only
		// invalidation an editor publishes and the site stays broken for five
		// minutes — and concludes, correctly, that the CMS does not work.
		const route = `/invalidation-probe-${Date.now()}`
		const objects = `${BASE}/index.php/apps/openregister/api/objects/portaliq/page`
		const auth = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true' }

		// 1. Cache the miss.
		const before = await request.get(`${API}/page?route=${encodeURIComponent(route)}`)
		expect(before.status()).toBe(404)

		// 2. Create the page.
		const created = await request.post(objects, {
			headers: { ...auth, 'Content-Type': 'application/json' },
			data: {
				title: 'Invalidatieproef',
				route,
				website: 'open-tilburg',
				status: 'published',
				locale: 'nl',
				summary: 'Controle op cache-invalidatie.',
				body: { type: 'markdown', markdown: 'Deze pagina bestond een moment geleden nog niet.\n' },
			},
		})
		expect(created.ok()).toBe(true)
		const id = (await created.json())['@self'].id

		try {
			// 3. Immediately — no sleep, no TTL.
			const after = await request.get(`${API}/page?route=${encodeURIComponent(route)}`)
			expect(after.status()).toBe(200)
			expect((await after.json()).title).toBe('Invalidatieproef')
		} finally {
			await request.delete(`${objects}/${id}`, { headers: auth })
		}
	})
})
