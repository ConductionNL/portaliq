/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-page-traffic: a portal page's own traffic. The per-page counts in
 * the daily record, the in-site route they are keyed by, the back-fill,
 * GET /api/traffic/page, and the four KPI cards plus Incoming and Outgoing
 * traffic on a page's detail page.
 *
 * Runs on the seed of seed-cms.sh: `open-tilburg` measures and has the
 * pages `/` and `/contact`; `open-venray` has measurement off and a page
 * "Over Venray". The data tests need E2E_CONTAINER to run the aggregation
 * job and occ inside the Nextcloud container.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	ADMIN_HEADERS,
	aggregate,
	APP,
	BASE,
	CONTAINER,
	ENABLED,
	login,
	occ,
	OR_OBJECTS,
	post,
	ROLLUP,
	rollupsOf,
	today,
	todaysRollup,
} from './lib/traffic.ts'

const DISABLED = 'open-venray'
const METRICS = ['page-views', 'sessions', 'visitors', 'engaged']
const SITE = `${APP}/site?portal=${ENABLED}`

/**
 * One event at an exact page location, as the client would post it.
 *
 * @param sequence The in-session sequence.
 * @param location The full page location.
 * @param name The event name.
 * @param extra More fields (linkUrl).
 */
function at(
	sequence: number,
	location: string,
	name = 'page_view',
	extra: Record<string, unknown> = {},
): Record<string, unknown> {
	return {
		name,
		timestamp: new Date().toISOString(),
		sequence,
		pageLocation: location,
		pageReferrer: '',
		pageTitle: 'Open Tilburg',
		params: {},
		...extra,
	}
}

/**
 * A page object of a portal, by route or by title.
 *
 * @param request The request context.
 * @param portal The portal slug.
 * @param match The route or the title to match.
 */
async function pageObject(
	request: APIRequestContext,
	portal: string,
	match: { route?: string; title?: string },
): Promise<Record<string, unknown>> {
	const res = await request.get(`${OR_OBJECTS}/page?portal=${portal}&_limit=100`, {
		headers: ADMIN_HEADERS,
	})
	expect(res.status()).toBe(200)
	const body = await res.json()
	const rows: Array<Record<string, unknown>> = Array.isArray(body)
		? body
		: (body.results ?? [])
	const row = rows.find(
		(r) =>
			r.portal === portal
			&& (match.route === undefined || r.route === match.route)
			&& (match.title === undefined || r.title === match.title),
	)
	expect(
		row,
		`the page ${JSON.stringify(match)} of ${portal} is seeded`,
	).toBeTruthy()
	return row!
}

/**
 * The id of an object.
 *
 * @param row The object.
 */
function idOf(row: Record<string, unknown>): string {
	const self = (row['@self'] ?? {}) as Record<string, unknown>
	return String(self.id ?? self.uuid ?? row.id ?? row.uuid ?? '')
}

/**
 * The page endpoint's answer.
 *
 * @param request The request context.
 * @param portal The portal slug.
 * @param route The route.
 * @param days The period, '' for the default.
 */
async function pageTraffic(
	request: APIRequestContext,
	portal: string,
	route: string,
	days = '',
): Promise<Record<string, any>> {
	const res = await request.get(
		`${APP}/api/traffic/page?portal=${portal}&route=${encodeURIComponent(route)}&days=${days}`,
		{ headers: ADMIN_HEADERS },
	)
	expect(res.status()).toBe(200)
	return res.json()
}

/**
 * The number a card shows.
 *
 * @param page The page.
 * @param testId The card's test id.
 */
async function cardValue(page: Page, testId: string): Promise<number> {
	const text = await page
		.getByTestId(testId)
		.locator('.cn-kpi-card__value')
		.innerText()
	return Number(text.replace(/[^\d]/g, '') || '0')
}

test.describe('page traffic', () => {
	test('the daily page rows count each page by its route, with its own sessions and sources', async ({
		request,
	}) => {
		// @e2e portal-page-traffic::the-built-in-site-counts-each-page-by-its-route
		// @e2e portal-page-traffic::a-trailing-slash-is-one-page
		// @e2e portal-page-traffic::a-page-row-counts-its-own-sessions-and-sources
		test.skip(CONTAINER === '', 'set E2E_CONTAINER to the Nextcloud container')

		// Visitor one, on the built-in site: home, then /contact by route.
		expect(
			(
				await post(
					request,
					[at(0, SITE), at(1, `${SITE}&route=%2Fcontact`)],
					{
						'User-Agent': 'page-traffic-e2e-one',
					},
				)
			).status(),
		).toBe(204)
		// Visitor two, on a path with a trailing slash, clicking out.
		expect(
			(
				await post(
					request,
					[
						at(0, `${BASE}/contact/`),
						at(1, `${BASE}/contact/`, 'outbound_click', {
							linkUrl: 'https://www.tilburg.nl/',
						}),
					],
					{ 'User-Agent': 'page-traffic-e2e-two' },
				)
			).status(),
		).toBe(204)
		aggregate()

		const pages = (await todaysRollup(request)).pages as Array<
			Record<string, any>
		>
		const paths = pages.map((p) => String(p.path))
		expect(paths).toContain('/contact')
		expect(paths).not.toContain('/contact/')
		expect(paths.some((p) => p.endsWith('/apps/portaliq/site'))).toBe(false)
		const contact = pages.find((p) => p.path === '/contact')!
		expect(contact.sessions).toBeGreaterThanOrEqual(2)
		expect(contact.visitors).toBeGreaterThanOrEqual(2)
		expect(typeof contact.engagedSessions).toBe('number')
		expect(Array.isArray(contact.referrers)).toBe(true)
		expect(contact.outbound).toEqual(
			expect.arrayContaining([
				expect.objectContaining({ url: 'https://www.tilburg.nl/' }),
			]),
		)
		const home = pages.find((p) => p.path === '/')
		expect(home, 'the built-in home page is `/`').toBeTruthy()
	})

	test('a roll-up sums the per-page counts of its members', async ({
		request,
	}) => {
		// @e2e portal-page-traffic::a-roll-up-leaves-out-a-figure-a-member-lacks
		// Both members write today's rows with the per-page counts, so the
		// roll-up carries them; the member-lacks-them case is staged in
		// tests/Unit/Service/Traffic/TrafficPageRowsTest.php
		// (testARollupLeavesOutAFigureAMemberLacks), since a live member
		// cannot be made to write an old-shaped row.
		test.skip(CONTAINER === '', 'set E2E_CONTAINER to the Nextcloud container')
		aggregate()

		const member = (await todaysRollup(request)).pages as Array<
			Record<string, any>
		>
		const rollup = (await rollupsOf(request, ROLLUP)).find(
			(r) => r.date === today(),
		)
		expect(rollup, "the roll-up has today's record").toBeTruthy()
		const summed = (rollup!.pages as Array<Record<string, any>>).find(
			(p) => p.path === '/contact',
		)
		const own = member.find((p) => p.path === '/contact')
		expect(summed).toBeTruthy()
		expect(summed!.sessions).toBeGreaterThanOrEqual(own!.sessions)
	})

	test('the back-fill rebuilds retained days and keeps a more complete record', async ({
		request,
	}) => {
		// @e2e portal-page-traffic::a-retained-day-gains-the-new-fields
		// @e2e portal-page-traffic::a-partly-purged-day-keeps-its-old-row
		test.skip(CONTAINER === '', 'set E2E_CONTAINER to the Nextcloud container')
		aggregate()

		const rebuilt = occ('portaliq:traffic:reaggregate', `--portal=${ENABLED}`)
		expect(rebuilt).toContain('Days rebuilt:')
		const pages = (await todaysRollup(request)).pages as Array<
			Record<string, any>
		>
		for (const row of pages) {
			expect(row, `${row.path} carries the per-page counts`).toHaveProperty(
				'sessions',
			)
		}

		// A stored record that counts more than the raw events still give
		// stands for a day whose events were partly purged: the back-fill
		// must leave it alone.
		const daily = await todaysRollup(request)
		const { '@self': _self, ...fields } = daily
		const inflated = Number(daily.pageViews) + 100000
		const put = await request.put(
			`${OR_OBJECTS}/portalTrafficDaily/${idOf(daily)}`,
			{
				headers: { ...ADMIN_HEADERS, 'Content-Type': 'application/json' },
				data: { ...fields, pageViews: inflated },
			},
		)
		expect(put.status()).toBeLessThan(300)
		occ('portaliq:traffic:reaggregate', `--portal=${ENABLED}`)
		expect((await todaysRollup(request)).pageViews).toBe(inflated)

		// The ordinary run rebuilds today regardless, which puts it back.
		aggregate()
		expect((await todaysRollup(request)).pageViews).toBeLessThan(inflated)
	})

	test('the page endpoint answers one page, refuses a bad route and nulls an unmeasured portal', async ({
		request,
	}) => {
		// @e2e portal-page-traffic::a-pages-figures-for-the-last-30-days
		// @e2e portal-page-traffic::days-without-per-page-figures-are-counted-not-zeroed
		// @e2e portal-page-traffic::an-unmeasured-portal-answers-null
		// @e2e portal-page-traffic::a-bad-route-is-refused
		const answer = await pageTraffic(request, ENABLED, '/contact')
		expect(answer.measured).toBe(true)
		expect(answer.days).toBe(30)
		expect(answer.route).toBe('/contact')
		expect(typeof answer.pageViews).toBe('number')
		expect(answer.detailDays).toBeLessThanOrEqual(answer.recordedDays)
		if (answer.detailDays === 0) {
			expect(answer.sessions).toBeNull()
			expect(answer.referrers).toBeNull()
		} else {
			expect(typeof answer.sessions).toBe('number')
			expect(Array.isArray(answer.outbound)).toBe(true)
		}
		expect(Array.isArray(answer.previous)).toBe(true)
		expect(Array.isArray(answer.next)).toBe(true)

		const off = await pageTraffic(request, DISABLED, '/over-ons')
		expect(off.measured).toBe(false)
		for (const key of [
			'pageViews',
			'sessions',
			'visitors',
			'engagedSessions',
			'previous',
			'next',
			'referrers',
			'outbound',
		]) {
			expect(off[key], key).toBeNull()
		}

		const bad = await request.get(
			`${APP}/api/traffic/page?portal=${ENABLED}&route=contact`,
			{ headers: ADMIN_HEADERS },
		)
		expect(bad.status()).toBe(400)
		expect(await bad.json()).toEqual({ error: 'invalid-route' })
	})

	test('a page opens with its four cards and its incoming and outgoing traffic', async ({
		page,
		request,
	}) => {
		// @e2e portal-page-traffic::a-page-shows-its-own-last-30-days
		// @e2e portal-page-traffic::incoming-and-outgoing-traffic-of-a-page
		const contact = await pageObject(request, ENABLED, { route: '/contact' })
		const answer = await pageTraffic(request, ENABLED, '/contact')

		await login(page)
		await page.goto(`${APP}/pages/${idOf(contact)}`)
		for (const metric of METRICS) {
			await expect(page.getByTestId(`page-kpi-${metric}`)).toBeVisible({
				timeout: 30_000,
			})
		}
		await expect
			.poll(() => cardValue(page, 'page-kpi-page-views'), { timeout: 15_000 })
			.toBe(answer.pageViews)
		if (answer.sessions === null) {
			await expect(page.getByTestId('page-kpi-sessions')).toContainText(
				'Not available for this period',
			)
		} else {
			await expect
				.poll(() => cardValue(page, 'page-kpi-sessions'), {
					timeout: 15_000,
				})
				.toBe(answer.sessions)
		}

		const incoming = page.getByTestId('page-traffic-incoming')
		const outgoing = page.getByTestId('page-traffic-outgoing')
		await expect(
			incoming.getByTestId('page-traffic-incoming-pages'),
		).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			incoming.getByTestId('page-traffic-incoming-boundary'),
		).toContainText(String(answer.entrances))
		await expect(
			outgoing.getByTestId('page-traffic-outgoing-pages'),
		).toBeVisible()
		await expect(
			outgoing.getByTestId('page-traffic-outgoing-boundary'),
		).toContainText(String(answer.exits))
		for (const row of answer.previous as Array<Record<string, unknown>>) {
			await expect(
				incoming.getByTestId('page-traffic-incoming-pages'),
			).toContainText(String(row.path))
		}

		await page.getByTestId('page-kpi-page-views').click()
		await expect(page).toHaveURL(new RegExp(`/traffic\\?portal=${ENABLED}`), {
			timeout: 15_000,
		})
	})

	test('a page of an unmeasured portal says so', async ({ page, request }) => {
		// @e2e portal-page-traffic::a-page-of-an-unmeasured-portal-says-so
		const venray = await pageObject(request, DISABLED, { title: 'Over Venray' })

		await login(page)
		await page.goto(`${APP}/pages/${idOf(venray)}`)
		for (const metric of METRICS) {
			await expect(page.getByTestId(`page-kpi-${metric}`)).toContainText(
				'Not measured',
				{ timeout: 30_000 },
			)
		}
		await expect(page.getByTestId('page-traffic-incoming-state')).toContainText(
			'Not measured',
		)
		await expect(page.getByTestId('page-traffic-outgoing-state')).toContainText(
			'Not measured',
		)
	})
})
