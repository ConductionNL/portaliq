/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * marketing-articles-from-pipelinq (task 4.2): portaliq owns reusable
 * marketing articles. One test per scenario of
 * specs/marketing-articles/spec.md that is not marked `@e2e exclude`; the one
 * excluded scenario (a client cannot claim the agent mark) is covered by
 * ArticleServiceTest.
 *
 * WHAT IS REAL. The article write path (ArticleController and ArticleService
 * under `/apps/portaliq/api/articles`), the declared lifecycle, the usages
 * endpoint with portaliq's own page usages, the Articles index, the new
 * article dialog, the article page and the public site rendering a page that
 * names an article are all portaliq's and run unstubbed. The API scenarios
 * call the endpoints as the Nextcloud admin (Basic auth); the screen
 * scenarios sign the admin in in a fresh browser context; the site scenario
 * reads signed out.
 *
 * WHAT IS STUBBED. pipelinq is not on the test instance, so its listener on
 * `ArticleUsagesRequestedEvent` never answers. The one scenario about a
 * pipelinq template stubs the browser's call to the usages endpoint with
 * page.route: it fetches the real answer and adds the template entry
 * pipelinq's listener would contribute, then asserts how the article page
 * shows it. Nothing else is stubbed.
 *
 * Articles and pages created here are deleted again in afterAll. The base URL
 * comes from tests/e2e/base-url.ts.
 *
 * @spec openspec/changes/archive/2026-11-24-marketing-articles-from-pipelinq/tasks.md#4.2
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'
import { SITE_PATH, SITE_PORTAL } from './portal-nav.ts'

const BASE = resolveBaseURL()
const ARTICLES = '/index.php/apps/portaliq/api/articles'
const OBJECTS = '/index.php/apps/openregister/api/objects/portaliq'

/** Nextcloud admin, as exported by the shared quality.yml Playwright step. */
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** The seeded portal's organisation (tests/e2e/fixtures/seed-cms.sh). */
const ORGANISATION = process.env.PORTALIQ_E2E_ORG || 'dev-org'

/** The three demo articles in lib/Settings/portaliq_register.json, with their seeded status. */
const DEMO_ARTICLES = [
	{ title: 'OpenRegister 3.0 is uit', status: /Published|Gepubliceerd/ },
	{ title: 'Gemeente Rotterdam koppelt haar zaaksysteem in zes weken', status: /Published|Gepubliceerd/ },
	{ title: 'Kom langs op de Common Ground meetup in november', status: /In review|Ter beoordeling/ },
]

/** Unique per run, so a dead earlier run cannot hold the slugs. */
const STAMP = Date.now()

/** Ids to delete afterwards, per schema. */
const created: Array<{ schema: string, id: string }> = []

/** The admin API context, opened once. */
let api: APIRequestContext

type Article = Record<string, any>

/**
 * The id an article answer carries, wherever OpenRegister put it.
 *
 * @param article the article
 * @return the id
 */
function idOf(article: Article): string {
	return String(article.uuid ?? article.id ?? article['@self']?.uuid ?? article['@self']?.id ?? '')
}

/**
 * Create an article through ArticleController and remember it for cleanup.
 *
 * @param data the article fields
 * @return the created article
 */
async function createArticle(data: Record<string, unknown>): Promise<Article> {
	const response = await api.post(ARTICLES, { data })
	expect(response.status(), `creating the article failed: ${await response.text()}`).toBe(201)
	const { article } = await response.json()
	created.push({ schema: 'article', id: idOf(article) })
	return article as Article
}

/**
 * Apply one lifecycle move through its route.
 *
 * @param id the article id
 * @param move `publish`, `archive`, or a transition name for the generic route
 * @return the response
 */
async function move(id: string, move: string) {
	if (move === 'publish' || move === 'archive') {
		return await api.post(`${ARTICLES}/${id}/${move}`)
	}
	return await api.post(`${ARTICLES}/${id}/transition`, { data: { transition: move } })
}

/**
 * Read one article back.
 *
 * @param id the article id or slug
 * @return the article
 */
async function readArticle(id: string): Promise<Article> {
	const response = await api.get(`${ARTICLES}/${id}`)
	expect(response.ok()).toBe(true)
	return (await response.json()).article as Article
}

/**
 * Sign the browser in to Nextcloud as the editor.
 *
 * @param page the page to sign in
 * @return nothing
 */
async function signInAsEditor(page: Page): Promise<void> {
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(ADMIN_USER)
	await page.locator('input[name="password"]').fill(ADMIN_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 60_000 })
	expect(/\/login(\?|$|\/)/.test(page.url()), `login failed — still on ${page.url()}`).toBe(false)
}

/**
 * The path prefix the app's router owns, read back rather than predicted.
 *
 * @param page the signed-in page
 * @return the base path, without a trailing slash
 */
async function spaBase(page: Page): Promise<string> {
	await page.goto(`${BASE}/index.php/apps/portaliq/`)
	await expect(page.locator('#content > *').first()).toBeVisible({ timeout: 60_000 })
	const settled = new URL(page.url()).pathname
	expect(settled).toContain('/apps/portaliq')
	return settled.replace(/\/+$/, '')
}

/**
 * Open the Articles page from the app's navigation (Website, then Articles).
 *
 * @param page the signed-in page
 * @return nothing
 */
async function openArticlesFromMenu(page: Page): Promise<void> {
	await spaBase(page)
	await page
		.locator('#app-navigation, nav')
		.getByRole('link', { name: /^\s*(Articles|Artikelen)\s*$/ })
		.first()
		.click()
	await expect(page).toHaveURL(/\/articles(\?|$)/)
}

test.beforeAll(async () => {
	const basic = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	api = await request.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Authorization: `Basic ${basic}`,
			Accept: 'application/json',
		},
	})
})

test.afterAll(async () => {
	for (const { schema, id } of created) {
		if (id !== '') {
			await api.delete(`${OBJECTS}/${schema}/${id}`)
		}
	}
	await api.dispose()
})

test.describe('marketing-articles: the write path', () => {
	// Requirement: An article holds its body as markdown and its own identity
	// Scenario: An article is created as a draft
	test('an article is created as a draft, by the editor, with a derived slug and no publication moment', async () => {
		const article = await createArticle({
			title: `Nieuwe release najaar ${STAMP}`,
			body: '## Wat er nieuw is\n\nDrie dingen.',
		})
		expect(article.status).toBe('draft')
		expect(article.author).toBe(ADMIN_USER)
		expect(article.publishedAt ?? '').toBe('')
		expect(article.slug).toBe(`nieuwe-release-najaar-${STAMP}`)
	})

	// Requirement: An article holds its body as markdown and its own identity
	// Scenario: A second article cannot claim a slug already in use
	test('a second article with a slug already in use is refused, naming the slug', async () => {
		const slug = `nieuwe-release-najaar-tweede-${STAMP}`
		await createArticle({ title: 'Eerste', slug, body: 'Een.' })

		const response = await api.post(ARTICLES, { data: { title: 'Tweede', slug, body: 'Twee.' } })
		expect(response.status()).toBe(400)
		expect(await response.json()).toMatchObject({ error: 'slug_taken', field: 'slug', slug })
	})

	// Requirement: An article moves through a declared lifecycle
	// Scenario: Publishing twice keeps the first publication moment
	test('publishing a second time keeps the first publication moment', async () => {
		const article = await createArticle({ title: `Twee keer gepubliceerd ${STAMP}`, body: 'Tekst.' })
		const id = idOf(article)

		expect((await move(id, 'publish')).ok()).toBe(true)
		const first = (await readArticle(id)).publishedAt
		expect(first, 'the first publish stamps the moment').toBeTruthy()

		// A second publish straight away is not a declared move and leaves it.
		await move(id, 'publish')
		expect((await readArticle(id)).publishedAt).toBe(first)

		// Nor does a publish after archive and restore move it: a second
		// apart, so a moved stamp could not compare equal by accident.
		await new Promise((resolve) => setTimeout(resolve, 1100))
		expect((await move(id, 'archive')).ok()).toBe(true)
		expect((await move(id, 'restore')).ok()).toBe(true)
		expect((await move(id, 'publish')).ok()).toBe(true)
		const again = await readArticle(id)
		expect(again.status).toBe('published')
		expect(again.publishedAt).toBe(first)
	})

	// Requirement: An article moves through a declared lifecycle
	// Scenario: A move the lifecycle does not declare is refused
	test('publishing an archived article directly is refused and it stays archived', async () => {
		const article = await createArticle({ title: `Gearchiveerd ${STAMP}`, body: 'Tekst.' })
		const id = idOf(article)
		expect((await move(id, 'archive')).ok()).toBe(true)

		const refused = await move(id, 'publish')
		expect(refused.status()).toBe(400)
		expect(await refused.json()).toMatchObject({ error: 'transition_not_allowed' })
		expect((await readArticle(id)).status).toBe('archived')
	})

	// Requirement: An article reports where it has been used
	// Scenario: An unused article reports an empty list
	test('an unused article reports an empty list, successfully', async () => {
		const article = await createArticle({ title: `Nergens gebruikt ${STAMP}`, body: 'Tekst.' })
		const response = await api.get(`${ARTICLES}/${idOf(article)}/usages`)
		expect(response.status()).toBe(200)
		expect((await response.json()).data).toEqual([])
	})
})

test.describe('marketing-articles: the screens', () => {
	// The editor signs in in the browser itself; start from nothing.
	test.use({ storageState: { cookies: [], origins: [] } })

	// Requirement: An article reports where it has been used
	// Scenario: A pipelinq template shows up as a usage
	test('a pipelinq campaign template shows up as a usage, marked as pipelinq, with a link', async ({ page }) => {
		const article = await createArticle({ title: `Najaarsartikel ${STAMP}`, body: 'Tekst.' })
		const id = idOf(article)
		const templateUrl = '/index.php/apps/pipelinq/templates/t-najaar'

		// pipelinq's listener, which is not installed here, would add this.
		await page.route(`**/apps/portaliq/api/articles/${id}/usages`, async (route) => {
			const response = await route.fetch()
			const body = await response.json()
			body.data = [
				...(body.data ?? []),
				{ id: 't-najaar', title: 'Najaarsmailing', kind: 'campaignTemplate', app: 'pipelinq', url: templateUrl },
			]
			await route.fulfill({ response, json: body })
		})

		await signInAsEditor(page)
		const app = await spaBase(page)
		await page.goto(`${BASE}${app}/articles/${id}`)

		const usage = page.getByTestId('article-usage')
		const group = usage.locator('section', { has: page.getByRole('heading', { name: /^\s*Marketing\s*$/ }) })
		await expect(group).toBeVisible({ timeout: 30_000 })
		const link = group.getByRole('link', { name: 'Najaarsmailing' })
		await expect(link).toHaveAttribute('href', templateUrl)
		await expect(group).toContainText(/Campaign template|Campagnesjabloon/)
	})

	// Requirement: A marketer writes and reads an article in portaliq
	// Scenario: The Articles page lists the articles
	test('Website, then Articles, lists the demo articles with title and status', async ({ page }) => {
		await signInAsEditor(page)
		await openArticlesFromMenu(page)

		for (const demo of DEMO_ARTICLES) {
			const row = page.locator('tr', { hasText: demo.title }).first()
			await expect(row, `the demo article "${demo.title}" is listed`).toBeVisible({ timeout: 60_000 })
			await expect(row).toContainText(demo.status)
		}
	})

	// Requirement: A marketer writes and reads an article in portaliq
	// Scenario: A marketer writes an article and publishes it
	test('a marketer writes an article, publishes it, and it is listed as published', async ({ page }) => {
		const title = `Najaarsactie ${STAMP}`

		await signInAsEditor(page)
		await openArticlesFromMenu(page)
		// Board PtArtikelen: "Nieuw artikel" beside the title. It goes to another page, so it is a link styled as the primary button.
		const newArticle = /^\s*(New article|Nieuw artikel)\s*$/
		await page.getByRole('link', { name: newArticle }).or(page.getByRole('button', { name: newArticle })).first().click()
		await expect(page).toHaveURL(/\/articles\/new$/)

		const dialog = page.getByRole('dialog')
		await dialog.getByLabel(/^(Title|Titel)\*?$/).fill(title)
		await dialog.getByTestId('cn-markdown-textarea').fill('## Najaar\n\nAlles over de **najaarsactie**.')
		await dialog.getByTestId('article-edit-save').click()

		// Created: the article page opens, with its lifecycle moves.
		await expect(page).toHaveURL(/\/articles\/(?!new)[^/]+$/, { timeout: 30_000 })
		const id = new URL(page.url()).pathname.split('/').pop() as string
		created.push({ schema: 'article', id })
		await expect(page.locator('.article-preview__title')).toHaveText(title)
		await expect(page.locator('.article-preview__body strong')).toHaveText('najaarsactie')

		await page.getByTestId('article-action-publish').click()
		await expect(page.getByTestId('article-action-archive')).toBeVisible({ timeout: 30_000 })

		await openArticlesFromMenu(page)
		const row = page.locator('tr', { hasText: title }).first()
		await expect(row).toBeVisible({ timeout: 60_000 })
		await expect(row).toContainText(/Published|Gepubliceerd/)
	})
})

test.describe('marketing-articles: the public site', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	// Requirement: A page can show an article
	// Scenario: A campaign page shows the article it names
	test('a campaign page shows the title, hero image and formatted body of the article it names', async ({ page }) => {
		const title = `Campagne najaar ${STAMP}`
		const article = await createArticle({
			title,
			body: '## Wat er nieuw is\n\nDe **najaarscampagne** begint.',
			heroImage: 'app:portaliq/hero-default.svg',
			organisation: ORGANISATION,
		})
		expect((await move(idOf(article), 'publish')).ok()).toBe(true)

		const route = `/e2e-campagne-${STAMP}`
		const pageResponse = await api.post(`${OBJECTS}/page`, {
			data: {
				title: 'Campagne',
				route,
				portal: SITE_PORTAL,
				status: 'published',
				locale: 'nl',
				body: { type: 'markdown', markdown: 'Onze campagne.', articleRef: article.slug },
			},
		})
		expect(pageResponse.ok(), `creating the campaign page failed: ${pageResponse.status()}`).toBe(true)
		const row = await pageResponse.json()
		created.push({ schema: 'page', id: String(row['@self']?.id ?? row.id) })

		const params = new URLSearchParams({ portal: SITE_PORTAL, route })
		await page.goto(`${SITE_PATH}?${params.toString()}`)

		const shown = page.getByTestId('page-article')
		await expect(shown).toBeVisible({ timeout: 30_000 })
		await expect(shown.getByRole('heading', { level: 2, name: title })).toBeVisible()
		const hero = shown.getByTestId('page-article-hero')
		await expect(hero).toHaveAttribute('src', /\/apps\/portaliq\/img\/hero-default\.svg/)
		await expect(hero).toHaveAttribute('alt', title)
		await expect(shown.getByRole('heading', { name: 'Wat er nieuw is' })).toBeVisible()
		await expect(shown.locator('strong')).toHaveText('najaarscampagne')
	})
})
