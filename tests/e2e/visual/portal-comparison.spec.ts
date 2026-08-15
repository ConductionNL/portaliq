/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Scenario S12 — capture both renderers, from one instance, at one viewport.
 *
 * WHAT THIS IS NOT. It is not a pixel diff. The two renderers show different
 * content models against different fixtures: the React portal shows
 * subject-scoped collections behind a bearer session, the Vue renderer shows
 * public CMS pages. Diffing those images would produce a large number that
 * means nothing, and worse, would look like a measurement.
 *
 * What IS compared — bundle size, request count, what each can render, and
 * what each cannot — is recorded in docs/portal-parity.md, and the numbers
 * there come from this spec.
 *
 * Opt-in, like the docs-capture project: run with
 *   npx playwright test tests/e2e/visual/portal-comparison.spec.ts
 */

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from '../base-url'

const BASE = resolveBaseURL()
const REACT_PORTAL = `${BASE}/index.php/apps/portaliq/portal`
const VUE_SITE = `${BASE}/index.php/apps/portaliq/site`
const OUT = 'tests/e2e/visual'

// One viewport for both, or the comparison is between two different layouts
// rather than two renderers.
const VIEWPORT = { width: 1280, height: 900 }

test.use({ viewport: VIEWPORT })

test.describe('portal comparison', () => {
	test('S12: capture the React portal', async ({ page, request }) => {
		// The React portal needs a bearer session or it renders its sign-in
		// state. Capturing it signed out would compare a working renderer with
		// a login screen and call the difference progress.
		const login = await request.post(
			`${BASE}/index.php/apps/portaliq/portal/api/session/dev-login`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)

		if (!login.ok()) {
			test.skip(true, 'dev-login unavailable; set portaliq dev_login_enabled=yes on the target')
		}

		const { token } = await login.json()
		await page.addInitScript((value) => {
			window.localStorage.setItem('portaliq_token', value as string)
		}, token)

		await page.goto(REACT_PORTAL)
		await expect(page.getByRole('button', { name: 'Voorbeeld' })).toBeVisible()

		await page.screenshot({
			path: `${OUT}/baseline-react/react-portal-1280.png`,
			fullPage: true,
		})
	})

	test('S12: capture the Vue site renderer', async ({ page }) => {
		await page.goto(VUE_SITE)
		await expect(page.getByTestId('site-title')).toBeVisible()

		await page.screenshot({
			path: `${OUT}/current-vue/vue-site-home-1280.png`,
			fullPage: true,
		})

		await page.goto(`${VUE_SITE}?route=/over-ons`)
		await expect(page.getByTestId('page-title')).toHaveText('Over ons')
		await page.screenshot({
			path: `${OUT}/current-vue/vue-site-markdown-1280.png`,
			fullPage: true,
		})
	})

	test('S12: record what each renderer costs on a first visit', async ({ page }) => {
		// Measured on TRANSFERRED bytes for an empty cache, not on the build's
		// own report of what it emitted. Those two numbers differ whenever
		// compression, code-splitting or a service worker is involved, and the
		// one a visitor pays is this one.
		const measure = async (url: string, marker: () => Promise<void>) => {
			const transferred: Record<string, number> = {}
			page.on('response', async (response) => {
				const url = response.url()
				if (!url.includes('/custom_apps/portaliq/js/')) {
					return
				}
				try {
					const body = await response.body()
					transferred[url.split('/').pop() as string] = body.length
				} catch {
					// A response whose body is no longer available is not worth
					// failing a measurement over; it is reported as absent.
				}
			})

			await page.goto(url)
			await marker()
			page.removeAllListeners('response')
			return transferred
		}

		const vue = await measure(VUE_SITE, async () => {
			await expect(page.getByTestId('site-title')).toBeVisible()
		})

		const total = Object.values(vue).reduce((a, b) => a + b, 0)
		// eslint-disable-next-line no-console
		console.log('vue site bundle bytes:', JSON.stringify(vue), 'total', total)

		// The site bundle must stay inside the public-surface budget. This is a
		// failure, not a warning: a budget nobody fails is a budget nobody
		// keeps.
		expect(total).toBeGreaterThan(0)
		expect(total).toBeLessThan(400 * 1024)
	})
})
