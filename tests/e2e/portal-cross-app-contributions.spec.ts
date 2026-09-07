/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The test that was missing when a whole app went dark.
 *
 * Portaliq exists to render OTHER apps' contributions. Until now nothing in
 * this suite asserted that a second app's contribution ever arrives: every
 * portal spec logs in and then exercises portaliq's own built-in
 * contribution, which resolves through the same code path whether or not
 * cross-app discovery works at all.
 *
 * That gap had a cost. `PortalContributionRegistry` derived each app's
 * provider class as `OCA\{ucfirst(appId)}\Portal\PortalContributionProvider`.
 * For `zaakafhandelapp`, whose declared namespace is `ZaakAfhandelApp`, that
 * names a class the autoloader cannot produce, and `class_exists()` reports
 * an unloadable class as `false` rather than raising. The app was skipped in
 * silence: no log line, no failing gate, and green unit tests on both sides,
 * because zaakafhandelapp's own provider test constructs the provider
 * directly and portaliq's registry tests only ever used the app id
 * `portaliq`, the one id where the guess and the declared namespace agree.
 *
 * So this spec asserts the integration itself: a second, independently
 * installed app's contribution reaches an authenticated subject. It is
 * deliberately written to FAIL, not skip, when that app is absent. A skip
 * here would restore exactly the silence this spec exists to break, and
 * `dossiq` is installed by `additional-apps` in .github/workflows/
 * code-quality.yml precisely so this assertion has something to see.
 *
 * `dossiq` declares audiences `supplier`, `citizen` and `inspector`. This
 * spec uses `supplier`, matching the dev-login helper the other portal specs
 * already use.
 *
 * BE CLEAR ABOUT WHAT THIS DOES NOT COVER. `dossiq` declares the namespace
 * `Dossiq`, which is exactly `ucfirst('dossiq')`, so this spec would have
 * passed unchanged while zaakafhandelapp was dark. It proves that cross-app
 * discovery works at all, which nothing asserted before; it does not prove
 * that the namespace derivation handles an id whose namespace differs from
 * `ucfirst`. That property is covered where it can be covered honestly, in
 * tests/Unit/Contribution/PortalProviderLocatorTest.php, which points a
 * non-matching app id at a provider class that really exists and fails
 * without the fix. Catching the namespace class here too would mean
 * installing zaakafhandelapp in CI, which ships no composer lockfile; that
 * is a deliberate trade, not an oversight.
 *
 * Run manually against a dev instance with:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test portal-cross-app
 *
 * Note that `dev-login` carries `#[AnonRateLimit(limit: 10, period: 60)]` and
 * answers a tripped limit with a 503 HTML page, so back-to-back local runs
 * fail at login with a message that reads as "debug is off" while debug is
 * on. Give it a clear minute between runs.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

// Pretty-URL app paths, matching tests/e2e/portal-inbox.spec.ts.
const API_BASE = '/apps/portaliq/portal/api'

/** The second app whose contribution must arrive. See the file header. */
const CONTRIBUTING_APP = 'dossiq'

/**
 * Mint a low-trust supplier dev session and return its bearer token.
 *
 * @param request the Playwright request context
 * @param subjectRef the subject this session speaks for
 * @return the bearer token
 */
async function supplierToken(
	request: APIRequestContext,
	subjectRef: string,
): Promise<string> {
	const res = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'supplier', organisation: 'e2e-org' },
	})
	expect(
		res.ok(),
		'dev-login must be enabled on the target instance (system config debug: true). '
			+ 'A 503 here is usually the AnonRateLimit(10/60s) on this endpoint, not a disabled debug flag.',
	).toBeTruthy()

	const token = (await res.json()).token as string
	expect(token, 'dev-login returned no bearer token').toBeTruthy()
	return token
}

test.describe('portal-cross-app-contributions', () => {
	test("a second installed app's contribution reaches an authenticated subject", async ({
		request,
	}) => {
		const token = await supplierToken(request, 'e2e-cross-app')

		const res = await request.get(`${API_BASE}/contributions`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		expect(
			res.ok(),
			`GET ${API_BASE}/contributions failed with ${res.status()}`,
		).toBeTruthy()

		const body = await res.json()
		const apps: string[] = (body.contributions ?? []).map(
			(c: { app: string }) => c.app,
		)

		// Portaliq's own contribution is the control. If this fails the
		// aggregate is broken outright, and the cross-app assertion below
		// would be measuring nothing.
		expect(
			apps,
			"portaliq's own contribution is missing, so the aggregate itself is broken",
		).toContain('portaliq')

		// The assertion this file exists for. A missing entry here means an
		// installed provider was skipped silently, which is exactly how
		// zaakafhandelapp stayed invisible.
		expect(
			apps,
			`${CONTRIBUTING_APP} is installed and declares the supplier audience, but its `
				+ `contribution did not reach the portal. Got: [${apps.join(', ')}]. A provider that `
				+ 'is installed but absent from the aggregate is being dropped without an error, '
				+ 'most likely in PortalProviderLocator.',
		).toContain(CONTRIBUTING_APP)
	})

	test('every contribution in the aggregate names an app and an audience-appropriate label', async ({
		request,
	}) => {
		const token = await supplierToken(request, 'e2e-cross-app-shape')

		const res = await request.get(`${API_BASE}/contributions`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		expect(res.ok()).toBeTruthy()

		const body = await res.json()
		expect(body.audience, 'the aggregate must echo the subject audience').toBe(
			'supplier',
		)
		expect(Array.isArray(body.contributions)).toBeTruthy()
		expect(
			body.contributions.length,
			'an authenticated supplier sees no contributions at all',
		).toBeGreaterThan(0)

		for (const contribution of body.contributions) {
			expect(
				contribution.app,
				'a contribution arrived without an app id',
			).toBeTruthy()
			expect(
				typeof contribution.label,
				`contribution from ${contribution.app} has no label to render`,
			).toBe('string')
		}
	})
})
