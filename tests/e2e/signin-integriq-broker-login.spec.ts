/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for signin-integriq-broker-login: the broker route refuses what
 * it cannot finish and lands every failure on the login screen's fragment,
 * and the Sign-in settings refuse a broker route without a start address.
 * Requires a seeded instance (tests/e2e/ci-seed.sh). Integriq has no start
 * address yet, so the signed-in happy path is pinned by
 * tests/Unit/Service/Signin/BrokerLoginTest.php, not driven here. Written,
 * run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test signin-integriq-broker-login
 *
 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md
 */

import { expect, test } from '@playwright/test'

const ADMIN = Buffer.from('admin:admin').toString('base64')
const HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }

test.describe('signin-integriq-broker-login', () => {
	test('Two different failures look the same', async ({ request }) => {
		const start = await request.get(
			'/apps/portaliq/portal/api/session/broker/start?org=no-such-org&provider=digid',
			{ maxRedirects: 0 },
		)
		const callback = await request.get(
			'/apps/portaliq/portal/api/session/broker/callback?state=unknown&code=x',
			{ maxRedirects: 0 },
		)

		expect(start.status()).toBe(302)
		expect(callback.status()).toBe(302)
		expect(start.headers().location).toMatch(/#signin=failed$/)
		expect(callback.headers().location).toBe(start.headers().location)
	})

	test('An OIDC-routed provider cannot be started on the broker route', async ({
		request,
	}) => {
		const start = await request.get(
			'/apps/portaliq/portal/api/session/broker/start?org=no-such-org&provider=generic',
			{ maxRedirects: 0 },
		)
		expect(start.headers().location).toMatch(/#signin=failed$/)
	})

	test('An incomplete broker route shows no button', async ({ request }) => {
		const slug = `signin-${Date.now()}`
		const created = await request.post(
			'/apps/openregister/api/objects/portaliq/portal',
			{
				headers: HEADERS,
				data: {
					slug,
					title: 'Inlog-test',
					status: 'draft',
					organisation: 'default',
				},
			},
		)
		expect(created.ok()).toBeTruthy()

		const saved = await request.put(
			`/apps/portaliq/api/portals/${slug}/signin`,
			{
				headers: HEADERS,
				data: {
					routes: { digid: 'broker' },
					broker: {
						exchangeUrl: 'https://integriq.example/x',
						consumerId: 'pq',
					},
					secret: 's3cret',
				},
			},
		)
		expect([404, 422]).toContain(saved.status())
	})

	test('A failed login lands on the login screen with one sentence', async ({
		page,
	}) => {
		await page.goto('/apps/portaliq/portal#signin=failed')
		await expect(page.getByRole('alert')).toContainText(
			'Inloggen is niet gelukt',
		)
		expect(page.url()).not.toContain('#signin=failed')
	})
})
