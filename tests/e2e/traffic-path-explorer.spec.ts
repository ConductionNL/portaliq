/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-traffic-path-explorer: the paths endpoint counts each visit's own
 * path from the raw events, and the Traffic page draws it where the
 * Journeys table was.
 *
 * Every visit this spec posts uses page paths under a prefix unique to the
 * run, so the other traffic specs' visits on the same portal and day never
 * reach an assertion here: each question starts from, or ends at, one of
 * this run's pages. A distinct User-Agent is a distinct cookieless visitor.
 *
 * The endpoint caches a fold for five minutes per portal and period, so
 * every request here asks for a period that starts a random number of days
 * back. A rerun inside five minutes then asks a different question rather
 * than reading the previous run's fold.
 *
 * The API tests need only the instance. The page tests also need the daily
 * figures (the widgets show "No traffic recorded yet" without them), so
 * they run the aggregation job and skip without E2E_CONTAINER.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	ADMIN_HEADERS,
	aggregate,
	APP,
	CONTAINER,
	event,
	login,
	post,
	seededTraffic,
	setTraffic,
	today,
} from './lib/traffic.ts'

const RUN = 'pe' + Date.now().toString(36)
const P = (page: string): string => `/${RUN}/${page}`
const BACK = 1 + Math.floor(Math.random() * 60)
const MOBILE_UA =
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'

let visitor = 0

/**
 * Post one cookieless visit: its page views, in order, as one batch from a
 * User-Agent no other visit uses.
 *
 * @param request The request context.
 * @param pages The page paths.
 * @param mobile Whether the visitor is on a phone.
 */
async function visit(
	request: APIRequestContext,
	pages: string[],
	mobile = false,
): Promise<void> {
	visitor++
	const ua = mobile
		? MOBILE_UA.replace('17_0', `17_${visitor}`)
		: `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.${visitor}.0 Safari/537.36`
	const res = await post(
		request,
		pages.map((page, i) => event(i, page)),
		{ 'User-Agent': ua },
	)
	expect(res.status(), 'the collector accepts the visit').toBe(204)
}

/**
 * A day as YYYY-MM-DD, some days before today (UTC).
 *
 * @param days How many days back.
 */
function daysBack(days: number): string {
	return new Date(Date.now() - days * 86400000).toISOString().substring(0, 10)
}

/**
 * Ask the paths endpoint, as the admin.
 *
 * @param request The request context.
 * @param params The query, on top of the portal and this run's period.
 */
async function paths(
	request: APIRequestContext,
	params: Record<string, string>,
): Promise<{ status: number; body: Record<string, any> }> {
	const query = new URLSearchParams({
		portal: 'open-tilburg',
		from: daysBack(BACK),
		to: today(),
		...params,
	})
	const res = await request.get(`${APP}/api/traffic/paths?${query}`, {
		headers: ADMIN_HEADERS,
	})
	return { status: res.status(), body: await res.json() }
}

/**
 * One step's page nodes as path => [visits, drop-offs].
 *
 * @param body The endpoint's answer.
 * @param step The step.
 */
function step(
	body: Record<string, any>,
	step: number,
): Record<string, [number, number]> {
	const out: Record<string, [number, number]> = {}
	for (const node of body.columns[step].nodes) {
		out[node.more > 0 ? `+${node.more}` : node.path] = [
			node.sessions,
			node.dropOffs,
		]
	}
	return out
}

test.describe.configure({ mode: 'serial' })

test.describe('traffic path explorer: the endpoint', () => {
	test.beforeAll(async ({ request }) => {
		await visit(request, [P('home'), P('news'), P('contact')])
		await visit(request, [P('about'), P('news')])
		await visit(request, [P('home'), P('home'), P('news')])
		await visit(request, [P('home')])
		const fan: Array<[string, number]> = [
			['a', 3],
			['b', 3],
			['c', 2],
			['d', 2],
			['e', 2],
			['f', 1],
			['g', 1],
		]
		for (const [page, times] of fan) {
			for (let i = 0; i < times; i++) {
				await visit(request, [P('hub'), P('to-' + page)])
			}
		}
	})

	// @e2e portal-traffic-path-explorer::a-path-is-what-one-visit-did-not-a-chain-of-pairs
	// @e2e portal-traffic-path-explorer::start-from-a-page
	test('a path is what one visit did: from /about the visit ends on /news and never reaches /contact', async ({
		request,
	}) => {
		const { status, body } = await paths(request, { anchor: P('about') })
		expect(status).toBe(200)
		expect(body.sessions).toBe(1)
		expect(step(body, 0)).toEqual({ [P('about')]: [1, 0] })
		expect(step(body, 1)).toEqual({ [P('news')]: [1, 1] })
		expect(step(body, 2)).toEqual({})
	})

	// @e2e portal-traffic-path-explorer::a-reload-is-not-a-step
	// @e2e portal-traffic-path-explorer::drop-offs-are-counted-per-node-and-per-step
	test('from /home a reload is one step, and the visits that ended are counted per node and per step', async ({
		request,
	}) => {
		const { body } = await paths(request, { anchor: P('home') })
		expect(body.sessions).toBe(3)
		expect(step(body, 0)).toEqual({ [P('home')]: [3, 1] })
		expect(body.columns[0].dropOffs).toBe(1)
		expect(step(body, 1)).toEqual({ [P('news')]: [2, 1] })
		expect(step(body, 2)).toEqual({ [P('contact')]: [1, 1] })
		expect(body.links).toContainEqual({
			step: 0,
			source: 0,
			target: 0,
			sessions: 2,
		})
	})

	// @e2e portal-traffic-path-explorer::end-at-a-page
	test('ending at /contact reads the steps backward', async ({ request }) => {
		const { body } = await paths(request, { mode: 'end', anchor: P('contact') })
		expect(body.mode).toBe('end')
		expect(step(body, 0)).toEqual({ [P('contact')]: [1, 0] })
		expect(step(body, 1)).toEqual({ [P('news')]: [1, 0] })
		expect(step(body, 2)).toEqual({ [P('home')]: [1, 1] })
	})

	// @e2e portal-traffic-path-explorer::the-sixth-page-and-beyond-become-one-node
	test('a step shows its five busiest pages and sums the rest into "+2 more"', async ({
		request,
	}) => {
		const { body } = await paths(request, { anchor: P('hub'), steps: '1' })
		expect(step(body, 1)).toEqual({
			[P('to-a')]: [3, 3],
			[P('to-b')]: [3, 3],
			[P('to-c')]: [2, 2],
			[P('to-d')]: [2, 2],
			[P('to-e')]: [2, 2],
			'+2': [2, 2],
		})
		expect(body.columns[1].sessions).toBe(14)
	})

	// @e2e portal-traffic-path-explorer::a-chosen-node-narrows-the-next-steps
	test('choosing /news on step 1 narrows step 2 and leaves step 1 whole', async ({
		request,
	}) => {
		await visit(request, [P('home'), P('faq'), P('bye')])
		// A different start day: the visit above landed after the earlier
		// fold was cached, and a new period is a new fold.
		const { body } = await paths(request, {
			anchor: P('home'),
			trail: JSON.stringify([null, P('news')]),
			from: daysBack(BACK + 2),
		})
		expect(Object.keys(step(body, 1)).sort()).toEqual(
			[P('faq'), P('news')].sort(),
		)
		expect(step(body, 2)).toEqual({ [P('contact')]: [1, 1] })
		const chosen = body.columns[1].nodes.find(
			(n: Record<string, unknown>) => n.path === P('news'),
		)
		expect(chosen.selected).toBe(true)
	})

	// @e2e portal-traffic-path-explorer::a-malformed-request-is-refused-with-a-reason
	test('a malformed request is refused with a reason', async ({ request }) => {
		const steps = await paths(request, { steps: '12' })
		expect(steps.status).toBe(400)
		expect(steps.body).toEqual({ error: 'invalid-steps' })
		const mode = await paths(request, { mode: 'sideways' })
		expect(mode.status).toBe(400)
		expect(mode.body).toEqual({ error: 'invalid-mode' })
	})

	// @e2e portal-traffic-path-explorer::a-period-beyond-retention-names-the-days-covered
	test('a period of 180 days on a portal that keeps 90 names the days it covers', async ({
		request,
	}) => {
		const { body } = await paths(request, { from: daysBack(179) })
		expect(body.coverage.beyondRetention).toBe(true)
		expect(body.coverage.retentionDays).toBe(90)
		expect(body.coverage.keptFrom).toBe(daysBack(89))
		expect(body.coverage.from).toBe(daysBack(89))
		expect(body.coverage.to).toBe(today())
	})

	// @e2e portal-traffic-path-explorer::a-capped-read-says-it-is-truncated
	test('a read under the cap says it was not truncated and names the cap', async ({
		request,
	}) => {
		// Fifty thousand events cannot be posted in a test run. The cap
		// itself is covered by TrafficPathServiceTest; this pins the fields
		// the page reads, and the page test below renders the note.
		const { body } = await paths(request, {})
		expect(body.truncated).toBe(false)
		expect(body.eventCap).toBe(50000)
		expect(body.eventsScanned).toBeLessThanOrEqual(50000)
		expect(body.coverage.partialDay).toBeNull()
	})

	// @e2e portal-traffic-path-explorer::a-segment-narrows-the-paths
	test('a segment narrows the paths to its visits', async ({ request }) => {
		await setTraffic(
			request,
			seededTraffic({
				segments: [
					{
						id: 'pe-mobile',
						name: 'Mobile',
						conditions: [
							{
								dimension: 'deviceType',
								operator: 'is',
								value: 'mobile',
							},
						],
					},
				],
			}),
		)
		try {
			await visit(request, [P('m-home'), P('m-news')], true)
			await visit(request, [P('m-home'), P('m-other')])
			const all = await paths(request, {
				anchor: P('m-home'),
				steps: '1',
				from: daysBack(BACK + 3),
			})
			expect(Object.keys(step(all.body, 1)).sort()).toEqual(
				[P('m-news'), P('m-other')].sort(),
			)
			const mobile = await paths(request, {
				anchor: P('m-home'),
				steps: '1',
				segment: 'pe-mobile',
				from: daysBack(BACK + 4),
			})
			expect(mobile.status).toBe(200)
			expect(step(mobile.body, 1)).toEqual({ [P('m-news')]: [1, 1] })
		} finally {
			await setTraffic(request, seededTraffic())
		}
	})
})

/**
 * Open the Traffic page and wait for the explorer.
 *
 * @param page The page.
 */
async function openTraffic(page: Page): Promise<void> {
	await login(page)
	await page.goto(`${APP}/traffic`)
	await expect(page.getByTestId('traffic-paths')).toBeVisible({
		timeout: 30_000,
	})
}

test.describe('traffic path explorer: the Traffic page', () => {
	test.beforeAll(() => {
		if (CONTAINER !== '') {
			aggregate()
		}
	})

	// @e2e portal-traffic-analytics::the-page-shows-the-numbers-for-a-measured-portal
	// @e2e portal-traffic-path-explorer::a-node-is-chosen-by-keyboard
	// @e2e portal-traffic-path-explorer::the-steps-are-available-as-a-table
	// @e2e portal-traffic-path-explorer::steps-can-be-added-and-removed
	test('the explorer replaces Journeys, its nodes work by keyboard, and the steps read as a table', async ({
		page,
	}) => {
		test.skip(
			CONTAINER === '',
			'the page needs the daily figures; set E2E_CONTAINER',
		)
		await openTraffic(page)
		await expect(page.getByTestId('traffic-journeys')).toHaveCount(0)
		await expect(page.getByTestId('traffic-paths-diagram')).toBeVisible({
			timeout: 30_000,
		})

		const node = page
			.locator('[data-testid="traffic-paths-node"][data-step="1"]')
			.first()
		await expect(node).toHaveAttribute('aria-pressed', 'false')
		const narrowed = page.waitForResponse(
			(r) =>
				r.url().includes('/api/traffic/paths') && r.url().includes('trail='),
		)
		await node.focus()
		await page.keyboard.press('Enter')
		expect((await narrowed).status()).toBe(200)
		await expect(
			page.locator(
				'[data-testid="traffic-paths-node"][data-step="1"][aria-pressed="true"]',
			),
		).toHaveCount(1)
		await expect(page.getByTestId('traffic-paths-clear')).toBeVisible()

		await page
			.getByTestId('traffic-paths-table-toggle')
			.locator('summary')
			.click()
		const rows = page.getByTestId('traffic-paths-table').locator('tbody tr')
		expect(await rows.count()).toBeGreaterThan(0)

		await expect(page.getByTestId('traffic-paths-step-count')).toContainText('3')
		await page.getByTestId('traffic-paths-add-step').click()
		await expect(page.getByTestId('traffic-paths-step-count')).toContainText('4')
		await expect(page.getByTestId('traffic-paths-step-total')).toHaveCount(5)
		await page.getByTestId('traffic-paths-remove-step').click()
		await expect(page.getByTestId('traffic-paths-step-count')).toContainText('3')
	})

	// @e2e portal-traffic-path-explorer::an-unmeasured-portal-shows-no-diagram
	test('an unmeasured portal reads "Not measured" in the explorer and draws nothing', async ({
		page,
	}) => {
		test.skip(
			CONTAINER === '',
			'the page needs the daily figures; set E2E_CONTAINER',
		)
		await openTraffic(page)
		const select = page.getByTestId('traffic-portal-select')
		await select.locator('input').first().click()
		await select.locator('input').first().fill('Venray')
		await page.keyboard.press('Enter')

		const explorer = page.getByTestId('traffic-paths')
		await expect(explorer.getByTestId('traffic-not-measured')).toBeVisible({
			timeout: 15_000,
		})
		await expect(page.getByTestId('traffic-paths-diagram')).toHaveCount(0)
	})

	// @e2e portal-traffic-path-explorer::a-capped-read-says-it-is-truncated
	// @e2e portal-traffic-path-explorer::a-period-beyond-retention-names-the-days-covered
	test('the explorer says when the paths cover less than the period', async ({
		page,
	}) => {
		test.skip(
			CONTAINER === '',
			'the page needs the daily figures; set E2E_CONTAINER',
		)
		// The endpoint's answer is replaced here: fifty thousand events
		// cannot be posted in a test run, and the note is the page's part.
		await page.route('**/api/traffic/paths?**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					mode: 'start',
					sessions: 1,
					truncated: true,
					eventCap: 50000,
					coverage: {
						from: '2026-09-20',
						to: '2026-09-23',
						keptFrom: '2026-06-26',
						retentionDays: 90,
						beyondRetention: true,
						partialDay: '2026-09-20',
					},
					columns: [
						{
							step: 0,
							sessions: 1,
							dropOffs: 1,
							nodes: [
								{
									path: '/',
									sessions: 1,
									dropOffs: 1,
									more: 0,
									selected: false,
								},
							],
						},
					],
					links: [],
				}),
			}),
		)
		await openTraffic(page)
		await expect(page.getByTestId('traffic-paths-truncated')).toContainText(
			'50000',
		)
		await expect(page.getByTestId('traffic-paths-truncated')).toContainText(
			'2026-09-20',
		)
		await expect(page.getByTestId('traffic-paths-retention')).toContainText('90')
	})
})
