/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-theme-application Task 3: the assertion that would have caught a
 * theme reference that changed nothing a visitor sees. Two sites that name
 * different themes render the SAME page, and the SAME element must COMPUTE a
 * different colour. It deliberately does not assert on a class name, on a
 * token file being present, or on the theme string an API returns: all three
 * passed while both sites rendered rgb(26, 26, 26).
 *
 * Also anchored: a theme token resolves to a value that is not the
 * component's own fallback, only the active theme's token set is fetched, a
 * theme that does not resolve leaves the page visibly unthemed, and the
 * rendered heading, links, paragraphs and table carry NL Design component
 * classes (Task 2's rendered-DOM check).
 *
 * NOT anchored here: that the unresolved theme is NAMED in the server log.
 * That is PortalThemeResolverTest::testAThemeThatDoesNotResolveIsReportedByName.
 *
 * The spec seeds its own three portals (two themes and one that does not
 * exist) with one page each, and deletes them afterwards. The two themes come
 * from the environment and default to the ones the CI fixture already uses:
 * PORTALIQ_E2E_THEME_A (default vng), PORTALIQ_E2E_THEME_B (default venray).
 * PORTALIQ_E2E_ADMIN defaults to admin:admin. Needs the theme app installed.
 *
 * Task 3 asks for this to be run FIRST against a build without the theming
 * and observed failing. That run needs a live instance and is not recorded
 * here.
 *
 * @spec openspec/changes/archive/2026-11-25-portal-theme-application/specs/portaliq-cms/spec.md#requirement-a-sites-theme-must-change-its-rendered-appearance
 * @spec openspec/changes/archive/2026-11-25-portal-theme-application/specs/portaliq-cms/spec.md#requirement-an-unresolvable-theme-must-be-obvious-not-silently-default
 * @spec openspec/changes/archive/2026-11-25-portal-theme-application/specs/portaliq-cms/spec.md#requirement-markup-must-carry-nl-design-component-classes
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { SITE_PATH } from './portal-nav.ts'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from(process.env.PORTALIQ_E2E_ADMIN || 'admin:admin').toString(
	'base64',
)
const ADMIN_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }
const THEME_A = process.env.PORTALIQ_E2E_THEME_A || 'vng'
const THEME_B = process.env.PORTALIQ_E2E_THEME_B || 'venray'

/** The page every seeded portal serves: a heading, prose, a link and a table. */
const MARKDOWN = [
	'# Afval en grondstoffen',
	'',
	'Lees hier wanneer de container wordt geleegd. Zie ook [de afvalkalender](https://example.org/kalender).',
	'',
	'| Dag | Container |',
	'| --- | --- |',
	'| Maandag | Groente en fruit |',
	'| Donderdag | Restafval |',
].join('\n')

/** What this run created, as `schema/id`, to delete afterwards. */
const created: string[] = []

/** The three portals this run serves. */
const SLUGS = { a: '', b: '', missing: '' }

/**
 * Create one portaliq object through OpenRegister's own object API, as the admin.
 *
 * @param request The request fixture.
 * @param schema The portaliq schema.
 * @param data The object.
 * @return The id.
 */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/${schema}`, {
		headers: ADMIN_HEADERS,
		data,
	})
	expect(res.ok(), `OpenRegister objects#create must be reachable for ${schema}`).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	created.push(`${schema}/${id}`)
	return id
}

/**
 * One published portal on a theme, with the page at its root.
 *
 * @param request The request fixture.
 * @param slug The portal's slug.
 * @param theme The theme it names.
 */
async function seedPortal(request: APIRequestContext, slug: string, theme: string): Promise<void> {
	await seed(request, 'portal', {
		title: `Thema ${slug}`,
		slug,
		status: 'published',
		domains: [],
		theme,
		locales: ['nl'],
		authentication: { modes: ['public'] },
	})
	await seed(request, 'page', {
		title: 'Afval',
		route: '/',
		portal: slug,
		status: 'published',
		locale: 'nl',
		summary: 'Testpagina voor het thema.',
		body: { type: 'markdown', markdown: MARKDOWN },
	})
}

/**
 * Open a seeded portal's root page and wait for its markdown to render.
 *
 * @param page The page.
 * @param slug The portal's slug.
 * @return The stylesheet URLs the page fetched.
 */
async function open(page: Page, slug: string): Promise<string[]> {
	const sheets: string[] = []
	page.on('request', (req) => {
		if (req.resourceType() === 'stylesheet') {
			sheets.push(req.url())
		}
	})
	await page.goto(`${SITE_PATH}?portal=${encodeURIComponent(slug)}`)
	await expect(page.locator('.pq-markdown h1')).toBeVisible()
	return sheets
}

/**
 * The computed colours of the same elements on the page.
 *
 * @param page The page.
 * @return Heading colour, link colour, table header background and body background.
 */
async function colours(page: Page): Promise<string[]> {
	return page.evaluate(() => {
		const style = (selector: string, property: string): string => {
			const el = document.querySelector(selector)
			return el ? getComputedStyle(el).getPropertyValue(property) : ''
		}
		return [
			style('.pq-markdown h1', 'color'),
			style('.pq-markdown a', 'color'),
			style('.pq-markdown th', 'background-color'),
			style('body', 'background-color'),
		]
	})
}

/**
 * A custom property as the root element resolves it.
 *
 * @param page The page.
 * @param name The property.
 * @return Its value, trimmed; '' when the theme does not define it.
 */
async function rootToken(page: Page, name: string): Promise<string> {
	return page.evaluate(
		(property) => getComputedStyle(document.documentElement).getPropertyValue(property).trim(),
		name,
	)
}

/**
 * The computed colour of the page's markdown link.
 *
 * @param page The page.
 * @return The colour.
 */
async function linkColour(page: Page): Promise<string> {
	return page.evaluate(
		() => getComputedStyle(document.querySelector('.pq-markdown a') as Element).color,
	)
}

test.describe('portal-theme-application', () => {
	test.beforeAll(async ({ request }) => {
		const stamp = Date.now()
		SLUGS.a = `thema-a-${stamp}`
		SLUGS.b = `thema-b-${stamp}`
		SLUGS.missing = `thema-weg-${stamp}`
		await seedPortal(request, SLUGS.a, THEME_A)
		await seedPortal(request, SLUGS.b, THEME_B)
		await seedPortal(request, SLUGS.missing, `bestaat-niet-${stamp}`)
	})

	test.afterAll(async ({ request }) => {
		for (const ref of created.splice(0).reverse()) {
			await request.delete(`${OR_OBJECTS_BASE}/portaliq/${ref}`, {
				headers: ADMIN_HEADERS,
			})
		}
	})

	test('two sites on different themes compute different colours for the same element', async ({
		browser,
	}) => {
		const first = await browser.newPage()
		await open(first, SLUGS.a)
		const a = await colours(first)
		await first.close()

		const second = await browser.newPage()
		await open(second, SLUGS.b)
		const b = await colours(second)
		await second.close()

		expect(a.every((value) => value !== ''), 'every inspected element rendered').toBe(true)
		expect(a, `${THEME_A} and ${THEME_B} must not render the page identically`).not.toEqual(b)
	})

	test('a theme token resolves, and not to the component fallback', async ({ browser }) => {
		// The fallback is what the same link computes where no theme applies.
		const unthemed = await browser.newPage()
		await open(unthemed, SLUGS.missing)
		const fallback = await linkColour(unthemed)
		await unthemed.close()

		const page = await browser.newPage()
		await open(page, SLUGS.a)
		const primary = await rootToken(page, '--nldesign-color-primary')
		expect(primary, `${THEME_A} defines --nldesign-color-primary`).not.toBe('')
		expect(await linkColour(page), 'the link takes its colour from the theme').not.toBe(fallback)
		await page.close()
	})

	test("only the active theme's token set is fetched", async ({ page }) => {
		const sheets = await open(page, SLUGS.a)

		expect(sheets.some((url) => url.includes(`/css/tokens/${THEME_A}.css`))).toBe(true)
		expect(sheets.filter((url) => url.includes(`/css/tokens/${THEME_B}.css`))).toEqual([])
	})

	test('a theme that does not resolve leaves the page visibly unthemed', async ({ page }) => {
		const sheets = await open(page, SLUGS.missing)

		expect(sheets.filter((url) => url.includes('/bestaat-niet-'))).toEqual([])
		expect(sheets.filter((url) => url.includes(`/css/tokens/${THEME_A}.css`))).toEqual([])
		// No token set applies, so no theme's primary colour is defined: the
		// page shows the neutral fallbacks, not a brand that looks right.
		expect(await rootToken(page, '--nldesign-color-primary')).toBe('')
	})

	test('the rendered heading, links, paragraphs and table carry NL Design classes', async ({
		page,
	}) => {
		await open(page, SLUGS.a)

		const markdown = page.locator('.pq-markdown')
		await expect(markdown.locator('h1.utrecht-heading-1')).toHaveCount(1)
		await expect(markdown.locator('p.utrecht-paragraph').first()).toBeVisible()
		await expect(markdown.locator('a.utrecht-link')).toHaveCount(1)
		await expect(markdown.locator('table.utrecht-table')).toHaveCount(1)
		await expect(markdown.locator('th.utrecht-table__header-cell')).toHaveCount(2)
		await expect(markdown.locator('td.utrecht-table__cell')).toHaveCount(4)

		const count = await page.evaluate(
			() => document.querySelectorAll('[class*="utrecht-"]').length,
		)
		expect(count).toBeGreaterThan(0)
	})
})
