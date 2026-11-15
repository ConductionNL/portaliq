/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for signin-session-idle-warning-and-sso T07-T11 at the edge: a
 * silent start that the broker answers with login_required lands on the portal
 * with no error, and a state that was never silent keeps the generic failure.
 * The signed-in round trip through a stub broker holding a session is pinned
 * by tests/Unit/Controller/SessionControllerTest.php (testSilentStartRecordsTheFlag,
 * testSilentLoginRequiredLandsQuietly, testLogoutReturnsTheBrokerLogoutUrl);
 * it needs an organisation with an OIDC broker configured. Written, run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test signin-session-sso
 *
 * @spec openspec/specs/portal-session-idle-and-sso/spec.md
 */

import { expect, test } from '@playwright/test'

test.describe('signin-session-sso', () => {
	test('An unknown state with login_required keeps the generic failure', async ({
		request,
	}) => {
		const callback = await request.get(
			'/apps/portaliq/portal/api/session/oidc/callback?state=unknown&error=login_required',
			{ maxRedirects: 0 },
		)
		expect(callback.status()).toBe(400)
		expect(await callback.json()).toEqual({ error: 'oidc_failed' })
	})

	test('A silent start for an organisation without a broker is the generic failure, not a redirect', async ({
		request,
	}) => {
		const start = await request.get(
			'/apps/portaliq/portal/api/session/oidc/start?org=no-such-org&provider=digid&silent=1',
			{ maxRedirects: 0 },
		)
		expect(start.status()).toBe(400)
	})

	test('Signing out without a session answers ok and no broker address', async ({
		request,
	}) => {
		const out = await request.delete('/apps/portaliq/portal/api/session')
		expect(await out.json()).toEqual({ ok: true })
	})
})
