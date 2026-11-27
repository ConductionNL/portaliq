/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * operate-pages-per-portal-and-client T03 and T05 (REQ-PGC-001, REQ-PGC-002,
 * REQ-PGC-003): an administrator chooses which contributed pages one portal's
 * menu shows and in which order, and a clerk hides a page for one client.
 *
 * WHAT IS REAL. Everything: the two portals, the contribution, the two
 * portal accounts, the admin screens (the "Navigation" section on the
 * portal's page, `PortalNavigation`, and "Pages hidden for this client" on
 * the account's page, `PortalAccountPages`), the admin routes behind them
 * (`/api/portals/{slug}/navigation`, `/api/accounts/{subjectRef}/pages`) and
 * the resident's signed-in menu on the site. Nothing is stubbed with
 * `page.route`: the pages come from Portaliq's own `portalPage` contribution,
 * so no sibling app has to be installed.
 *
 * WHY NOT pipelinq's quotes and invoices. The scenarios name pipelinq's pages;
 * pipelinq is not on the test instance. The contribution seeded here declares
 * pages with the same names ("Invoices", "My cases", "Quotes", later
 * "Documents"), so the menu reads like the scenario and the choice is applied
 * by exactly the code that would apply it to pipelinq's.
 *
 * WHY A RUN-OWN AUDIENCE. Portaliq's provider reads ONE active `portalPage`
 * per audience, the lowest id, and logs the rest
 * (`PortalContributionProvider::pageContribution()`). A new `client` row would
 * lose to the seeded one and the test would see the wrong pages. So this run
 * contributes for an audience of its own and signs its residents in with it.
 *
 * WHY TWO PORTALS OF ITS OWN. The portal choice is presentation per portal
 * (design D1): the control is the same resident on a second portal of the
 * organisation without the choice. Seeding both keeps the seeded
 * `open-tilburg` untouched. A named slug resolves exactly that portal and
 * never falls through to the host (`PortalResolver::resolve()`).
 *
 * The direct collection call of REQ-PGC-002 and the self-service PATCH are
 * `@e2e exclude` (pinned by ContributionControllerTest and the self-service
 * PATCH test); this spec covers the menu half.
 *
 * Needs an instance with dev-login (see tests/e2e/ci-seed.sh) and the admin's
 * credentials in ADMIN_USER / ADMIN_PASSWORD (default admin/admin). Browser
 * contexts start from an empty storage state. The target comes from BASE_URL
 * (see tests/e2e/base-url.ts).
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { devLogin, PORTAL_API, seedSiteSession, SITE_PATH } from './portal-nav.ts'

const APP_BASE = '/index.php/apps/portaliq'
const OBJECTS = '/index.php/apps/openregister/api/objects/portaliq'
const ADMIN_USER = process.env.ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? 'admin'
const ADMIN = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
const HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }
const EMPTY = { cookies: [], origins: [] }
const ORGANISATION = 'dev-org'
/** The notice both editors show after a save (pageChoiceEditor.js), in English or Dutch. */
const SAVED = /Your choices are saved\.|Uw keuzes zijn opgeslagen\./

const STAMP = Date.now()
/** The audience this run contributes for; see the top comment. */
const AUDIENCE = `pgc-${STAMP}`
/** The portal that carries the choices. */
const CHOSEN = `pgc-chosen-${STAMP}`
/** The organisation's other portal, without any choice. */
const OTHER = `pgc-other-${STAMP}`
/** "Bakkerij De Kroon B.V." and another business client. */
const BAKERY = `pgc-bakkerij-${STAMP}`
const NEIGHBOUR = `pgc-buurman-${STAMP}`

/** The page keys as the choice stores them: `<app>:<pageId>`. */
const KEY = {
	invoices: 'portaliq:invoices',
	cases: 'portaliq:my-cases',
	quotes: 'portaliq:quotes',
	documents: 'portaliq:documents',
}

/** What this run created, as `[schema, id]`, deleted newest first. */
const created: Array<[string, string]> = []
let contributionId = ''

test.use({ storageState: EMPTY })
test.describe.configure({ mode: 'serial' })

/**
 * One page of the seeded contribution, with a collection of its own, so a
 * hidden page also has a collection that only it shows (REQ-PGC-002).
 *
 * @param id The page id.
 * @param label The menu label.
 * @return The page and its collection.
 */
function contributedPage(id: string, label: string): { page: Record<string, unknown>, collection: Record<string, unknown> } {
	return {
		page: { id, label, blocks: [{ type: 'collection', collection: id }] },
		collection: {
			id,
			label,
			register: 'portaliq',
			schema: 'portalCase',
			scopeField: 'subjectRef',
			fields: ['reference', 'omschrijving'],
			columns: [{ field: 'reference', label: 'Nummer' }],
		},
	}
}

/**
 * The contribution body: the pages listed, in this order.
 *
 * @param pages `[id, label]` per page.
 * @return The `portalPage` row.
 */
function contribution(pages: Array<[string, string]>): Record<string, unknown> {
	const parts = pages.map(([id, label]) => contributedPage(id, label))
	return {
		label: 'Zakelijk loket',
		audience: AUDIENCE,
		status: 'active',
		collections: parts.map((part) => part.collection),
		actions: [],
		pages: parts.map((part) => part.page),
	}
}

/**
 * Create one object through OpenRegister's object API, as the administrator.
 *
 * @param request The request fixture.
 * @param schema The portaliq schema.
 * @param data The object.
 * @return The id.
 */
async function seed(request: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<string> {
	const res = await request.post(`${OBJECTS}/${schema}`, { headers: HEADERS, data })
	expect(res.ok(), `seeding ${schema}: ${res.status()} ${(await res.text()).slice(0, 200)}`).toBeTruthy()
	const body = await res.json()
	const id = String(body.id ?? body.uuid ?? body['@self']?.id)
	created.push([schema, id])
	return id
}

/**
 * A published portal of the organisation with a front page, so the site
 * serves it by its slug.
 *
 * @param request The request fixture.
 * @param slug The portal slug.
 * @return The portal's object id.
 */
async function seedPortal(request: APIRequestContext, slug: string): Promise<string> {
	const id = await seed(request, 'portal', {
		title: `Ondernemersloket ${slug}`,
		slug,
		status: 'published',
		organisation: ORGANISATION,
	})
	await seed(request, 'page', {
		title: 'Welkom',
		route: '/',
		portal: slug,
		status: 'published',
		body: { type: 'markdown', markdown: 'Welkom bij het ondernemersloket.' },
	})
	return id
}

/**
 * Sign in as the administrator through Nextcloud's own login form.
 *
 * @param page The page.
 * @return Nothing.
 */
async function signIn(page: Page): Promise<void> {
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(ADMIN_USER)
	await page.locator('input[name="password"]').fill(ADMIN_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 60_000 })
}

/**
 * Close a modal over the app (the first-run wizard, Portaliq's tour) if one
 * is open; looked for briefly, never waited for.
 *
 * @param page The page.
 * @return Nothing.
 */
async function dismissOverlay(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"], .modal-container, [data-testid="cn-tour"]').first()
	if (await modal.isVisible({ timeout: 3_000 }).catch(() => false)) {
		await page.keyboard.press('Escape')
	}
}

/**
 * Open the "Navigation" section of a portal's page: the Instellingen tab of
 * the portal detail page, where `PortalNavigation` mounts (the tabs are lazy).
 *
 * @param page The signed-in page.
 * @param portalId The portal's object id.
 * @return The section's audience group for this run's audience.
 */
async function openNavigation(page: Page, portalId: string): Promise<Locator> {
	await page.goto(`${APP_BASE}/portals/${portalId}`)
	await dismissOverlay(page)
	await page.getByRole('tab', { name: 'Instellingen' }).click()
	const group = page.getByTestId(`portal-navigation-${AUDIENCE}`)
	await expect(group, 'the Navigation section lists this run\'s audience').toBeVisible({ timeout: 30_000 })
	return group
}

/**
 * A resident of this run's audience, signed in on the site of one portal and
 * on its signed-in area.
 *
 * @param page The page, not yet navigated.
 * @param request The request fixture.
 * @param subjectRef The resident.
 * @param portal The portal slug the site is served as.
 * @return Nothing.
 */
async function openArea(page: Page, request: APIRequestContext, subjectRef: string, portal: string): Promise<void> {
	const token = await devLogin(request, subjectRef, AUDIENCE, ORGANISATION)
	await seedSiteSession(page, token)
	await page.goto(`${SITE_PATH}?${new URLSearchParams({ portal, route: '/mijn' }).toString()}`)
	await expect(page.getByTestId('site-resident-menu')).toBeVisible({ timeout: 30_000 })
}

/**
 * The contributed page ids in the signed-in menu, in menu order: the links
 * whose in-site route is `/mijn/portaliq/<id>` (src/shared/portalNav.js).
 *
 * @param page The page, on the signed-in area.
 * @return The page ids, top to bottom.
 */
async function menuPages(page: Page): Promise<string[]> {
	const hrefs = await page
		.getByTestId('site-resident-menu')
		.locator('a[href]')
		.evaluateAll((links) => links.map((link) => link.getAttribute('href') ?? ''))
	const ids: string[] = []
	for (const href of hrefs) {
		const route = new URL(href, 'http://site.invalid').searchParams.get('route') ?? ''
		const match = route.match(/^\/mijn\/portaliq\/([^/?#]+)$/)
		if (match && !ids.includes(match[1])) {
			ids.push(match[1])
		}
	}
	return ids
}

/**
 * The menu pages answered by the contributions route as one portal serves
 * them, the same read the site makes (`X-Portaliq-Portal`).
 *
 * @param request The request fixture.
 * @param subjectRef The resident.
 * @param portal The portal slug.
 * @return The page ids of Portaliq's contribution, in order.
 */
async function contributedPages(request: APIRequestContext, subjectRef: string, portal: string): Promise<string[]> {
	const token = await devLogin(request, subjectRef, AUDIENCE, ORGANISATION)
	const res = await request.get(`${PORTAL_API}/contributions`, {
		headers: { Authorization: `Bearer ${token}`, 'X-Portaliq-Portal': portal },
	})
	expect(res.status()).toBe(200)
	const { contributions } = await res.json() as { contributions: Array<{ app: string, pages: Array<{ id: string }> }> }
	return (contributions.find((entry) => entry.app === 'portaliq')?.pages ?? []).map((entry) => entry.id)
}

/**
 * Save one portal's navigation for this run's audience through the admin
 * route the Navigation section saves through.
 *
 * @param request The request fixture.
 * @param slug The portal slug.
 * @param entries The `{page, hidden}` list, in menu order.
 * @return Nothing.
 */
async function saveNavigation(request: APIRequestContext, slug: string, entries: Array<{ page: string, hidden: boolean }>): Promise<void> {
	const res = await request.put(`${APP_BASE}/api/portals/${slug}/navigation`, {
		headers: HEADERS,
		data: { navigation: { [AUDIENCE]: entries } },
	})
	expect(res.status(), (await res.text()).slice(0, 200)).toBe(200)
}

let chosenId = ''
let bakeryAccountId = ''

test.describe('operate-pages-per-portal-and-client', () => {
	test.beforeAll(async ({ request }) => {
		contributionId = await seed(request, 'portalPage', contribution([
			['invoices', 'Invoices'],
			['my-cases', 'My cases'],
			['quotes', 'Quotes'],
		]))
		chosenId = await seedPortal(request, CHOSEN)
		await seedPortal(request, OTHER)
		bakeryAccountId = await seed(request, 'portalAccount', {
			audience: AUDIENCE,
			subjectRef: BAKERY,
			organisation: ORGANISATION,
			displayName: 'Bakkerij De Kroon B.V.',
			status: 'active',
		})
		await seed(request, 'portalAccount', {
			audience: AUDIENCE,
			subjectRef: NEIGHBOUR,
			organisation: ORGANISATION,
			displayName: 'Slagerij Van Dam',
			status: 'active',
		})
	})

	test.afterAll(async ({ request }) => {
		for (const [schema, id] of created.splice(0).reverse()) {
			await request.delete(`${OBJECTS}/${schema}/${id}`, { headers: HEADERS })
		}
	})

	// @e2e portal-page-choice::the-quotes-page-is-left-out-of-one-portal
	test('REQ-PGC-001: the quotes page is left out of one portal, and kept on the other', async ({ page, request }) => {
		await saveNavigation(request, CHOSEN, [
			{ page: KEY.invoices, hidden: false },
			{ page: KEY.cases, hidden: false },
			{ page: KEY.quotes, hidden: true },
		])

		await openArea(page, request, NEIGHBOUR, CHOSEN)
		const chosen = await menuPages(page)
		expect(chosen, 'control: the portal still shows its other pages').toEqual(expect.arrayContaining(['invoices', 'my-cases']))
		expect(chosen, 'the hidden quotes page is not in the menu').not.toContain('quotes')

		// The same client on the organisation's other portal, which made no choice.
		await openArea(page, request, NEIGHBOUR, OTHER)
		expect(await menuPages(page)).toContain('quotes')
	})

	// @e2e portal-page-choice::a-newly-installed-app-stays-visible
	test('REQ-PGC-001: a page contributed after the choice was made stays visible, after the listed ones', async ({ page, request }) => {
		// The choice lists three pages. A page the contribution gains later
		// stands in for an app installed after the choice was made: neither is
		// in the stored list, and both are arranged by the same rule.
		const res = await request.patch(`${OBJECTS}/portalPage/${contributionId}`, {
			headers: HEADERS,
			data: contribution([
				['documents', 'Documents'],
				['invoices', 'Invoices'],
				['my-cases', 'My cases'],
				['quotes', 'Quotes'],
			]),
		})
		expect(res.ok(), `adding the Documents page: ${res.status()}`).toBeTruthy()

		expect(await contributedPages(request, NEIGHBOUR, CHOSEN)).toEqual(['invoices', 'my-cases', 'documents'])

		await openArea(page, request, NEIGHBOUR, CHOSEN)
		const menu = await menuPages(page)
		expect(menu, '"Documents" is in the menu').toContain('documents')
		expect(menu.indexOf('documents'), '"Documents" comes after the pages the choice lists')
			.toBeGreaterThan(menu.indexOf('my-cases'))
	})

	// @e2e portal-page-choice::an-administrator-reorders-the-menu
	test('REQ-PGC-003: an administrator moves "My cases" above "Invoices" and the client\'s menu follows', async ({ page, request }) => {
		await openArea(page, request, NEIGHBOUR, CHOSEN)
		let menu = await menuPages(page)
		expect(menu.indexOf('invoices'), 'control: "Invoices" starts above "My cases"').toBeLessThan(menu.indexOf('my-cases'))

		await signIn(page)
		const group = await openNavigation(page, chosenId)
		await expect(page.getByTestId('portal-navigation')).toContainText(
			/Hidden pages are left out of this portal's menu\.|Verborgen pagina's/,
		)
		await expect(group.getByTestId(`portal-navigation-page-${KEY.quotes}`).getByRole('switch'))
			.not.toBeChecked()
		await group.getByTestId(`portal-navigation-up-${KEY.cases}`).click()
		await page.getByTestId('portal-navigation-save').click()
		await expect(page.getByTestId('portal-navigation')).toContainText(SAVED)

		const stored = await request.get(`${APP_BASE}/api/portals/${CHOSEN}/navigation`, { headers: HEADERS })
		const audiences = (await stored.json()).audiences as Array<{ audience: string, pages: Array<{ page: string }> }>
		const order = audiences.find((entry) => entry.audience === AUDIENCE)?.pages.map((entry) => entry.page) ?? []
		expect(order.indexOf(KEY.cases)).toBeLessThan(order.indexOf(KEY.invoices))

		const resident = await page.context().browser()!.newContext({ storageState: EMPTY })
		const residentPage = await resident.newPage()
		await openArea(residentPage, request, NEIGHBOUR, CHOSEN)
		menu = await menuPages(residentPage)
		expect(menu.indexOf('my-cases'), 'the client\'s menu lists "My cases" before "Invoices"').toBeLessThan(menu.indexOf('invoices'))
		await resident.close()
	})

	// @e2e portal-page-choice::one-business-client-does-not-see-its-invoices (the menu half)
	test('REQ-PGC-002: a clerk hides the invoices page for one client, and another client keeps it', async ({ page, request }) => {
		await signIn(page)
		await page.goto(`${APP_BASE}/accounts/${bakeryAccountId}`)
		await dismissOverlay(page)
		const field = page.getByTestId('portal-account-pages')
		await expect(field).toBeVisible({ timeout: 30_000 })
		const row = field.getByTestId(`account-page-${KEY.invoices}`)
		await expect(row.getByRole('switch')).not.toBeChecked()
		await row.getByRole('switch').click()
		await page.getByTestId('account-pages-save').click()
		await expect(field).toContainText(SAVED)

		const stored = await request.get(`${APP_BASE}/api/accounts/${BAKERY}/pages`, { headers: HEADERS })
		expect(stored.status()).toBe(200)
		const pages = (await stored.json()).pages as Array<{ page: string, hidden: boolean }>
		expect(pages.find((entry) => entry.page === KEY.invoices)?.hidden).toBe(true)

		const bakery = await page.context().browser()!.newContext({ storageState: EMPTY })
		const bakeryPage = await bakery.newPage()
		await openArea(bakeryPage, request, BAKERY, OTHER)
		const bakeryMenu = await menuPages(bakeryPage)
		expect(bakeryMenu, 'control: the bakery still has its other pages').toContain('my-cases')
		expect(bakeryMenu, 'the bakery\'s menu has no invoices page').not.toContain('invoices')
		await bakery.close()

		const neighbour = await page.context().browser()!.newContext({ storageState: EMPTY })
		const neighbourPage = await neighbour.newPage()
		await openArea(neighbourPage, request, NEIGHBOUR, OTHER)
		expect(await menuPages(neighbourPage), 'another client still sees its invoices').toContain('invoices')
		await neighbour.close()
	})
})
