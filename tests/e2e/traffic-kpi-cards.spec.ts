/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-traffic-kpi-cards: four traffic KPI cards on a portal's detail
 * page, the same four as KPI cards on the Traffic page, and the summary
 * endpoint behind the portal page's cards.
 *
 * Runs on the seed of traffic-analytics.spec.ts: `open-tilburg` measures
 * and has daily records once that suite's rollup test ran; `open-venray`
 * has measurement off. The card values are checked against the summary
 * endpoint, and the endpoint against the "all visits" daily records, so a
 * card that reads the wrong number and an endpoint that sums segment rows
 * both fail here.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const APP = `${BASE}/index.php/apps/portaliq`
const OR_OBJECTS = `${BASE}/index.php/apps/openregister/api/objects/portaliq`
const ENABLED = 'open-tilburg'
const DISABLED = 'open-venray'
const ADMIN_USER = process.env.ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? 'admin'
const ADMIN_BASIC =
	'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
const HEADERS = { Authorization: ADMIN_BASIC, 'OCS-APIRequest': 'true' }
const METRICS = ['page-views', 'sessions', 'visitors', 'engaged']

/**
 * Log in as the admin.
 *
 * @param page The page.
 */
async function login(page: Page): Promise<void> {
	await page.goto(`${BASE}/index.php/login`)
	await page.locator('input[name="user"]').fill(ADMIN_USER)
	await page.locator('input[name="password"]').fill(ADMIN_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 60_000 })
}

/**
 * The uuid of a portal by slug.
 *
 * @param request The request context.
 * @param slug The portal slug.
 */
async function portalId(request: APIRequestContext, slug: string): Promise<string> {
	const res = await request.get(`${OR_OBJECTS}/portal?slug=${slug}&_limit=5`, {
		headers: HEADERS,
	})
	expect(res.status()).toBe(200)
	const body = await res.json()
	const rows = Array.isArray(body) ? body : (body.results ?? [])
	const row = rows.find((r: Record<string, unknown>) => r.slug === slug)
	expect(row, `portal ${slug} is seeded`).toBeTruthy()
	return String(row.id ?? row.uuid ?? row['@self']?.id)
}

/**
 * The summary endpoint's answer.
 *
 * @param request The request context.
 * @param days The period, '' for the default.
 */
async function summary(
	request: APIRequestContext,
	days = '',
): Promise<Record<string, number>> {
	const res = await request.get(
		`${APP}/api/traffic/summary?portal=${ENABLED}&days=${days}`,
		{ headers: HEADERS },
	)
	expect(res.status()).toBe(200)
	return res.json()
}

/**
 * The number a portal page card shows.
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

test.describe('traffic KPI cards', () => {
	test('the summary endpoint folds the all-visits records and refuses a bad period', async ({
		request,
	}) => {
		// @e2e portal-traffic-kpi-cards::the-totals-exclude-segment-rows
		// @e2e portal-traffic-kpi-cards::a-bad-period-is-refused
		const res = await request.get(
			`${OR_OBJECTS}/portalTrafficDaily?portal=${ENABLED}&_limit=500`,
			{ headers: HEADERS },
		)
		expect(res.status()).toBe(200)
		const body = await res.json()
		const rows: Array<Record<string, unknown>> = Array.isArray(body)
			? body
			: (body.results ?? [])
		const start = new Date(Date.now() - 29 * 86400000)
			.toISOString()
			.substring(0, 10)
		const expected = rows
			.filter(
				(r) =>
					r.portal === ENABLED
					&& String(r.segment ?? '') === ''
					&& String(r.date) >= start,
			)
			.reduce((sum, r) => sum + Number(r.pageViews ?? 0), 0)

		const totals = await summary(request)
		expect(totals.days).toBe(30)
		expect(totals.pageViews).toBe(expected)

		const bad = await request.get(
			`${APP}/api/traffic/summary?portal=${ENABLED}&days=12`,
			{ headers: HEADERS },
		)
		expect(bad.status()).toBe(400)
		expect(await bad.json()).toEqual({ error: 'invalid-period' })
	})

	test('a measured portal opens with its four cards, and a card keeps its own period', async ({
		page,
		request,
	}) => {
		// @e2e portal-traffic-kpi-cards::a-measured-portal-shows-its-last-30-days
		// @e2e portal-traffic-kpi-cards::a-cards-period-picker-changes-only-that-card
		const id = await portalId(request, ENABLED)
		const thirty = await summary(request)
		const seven = await summary(request, '7')

		await login(page)
		await page.goto(`${APP}/portals/${id}`)
		for (const metric of METRICS) {
			await expect(page.getByTestId(`portal-kpi-${metric}`)).toBeVisible({
				timeout: 30_000,
			})
		}
		await expect
			.poll(() => cardValue(page, 'portal-kpi-page-views'), {
				timeout: 15_000,
			})
			.toBe(thirty.pageViews)
		await expect
			.poll(() => cardValue(page, 'portal-kpi-sessions'), { timeout: 15_000 })
			.toBe(thirty.sessions)

		await page
			.getByTestId('portal-kpi-page-views')
			.getByTestId('cn-stat-widget-range')
			.selectOption('7')
		await expect
			.poll(() => cardValue(page, 'portal-kpi-page-views'), {
				timeout: 15_000,
			})
			.toBe(seven.pageViews)
		// The other cards stay on 30 days.
		await expect(
			page
				.getByTestId('portal-kpi-sessions')
				.getByTestId('cn-stat-widget-range'),
		).toHaveValue('')
		expect(await cardValue(page, 'portal-kpi-sessions')).toBe(thirty.sessions)
	})

	test('an unmeasured portal says so and asks for nothing', async ({
		page,
		request,
	}) => {
		// @e2e portal-traffic-kpi-cards::an-unmeasured-portal-says-so
		const id = await portalId(request, DISABLED)
		const asked: string[] = []
		page.on('request', (r) => {
			if (r.url().includes('/api/traffic/summary')) {
				asked.push(r.url())
			}
		})

		await login(page)
		await page.goto(`${APP}/portals/${id}`)
		for (const metric of METRICS) {
			await expect(page.getByTestId(`portal-kpi-${metric}`)).toContainText(
				'Not measured',
				{ timeout: 30_000 },
			)
		}
		expect(asked).toEqual([])
	})

	test('a card opens the Traffic page on its portal, where the numbers are cards too', async ({
		page,
		request,
	}) => {
		// @e2e portal-traffic-kpi-cards::a-card-opens-the-traffic-page-on-this-portal
		// @e2e portal-traffic-kpi-cards::the-query-parameter-selects-the-portal
		// @e2e portal-traffic-kpi-cards::the-cards-follow-the-overviews-selectors
		const id = await portalId(request, ENABLED)
		const thirty = await summary(request)

		await login(page)
		await page.goto(`${APP}/portals/${id}`)
		await page.getByTestId('portal-kpi-page-views').click()
		await expect(page).toHaveURL(new RegExp(`/traffic\\?portal=${ENABLED}`), {
			timeout: 15_000,
		})
		await expect(page.getByTestId('traffic-portal-select')).toContainText(
			'Tilburg',
			{
				timeout: 30_000,
			},
		)

		const tile = page.getByTestId('traffic-tile-page-views')
		await expect(tile).toBeVisible({ timeout: 30_000 })
		await expect
			.poll(
				async () =>
					Number(
						(
							await tile
								.locator('.cn-stats-block__count-value')
								.innerText()
						).replace(/[^\d]/g, '') || '0',
					),
				{ timeout: 15_000 },
			)
			.toBe(thirty.pageViews)
		for (const metric of METRICS) {
			await expect(page.getByTestId(`traffic-tile-${metric}`)).toBeVisible()
		}
		// The overview no longer carries the numbers itself.
		await expect(
			page
				.getByTestId('traffic-overview')
				.getByTestId('traffic-tile-page-views'),
		).toHaveCount(0)

		// The cards follow the overview's period.
		const range = page.getByTestId('traffic-range-select')
		await range.locator('input').first().click()
		await range.locator('input').first().fill('Last 7')
		await page.keyboard.press('Enter')
		await expect(tile).toContainText('7 days', { timeout: 15_000 })
	})
})
