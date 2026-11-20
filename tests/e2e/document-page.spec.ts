/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * publication-detail-page-complete 2.1, 3.1 and 3.2 (REQ-PDC-002,
 * REQ-PDC-003, REQ-PDC-004): a public document has a page of its own that
 * links back to its publication; a document of a draft is not found; the
 * publication page offers the whole publication as one archive and each
 * document's metadata beside it.
 *
 * WHAT IS REAL. The site, its document route (`/document/<publicationId>/
 * <documentId>`, src/site/lib/documentRoute.js), `DocumentPage.vue`, the
 * not-found page and the `/publicatie` page with its publication detail block,
 * which tests/e2e/fixtures/seed-cms.sh seeds on the served portal.
 *
 * WHAT IS STUBBED, AND WHY. opencatalogi is not on the test instance, so its
 * public reads are answered in the browser with `page.route`, in the shape of
 * its public read (source: opencatalogi docs/Technical/
 * publication-detail-for-the-portal.md, the fixture of
 * tests/document-page.spec.mjs): the publication by id
 * (`/api/federation/publications/<id>`), its attachment list (`.../attachments`)
 * and the theme names. A draft is what opencatalogi's anonymous read answers
 * for it: 404. Nothing of portaliq is stubbed.
 *
 * NOT COVERED, AND WHY: that the archive link answers 200 with
 * `application/zip` (task 3.1) is opencatalogi's own route, which this
 * instance does not have; here the link's address and count are checked.
 * The viewer of REQ-PDC-001 and the reaction period of REQ-PDC-005 are pinned
 * by tests/publication-documents.spec.mjs and tests/publication-detail.spec.mjs.
 *
 * The SPA renders the not-found page itself, so "status 404" is the page's own
 * `data-portaliq-status="404"` and its error code, not the HTML shell's status.
 *
 * Every context is an anonymous visitor with an empty storage state. The
 * target comes from BASE_URL (see tests/e2e/base-url.ts); the portal from
 * PORTALIQ_E2E_PORTAL (see tests/e2e/portal-nav.ts).
 *
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { siteAddress } from './portal-nav.ts'

test.use({ storageState: { cookies: [], origins: [] } })

const FEDERATION = '**/apps/opencatalogi/api/federation/publications**'
const THEMES = '**/apps/opencatalogi/api/themes**'
const PUBLIC_ID = '5b0d1e2f-0000-4000-a000-00000000pdc1'
const DRAFT_ID = '5b0d1e2f-0000-4000-a000-00000000pdc2'
const PUBLICATION_TITLE = 'Besluit Stationsweg'
const DOCUMENTS_BASE = `/index.php/apps/opencatalogi/api/woo/${PUBLIC_ID}/documents`

/** The public read of the publication. */
const PUBLICATION = {
	id: PUBLIC_ID,
	'@self': { id: PUBLIC_ID, name: PUBLICATION_TITLE, schema: 'publication' },
	title: PUBLICATION_TITLE,
	summary: 'Het besluit over de herinrichting van de Stationsweg.',
	published: '2026-11-02T09:00:00+00:00',
}

/**
 * One attachment row of the publication's attachment list.
 *
 * @param documentId The stable document id.
 * @param title The file's name.
 * @param diwoo Whether the read carries a DiWoo record for it.
 * @return The row.
 */
function attachment(documentId: string, title: string, diwoo: boolean): Record<string, unknown> {
	return {
		documentId,
		title,
		size: 120000,
		published: '2026-11-02T09:00:00+00:00',
		format: 'pdf',
		documentsoort: 'besluit',
		creationDate: '2026-10-30',
		downloadUrl: `/index.php/s/Pdc${documentId}/download`,
		metadataUrl: `${DOCUMENTS_BASE}/${documentId}`,
		...(diwoo ? { diwooUrl: `${DOCUMENTS_BASE}/${documentId}/diwoo` } : {}),
	}
}

/** Three documents; only the first carries a DiWoo record. */
const ATTACHMENTS = {
	results: [
		attachment('4711', 'besluit.pdf', true),
		attachment('4712', 'bijlage-tekening.pdf', false),
		attachment('4713', 'zienswijzenverslag.pdf', false),
	],
	total: 3,
}

/**
 * Answer opencatalogi's public reads in the browser: the public publication
 * and its attachments; the draft as the anonymous read answers it, 404.
 *
 * @param page The page, not yet navigated.
 * @return The paths the page asked for, in order.
 */
async function routeTheCatalogue(page: Page): Promise<string[]> {
	const asked: string[] = []
	await page.route(FEDERATION, async (route) => {
		const path = new URL(route.request().url()).pathname
		asked.push(path)
		if (path.endsWith(`/${PUBLIC_ID}/attachments`)) {
			await route.fulfill({ json: ATTACHMENTS })
			return
		}
		if (path.endsWith(`/${PUBLIC_ID}`)) {
			await route.fulfill({ json: PUBLICATION })
			return
		}
		if (path.endsWith(`/${DRAFT_ID}/attachments`)) {
			// Never reached when the page reads as it should; answered so a
			// page that did read it would show the document and fail below.
			await route.fulfill({ json: { results: [attachment('9001', 'concept-besluit.pdf', false)], total: 1 } })
			return
		}
		await route.fulfill({ status: 404, json: { error: 'not_found' } })
	})
	await page.route(THEMES, (route) => route.fulfill({ json: { results: [], total: 0 } }))
	return asked
}

test.describe('publication-detail-page-complete: the document page', () => {
	// @e2e portal-federated-search::from-a-document-back-to-its-publication
	test('REQ-PDC-002: a public document\'s page shows its metadata and links back to its publication', async ({ page }) => {
		await routeTheCatalogue(page)
		await page.goto(siteAddress(`/document/${PUBLIC_ID}/4711`))

		await expect(page.getByTestId('document-page-title')).toHaveText('besluit.pdf', { timeout: 30_000 })
		const facts = page.getByTestId('document-page-facts')
		await expect(facts).toContainText('PDF')
		await expect(facts).toContainText('120 kB')
		await expect(facts).toContainText(/2 november 2026|November 2, 2026|2 November 2026/)
		await expect(facts).toContainText('besluit')
		await expect(page.getByTestId('document-page-download')).toHaveAttribute('href', '/index.php/s/Pdc4711/download')
		await expect(page.getByTestId('document-page-metadata')).toHaveAttribute('href', `${DOCUMENTS_BASE}/4711`)
		await expect(page.getByTestId('document-page-diwoo')).toHaveAttribute('href', `${DOCUMENTS_BASE}/4711/diwoo`)

		const back = page.getByTestId('document-page-publication')
		await expect(back).toHaveText(PUBLICATION_TITLE)
		await back.click()
		await expect(page.getByTestId('publication-detail-title')).toHaveText(PUBLICATION_TITLE, { timeout: 30_000 })
		expect(decodeURIComponent(page.url()), 'the link leads to the publication\'s own page').toContain(`/publicatie/${PUBLIC_ID}`)
	})

	// @e2e portal-federated-search::a-document-of-a-draft-is-not-found
	test('REQ-PDC-002: a document of a draft publication renders the not-found page and nothing of the document', async ({ page }) => {
		const asked = await routeTheCatalogue(page)
		await page.goto(siteAddress(`/document/${DRAFT_ID}/9001`))

		const notFound = page.locator('[data-portaliq-status="404"]')
		await expect(notFound).toBeVisible({ timeout: 30_000 })
		await expect(page.getByTestId('not-found-code')).toContainText('404')
		await expect(page.getByTestId('document-page-title')).toHaveCount(0)
		await expect(page.locator('body')).not.toContainText('concept-besluit.pdf')
		expect(asked.some((path) => path.endsWith(`/${DRAFT_ID}`)), 'the publication was read').toBe(true)
		expect(asked.some((path) => path.endsWith(`/${DRAFT_ID}/attachments`)), 'a draft\'s attachments are never read').toBe(false)
	})

	// @e2e portal-federated-search::download-alles
	test('REQ-PDC-003: the publication page offers every document as one archive, with the count', async ({ page }) => {
		await routeTheCatalogue(page)
		await page.goto(siteAddress(`/publicatie/${PUBLIC_ID}`))

		const all = page.getByTestId('publication-download-all')
		await expect(all).toHaveText(/Download alles \(3 documenten\)|Download all \(3 documents\)/, { timeout: 30_000 })
		// The seeded page names no catalogue, so the archive is the ZIP route
		// beside the read the page used (`downloadAllLink()`); with a
		// `catalogSlug` it is `/index.php/apps/opencatalogi/api/<slug>/<id>/download`.
		await expect(all).toHaveAttribute('href', new RegExp(`^/index\\.php/apps/opencatalogi/api/.+/${PUBLIC_ID}/download$`))
	})

	// @e2e portal-federated-search::json-beside-each-file
	test('REQ-PDC-004: each document has its metadata as JSON, and only the one the read carries it for has DiWoo XML', async ({ page }) => {
		await routeTheCatalogue(page)
		await page.goto(siteAddress(`/publicatie/${PUBLIC_ID}`))

		const rows = page.getByTestId('publication-document')
		await expect(rows).toHaveCount(3, { timeout: 30_000 })
		for (const [index, documentId] of ['4711', '4712', '4713'].entries()) {
			await expect(rows.nth(index).getByTestId('publication-document-metadata')).toHaveAttribute('href', `${DOCUMENTS_BASE}/${documentId}`)
		}
		await expect(page.getByTestId('publication-document-diwoo')).toHaveCount(1)
		await expect(rows.nth(0).getByTestId('publication-document-diwoo')).toHaveAttribute('href', `${DOCUMENTS_BASE}/4711/diwoo`)

		// The publication page links each document to its own page.
		await expect(rows.nth(1).getByTestId('publication-document-page')).toHaveAttribute('href', new RegExp(`document(/|%2F)${PUBLIC_ID}(/|%2F)4712`))
	})
})
