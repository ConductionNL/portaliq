/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for intake-catalogue-and-address-without-sign-in: a signed-out
 * visitor reads the intake catalogue and looks up an address; the other
 * intake routes keep the bearer gate (decision 25 Nov 2026 21:50 CET).
 *
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions. Run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test intake-catalogue-and-address-without-sign-in
 *
 * @spec openspec/changes/intake-catalogue-and-address-without-sign-in/specs/portal-intake-form/spec.md
 */

import { expect, test } from '@playwright/test'
import { SITE_PORTAL } from './portal-nav.ts'

const API_BASE = '/apps/portaliq/portal/api'

test.describe('intake-catalogue-and-address-without-sign-in', () => {
	// @e2e portal-intake-form::a-signed-out-visitor-reads-the-catalogue
	test('a signed-out visitor reads the catalogue', async ({ request }) => {
		const res = await request.get(`${API_BASE}/intake/catalogue?portal=${encodeURIComponent(SITE_PORTAL)}`)

		expect(res.status(), await res.text()).toBe(200)
		expect(Array.isArray((await res.json()).topics)).toBeTruthy()
	})

	// @e2e portal-intake-form::a-signed-out-visitor-looks-up-an-address
	test('a signed-out visitor looks up an address', async ({ request }) => {
		const res = await request.get(`${API_BASE}/intake/address?postcode=9999ZZ&number=1`)

		// No address register answers this one: a 404 the form falls back
		// from, never the bearer gate's 401.
		expect(res.status()).not.toBe(401)
		expect([200, 404]).toContain(res.status())
	})

	// @e2e portal-intake-form::the-other-intake-routes-still-need-a-session
	test('the other intake routes still need a session', async ({ request }) => {
		const res = await request.get(`${API_BASE}/intake/form?route=${encodeURIComponent('aanvragen/verhuizing')}&portal=${encodeURIComponent(SITE_PORTAL)}`)

		expect(res.status()).toBe(401)
	})
})
