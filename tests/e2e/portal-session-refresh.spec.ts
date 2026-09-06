/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e for portal-session-hardening-v2's session refresh. Closes
 * the `@e2e exclude add Playwright e2e in apply phase` marker on the "A valid
 * bearer refreshes and rotates its jti" scenario in
 * openspec/specs/supplier-portal/spec.md — proving, against the running API,
 * that `POST /portal/api/session/refresh` mints a NEW bearer and the OLD
 * bearer stops validating immediately (rotation, not a second live token).
 * This is what lets a subject filling in a long form or reading a case stay
 * signed in past the fixed TTL by refreshing ahead of it (T04's periodic
 * client-side call), without the test having to wait out the real 2h TTL.
 *
 * The absolute-cap and fail-closed-on-revoked/expired scenarios remain
 * `@e2e exclude ... no UI surface` by design (spec.md) — they are PHPUnit-only
 * invariants (`PortalSessionServiceTest`), not asserted here.
 *
 * Uses the debug-gated `/portal/api/session/dev-login` endpoint (same pattern
 * as tests/e2e/portal-document-download.spec.ts) rather than a real OIDC
 * login — requires `debug: true` (or the dedicated dev-login config) on the
 * target instance; devLogin() 404s otherwise and these tests fail closed with
 * a clear reason rather than a flaky hang.
 *
 * Not run as part of this change's local verification (no live instance with
 * dev-login enabled was exercised for the apply pass) — run manually against
 * a dev instance with:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test portal-session-refresh
 *
 * @spec openspec/specs/supplier-portal/spec.md#session-refresh-rotates-the-token-within-an-absolute-cap
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'

/**
 * Mint a low-trust supplier dev session directly via the API (no UI needed —
 * refresh is a pure API rotation, so this spec never opens a page).
 */
async function devLogin(
	request: APIRequestContext,
	subjectRef: string,
): Promise<string> {
	const res = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'supplier', organisation: 'e2e-org' },
	})
	expect(
		res.ok(),
		'dev-login must be enabled on the target instance (system config debug: true)',
	).toBeTruthy()
	const body = await res.json()
	const token = body.token as string
	expect(token).toBeTruthy()
	return token
}

test.describe('portal-session-hardening-v2 — session refresh', () => {
	// @e2e supplier-portal::a-valid-bearer-refreshes-and-rotates-its-jti
	//
	// The absolute-cap and fail-closed-on-expired scenarios are NOT anchored:
	// they have no UI surface and remain PHPUnit-only invariants in
	// PortalSessionServiceTest, exactly as the spec says.
	test('a valid bearer refreshes, rotates its jti, and the old bearer stops validating', async ({
		request,
	}) => {
		const oldToken = await devLogin(request, `e2e-refresh-${Date.now()}`)

		// The old bearer resolves BEFORE refresh.
		const before = await request.get(`${API_BASE}/session`, {
			headers: { Authorization: `Bearer ${oldToken}` },
		})
		expect(before.ok()).toBeTruthy()
		expect((await before.json()).authenticated).toBe(true)

		const refreshRes = await request.post(`${API_BASE}/session/refresh`, {
			headers: { Authorization: `Bearer ${oldToken}` },
		})
		expect(refreshRes.ok()).toBeTruthy()
		const refreshed = await refreshRes.json()
		const newToken = refreshed.token as string
		expect(newToken).toBeTruthy()
		expect(newToken).not.toBe(oldToken)

		// The NEW bearer resolves.
		const afterNew = await request.get(`${API_BASE}/session`, {
			headers: { Authorization: `Bearer ${newToken}` },
		})
		expect(afterNew.ok()).toBeTruthy()
		expect((await afterNew.json()).authenticated).toBe(true)

		// The OLD bearer no longer validates — rotation, not a second live token.
		const afterOld = await request.get(`${API_BASE}/session`, {
			headers: { Authorization: `Bearer ${oldToken}` },
		})
		expect(afterOld.status()).toBe(401)
		expect((await afterOld.json()).authenticated).toBe(false)
	})

	test('refreshing an already-revoked (logged-out) bearer fails closed with 401', async ({
		request,
	}) => {
		const token = await devLogin(request, `e2e-refresh-revoked-${Date.now()}`)

		const logoutRes = await request.delete(`${API_BASE}/session`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		expect(logoutRes.ok()).toBeTruthy()

		const refreshRes = await request.post(`${API_BASE}/session/refresh`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		expect(refreshRes.status()).toBe(401)
		expect((await refreshRes.json()).error).toBe('unauthorized')
	})
})
