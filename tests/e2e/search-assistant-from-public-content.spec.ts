/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * search-assistant-from-public-content, task T05: the `assistant` widget on a
 * live public page, against a stubbed hermiq entry point.
 *
 * THE STUB. hermiq's in-process entry point (T01) does not exist yet, so the
 * site record never says `assistantEnabled` and the route answers nothing
 * real. The browser is given what hermiq would make the server say: the site
 * record with `assistantEnabled: true`, and `/api/assistant/ask` answering in
 * the route's own shape (`status`, `answer`, `sources`, `removed`). Everything
 * else is the real stack: the seeded portal, a published page that places the
 * widget, the site SPA, the widget gate and the block.
 *
 * What the unit level already pins and this file leaves alone: the bearer
 * refusal and the abstention without a source (PublicAssistantControllerTest),
 * the forwarded payload and the removed citizen service number
 * (PublicAssistantChannelTest), the scope (PublicSourceScopeTest), and the
 * absence when the portal has it off (WidgetGrid, tests/search-assistant.spec.mjs).
 *
 * THE SEEDED PORTAL IS THE FIXTURE. `open-tilburg` comes from
 * tests/e2e/fixtures/seed-cms.sh; this file adds two pages at its own routes
 * and removes them again.
 *
 * @spec openspec/changes/search-assistant-from-public-content/specs/portal-public-assistant/spec.md
 */

import type { APIRequestContext, Page, Route } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const PAGES = `${BASE}/index.php/apps/openregister/api/objects/portaliq/page`

const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** The seeded portal (fixtures/seed-cms.sh). */
const PORTAL = 'open-tilburg'

/** The routes this file owns. */
const ROUTE = '/e2e-assistant'
const SOURCE_ROUTE = '/e2e-afvalkalender-feestdagen'
const FORM_ROUTE = '/e2e-parkeervergunning-aanvragen'
const OWNED = [ROUTE, SOURCE_ROUTE, FORM_ROUTE]

/** The published page the answer cites. */
const SOURCE_TITLE = 'Afvalkalender feestdagen'

/** The sentences of REQ-SAP-003 (English, the widget is placed with `locale: en`). */
const DISCLOSURE =
	'Answers come from an AI assistant and can be wrong. Check the page it links to.'
const NOTE = 'Do not type personal details such as your citizen service number.'

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
 * Remove every page at this file's routes.
 *
 * @param api the admin context
 */
async function removeFixture(api: APIRequestContext): Promise<void> {
	const existing = await api.get(`${PAGES}?_limit=500`)
	if (existing.ok()) {
		for (const row of (await existing.json()).results ?? []) {
			if (OWNED.includes(row.route)) {
				await api.delete(`${PAGES}/${row['@self']?.id ?? row.id}`)
			}
		}
	}
}

/**
 * Create one published page of the seeded portal.
 *
 * @param api     the admin context
 * @param title   the page title
 * @param route   the page route
 * @param widgets the grid widgets
 */
async function createPage(
	api: APIRequestContext,
	title: string,
	route: string,
	widgets: Record<string, unknown>[],
): Promise<void> {
	const created = await api.post(PAGES, {
		data: {
			title,
			route,
			portal: PORTAL,
			status: 'published',
			locale: 'nl',
			body: { type: 'grid', widgets },
		},
	})
	expect(created.ok(), await created.text()).toBe(true)
}

/**
 * A markdown widget for a content page.
 *
 * @param id       the widget id
 * @param markdown the text
 * @return the widget
 */
function markdown(id: string, markdown: string): Record<string, unknown> {
	return {
		id,
		widgetKey: 'markdown',
		slot: 'body',
		gridX: 0,
		gridY: 0,
		gridWidth: 12,
		gridHeight: 2,
		props: { markdown },
	}
}

/**
 * The hermiq stub: what the assistant route answers, by question.
 *
 * @param question the visitor's question
 * @return the route's answer body
 */
function stubbedAnswer(question: string): Record<string, unknown> {
	if (/afval/i.test(question)) {
		return {
			status: 'answered',
			answer: 'Met Koningsdag wordt het afval een dag later opgehaald.',
			sources: [{ title: SOURCE_TITLE, url: SOURCE_ROUTE }],
			conversationId: 'e2e-conversation',
		}
	}
	if (/parkeervergunning/i.test(question)) {
		return {
			status: 'answered',
			answer:
				'Ik kan niets voor u aanvragen. U vraagt een parkeervergunning aan met het formulier op de pagina hieronder.',
			sources: [{ title: 'Parkeervergunning aanvragen', url: FORM_ROUTE }],
			conversationId: 'e2e-conversation',
		}
	}

	// What the route says when hermiq found no published source.
	return { status: 'abstained', answer: null, sources: [] }
}

/**
 * Stub hermiq's entry point for this page and record what the browser asks.
 *
 * @param page the page
 * @return the asks the widget sent, and every write the page attempted
 */
async function stubAssistant(
	page: Page,
): Promise<{ asks: any[]; writes: string[] }> {
	const asks: any[] = []
	const writes: string[] = []

	// The site record says the assistant is on, as it does once hermiq answers.
	await page.route(/\/api\/content\/site(\?|$)/, async (route: Route) => {
		const response = await route.fetch()
		const body = await response.json()
		await route.fulfill({
			response,
			json: { ...body, assistantEnabled: true },
		})
	})
	await page.route('**/api/assistant/ask', async (route: Route) => {
		const sent = route.request().postDataJSON()
		asks.push({ body: sent, headers: route.request().headers() })
		await route.fulfill({ json: stubbedAnswer(String(sent?.question ?? '')) })
	})
	page.on('request', (sent) => {
		const url = sent.url()
		if (
			sent.method() !== 'GET'
			&& !url.includes('/api/assistant/ask')
			&& !url.includes('/traffic')
		) {
			writes.push(`${sent.method()} ${url}`)
		}
	})

	return { asks, writes }
}

/**
 * Open the page that places the widget and wait for the widget.
 *
 * @param page the page
 */
async function openAssistantPage(page: Page): Promise<void> {
	await page.goto(`${SITE}?route=${ROUTE}`)
	await expect(page.getByTestId('assistant')).toBeVisible({ timeout: 60_000 })
}

/**
 * Ask a question through the widget.
 *
 * @param page     the page
 * @param question the question
 */
async function ask(page: Page, question: string): Promise<void> {
	await page.locator('#pq-assistant-question').fill(question)
	await page.getByTestId('assistant').locator('button[type="submit"]').click()
}

test.beforeAll(async () => {
	const api = await adminApi()
	await removeFixture(api)
	await createPage(api, SOURCE_TITLE, SOURCE_ROUTE, [
		markdown('e2e-afval', 'Met Koningsdag wordt het afval een dag later opgehaald.'),
	])
	await createPage(api, 'Parkeervergunning aanvragen', FORM_ROUTE, [
		markdown('e2e-parkeren', 'Vraag hier uw parkeervergunning aan.'),
	])
	await createPage(api, 'E2E assistant fixture', ROUTE, [
		{
			id: 'e2e-assistant',
			widgetKey: 'assistant',
			slot: 'body',
			gridX: 0,
			gridY: 0,
			gridWidth: 12,
			gridHeight: 6,
			props: { title: 'Ask a question', locale: 'en', contactRoute: '/contact' },
		},
	])
	await api.dispose()
})

test.afterAll(async () => {
	const api = await adminApi()
	await removeFixture(api)
	await api.dispose()
})

test.describe('the public assistant answers from published content', () => {
	test.setTimeout(120_000)

	// @e2e openspec/changes/search-assistant-from-public-content/specs/portal-public-assistant/spec.md#scenario-the-disclosure-is-there-before-asking
	test('both sentences are visible before anything is typed', async ({ page }) => {
		const { asks } = await stubAssistant(page)
		await openAssistantPage(page)

		await expect(page.getByTestId('assistant-disclosure')).toHaveText(DISCLOSURE)
		await expect(page.getByTestId('assistant-note')).toHaveText(NOTE)
		await expect(page.locator('#pq-assistant-question')).toHaveValue('')
		expect(asks).toHaveLength(0)
	})

	// @e2e openspec/changes/search-assistant-from-public-content/specs/portal-public-assistant/spec.md#scenario-a-visitor-asks-about-waste-collection
	test('a visitor asks about waste collection and gets the source', async ({ page }) => {
		const { asks } = await stubAssistant(page)
		await openAssistantPage(page)

		await ask(page, 'Wanneer wordt het afval opgehaald met Koningsdag?')

		const answer = page.getByTestId('assistant-answer')
		await expect(answer).toBeVisible({ timeout: 30_000 })
		await expect(answer).toContainText('Met Koningsdag')
		await expect(answer.getByRole('heading', { name: 'Sources' })).toBeVisible()
		await expect(answer.getByRole('link', { name: SOURCE_TITLE })).toHaveAttribute(
			'href',
			SOURCE_ROUTE,
		)

		// One ask, with the question, and no session carried along.
		expect(asks).toHaveLength(1)
		expect(asks[0].body.question).toBe(
			'Wanneer wordt het afval opgehaald met Koningsdag?',
		)
		expect(asks[0].headers.authorization).toBeUndefined()
	})

	// @e2e openspec/changes/search-assistant-from-public-content/specs/portal-public-assistant/spec.md#scenario-no-source-no-answer
	test('no source, no answer', async ({ page }) => {
		await stubAssistant(page)
		await openAssistantPage(page)

		await ask(page, 'Hoeveel inwoners had de gemeente in 1850?')

		const abstained = page.getByTestId('assistant-abstained')
		await expect(abstained).toBeVisible({ timeout: 30_000 })
		await expect(abstained).toHaveText(
			'I could not find this in our information. You can contact us.',
		)
		await expect(page.getByTestId('assistant-contact')).toHaveAttribute('href', '/contact')
		await expect(page.getByTestId('assistant-answer')).toHaveCount(0)
	})

	// @e2e openspec/changes/search-assistant-from-public-content/specs/portal-public-assistant/spec.md#scenario-a-visitor-asks-it-to-file-a-request
	test('a request to act is answered in words with the form page, and nothing is written', async ({
		page,
	}) => {
		const { writes } = await stubAssistant(page)
		await openAssistantPage(page)
		const before = writes.length

		await ask(page, 'Vraag een parkeervergunning voor me aan')

		const answer = page.getByTestId('assistant-answer')
		await expect(answer).toBeVisible({ timeout: 30_000 })
		await expect(
			answer.getByRole('link', { name: 'Parkeervergunning aanvragen' }),
		).toHaveAttribute('href', FORM_ROUTE)
		expect(writes.slice(before)).toEqual([])
	})
})
