/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e for portal-inbox-v2 (T10). Closes the `@e2e exclude add
 * Playwright e2e in apply phase` markers on the "messages from multiple apps
 * merge into one sorted inbox" and "2:10 metadata renders only when
 * supplied" scenarios in openspec/specs/supplier-portal/spec.md. The other
 * `@e2e exclude` markers on that spec (per-row trust/tenant boundary,
 * mark-read contract, foreign-message 404/no-oracle, field-whitelist,
 * unread-count contract) remain — they are asserted at the PHPUnit level
 * (`ContributionControllerTest`, `PortalInboxReaderTest`), not the UI.
 *
 * Seeds two `portalMessage` rows directly against OpenRegister's own object
 * API (`objects#create` is `#[PublicPage]` — no auth required, an existing
 * OpenRegister surface, not something this change introduces) rather than
 * through the portal: the demo contribution (`PortalContributionProvider`)
 * declares the `inbox` collection read-only, with no `type: create` action
 * for `portalMessage` (mark-read is the only write this change adds). This
 * mirrors how a real contributing app (procest, pipelinq, …) would already
 * have written the row before the subject ever opens the portal.
 *
 * Uses the debug-gated `/portal/api/session/dev-login` endpoint (see
 * portal-document-download.spec.ts) rather than driving a real OIDC login —
 * requires `debug: true` (or the dedicated dev-login config) on the target
 * instance; devLogin() 404s otherwise and these tests will fail closed with
 * a clear reason rather than a flaky UI hang.
 *
 * Not run as part of this change's local verification (no live 8080 instance
 * with dev-login enabled was exercised for the apply pass) — run manually
 * against a dev instance with:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test portal-inbox
 *
 * @spec openspec/specs/supplier-portal/spec.md#unified-inbox-aggregates-every-inbox-collection-subject-scoped
 * @spec openspec/specs/supplier-portal/spec.md#optional-2-10-message-metadata-rendered-when-present
 * @spec openspec/specs/supplier-portal/spec.md#tamper-proof-mark-read
 */

import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

// Pretty-URL app paths, matching the convention already used by
// tests/e2e/portal-document-download.spec.ts (`/apps/portaliq/...`, no `index.php`).
const PORTAL_PATH = '/apps/portaliq/portal'
const API_BASE = '/apps/portaliq/portal/api'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'

/**
 * Mint a low-trust supplier dev session and seed it into the SPA's
 * localStorage token slot BEFORE the app boots, so the portal loads already
 * authenticated (mirrors how a real bearer, once minted, is stored).
 */
async function loginAsSupplier(request: APIRequestContext, page: Page, subjectRef: string, organisation: string): Promise<string> {
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

	return token
}

/**
 * Seed a `portalMessage` row directly via OpenRegister's own object API — the
 * demo contribution declares the inbox collection read-only (no `type:
 * create` action exists for `portalMessage`), so this stands in for a
 * contributing app having already written the row.
 */
async function seedMessage(request: APIRequestContext, subjectRef: string, organisation: string, data: Record<string, unknown>): Promise<string> {
	// OpenRegister's object API is an internal admin surface (no portal bearer
	// applies) — authenticate as the dev admin. This stands in for a
	// contributing app having written the row server-side.
	const admin = Buffer.from('admin:admin').toString('base64')
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/portalMessage`, {
		headers: { Authorization: `Basic ${admin}`, 'OCS-APIRequest': 'true' },
		data: { subjectRef, organisation, ...data },
	})
	expect(res.ok(), 'OpenRegister objects#create must be reachable on the target instance').toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	return id
}

test.describe('portal-inbox-v2', () => {
	test('merged inbox with unread badge, 2:10 metadata, and read-state toggle', async ({ page, request }) => {
		const subjectRef = `e2e-inbox-${Date.now()}`
		const organisation = 'e2e-org'

		// Two messages: one unread carrying the WMEBV 2:10 readiness fields
		// (newer), one already read with none of them (older) — proves both
		// the conditional-metadata render AND that an unread row without the
		// fields shows no empty placeholders.
		await seedMessage(request, subjectRef, organisation, {
			subject: 'Uw aanvraag is beoordeeld',
			body: 'Zie de bijlage voor details.',
			read: false,
			receivedAt: '2026-07-23T10:00:00Z',
			aard: 'Beschikking',
			rechtsgevolg: 'De aanvraag is toegewezen.',
			termijn: '2026-08-06T00:00:00Z',
		})
		await seedMessage(request, subjectRef, organisation, {
			subject: 'Welkomstbericht',
			body: 'Welkom in het portaal.',
			read: true,
			receivedAt: '2026-07-01T09:00:00Z',
		})

		await loginAsSupplier(request, page, subjectRef, organisation)

		await page.goto(PORTAL_PATH)
		await page.waitForLoadState('domcontentloaded')

		// The unread badge on the Inbox nav item reflects the ONE unread
		// message (portal-inbox-v2 T04 / contributions unread count).
		const inboxNav = page.getByRole('button', { name: /Inbox/ })
		await expect(inboxNav.locator('.portaliq-badge-count')).toHaveText('1')

		await inboxNav.click()

		// Both rows are present, newest (unread) first — merged + sorted by
		// receivedAt descending.
		const rows = page.locator('.portaliq-inbox-row')
		await expect(rows).toHaveCount(2)
		await expect(rows.nth(0)).toContainText('Uw aanvraag is beoordeeld')
		await expect(rows.nth(1)).toContainText('Welkomstbericht')

		// 2:10 metadata renders on the row that carries it …
		const unreadRow = rows.nth(0)
		await expect(unreadRow).toHaveClass(/portaliq-inbox-row--unread/)
		await expect(unreadRow.locator('.portaliq-inbox-row__meta')).toContainText('Beschikking')
		await expect(unreadRow.locator('.portaliq-inbox-row__meta')).toContainText('De aanvraag is toegewezen.')

		// … and is absent entirely (no empty placeholder) on the row without it.
		const readRow = rows.nth(1)
		await expect(readRow).not.toHaveClass(/portaliq-inbox-row--unread/)
		await expect(readRow.locator('.portaliq-inbox-row__meta')).toHaveCount(0)

		// Toggle read state on the unread row — tamper-proof mark-read
		// (portal-inbox-v2 T03), server-confirmed before the UI flips.
		await unreadRow.locator('.portaliq-inbox-row__toggle').click()
		await expect(unreadRow.locator('.portaliq-inbox-row__toggle')).toHaveText('Read')
		await expect(unreadRow).not.toHaveClass(/portaliq-inbox-row--unread/)

		// The nav badge disappears once nothing is unread.
		await expect(inboxNav.locator('.portaliq-badge-count')).toHaveCount(0)
	})
})
