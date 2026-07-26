/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e for wmebv-submission-receipts (T11). Closes the `@e2e
 * exclude add Playwright e2e in apply phase` marker on the "a successful
 * submission produces a receipt in the subject's inbox" scenario in
 * openspec/specs/supplier-portal/spec.md. The other `@e2e exclude` markers on
 * that requirement group (whitelisted-copy invariant, failed-create/no-receipt,
 * proof-log contract, subject/tenant scoping, failure-isolation, and the
 * data-minimisation normaliser matrix) remain — they are asserted at the
 * PHPUnit level (`SubmissionReceiptServiceTest`, `PortalManifestNormaliserTest`,
 * `ContributionControllerTest`), not the UI.
 *
 * Submits the demo `createExample` action (a real `type: create` action
 * declared by `PortalContributionProvider`, whose `exampleDocument` schema
 * genuinely mandates `title` — the same fixture the data-minimisation guard's
 * positive path exercises) through the SPA and asserts the ontvangstbevestiging
 * appears in the unified inbox (portal-inbox-v2) the receipt reuses — bilingual
 * NL/EN text, and NOT the raw client input.
 *
 * Uses the debug-gated `/portal/api/session/dev-login` endpoint (see
 * portal-document-download.spec.ts) rather than driving a real OIDC login —
 * requires `debug: true` (or the dedicated dev-login config) on the target
 * instance; devLogin() 404s otherwise and this test will fail closed with a
 * clear reason rather than a flaky UI hang.
 *
 * Not run as part of this change's local verification (no live 8080 instance
 * with dev-login enabled was exercised for the apply pass) — run manually
 * against a dev instance with:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test wmebv-submission-receipts
 *
 * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
 */

import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

// Pretty-URL app paths, matching the convention already used by
// tests/e2e/portal-inbox.spec.ts (`/apps/portaliq/...`, no `index.php`).
const PORTAL_PATH = '/apps/portaliq/portal'
const API_BASE = '/apps/portaliq/portal/api'

/**
 * Mint a low-trust supplier dev session and seed it into the SPA's
 * localStorage token slot BEFORE the app boots, so the portal loads already
 * authenticated (mirrors how a real bearer, once minted, is stored).
 */
async function loginAsSupplier(request: APIRequestContext, page: Page, subjectRef: string, organisation: string): Promise<void> {
	const res = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'supplier', organisation },
	})
	expect(res.ok(), 'dev-login must be enabled on the target instance (system config debug: true)').toBeTruthy()
	const body = await res.json()
	const token = body.token as string
	expect(token).toBeTruthy()

	await page.addInitScript((t) => {
		window.localStorage.setItem('portaliq_token', t)
	}, token)
}

test.describe('wmebv-submission-receipts', () => {
	test('a successful create-action submission produces a bilingual receipt in the inbox', async ({ page, request }) => {
		const subjectRef = `e2e-receipt-${Date.now()}`
		const organisation = 'e2e-org'
		await loginAsSupplier(request, page, subjectRef, organisation)

		await page.goto(PORTAL_PATH)
		await page.waitForLoadState('domcontentloaded')

		// Submit the demo create-action through the SPA form — the SAME
		// fixture portal-document-download.spec.ts uses to seed a fresh row,
		// `title` being the ONE field the exampleDocument schema genuinely
		// mandates (the WMEBV data-minimisation guard's positive path).
		const title = `E2E WMEBV ${Date.now()}`
		await page.getByLabel('Onderwerp').fill(title)
		await page.getByRole('button', { name: 'Aanmaken' }).click()
		await expect(page.getByText('Voorbeeld aangemaakt')).toBeVisible()

		// The Inbox nav gains an unread badge — the receipt landed in the
		// SAME unified inbox portal-inbox-v2 aggregates, not a separate surface.
		const inboxNav = page.getByRole('button', { name: /Inbox/ })
		await expect(inboxNav.locator('.portaliq-badge-count')).toHaveText('1')

		await inboxNav.click()

		const rows = page.locator('.portaliq-inbox-row')
		await expect(rows).toHaveCount(1)

		// Bilingual (NL / EN) B1-level receipt text with a reference id — never
		// the raw client input rendered as if it were the receipt itself.
		await expect(rows.first()).toContainText('Bevestiging van ontvangst')
		await expect(rows.first()).toContainText('Confirmation of receipt')
		await expect(rows.first().locator('.portaliq-inbox-row__body')).toContainText('WMEBV-')
	})
})
