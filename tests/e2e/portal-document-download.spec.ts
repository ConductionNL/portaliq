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

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import {
	openPortaliqDemoPage,
	PORTAL_API,
	readSiteSession,
	seedSiteSession,
	siteAddress,
} from './portal-nav.ts'

const API_BASE = PORTAL_API

/**
 * Mint a low-trust supplier dev session and seed it into the site's session
 * token slot BEFORE the app boots, so the site loads already signed in
 * (mirrors how a real bearer, once minted, is stored).
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

	await seedSiteSession(page, token)

	return token
}

test.describe('portal-document-download', () => {
	// This test seeds its own fixture by UPLOADING through the portal's upload
	// block before it downloads, so its body exercises BOTH halves of the file
	// path end to end on a row the subject owns: it drives the detail card's
	// file input (`detail-card-upload`), proves the attach by the block's
	// confirmation and the file appearing in the row's download list, then
	// downloads it back by name and checks the bytes are the ones uploaded.
	//
	// It used to be grep-inverted out of every run while ConductionNL/portaliq#29
	// (a portal subject could not attach on a fresh instance) was open, and so
	// carried no anchors — a test that never executes is not coverage. #843
	// removed that filter once OpenRegister #4116 fixed the first upload on a
	// fresh instance; it runs in CI again, so it now anchors the scenarios it
	// asserts. The Content-Disposition / filename-sanitisation detail of the
	// download scenario is not visible here (the SPA fetches with the bearer and
	// saves through a Blob URL under the listed name); that half stays pinned by
	// ContributionControllerTest::testDownloadStreamsOwnedFileAndInvokesAuditHookOnSuccess.
	// @e2e supplier-portal::a-subject-downloads-a-file-on-a-row-they-own
	// @e2e portal-contribution-contract::a-subject-attaches-a-file-to-a-row-they-own
	test('a subject downloads a file on a row they own', async ({
		page,
		request,
	}) => {
		await loginAsSupplier(request, page, `e2e-download-${Date.now()}`)

		await page.goto(siteAddress())
		await page.waitForLoadState('domcontentloaded')
		await openPortaliqDemoPage(page)

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
			.getByTestId('collection-table-row')
			.filter({ hasText: title })
		await row.waitFor({ timeout: 20_000 })

		// Select the newly created row so its detail card (with the file blocks) renders.
		await row.click()

		// Upload a file to the owned row — the file-download list only shows
		// files that actually exist, so the upload block seeds the fixture.
		const fileInput = page
			.getByTestId('detail-card-upload')
			.locator('input[type="file"]')
		await fileInput.setInputFiles({
			name: 'e2e-besluit.txt',
			mimeType: 'text/plain',
			buffer: Buffer.from('e2e download fixture'),
		})

		// The upload block reports success, and the download list picks up the
		// new file (server-attached `_files`; the detail card re-reads the row
		// after a successful upload).
		await expect(page.getByTestId('detail-card-upload')).toContainText(
			/toegevoegd|File added/,
		)

		const downloadButton = page
			.getByTestId('detail-card-download')
			.filter({ hasText: 'e2e-besluit.txt' })
		await expect(downloadButton).toBeVisible()

		const [download] = await Promise.all([
			page.waitForEvent('download'),
			downloadButton.click(),
		])

		expect(download.suggestedFilename()).toBe('e2e-besluit.txt')
		const downloadedPath = await download.path()
		expect(downloadedPath).toBeTruthy()
		// The bytes served for the owned row are the file that was attached.
		expect(await readFile(downloadedPath)).toEqual(
			Buffer.from('e2e download fixture'),
		)
	})

	// Asserts the three-way identical refusal against the running API.
	// @e2e supplier-portal::non-existent-foreign-and-non-opted-in-all-404-identically
	test('a foreign or absent file 404s identically — no existence oracle', async ({
		page,
		request,
	}) => {
		await loginAsSupplier(request, page, `e2e-download-404-${Date.now()}`)

		await page.goto(siteAddress())
		await page.waitForLoadState('domcontentloaded')
		await openPortaliqDemoPage(page)

		const title = `E2E 404 ${Date.now()}`
		await page.getByLabel('Onderwerp').fill(title)
		await page.getByRole('button', { name: 'Aanmaken' }).click()
		await page.waitForTimeout(500)

		const token = await readSiteSession(page)

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
