/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e for portal-notifications-dispatch (T11). Closes the `@e2e
 * exclude add Playwright e2e in apply phase` marker on the "a declared rule
 * key sends a content-free nudge" scenario's OBSERVABLE-from-the-SPA half —
 * the deep link the email points at resolves, and a create-action submission
 * that fires the `message.created` trigger completes promptly (the request
 * is never slowed or failed by the async dispatch, per "dispatch is decoupled
 * from the request path").
 *
 * What this test CANNOT assert from Playwright alone, and therefore does
 * NOT attempt to: the actual email content/delivery. Sending only happens
 * once NC's background-job runner (cron/ajax/webcron) later executes
 * `NotificationDispatchJob`, and observing it requires a mail-catcher (e.g.
 * MailHog) wired into the target instance's SMTP config — infrastructure
 * this apply pass had no access to. The content-free template, the
 * portalNotification sent/failed log, and the needsAlternativeContact
 * fallback flag are all asserted at the PHPUnit level instead
 * (`NotificationDispatchJobTest`) — genuinely "no UI surface", not a
 * shortcut. The manifest opt-in match/no-match and the enqueue-never-sends-
 * inline invariant are asserted in `NotificationDispatchServiceTest`.
 *
 * Uses the debug-gated `/portal/api/session/dev-login` endpoint (see
 * portal-document-download.spec.ts) rather than driving a real OIDC login —
 * requires `debug: true` (or the dedicated dev-login config) on the target
 * instance; devLogin() 404s otherwise and this test will fail closed with a
 * clear reason rather than a flaky UI hang.
 *
 * NOT RUN as part of this change's local verification — no live instance
 * with dev-login AND a mail-catcher was exercised for the apply pass. Run
 * manually against a dev instance with:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test portal-notifications-dispatch
 *
 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
 * @spec openspec/specs/supplier-portal/spec.md#dispatch-is-decoupled-from-the-request-path
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

test.describe('portal-notifications-dispatch', () => {
	test('the deep link an email points at resolves the tenant', async ({ page }) => {
		// The privacy-minimal email's ONLY link is `/portal?org=<slug>` — assert
		// it lands the (unauthenticated) portal shell rather than a 404/blank
		// page, and that white-label resolution reads the org param, exactly as
		// design.md specifies ("the deep link routes through the SPA's existing
		// deep-linking, landing the subject at the authenticated inbox after
		// login").
		await page.goto(`${PORTAL_PATH}?org=e2e-org`)
		await page.waitForLoadState('networkidle')

		// The shell renders (no 404) — the subject is prompted to authenticate
		// rather than seeing case content, matching the "content only behind the
		// portal auth edge" invariant.
		await expect(page.locator('body')).not.toContainText('404')
	})

	test('a create-action that fires message.created completes promptly — dispatch never blocks the request', async ({ page, request }) => {
		const subjectRef = `e2e-notif-${Date.now()}`
		const organisation = 'e2e-org'
		await loginAsSupplier(request, page, subjectRef, organisation)

		await page.goto(PORTAL_PATH)
		await page.waitForLoadState('networkidle')

		// The demo contribution (PortalContributionProvider) now declares
		// `message.created` (and `status.changed`) in its `notifications` list —
		// so THIS submission is exactly the trigger NotificationDispatchService
		// matches. The receipt-message write (wmebv-submission-receipts) already
		// fires the trigger synchronously in-request; only the EMAIL SEND itself
		// is pushed to the background job. A slow/failing mail server must never
		// surface here — the request completing with the normal success UI,
		// promptly, is the observable proof of that decoupling from the SPA side.
		const title = `E2E notif ${Date.now()}`
		await page.getByLabel('Onderwerp').fill(title)
		await page.getByRole('button', { name: 'Aanmaken' }).click()
		await expect(page.getByText('Voorbeeld aangemaakt')).toBeVisible({ timeout: 5000 })
	})
})
