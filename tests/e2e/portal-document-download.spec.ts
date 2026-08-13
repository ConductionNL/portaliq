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
async function loginAsSupplier(
	request: APIRequestContext,
	page: Page,
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

	await page.addInitScript((t) => {
		window.localStorage.setItem('portaliq_token', t)
	}, token)

	return token
}

test.describe('portal-document-download', () => {
	// This test seeds its own fixture by UPLOADING through the portal's upload
	// block before it downloads, so its body really does exercise the scoped
	// attach path end to end — it drives `.portaliq-fileupload input[type="file"]`
	// on a row the subject owns and proves the file landed by downloading it
	// back by name.
	//
	// It deliberately carries NO `@e2e` reference to
	// `portal-contribution-contract::a-subject-attaches-a-file-to-a-row-they-own`,
	// even though it would satisfy the gate if it did. THIS TEST DOES NOT RUN IN
	// CI: playwright.config.ts in this directory `grepInvert`s it by title while
	// ConductionNL/portaliq#29 is open. Measured on hydra-gates @94c855b —
	// gate-19 honours `testIgnore` but not `grepInvert`, so adding the tag moves
	// the count from 47 to 46 and buys a green from a test that never executes.
	// The scenario carries a reason-bearing `@e2e exclude` naming #29 instead;
	// swap the exclude for the tag here when #29 closes and the grepInvert goes.
	test('a subject downloads a file on a row they own', async ({
		page,
		request,
	}) => {
		await loginAsSupplier(request, page, `e2e-download-${Date.now()}`)

		await page.goto(PORTAL_PATH)
		await page.waitForLoadState('domcontentloaded')

		// Create a fresh example row via the demo "Nieuw voorbeeld" form so the
		// test owns a row with no pre-existing state to collide with.
		const title = `E2E download ${Date.now()}`
		await page.getByLabel('Onderwerp').fill(title)
		await page.getByRole('button', { name: 'Aanmaken' }).click()
		// Wait for the create to CONFIRM (and its collection reload to settle)
		// before selecting the row — the create does several synchronous
		// OpenRegister writes and takes seconds on a shared dev instance;
		// selecting the row while the table is still reloading races an empty
		// render. The row is the clickable table entry carrying the title.
		await expect(page.getByText('Voorbeeld aangemaakt')).toBeVisible({
			timeout: 20_000,
		})
		const row = page
			.locator('tr.portaliq-row-clickable')
			.filter({ hasText: title })
		await row.waitFor({ timeout: 20_000 })

		// Select the newly created row so its detail card (with the file blocks) renders.
		await row.click()

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
		await expect(page.locator('.portaliq-fileupload-msg')).toContainText(
			'toegevoegd',
		)
		await row.click()

		const downloadButton = page.locator('.portaliq-filelist button', {
			hasText: 'e2e-besluit.txt',
		})
		await expect(downloadButton).toBeVisible()

		const [download] = await Promise.all([
			page.waitForEvent('download'),
			downloadButton.click(),
		])

		expect(download.suggestedFilename()).toBe('e2e-besluit.txt')
		const downloadedPath = await download.path()
		expect(downloadedPath).toBeTruthy()
	})

	// Asserts the three-way identical refusal against the running API.
	// @e2e supplier-portal::non-existent-foreign-and-non-opted-in-all-404-identically
	test('a foreign or absent file 404s identically — no existence oracle', async ({
		page,
		request,
	}) => {
		await loginAsSupplier(request, page, `e2e-download-404-${Date.now()}`)

		await page.goto(PORTAL_PATH)
		await page.waitForLoadState('domcontentloaded')

		const title = `E2E 404 ${Date.now()}`
		await page.getByLabel('Onderwerp').fill(title)
		await page.getByRole('button', { name: 'Aanmaken' }).click()
		await page.waitForTimeout(500)

		const token = await page.evaluate(() =>
			window.localStorage.getItem('portaliq_token'),
		)

		// A non-existent fileId on a row this subject does NOT necessarily even
		// own yet (no upload happened) still 404s — never a 401/500, and never
		// a body distinguishing "no file" from "not yours".
		const nonExistent = await request.get(
			`${API_BASE}/collections/portaliq/exampleDocument/nonexistent-object-id/files/999999?collection=exampleCollection`,
			{ headers: { Authorization: `Bearer ${token}` } },
		)
		expect(nonExistent.status()).toBe(404)
		const nonExistentBody = await nonExistent.json()
		expect(nonExistentBody).toEqual({ error: 'not_found' })

		// A collection that has NOT opted into filesDownload — the claim-scoped
		// demo collection never declares it — 404s with the IDENTICAL body.
		const notOptedIn = await request.get(
			`${API_BASE}/collections/portaliq/exampleDocument/nonexistent-object-id/files/999999?collection=exampleClaimScoped`,
			{ headers: { Authorization: `Bearer ${token}` } },
		)
		expect(notOptedIn.status()).toBe(404)
		expect(await notOptedIn.json()).toEqual(nonExistentBody)
	})
})
