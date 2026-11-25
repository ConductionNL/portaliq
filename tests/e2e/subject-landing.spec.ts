/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * home-and-theme-landing-pages, task 2.1: a subject's public landing page,
 * `/onderwerp/{slug}`, shows the subject's image, title and description and
 * then its own publications only, through the search block locked to it.
 *
 * WHAT IS REAL. The seeded portal (`open-tilburg`, tests/e2e/fixtures/seed-cms.sh),
 * the `/onderwerp` page placing `nlSubjectLanding` (created through
 * OpenRegister's object API when the portal has none, and removed again),
 * the site SPA's fallback from `/onderwerp/<slug>` to that page, the widget,
 * the subject read it makes, the search block it mounts with
 * `lockedFilters: {themes: <subject id>}`, the locked chip, the facet list
 * without the locked field and the address without it.
 *
 * WHAT IS STUBBED, AND WHY. opencatalogi is a sibling app and is not on the
 * test instance (the seed installs no catalogue), so its two public reads are
 * answered in the browser with `page.route`:
 *
 * - `/api/themes/{slug}`: the subject, in REQ-SUB-003's keys (the same keys
 *   tests/fixtures/subjects-contract.json pins); an unknown slug answers 404.
 * - `/api/federation/publications`: two publications under the subject and a
 *   third under another one; the stub filters on `themes`, as the catalogue
 *   does, so what this file proves is that the page ASKED for the subject's
 *   publications on every request and drew only what came back.
 *
 * WHAT IS NOT BUILT, AND SO NOT ASSERTED. REQ-HTL-002 asks an unknown subject
 * to answer the site's not-found page with status 404. What is built is a
 * not-found message inside the widget; the HTTP status is the `/onderwerp`
 * CMS page's (tasks.md 2.1). The second test asserts the message only.
 *
 * PATH ADDRESSING. The page is opened as `/site/onderwerp/<slug>`. Since
 * #2620 WidgetGrid.propsFor() hands `nlSubjectLanding` the route segment, so
 * `/site?route=/onderwerp/<slug>` reaches the same subject; path addressing is
 * kept because it is the address a visitor follows from a subject card.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */

import type { APIRequestContext, Page, Request, Route } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const PAGES = `${BASE}/index.php/apps/openregister/api/objects/portaliq/page`

const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** The seeded portal (fixtures/seed-cms.sh). */
const PORTAL = process.env.PORTALIQ_E2E_PORTAL || 'open-tilburg'

/** The page subject addresses hang under. */
const SUBJECT_ROUTE = '/onderwerp'

/** opencatalogi's two public reads, as the browser asks them. */
const FEDERATION = '**/apps/opencatalogi/api/federation/publications**'
const THEMES = '**/apps/opencatalogi/api/themes/**'

/** One row of an answer. */
type Row = Record<string, unknown>

/** The scenario's subject, in REQ-SUB-003's keys. */
const PARKEREN = {
	id: 'th-parkeren',
	slug: 'parkeren',
	title: 'Parkeren',
	summary: 'Vergunningen en tarieven',
	description: 'Alles over parkeren in de gemeente: vergunningen, tarieven en zones.',
	image: {
		url: '/index.php/apps/opencatalogi/api/e2e-files/parkeren.png',
		alt: 'Een parkeerplaats',
	},
	publicationsUrl: '/index.php/apps/opencatalogi/api/themes/th-parkeren/publications',
	publicationCount: 2,
}

/** Two public publications under the subject, and one under another. */
const PUBLICATIONS: Row[] = [
	{
		id: 'e2e-htl-1',
		name: 'Parkeervergunning aanvragen',
		themes: ['th-parkeren'],
		publicationDate: '2026-09-01T09:00:00+00:00',
		'@self': { id: 'e2e-htl-1', schema: 'publication' },
	},
	{
		id: 'e2e-htl-2',
		name: 'Tarieven betaald parkeren 2026',
		themes: ['th-parkeren'],
		publicationDate: '2026-08-01T09:00:00+00:00',
		'@self': { id: 'e2e-htl-2', schema: 'publication' },
	},
	{
		id: 'e2e-htl-3',
		name: 'Afvalkalender 2026',
		themes: ['th-afval'],
		publicationDate: '2026-07-01T09:00:00+00:00',
		'@self': { id: 'e2e-htl-3', schema: 'publication' },
	},
]

/** A one-pixel PNG for the subject's image. */
const PIXEL = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
	'base64',
)

/** The page this file created, if any, removed again in afterAll. */
let createdId = ''

/**
 * An admin API context with its own cookie jar.
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
 * The id of an object row.
 *
 * @param row the row
 * @return its id
 */
function idOf(row: Row): string {
	const self = (row['@self'] ?? {}) as Row
	return String(self.id ?? row.id ?? '')
}

/**
 * Make sure the portal has a `/onderwerp` page placing the subject page. An
 * existing one is used and left alone; otherwise one is created.
 *
 * @param api the admin context
 */
async function ensureSubjectPage(api: APIRequestContext): Promise<void> {
	const listed = await api.get(`${PAGES}?_limit=500`)
	expect(listed.ok(), await listed.text()).toBe(true)
	const body = await listed.json()
	const rows: Row[] = Array.isArray(body) ? body : (body.results ?? [])
	const existing = rows.find((row) => row.route === SUBJECT_ROUTE && row.portal === PORTAL)
	if (existing) {
		expect(
			JSON.stringify(existing.body ?? {}).includes('nlSubjectLanding'),
			`${PORTAL} already has a ${SUBJECT_ROUTE} page, and it does not place nlSubjectLanding`,
		).toBe(true)
		return
	}

	const created = await api.post(PAGES, {
		data: {
			title: 'Onderwerp',
			route: SUBJECT_ROUTE,
			portal: PORTAL,
			status: 'published',
			locale: 'nl',
			body: {
				type: 'grid',
				widgets: [
					{
						id: 'e2e-subject',
						widgetKey: 'nlSubjectLanding',
						slot: 'body',
						gridX: 0,
						gridY: 0,
						gridWidth: 12,
						gridHeight: 10,
						props: { backLabel: 'Alle onderwerpen', backHref: '/' },
					},
				],
			},
		},
	})
	expect(created.ok(), await created.text()).toBe(true)
	createdId = idOf(await created.json())
}

/**
 * Stub opencatalogi's reads for this page and record the search block's
 * requests (the typing suggestions ask the same endpoint with `_fields`).
 *
 * @param page the page
 * @return the subject reads and the search requests
 */
async function stubCatalogue(
	page: Page,
): Promise<{ subjects: Request[]; searches: Request[] }> {
	const subjects: Request[] = []
	const searches: Request[] = []

	await page.route(THEMES, async (route: Route) => {
		subjects.push(route.request())
		const slug = new URL(route.request().url()).pathname.split('/').pop()
		if (slug === PARKEREN.slug) {
			await route.fulfill({ json: PARKEREN })
			return
		}
		await route.fulfill({ status: 404, json: { message: 'Not found' } })
	})
	await page.route(FEDERATION, async (route: Route) => {
		const url = new URL(route.request().url())
		if (!url.searchParams.has('_fields')) {
			searches.push(route.request())
		}
		const themes = url.searchParams.getAll('themes')
		const results = PUBLICATIONS.filter(
			(row) =>
				themes.length === 0
				|| (row.themes as string[]).some((theme) => themes.includes(theme)),
		)
		await route.fulfill({
			json: {
				results,
				total: results.length,
				page: 1,
				pages: 1,
				facets: {
					// The catalogue would count subjects too; the page must not offer them.
					themes: { buckets: [{ key: 'th-parkeren', results: 2 }, { key: 'th-afval', results: 1 }] },
				},
			},
		})
	})
	await page.route(`**${PARKEREN.image.url}`, (route: Route) =>
		route.fulfill({ status: 200, contentType: 'image/png', body: PIXEL }),
	)

	return { subjects, searches }
}

test.beforeAll(async () => {
	const api = await adminApi()
	await ensureSubjectPage(api)
	await api.dispose()
})

test.afterAll(async () => {
	if (createdId === '') {
		return
	}
	const api = await adminApi()
	await api.delete(`${PAGES}/${createdId}`)
	await api.dispose()
})

test.describe('a subject has a public landing page', () => {
	test.setTimeout(120_000)

	// REQ-HTL-002, scenario "The Parkeren page"; REQ-HTL-001 (the locked filter it relies on).
	test('REQ-HTL-002: /onderwerp/parkeren shows the image, "Parkeren", the description and only its own publications', async ({
		page,
	}) => {
		const { subjects, searches } = await stubCatalogue(page)
		await page.goto(`${SITE}${SUBJECT_ROUTE}/${PARKEREN.slug}?portal=${PORTAL}`)

		const landing = page.getByTestId('nl-subject-landing')
		await expect(landing).toBeVisible({ timeout: 60_000 })
		await expect(page.getByTestId('nl-subject-title')).toHaveText('Parkeren', {
			timeout: 30_000,
		})
		await expect(page.getByTestId('nl-subject-image')).toHaveAttribute(
			'alt',
			PARKEREN.image.alt,
		)
		await expect(page.getByTestId('nl-subject-image')).toHaveAttribute(
			'src',
			PARKEREN.image.url,
		)
		await expect(page.getByTestId('nl-subject-description')).toHaveText(
			PARKEREN.description,
		)

		// The subject was read by its slug, as an anonymous visitor.
		expect(new URL(subjects[0].url()).pathname).toMatch(/\/api\/themes\/parkeren$/)
		expect((await subjects[0].allHeaders()).cookie).toBeUndefined()

		// Its publications, and only those.
		const results = landing.getByTestId('federated-search-result')
		await expect(results).toHaveCount(2, { timeout: 30_000 })
		await expect(landing).toContainText('Parkeervergunning aanvragen')
		await expect(landing).toContainText('Tarieven betaald parkeren 2026')
		await expect(landing).not.toContainText('Afvalkalender 2026')

		// Locked to the subject on every request (REQ-HTL-001): shown as a
		// fixed chip, not offered as a facet, not written to the address.
		for (const sent of searches) {
			expect(new URL(sent.url()).searchParams.getAll('themes')).toEqual([PARKEREN.id])
			expect(new URL(sent.url()).searchParams.has('_facets[themes][type]')).toBe(false)
		}
		await expect(landing.getByTestId('federated-search-locked-chip')).toHaveText('Parkeren')
		await expect(landing.locator('[data-testid="federated-search-locked-chip"] button')).toHaveCount(0)
		await expect(landing.getByTestId('federated-search-facet-group-themes')).toHaveCount(0)
		expect(new URL(page.url()).searchParams.has('f.themes')).toBe(false)
	})

	// REQ-HTL-002, scenario "An unknown subject": the not-found message (the 404 status is not built).
	test('REQ-HTL-002: /onderwerp/bestaat-niet renders "Dit onderwerp bestaat niet" and no publications', async ({
		page,
	}) => {
		const { searches } = await stubCatalogue(page)
		await page.goto(`${SITE}${SUBJECT_ROUTE}/bestaat-niet?portal=${PORTAL}`)

		const notFound = page.getByTestId('nl-subject-not-found')
		await expect(notFound).toBeVisible({ timeout: 60_000 })
		await expect(notFound.getByRole('heading', { level: 1 })).toHaveText(
			/Dit onderwerp bestaat niet|This subject does not exist/,
		)
		await expect(page.getByTestId('nl-subject-image')).toHaveCount(0)
		await expect(page.getByTestId('federated-search')).toHaveCount(0)
		expect(searches).toHaveLength(0)
	})
})
