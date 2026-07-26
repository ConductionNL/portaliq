/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e for portal-document-download. Closes the `@e2e exclude add
 * Playwright e2e in apply phase` marker on the "subject downloads a file on a
 * row they own" scenario in openspec/specs/supplier-portal/spec.md, and
 * exercises the identical-404 discipline (foreign/absent file) against the
 * running API. The other two `@e2e exclude` markers on that spec
 * (opt-in-gate, no-oracle three-way, audit-hook placement) remain — they are
 * asserted at the PHPUnit level (`ContributionControllerTest`), not the UI.
 *
 * Uses the debug-gated `/portal/api/session/dev-login` endpoint (mirrors
 * `PortalContributionProvider`'s demo `exampleCollection`, which declares both
 * `filesUpload: true` and `filesDownload: true`) rather than driving a real
 * OIDC login — requires `debug: true` (or the dedicated dev-login config) on
 * the target instance; devLogin() 404s otherwise and these tests will fail
 * closed with a clear reason rather than a flaky UI hang.
 *
 * Not run as part of this change's local verification (no live 8080 instance
 * with dev-login enabled was exercised for the apply pass) — run manually
 * against a dev instance with:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test portal-document-download
 *
 * @spec openspec/specs/supplier-portal/spec.md#scoped-file-download-re-verifies-ownership-before-serving-a-byte
 * @spec openspec/specs/supplier-portal/spec.md#identical-404-discipline-no-existence-oracle
 */

import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

// Pretty-URL app paths, matching the convention already used by
// tests/e2e/docs-screenshots.spec.ts (`/apps/portaliq/...`, no `index.php`).
const PORTAL_PATH = '/apps/portaliq/portal'
const API_BASE = '/apps/portaliq/portal/api'

/**
 * Mint a low-trust supplier dev session and seed it into the SPA's
 * localStorage token slot BEFORE the app boots, so the portal loads already
 * authenticated (mirrors how a real bearer, once minted, is stored).
 */
async function loginAsSupplier(request: APIRequestContext, page: Page, subjectRef: string): Promise<string> {
	const res = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'supplier', organisation: 'e2e-org' },
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

test.describe('portal-document-download', () => {
	test('a subject downloads a file on a row they own', async ({ page, request }) => {
		await loginAsSupplier(request, page, `e2e-download-${Date.now()}`)

		await page.goto(PORTAL_PATH)
		await page.waitForLoadState('domcontentloaded')

		// Create a fresh example row via the demo "Nieuw voorbeeld" form so the
		// test owns a row with no pre-existing state to collide with.
		const title = `E2E download ${Date.now()}`
		await page.getByLabel('Onderwerp').fill(title)
		await page.getByRole('button', { name: 'Aanmaken' }).click()

		// Select the newly created row so its detail card (with the file blocks) renders.
		await page.getByText(title, { exact: false }).first().click()

		// Upload a file to the owned row — the file-download list only shows
		// files that actually exist, so the upload block seeds the fixture.
		const fileInput = page.locator('.portaliq-fileupload input[type="file"]')
		await fileInput.setInputFiles({
			name: 'e2e-besluit.txt',
			mimeType: 'text/plain',
			buffer: Buffer.from('e2e download fixture'),
		})

		// The upload block reports success, and the download list picks up the
		// new file (server-attached `_files`, refreshed by re-selecting the row).
		await expect(page.locator('.portaliq-fileupload-msg')).toContainText('toegevoegd')
		await page.getByText(title, { exact: false }).first().click()

		const downloadButton = page.locator('.portaliq-filelist button', { hasText: 'e2e-besluit.txt' })
		await expect(downloadButton).toBeVisible()

		const [download] = await Promise.all([
			page.waitForEvent('download'),
			downloadButton.click(),
		])

		expect(download.suggestedFilename()).toBe('e2e-besluit.txt')
		const downloadedPath = await download.path()
		expect(downloadedPath).toBeTruthy()
	})

	test('a foreign or absent file 404s identically — no existence oracle', async ({ page, request }) => {
		await loginAsSupplier(request, page, `e2e-download-404-${Date.now()}`)

		await page.goto(PORTAL_PATH)
		await page.waitForLoadState('domcontentloaded')

		const title = `E2E 404 ${Date.now()}`
		await page.getByLabel('Onderwerp').fill(title)
		await page.getByRole('button', { name: 'Aanmaken' }).click()
		await page.waitForTimeout(500)

		const token = await page.evaluate(() => window.localStorage.getItem('portaliq_token'))

		// A non-existent fileId on a row this subject does NOT necessarily even
		// own yet (no upload happened) still 404s — never a 401/500, and never
		// a body distinguishing "no file" from "not yours".
		const nonExistent = await request.get(
			`${API_BASE}/collections/portaliq/exampleDocument/nonexistent-object-id/files/999999?collection=exampleCollection`,
			{ headers: { Authorization: `Bearer ${token}` } }
		)
		expect(nonExistent.status()).toBe(404)
		const nonExistentBody = await nonExistent.json()
		expect(nonExistentBody).toEqual({ error: 'not_found' })

		// A collection that has NOT opted into filesDownload — the claim-scoped
		// demo collection never declares it — 404s with the IDENTICAL body.
		const notOptedIn = await request.get(
			`${API_BASE}/collections/portaliq/exampleDocument/nonexistent-object-id/files/999999?collection=exampleClaimScoped`,
			{ headers: { Authorization: `Bearer ${token}` } }
		)
		expect(notOptedIn.status()).toBe(404)
		expect(await notOptedIn.json()).toEqual(nonExistentBody)
	})
})
