/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-traffic-analytics, phase 0: the collector, the served client, the
 * built-in site loading it, the aggregation job, and the Traffic page.
 *
 * Two seeded portals carry the whole suite (tests/e2e/fixtures/seed-cms.sh):
 * `open-tilburg` MEASURES (page_view, session_start, scroll, outbound_click,
 * search) and owns the `localhost` host; `open-venray` has measurement OFF
 * and owns `venray.localhost`. Every refusal is asserted through
 * `/api/metrics`, because the collector answers 204 to a refused event on
 * purpose: the client has no business learning which portals exist.
 *
 * The aggregation job runs inside the container (`occ background-job:execute
 * --force-execute`) when `E2E_CONTAINER` names it; without that the rollup
 * test skips with a stated reason rather than pretending.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const APP = `${BASE}/index.php/apps/portaliq`
const OR_OBJECTS = `${BASE}/index.php/apps/openregister/api/objects/portaliq`
const ENABLED = 'open-tilburg'
const DISABLED = 'open-venray'
const DISABLED_HOST = 'venray.localhost'
const ADMIN_USER = process.env.ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? 'admin'
const ADMIN_BASIC =
	'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
const CONTAINER = process.env.E2E_CONTAINER ?? ''

// A real desktop browser's user agent. Playwright's request context announces
// itself otherwise, and the collector refuses a crawler as `bot` before it
// looks at a single event, which would make every acceptance assertion here
// pass for the wrong reason or fail for an unrelated one.
const DESKTOP_UA =
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'

/**
 * One page view event as the client would post it.
 *
 * @param sequence The in-session sequence.
 * @param path The page path.
 * @param name The event name.
 */
function event(
	sequence: number,
	path: string,
	name = 'page_view',
): Record<string, unknown> {
	return {
		name,
		timestamp: new Date().toISOString(),
		sequence,
		pageLocation: `${BASE}${path}`,
		pageReferrer: 'https://www.google.nl/',
		pageTitle: 'Open Tilburg',
		params: {},
	}
}

/**
 * Post a batch to the collector as text/plain, the way sendBeacon does.
 *
 * @param request The request context.
 * @param body The envelope, already serialised.
 * @param headers Extra headers.
 */
async function post(
	request: APIRequestContext,
	body: string,
	headers: Record<string, string> = {},
) {
	return request.post(`${APP}/api/traffic`, {
		data: body,
		headers: {
			'Content-Type': 'text/plain',
			'User-Agent': DESKTOP_UA,
			...headers,
		},
	})
}

/**
 * Read one counter off the admin metrics scrape; 0 when the line is absent.
 *
 * @param request The request context.
 * @param name The metric name.
 * @param labels The label selector, e.g. `{reason="bot"}`.
 */
async function metric(
	request: APIRequestContext,
	name: string,
	labels = '',
): Promise<number> {
	const res = await request.get(`${APP}/api/metrics`, {
		headers: { Authorization: ADMIN_BASIC, 'OCS-APIRequest': 'true' },
	})
	expect(res.status(), 'the admin metrics scrape must answer').toBe(200)
	const text = await res.text()
	const line = text.split('\n').find((l) => l.startsWith(`${name}${labels} `))
	return line ? Number(line.substring(line.lastIndexOf(' ') + 1)) : 0
}

/**
 * Sign in to Nextcloud as the admin.
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
 * Run occ inside the container named by E2E_CONTAINER.
 *
 * @param args The occ arguments.
 */
function occ(...args: string[]): string {
	return execFileSync(
		'docker',
		['exec', '-u', 'www-data', CONTAINER, 'php', 'occ', ...args],
		{
			encoding: 'utf8',
			timeout: 300_000,
		},
	)
}

test.describe('traffic analytics: the collector and the client', () => {
	// @e2e portal-traffic-analytics::an-anonymous-request-for-the-client
	test('anonymous GET /api/traffic-client.js serves the client as JavaScript', async ({
		request,
	}) => {
		const res = await request.get(`${APP}/api/traffic-client.js`)

		expect(res.status()).toBe(200)
		expect(res.headers()['content-type']).toContain('application/javascript')
		expect(res.headers()['cache-control']).toContain('max-age=3600')
		expect(res.headers().etag).toBeTruthy()
		expect(await res.text()).toContain('portaliqTraffic')
	})

	// @e2e portal-traffic-analytics::a-client-reads-its-configuration
	test('the site record carries traffic.enabled and the collector for both portals', async ({
		request,
	}) => {
		const enabled = await request.get(
			`${APP}/api/content/site?portal=${ENABLED}`,
		)
		expect(enabled.status()).toBe(200)
		const tilburg = await enabled.json()
		expect(tilburg.traffic.enabled).toBe(true)
		expect(tilburg.traffic.events).toContain('page_view')
		expect(tilburg.traffic.events).not.toContain('form_submit')
		expect(tilburg.traffic.persistClientId).toBe(false)
		expect(tilburg.traffic.sensitive).toEqual({
			persistClientId: false,
			accountLinking: false,
			heatmaps: false,
			sessionRecording: false,
		})
		// With or without index.php: an instance with pretty URLs drops it.
		expect(String(tilburg.collector)).toMatch(/\/apps\/portaliq\/api\/traffic$/)

		const disabled = await request.get(
			`${APP}/api/content/site?portal=${DISABLED}`,
		)
		expect(disabled.status()).toBe(200)
		expect((await disabled.json()).traffic.enabled).toBe(false)
	})

	// @e2e portal-traffic-analytics::a-static-site-on-its-own-domain-posts-a-batch
	test('a cross-origin caller gets the origin reflected without credentials', async ({
		request,
	}) => {
		const res = await request.get(`${APP}/api/content/site?portal=${ENABLED}`, {
			headers: { Origin: 'https://docs.example' },
		})

		expect(res.status()).toBe(200)
		expect(res.headers()['access-control-allow-origin']).toBe(
			'https://docs.example',
		)
		expect(res.headers().vary).toContain('Origin')
		expect(res.headers()['access-control-allow-credentials']).toBeUndefined()
	})

	// @e2e portal-traffic-analytics::an-accepted-batch-answers-without-a-body-or-a-cookie
	test('a valid batch is accepted: 204, no body, no Set-Cookie', async ({
		request,
	}) => {
		const before = await metric(request, 'portaliq_traffic_accepted_total')

		const res = await post(
			request,
			JSON.stringify({
				portal: ENABLED,
				consent: true,
				events: [
					event(0, '/'),
					event(1, '/over-ons'),
					event(2, '/over-ons', 'scroll'),
				],
			}),
		)

		expect(res.status()).toBe(204)
		expect(await res.text()).toBe('')
		expect(res.headers()['set-cookie']).toBeUndefined()
		expect(res.headers()['cache-control']).toContain('no-store')

		const after = await metric(request, 'portaliq_traffic_accepted_total')
		expect(after, 'the accepted counter moved by the three events').toBe(
			before + 3,
		)
	})

	// @e2e portal-traffic-analytics::an-unlisted-event-is-refused-not-silently-dropped
	test('an event the portal did not enable is refused and COUNTED', async ({
		request,
	}) => {
		const before = await metric(
			request,
			'portaliq_traffic_refused_total',
			'{reason="event-not-enabled"}',
		)

		// form_submit is a known event; Open Tilburg simply did not enable it.
		const res = await post(
			request,
			JSON.stringify({
				portal: ENABLED,
				consent: true,
				events: [event(0, '/contact', 'form_submit')],
			}),
		)

		expect(
			res.status(),
			'a per-event refusal is not reported to the client',
		).toBe(204)
		const after = await metric(
			request,
			'portaliq_traffic_refused_total',
			'{reason="event-not-enabled"}',
		)
		expect(after).toBe(before + 1)
	})

	// @e2e portal-traffic-analytics::a-browser-claims-a-mail-event
	test('a browser cannot claim a mail event', async ({ request }) => {
		const before = await metric(
			request,
			'portaliq_traffic_refused_total',
			'{reason="event-server-side-only"}',
		)

		const res = await post(
			request,
			JSON.stringify({
				portal: ENABLED,
				consent: true,
				events: [event(0, '/', 'email_open')],
			}),
		)

		expect(res.status()).toBe(204)
		expect(
			await metric(
				request,
				'portaliq_traffic_refused_total',
				'{reason="event-server-side-only"}',
			),
		).toBe(before + 1)
	})

	// @e2e portal-traffic-analytics::measurement-is-disabled-for-a-portal
	test('a batch for the disabled portal is refused as measurement-disabled', async ({
		request,
	}) => {
		// The collector resolves the portal by HOST first, so naming Venray's
		// slug from Tilburg's host would land on Tilburg. The request carries
		// Venray's verified host instead. An instance that does not trust that
		// host answers 400 before the app runs; that is a fixture gap, not a
		// collector verdict, and is reported as such.
		const before = await metric(
			request,
			'portaliq_traffic_refused_total',
			'{reason="measurement-disabled"}',
		)

		const res = await post(
			request,
			JSON.stringify({
				portal: DISABLED,
				consent: true,
				events: [event(0, '/')],
			}),
			{ Host: DISABLED_HOST },
		)
		test.skip(
			res.status() === 400
				&& (await res.text()).toLowerCase().includes('trusted'),
			`${DISABLED_HOST} is not a trusted domain on this instance; add it to trusted_domains to run this test`,
		)

		expect(res.status()).toBe(204)
		const after = await metric(
			request,
			'portaliq_traffic_refused_total',
			'{reason="measurement-disabled"}',
		)
		expect(after).toBe(before + 1)
	})

	// @e2e portal-traffic-analytics::an-oversized-or-malformed-batch
	test('fifty-one events, or malformed JSON, is refused whole with a 400', async ({
		request,
	}) => {
		const events = []
		for (let i = 0; i < 51; i++) {
			events.push(event(i, `/p${i}`))
		}
		const before = await metric(request, 'portaliq_traffic_accepted_total')

		const big = await post(
			request,
			JSON.stringify({ portal: ENABLED, consent: true, events }),
		)
		expect(big.status()).toBe(400)
		expect((await big.json()).error).toBe('batch-too-large')

		const broken = await post(request, '{"events": [')
		expect(broken.status()).toBe(400)
		expect((await broken.json()).error).toBe('malformed-batch')

		expect(
			await metric(request, 'portaliq_traffic_accepted_total'),
			'nothing partial was stored',
		).toBe(before)
	})

	// @e2e portal-traffic-analytics::a-bot-is-not-a-visitor
	test('a crawler is refused as bot and counted', async ({ request }) => {
		const before = await metric(
			request,
			'portaliq_traffic_refused_total',
			'{reason="bot"}',
		)

		const res = await post(
			request,
			JSON.stringify({ portal: ENABLED, events: [event(0, '/')] }),
			{
				'User-Agent':
					'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
			},
		)

		expect(res.status()).toBe(204)
		expect(
			await metric(
				request,
				'portaliq_traffic_refused_total',
				'{reason="bot"}',
			),
		).toBe(before + 1)
	})

	// @e2e portal-traffic-analytics::the-served-site-loads-the-client
	// @e2e portal-traffic-analytics::the-default-mode-stores-nothing-in-the-browser
	test('the built-in site loads the client, a page view reaches the collector, and nothing is stored', async ({
		page,
	}) => {
		const collected = page.waitForRequest(
			(req) =>
				req.method() === 'POST'
				&& req.url().includes('/apps/portaliq/api/traffic'),
			{ timeout: 20_000 },
		)

		await page.goto(`${APP}/site?portal=${ENABLED}`)
		await expect(page.getByTestId('site-title')).toHaveText('Open Tilburg')

		const tag = page.locator('script[src*="/api/traffic-client.js"]')
		await expect(tag).toHaveCount(1)

		const request = await collected
		const body = JSON.parse(request.postData() ?? '{}')
		expect(body.events[0].name).toBe('page_view')
		expect(body.events[0].pageLocation).toContain('/apps/portaliq/site')

		// Cookieless: NOTHING in local or session storage, and no cookie of
		// this client's. A script that stores an id and sends nothing still
		// sets a cookie, which is why both halves are asserted.
		const stored = await page.evaluate(() => ({
			local: Object.keys(window.localStorage).filter((k) =>
				k.startsWith('portaliq-traffic'),
			),
			session: Object.keys(window.sessionStorage).filter((k) =>
				k.startsWith('portaliq-traffic'),
			),
			cookie: document.cookie,
		}))
		expect(stored.local).toEqual([])
		expect(stored.session).toEqual([])
		expect(stored.cookie).not.toContain('portaliq-traffic')
	})
})

test.describe('traffic analytics: the rollup and the Traffic page', () => {
	// @e2e portal-traffic-analytics::a-rollup-exists-after-the-job
	// @e2e portal-traffic-analytics::the-job-runs-twice
	test('the aggregation job writes one portalTrafficDaily per portal-day, and twice gives the same', async ({
		request,
	}) => {
		test.skip(
			CONTAINER === '',
			'set E2E_CONTAINER to the Nextcloud container to run the aggregation job',
		)

		// Two events that the job will fold into one day, one session.
		const seeded = await post(
			request,
			JSON.stringify({
				portal: ENABLED,
				consent: true,
				events: [event(0, '/'), event(1, '/begrippen')],
			}),
		)
		expect(seeded.status()).toBe(204)

		const jobs = JSON.parse(
			occ('background-job:list', '--output=json', '--limit', '500'),
		) as Array<{
			id: number
			class: string
		}>
		const job = jobs.find(
			(j) => j.class === 'OCA\\Portaliq\\BackgroundJob\\TrafficAggregationJob',
		)
		expect(job, 'the job is registered from info.xml on app:enable').toBeTruthy()

		occ('background-job:execute', String(job!.id), '--force-execute')
		const first = await rollups(request)
		occ('background-job:execute', String(job!.id), '--force-execute')
		const second = await rollups(request)

		const today = new Date().toISOString().substring(0, 10)
		const todays = first.filter((r) => r.date === today)
		expect(todays, 'exactly one rollup for today').toHaveLength(1)
		expect(Number(todays[0].pageViews)).toBeGreaterThan(0)
		expect(Number(todays[0].sessions)).toBeGreaterThan(0)
		expect(second.filter((r) => r.date === today)).toHaveLength(1)
		expect(second.find((r) => r.date === today)?.pageViews).toBe(
			todays[0].pageViews,
		)
	})

	// @e2e portal-traffic-analytics::measurement-is-disabled-for-a-portal
	// @e2e portal-traffic-analytics::the-warning-is-shown-where-the-switch-is
	test('the Traffic page shows the numbers for the measured portal and "not measured" for the other', async ({
		page,
		request,
	}) => {
		test.skip(
			CONTAINER === '',
			'the page needs the rollup the job writes; set E2E_CONTAINER',
		)
		expect(
			(await rollups(request)).length,
			'run the rollup test first',
		).toBeGreaterThan(0)

		await login(page)
		await page.goto(`${APP}/traffic`)

		const overview = page.getByTestId('traffic-overview')
		await expect(overview).toBeVisible({ timeout: 30_000 })
		await expect(page.getByTestId('traffic-tile-page-views')).toBeVisible({
			timeout: 30_000,
		})
		const views = await page.getByTestId('traffic-tile-page-views').innerText()
		expect(views.match(/\d+/)?.[0] ?? '0').not.toBe('0')
		await expect(page.getByTestId('traffic-daily-chart')).toBeVisible()
		await expect(page.getByTestId('traffic-not-measured')).toHaveCount(0)
		// No sensitive switch is on for the seeded portal: no warning card.
		await expect(page.getByTestId('traffic-sensitive-warning')).toHaveCount(0)

		// Switch to the portal that does not measure.
		const select = page.getByTestId('traffic-portal-select')
		await select.locator('input').first().click()
		await select.locator('input').first().fill('Venray')
		await page.keyboard.press('Enter')

		await expect(page.getByTestId('traffic-not-measured').first()).toBeVisible({
			timeout: 15_000,
		})
		await expect(page.getByTestId('traffic-daily-chart')).toHaveCount(0)
		await expect(page.getByTestId('traffic-tile-page-views')).toHaveCount(0)
	})
})

/**
 * The measured portal's rollups, read as the admin through the object API.
 *
 * @param request The request context.
 */
async function rollups(
	request: APIRequestContext,
): Promise<Array<Record<string, unknown>>> {
	const res = await request.get(
		`${OR_OBJECTS}/portalTrafficDaily?portal=${ENABLED}&_limit=100`,
		{
			headers: { Authorization: ADMIN_BASIC, 'OCS-APIRequest': 'true' },
		},
	)
	expect(res.status()).toBe(200)
	const body = await res.json()
	const rows = Array.isArray(body) ? body : (body.results ?? [])
	return rows.filter((r: Record<string, unknown>) => r.portal === ENABLED)
}
