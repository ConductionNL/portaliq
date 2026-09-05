/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-traffic-visitors-and-geo, phase 1: geography from an offline
 * database, visitors said honestly, the period selector, and the Reports
 * card.
 *
 * Runs AFTER tests/e2e/traffic-analytics.spec.ts (alphabetical, one
 * worker) against the same two seeded portals. Everything that needs the
 * container (the fixture database, the aggregation job, the occ command,
 * a trusted proxy so a test address reaches the collector) skips with a
 * stated reason when E2E_CONTAINER is unset.
 *
 * THE FIXTURE DATABASE. MaxMind's GeoIP2-City-Test.mmdb (tests/fixtures,
 * Apache-2.0) is copied into the app data directory with `docker cp`, so
 * the resolver runs a real lookup without a download. It maps
 * 81.2.69.160 to GB and nothing much else, which is exactly what a proof
 * needs: one address that resolves and one (the runner's own) that does
 * not.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { resolveBaseURL } from './base-url.ts'
import { seededTraffic } from './lib/traffic.ts'

const BASE = resolveBaseURL()
const APP = `${BASE}/index.php/apps/portaliq`
const OR_OBJECTS = `${BASE}/index.php/apps/openregister/api/objects/portaliq`
const ENABLED = 'open-tilburg'
const ADMIN_USER = process.env.ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? 'admin'
const ADMIN_BASIC =
	'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
const CONTAINER = process.env.E2E_CONTAINER ?? ''
const FIXTURE = path.resolve(__dirname, '../fixtures/GeoIP2-City-Test.mmdb')
const GB_ADDRESS = '81.2.69.160'
const DESKTOP_UA =
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'
const ADMIN_HEADERS = { Authorization: ADMIN_BASIC, 'OCS-APIRequest': 'true' }

/**
 * One event as the client would post it.
 *
 * @param sequence The in-session sequence.
 * @param path The page path.
 * @param at When it happened; now when absent.
 * @param name The event name.
 */
function event(
	sequence: number,
	pagePath: string,
	at?: Date,
	name = 'page_view',
): Record<string, unknown> {
	return {
		name,
		timestamp: (at ?? new Date()).toISOString(),
		sequence,
		pageLocation: `${BASE}${pagePath}`,
		pageReferrer: '',
		pageTitle: 'Open Tilburg',
		params: {},
	}
}

/**
 * Post a batch as text/plain.
 *
 * @param request The request context.
 * @param events The events.
 * @param headers Extra headers.
 */
async function post(
	request: APIRequestContext,
	events: Array<Record<string, unknown>>,
	headers: Record<string, string> = {},
) {
	return request.post(`${APP}/api/traffic`, {
		data: JSON.stringify({ portal: ENABLED, consent: true, events }),
		headers: {
			'Content-Type': 'text/plain',
			'User-Agent': DESKTOP_UA,
			...headers,
		},
	})
}

/**
 * Run occ inside the container.
 *
 * @param args The occ arguments.
 */
function occ(...args: string[]): string {
	return execFileSync(
		'docker',
		['exec', '-u', 'www-data', CONTAINER, 'php', 'occ', ...args],
		{ encoding: 'utf8', timeout: 300_000 },
	)
}

/**
 * Run a shell command inside the container as root.
 *
 * @param script The script.
 */
function shell(script: string): string {
	return execFileSync('docker', ['exec', CONTAINER, 'sh', '-c', script], {
		encoding: 'utf8',
		timeout: 120_000,
	})
}

/**
 * The address the runner's requests arrive from, as the container sees
 * it: the gateway of the container's network.
 */
function gateway(): string {
	const out = execFileSync(
		'docker',
		[
			'inspect',
			'-f',
			'{{range .NetworkSettings.Networks}}{{.Gateway}}{{end}}',
			CONTAINER,
		],
		{ encoding: 'utf8', timeout: 60_000 },
	).trim()
	expect(out, 'the container has a network gateway').toMatch(/^[\d.:a-f]+$/i)
	return out
}

/**
 * Run the aggregation job once.
 */
function aggregate(): void {
	const jobs = JSON.parse(
		occ('background-job:list', '--output=json', '--limit', '500'),
	) as Array<{ id: number; class: string }>
	const job = jobs.find(
		(j) => j.class === 'OCA\\Portaliq\\BackgroundJob\\TrafficAggregationJob',
	)
	expect(job, 'the aggregation job is registered').toBeTruthy()
	occ('background-job:execute', String(job!.id), '--force-execute')
}

/**
 * The measured portal's record, with its id, as the admin.
 *
 * @param request The request context.
 */
async function portalRecord(
	request: APIRequestContext,
): Promise<Record<string, unknown>> {
	const res = await request.get(`${OR_OBJECTS}/portal?slug=${ENABLED}&_limit=5`, {
		headers: ADMIN_HEADERS,
	})
	expect(res.status()).toBe(200)
	const body = await res.json()
	const rows = Array.isArray(body) ? body : (body.results ?? [])
	const portal = rows.find((r: Record<string, unknown>) => r.slug === ENABLED)
	expect(portal, 'the seeded portal exists').toBeTruthy()
	return portal
}

/**
 * Write the portal's traffic block back, keeping everything else.
 *
 * @param request The request context.
 * @param traffic The new traffic block.
 */
async function setTraffic(
	request: APIRequestContext,
	traffic: Record<string, unknown>,
): Promise<void> {
	const portal = await portalRecord(request)
	const self = (portal['@self'] ?? {}) as Record<string, unknown>
	const id = String(self.id ?? self.uuid ?? portal.id ?? '')
	expect(id, 'the portal has an id').not.toBe('')
	const { '@self': _self, ...fields } = portal
	const res = await request.put(`${OR_OBJECTS}/portal/${id}`, {
		headers: { ...ADMIN_HEADERS, 'Content-Type': 'application/json' },
		data: { ...fields, traffic },
	})
	expect(res.status(), 'the portal write is accepted').toBeLessThan(300)
}

/**
 * The measured portal's rollups.
 *
 * @param request The request context.
 */
async function rollups(
	request: APIRequestContext,
): Promise<Array<Record<string, unknown>>> {
	const res = await request.get(
		`${OR_OBJECTS}/portalTrafficDaily?portal=${ENABLED}&_limit=100`,
		{ headers: ADMIN_HEADERS },
	)
	expect(res.status()).toBe(200)
	const body = await res.json()
	const rows = Array.isArray(body) ? body : (body.results ?? [])
	// All visits only: a segment's record (portal-traffic-reporting) is a
	// second row for the same day, and this spec counts days.
	return rows.filter(
		(r: Record<string, unknown>) =>
			r.portal === ENABLED && String(r.segment ?? '') === '',
	)
}

/**
 * The newest raw events for the measured portal.
 *
 * @param request The request context.
 */
async function newestEvents(
	request: APIRequestContext,
): Promise<Array<Record<string, unknown>>> {
	const res = await request.get(
		`${OR_OBJECTS}/portalTrafficEvent?portal=${ENABLED}&_limit=500&_order[receivedAt]=desc`,
		{ headers: ADMIN_HEADERS },
	)
	expect(res.status()).toBe(200)
	const body = await res.json()
	const rows = Array.isArray(body) ? body : (body.results ?? [])
	return rows
		.filter((r: Record<string, unknown>) => r.portal === ENABLED)
		.sort((a: Record<string, unknown>, b: Record<string, unknown>) =>
			String(b.receivedAt ?? '').localeCompare(String(a.receivedAt ?? '')),
		)
}

/**
 * Sign in as the admin.
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
 * The number in a stats tile.
 *
 * @param page The page.
 * @param testId The tile's test id.
 */
async function tile(page: Page, testId: string): Promise<number> {
	// The value span only: the card also carries the range label ("30
	// days"), whose digits would otherwise glue onto the count.
	const text = await page
		.getByTestId(testId)
		.locator('.cn-stats-block__count-value')
		.innerText()
	return Number(text.replace(/[^\d]/g, '') || '0')
}

test.describe('traffic visitors and geography', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(() => {
		test.skip(CONTAINER === '', 'set E2E_CONTAINER to the Nextcloud container')
		// The runner's requests reach the container from the docker bridge
		// gateway. Trusting THAT address, and only that address, lets
		// X-Forwarded-For name the test address, which is the only way a
		// documented address can reach the resolver from a Playwright run.
		// Not 0.0.0.0/0: Nextcloud walks the header from the right and
		// skips every hop that is itself a trusted proxy, so trusting the
		// world makes the forwarded address a proxy too and the collector
		// falls back to the gateway. Throwaway instance only.
		occ('config:system:delete', 'trusted_proxies')
		occ('config:system:set', 'trusted_proxies', '0', `--value=${gateway()}`)
	})

	test.afterAll(async ({ request }) => {
		if (CONTAINER === '') {
			return
		}
		await setTraffic(request, seededTraffic())
		occ('config:app:set', 'portaliq', 'traffic.geo.provider', '--value=dbip')
		occ('config:system:delete', 'trusted_proxies')
	})

	// @e2e portal-traffic-visitors-and-geo::the-command-with-provider-none-says-so-and-exits-0
	test('occ portaliq:traffic:geo-refresh with provider none says geography is disabled and exits 0', () => {
		occ('config:app:set', 'portaliq', 'traffic.geo.provider', '--value=none')
		// execFileSync throws on a non-zero exit, so reaching the assertion
		// IS the exit code check.
		const out = occ('portaliq:traffic:geo-refresh')
		expect(out).toContain('Provider: none')
		expect(out.toLowerCase()).toContain('geography is disabled')
		occ('config:app:set', 'portaliq', 'traffic.geo.provider', '--value=dbip')
	})

	// @e2e portal-traffic-visitors-and-geo::nothing-is-stored-at-granularity-none
	test('at regionGranularity none the stored event carries no region', async ({
		request,
	}) => {
		await setTraffic(
			request,
			seededTraffic({
				regionGranularity: 'none',
				dimensions: [...(seededTraffic().dimensions as string[]), 'region'],
			}),
		)
		const marker = `/geo-none-${Date.now()}`
		const res = await post(request, [event(0, marker)], {
			'X-Forwarded-For': GB_ADDRESS,
		})
		expect(res.status()).toBe(204)

		const stored = (await newestEvents(request)).find((r) =>
			String(r.pageLocation ?? '').endsWith(marker),
		)
		expect(stored, 'the event was stored').toBeTruthy()
		expect(stored!.region ?? '').toBe('')
		expect(JSON.stringify(stored)).not.toContain(GB_ADDRESS)
	})

	// @e2e portal-traffic-visitors-and-geo::a-country-is-derived-from-a-documented-test-address
	// @e2e portal-traffic-visitors-and-geo::regions-are-listed-from-the-rollups
	test('with the fixture database and regionGranularity country, 81.2.69.160 becomes GB in the event and the rollup', async ({
		request,
		page,
	}) => {
		// Install the fixture where GeoDatabaseStore looks, with its
		// attribution beside it, as an operator installing by hand would.
		const instance = occ('config:system:get', 'instanceid').trim()
		const dir = `/var/www/html/data/appdata_${instance}/portaliq/geo`
		shell(`mkdir -p ${dir}`)
		execFileSync('docker', [
			'cp',
			FIXTURE,
			`${CONTAINER}:${dir}/traffic-geo.mmdb`,
		])
		shell(
			`printf '%s' '{"provider":"fixture","attribution":"MaxMind GeoIP2-City test database (Apache-2.0)","source":"tests/fixtures","databaseType":"GeoIP2-City","fetchedAt":"2026-09-04T00:00:00Z"}' > ${dir}/traffic-geo.json && chown -R www-data:www-data ${dir}`,
		)

		await setTraffic(
			request,
			seededTraffic({
				regionGranularity: 'country',
				dimensions: [...(seededTraffic().dimensions as string[]), 'region'],
			}),
		)

		const marker = `/geo-gb-${Date.now()}`
		const res = await post(
			request,
			[event(0, marker), event(1, `${marker}/next`)],
			{
				'X-Forwarded-For': GB_ADDRESS,
			},
		)
		expect(res.status()).toBe(204)

		const stored = (await newestEvents(request)).find((r) =>
			String(r.pageLocation ?? '').endsWith(marker),
		)
		expect(stored, 'the event was stored').toBeTruthy()
		expect(stored!.region).toBe('GB')
		expect(JSON.stringify(stored)).not.toContain(GB_ADDRESS)

		aggregate()
		const today = new Date().toISOString().substring(0, 10)
		const rollup = (await rollups(request)).find((r) => r.date === today)
		expect(rollup, 'a rollup for today').toBeTruthy()
		const regions = (rollup!.regions ?? {}) as Record<string, number>
		expect(Number(regions.GB ?? 0)).toBeGreaterThan(0)

		// The Visitors widget lists it.
		await login(page)
		await page.goto(`${APP}/traffic`)
		const list = page.getByTestId('traffic-breakdown-region')
		await expect(list).toBeVisible({ timeout: 30_000 })
		await expect(list).toContainText('GB', { timeout: 30_000 })
	})

	// @e2e portal-traffic-visitors-and-geo::cookieless-returning-visitors-are-not-available
	test('a cookieless portal reports returningVisitors as null and the page says not available', async ({
		request,
		page,
	}) => {
		const today = new Date().toISOString().substring(0, 10)
		const rollup = (await rollups(request)).find((r) => r.date === today)
		expect(rollup, 'run the geography test first').toBeTruthy()
		expect(Number(rollup!.visitors)).toBeGreaterThan(0)
		// The rollup writes null; OpenRegister hands a null field back as an
		// absent one. Both are "not available"; neither is a zero.
		expect(rollup!.returningVisitors ?? null, 'null, never zero').toBeNull()
		expect(rollup!.newVisitors ?? null).toBeNull()

		await login(page)
		await page.goto(`${APP}/traffic`)
		await expect(page.getByTestId('traffic-visitors-cookieless')).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			page.getByTestId('traffic-tile-returning-visitors'),
		).toHaveCount(0)
		await expect(page.getByTestId('traffic-tile-visitors-total')).toBeVisible()
	})

	// @e2e portal-traffic-visitors-and-geo::the-range-changes-the-numbers
	test('the period selector changes the page view count, and a custom day shows that day alone', async ({
		request,
		page,
	}) => {
		// Three views dated yesterday, so two days carry numbers.
		const yesterday = new Date(Date.now() - 86_400_000)
		yesterday.setUTCHours(12, 0, 0, 0)
		const res = await post(request, [
			event(0, '/gisteren', yesterday),
			event(1, '/gisteren/2', new Date(yesterday.getTime() + 20_000)),
			event(2, '/gisteren/3', new Date(yesterday.getTime() + 40_000)),
		])
		expect(res.status()).toBe(204)
		aggregate()

		const day = yesterday.toISOString().substring(0, 10)
		const daily = (await rollups(request)).find((r) => r.date === day)
		expect(daily, "yesterday's rollup exists").toBeTruthy()
		const yesterdayViews = Number(daily!.pageViews)
		expect(yesterdayViews).toBeGreaterThanOrEqual(3)

		await login(page)
		await page.goto(`${APP}/traffic`)
		await expect(page.getByTestId('traffic-tile-page-views')).toBeVisible({
			timeout: 30_000,
		})
		const thirty = await tile(page, 'traffic-tile-page-views')
		expect(thirty).toBeGreaterThan(yesterdayViews)

		// Last 7 days covers both days: the same total.
		const range = page.getByTestId('traffic-range-select')
		await range.locator('input').first().click()
		await range.locator('input').first().fill('Last 7')
		await page.keyboard.press('Enter')
		await expect(page.getByTestId('traffic-tile-page-views')).toContainText(
			'7 days',
		)
		expect(await tile(page, 'traffic-tile-page-views')).toBe(thirty)

		// A custom period of yesterday alone shows yesterday's figure.
		await range.locator('input').first().click()
		await range.locator('input').first().fill('Custom')
		await page.keyboard.press('Enter')
		// The native picker puts the id on the input itself.
		await page.locator('input#traffic-range-from').fill(day)
		await page.locator('input#traffic-range-to').fill(day)
		await expect(page.getByTestId('traffic-tile-page-views')).toContainText(
			'1 day',
			{ timeout: 15_000 },
		)
		expect(await tile(page, 'traffic-tile-page-views')).toBe(yesterdayViews)
	})

	// @e2e portal-traffic-visitors-and-geo::the-reports-card-opens-the-page
	test('the Traffic card on Reports opens the Traffic page', async ({ page }) => {
		await login(page)
		await page.goto(`${APP}/reports`)
		const card = page
			.locator('main, .app-content')
			.first()
			.getByText('Traffic', { exact: true })
			.first()
		await expect(card).toBeVisible({ timeout: 30_000 })
		await card.click()
		await expect(page).toHaveURL(/\/apps\/portaliq\/traffic(\?|$)/, {
			timeout: 15_000,
		})
		await expect(page.getByTestId('traffic-overview')).toBeVisible({
			timeout: 30_000,
		})
	})

	// @e2e portal-traffic-visitors-and-geo::a-settings-read-says-a-key-is-stored-not-what-it-is
	test('a saved MaxMind licence key is reported as stored, never returned', async ({
		request,
	}) => {
		const secret = `e2e-secret-${Date.now()}`
		const put = await request.put(`${APP}/api/settings`, {
			headers: { ...ADMIN_HEADERS, 'Content-Type': 'application/json' },
			data: {
				traffic_geo: {
					provider: 'dbip',
					maxmindAccountId: '123456',
					maxmindLicenseKey: secret,
					maxmindEdition: 'GeoLite2-City',
				},
			},
		})
		expect(put.status()).toBe(200)
		expect(await put.text()).not.toContain(secret)

		const get = await request.get(`${APP}/api/settings`, {
			headers: ADMIN_HEADERS,
		})
		expect(get.status()).toBe(200)
		const text = await get.text()
		expect(text).not.toContain(secret)
		const geo = (await get.json()).traffic_geo
		expect(geo.maxmindLicenseKeySet).toBe(true)
		expect(geo.maxmindAccountId).toBe('123456')
		expect(geo).not.toHaveProperty('maxmindLicenseKey')

		// Clean up: an empty key removes it.
		await request.put(`${APP}/api/settings`, {
			headers: { ...ADMIN_HEADERS, 'Content-Type': 'application/json' },
			data: { traffic_geo: { maxmindLicenseKey: '', maxmindAccountId: '' } },
		})
	})
})
