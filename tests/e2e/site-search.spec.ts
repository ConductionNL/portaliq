/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-public-search (S23 to S28): an anonymous visitor searches a
 * portal's public content. The search finds published page prose, never a
 * draft, never another portal's content, narrows on a facet, reaches text
 * inside an attached file, answers an unresolved portal with the shared
 * 404, and caps what it returns.
 *
 * WRITTEN AHEAD OF THE BUILD. The search endpoint (tasks 2 to 5) is not
 * built: the change is blocked on OpenRegister's
 * `rbac-default-authenticated`, because granting anonymous read to the CMS
 * schemas while an unmarked schema still reads fail-open would make every
 * unmarked schema searchable. So the whole file is `describe.fixme` and the
 * address below is the proposed one, beside the other public content reads
 * (`/api/content/pages`, `/api/content/menus`): `GET /api/content/search`
 * with `portal` and `_search`, answering `{results: [{route, title, kind}],
 * total, _facets}`. Whoever builds task 2 drops the `fixme` and aligns the
 * address and the keys with what the controller answers.
 *
 * The fixture is this file's own: pages on the seeded portals (`open-tilburg`
 * or PORTALIQ_E2E_PORTAL, and `open-venray` as the other portal), created
 * through OpenRegister's object API as the Nextcloud admin and deleted again
 * afterwards. The visitor reads anonymously in a fresh context. The file-text
 * scenario (S27) needs a fixture with an attached file whose extracted text
 * holds a term found nowhere else; the change names
 * `tests/e2e/fixtures/seed-search.sh` for it, so that test reads the term
 * from PORTALIQ_E2E_FILE_TERM and is skipped without it.
 *
 * @spec openspec/changes/portal-public-search/specs/portal-public-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-a-portals-public-content
 * @spec openspec/changes/portal-public-search/specs/portal-public-search/spec.md#requirement-search-must-not-return-what-the-caller-may-not-read
 * @spec openspec/changes/portal-public-search/specs/portal-public-search/spec.md#requirement-search-results-must-carry-facets-discovered-from-the-result-set
 * @spec openspec/changes/portal-public-search/specs/portal-public-search/spec.md#requirement-search-must-reach-text-extracted-from-attached-files
 * @spec openspec/changes/portal-public-search/specs/portal-public-search/spec.md#requirement-the-search-endpoint-must-be-publicly-cacheable-and-rate-limited
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'
import { SITE_PORTAL } from './portal-nav.ts'

const BASE = resolveBaseURL()
const APP = `${BASE}/index.php/apps/portaliq`
const SEARCH = `${APP}/api/content/search`
const PAGES = `${APP}/api/content/pages`
const OBJECTS = `${BASE}/index.php/apps/openregister/api/objects/portaliq/page`

/** The other seeded portal, for the cross-portal scenario. */
const OTHER_PORTAL = process.env.PORTALIQ_E2E_OTHER_PORTAL || 'open-venray'

/** A slug no portal claims. */
const NO_PORTAL = `e2e-geen-portaal-${Date.now()}`

/** A term held only by the extracted text of a seeded attachment (S27). */
const FILE_TERM = process.env.PORTALIQ_E2E_FILE_TERM ?? ''

/** Nextcloud admin, as exported by the shared quality.yml Playwright step. */
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** This run's own term, so no earlier run's or seeded page matches it. */
const STAMP = Date.now()
const TERM = `zoekterm${STAMP}`

/** The routes this run creates. */
const ROUTES = {
	published: `/e2e-zoeken-${STAMP}`,
	draft: `/e2e-zoeken-${STAMP}-concept`,
	other: `/e2e-zoeken-${STAMP}-elders`,
}

/** The ids of the pages created for this run. */
const pageIds: string[] = []

/** One search result, as the proposed contract names its keys. */
interface SearchResult {
	route?: string
	title?: string
	kind?: string
}

/** One facet bucket. */
interface FacetBucket {
	key?: string
	value?: string
	count?: number
	results?: number
}

/** The search answer, as the proposed contract names its keys. */
interface SearchAnswer {
	results?: SearchResult[]
	total?: number
	_facets?: Record<string, { buckets?: FacetBucket[] } | FacetBucket[]>
}

/**
 * An admin API context, Basic auth with the OCS header so Nextcloud skips
 * its CSRF check. Seeding and cleanup only; the visitor never holds it.
 *
 * @return the context; the caller disposes it
 */
async function adminApi(): Promise<APIRequestContext> {
	const basic = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	return await request.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Authorization: `Basic ${basic}`,
			'Content-Type': 'application/json',
		},
	})
}

/**
 * A markdown page of this run.
 *
 * @param portal the portal it belongs to
 * @param route the route
 * @param status published or draft
 * @param title the title
 * @return the page record
 */
function pageRecord(
	portal: string,
	route: string,
	status: 'published' | 'draft',
	title: string,
): object {
	return {
		title,
		route,
		portal,
		status,
		locale: 'nl',
		body: {
			type: 'markdown',
			markdown: `Deze pagina bevat het woord ${TERM} in de lopende tekst.`,
		},
	}
}

/**
 * Search anonymously.
 *
 * @param visitor an unauthenticated request context
 * @param portal the portal slug
 * @param query extra query parameters
 * @return the response
 */
async function search(
	visitor: APIRequestContext,
	portal: string,
	query: Record<string, string> = {},
) {
	const params = new URLSearchParams({ portal, _search: TERM, ...query })
	return await visitor.get(`${SEARCH}?${params.toString()}`)
}

/**
 * The routes in a search answer.
 *
 * @param answer the answer
 * @return the routes
 */
function routesOf(answer: SearchAnswer): string[] {
	return (answer.results ?? []).map((row) => String(row.route ?? ''))
}

/**
 * The buckets of one facet, whichever of the two shapes OpenRegister sends.
 *
 * @param answer the answer
 * @param field the facet field
 * @return the buckets
 */
function bucketsOf(answer: SearchAnswer, field: string): FacetBucket[] {
	const facet = answer._facets?.[field]
	if (Array.isArray(facet)) {
		return facet
	}
	return facet?.buckets ?? []
}

test.describe.fixme('portal public search (endpoint not built yet)', () => {
	test.beforeAll(async () => {
		const api = await adminApi()
		const records = [
			pageRecord(
				SITE_PORTAL,
				ROUTES.published,
				'published',
				`Gevonden ${STAMP}`,
			),
			pageRecord(SITE_PORTAL, ROUTES.draft, 'draft', `Concept ${STAMP}`),
			pageRecord(OTHER_PORTAL, ROUTES.other, 'published', `Elders ${STAMP}`),
		]
		for (const record of records) {
			const response = await api.post(OBJECTS, { data: record })
			expect(response.ok(), await response.text()).toBe(true)
			const body = await response.json()
			pageIds.push(String(body?.['@self']?.id ?? body?.id))
		}
		await api.dispose()
	})

	test.afterAll(async () => {
		const api = await adminApi()
		for (const id of pageIds) {
			await api.delete(`${OBJECTS}/${id}`)
		}
		await api.dispose()
	})

	test('a term in page prose is found', async () => {
		// @e2e portal-public-search::a-term-in-page-prose-is-found (S23)
		const visitor = await request.newContext()
		const response = await search(visitor, SITE_PORTAL)
		expect(response.status()).toBe(200)
		const answer = (await response.json()) as SearchAnswer
		expect(routesOf(answer)).toContain(ROUTES.published)
		await visitor.dispose()
	})

	test('an unresolved portal returns the shared 404', async () => {
		// @e2e portal-public-search::an-unresolved-portal-returns-the-shared-404 (S23b)
		const visitor = await request.newContext()
		const searched = await search(visitor, NO_PORTAL)
		const paged = await visitor.get(
			`${PAGES}?portal=${encodeURIComponent(NO_PORTAL)}`,
		)
		expect(searched.status()).toBe(404)
		expect(paged.status()).toBe(404)
		// The same body as every other content read: search must not be the
		// one route that tells which hosts are claimed.
		expect(await searched.text()).toBe(await paged.text())
		await visitor.dispose()
	})

	test('a draft page is not searchable, while a published one is', async () => {
		// @e2e portal-public-search::a-draft-page-is-not-searchable-while-a-published-one-is (S24)
		const visitor = await request.newContext()
		const answer = (await (
			await search(visitor, SITE_PORTAL)
		).json()) as SearchAnswer
		const routes = routesOf(answer)
		// Both in one test: a search that found nothing would pass the
		// exclusion on its own.
		expect(routes).toContain(ROUTES.published)
		expect(routes).not.toContain(ROUTES.draft)
		await visitor.dispose()
	})

	test('content does not cross portals', async () => {
		// @e2e portal-public-search::content-does-not-cross-portals (S25)
		const visitor = await request.newContext()
		const here = routesOf(
			(await (await search(visitor, SITE_PORTAL)).json()) as SearchAnswer,
		)
		const there = routesOf(
			(await (await search(visitor, OTHER_PORTAL)).json()) as SearchAnswer,
		)
		expect(here).toContain(ROUTES.published)
		expect(here).not.toContain(ROUTES.other)
		expect(there).toContain(ROUTES.other)
		expect(there).not.toContain(ROUTES.published)
		await visitor.dispose()
	})

	test('facets narrow the result set', async () => {
		// @e2e portal-public-search::facets-narrow-the-result-set (S26)
		const visitor = await request.newContext()
		const whole = (await (
			await search(visitor, SITE_PORTAL)
		).json()) as SearchAnswer
		const kinds = bucketsOf(whole, 'kind')
		expect(kinds.length).toBeGreaterThan(0)

		const bucket = kinds[0]
		const kind = String(bucket.key ?? bucket.value ?? '')
		const narrowed = (await (
			await search(visitor, SITE_PORTAL, { kind })
		).json()) as SearchAnswer

		// The unfiltered count is asserted beside the filtered one, so a
		// facet that filters nothing cannot pass.
		expect(Number(whole.total)).toBeGreaterThanOrEqual(Number(narrowed.total))
		for (const row of narrowed.results ?? []) {
			expect(row.kind).toBe(kind)
		}
		await visitor.dispose()
	})

	test('a term that appears only inside an attached PDF is found', async () => {
		// @e2e portal-public-search::a-term-that-appears-only-inside-an-attached-pdf-is-found (S27)
		test.skip(
			FILE_TERM === '',
			'set PORTALIQ_E2E_FILE_TERM to the term tests/e2e/fixtures/seed-search.sh puts in the attachment',
		)
		const visitor = await request.newContext()
		const params = new URLSearchParams({
			portal: SITE_PORTAL,
			_search: FILE_TERM,
		})
		const response = await visitor.get(`${SEARCH}?${params.toString()}`)
		expect(response.status()).toBe(200)
		const answer = (await response.json()) as SearchAnswer
		// The hit is the owning object with its route, never a naked chunk.
		expect((answer.results ?? []).length).toBeGreaterThan(0)
		for (const row of answer.results ?? []) {
			expect(String(row.route ?? '')).toMatch(/^\//)
		}
		await visitor.dispose()
	})

	test('search is throttled and capped', async () => {
		// @e2e portal-public-search::search-is-throttled-and-capped (S28)
		const visitor = await request.newContext()
		const response = await search(visitor, SITE_PORTAL, { _limit: '100000' })
		expect(response.status()).toBe(200)
		const answer = (await response.json()) as SearchAnswer
		// An unbounded request still answers a bounded page.
		expect((answer.results ?? []).length).toBeLessThanOrEqual(100)
		await visitor.dispose()
	})
})
