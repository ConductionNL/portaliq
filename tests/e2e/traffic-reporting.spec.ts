/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-traffic-reporting, phase 4a: segments, roll-ups, scheduled
 * reports and alerts, the export, the server API, the log import and
 * script errors.
 *
 * Runs after traffic-outcomes.spec.ts and before traffic-visitors.spec.ts
 * (alphabetical, one worker) against the same seeded portal, plus the
 * roll-up portal the seed creates. Everything here needs the container
 * (the jobs, the occ commands), so the whole file skips with a stated
 * reason when E2E_CONTAINER is unset.
 *
 * ISOLATION. Paths, report ids and alert ids carry a run-unique suffix so
 * a rerun on the same day reads its own events and its own period keys.
 * The seeded traffic block is put back in afterAll, and the aggregation
 * is run once more so the segment record this spec created is deleted
 * again (a segment that is gone has no record): the other specs count
 * "exactly one rollup for today".
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import {
	ADMIN_HEADERS,
	aggregate,
	APP,
	BASE,
	CONTAINER,
	DESKTOP_UA,
	ENABLED,
	event,
	login,
	newestEvents,
	nextBeacon,
	occ,
	post,
	reportJob,
	ROLLUP,
	rollupsOf,
	seededTraffic,
	setTraffic,
	today,
	todaysRollup,
} from './lib/traffic.ts'

/**
 * A run-unique suffix.
 */
const RUN = Date.now().toString(36)
const PATH = `/reporting-${RUN}`
const MOBILE_UA =
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'

/**
 * A visitor of its own: the agent is half the daily hash.
 *
 * @param tag Which visitor.
 * @param base The base agent.
 */
function visitor(tag: string, base = DESKTOP_UA): Record<string, string> {
	return { 'User-Agent': `${base} reporting/${RUN}/${tag}` }
}

/**
 * The traffic block this spec proves against: the seed plus a desktop
 * segment, a daily report, an `above` alert and the js_error event.
 */
function reportingTraffic(): Record<string, unknown> {
	const seeded = seededTraffic()
	return seededTraffic({
		events: [...(seeded.events as string[]), 'js_error'],
		segments: [
			{
				id: 'desktop',
				name: 'Desktop visitors',
				conditions: [
					{ dimension: 'deviceType', operator: 'is', value: 'desktop' },
				],
			},
		],
		reports: [
			{
				id: `report-${RUN}`,
				name: `Daily report ${RUN}`,
				cadence: 'daily',
				recipients: ['admin'],
				sections: ['overview', 'pages', 'sources'],
			},
		],
		alerts: [
			{
				id: `alert-${RUN}`,
				name: `Busy day ${RUN}`,
				metric: 'pageViews',
				comparison: 'above',
				threshold: 0,
				period: 'day',
				recipients: ['admin'],
			},
		],
	})
}

/**
 * The first number in a tile's text ("Page views 1.234 in 30 days" is
 * 1234): the count comes before the range label.
 *
 * @param text The tile's inner text.
 */
function firstNumber(text: string): number {
	const match = text.match(/\d[\d.,]*/)
	return match ? Number(match[0].replace(/[.,]/g, '')) : -1
}

/**
 * Whether a day is in the page's default range: the last 30 days.
 *
 * @param date The day, YYYY-MM-DD.
 */
function inRange(date: string): boolean {
	const start = new Date(Date.now() - 29 * 86400000).toISOString().substring(0, 10)
	return date >= start && date <= today()
}

/**
 * The admin's Portaliq notifications whose subject carries a text.
 *
 * @param request The request context.
 * @param text The text the rendered subject carries.
 */
async function notificationsWith(
	request: APIRequestContext,
	text: string,
): Promise<Array<Record<string, unknown>>> {
	const res = await request.get(
		`${BASE}/ocs/v2.php/apps/notifications/api/v2/notifications?format=json`,
		{ headers: ADMIN_HEADERS },
	)
	expect(res.status()).toBe(200)
	const body = await res.json()
	const rows = (body.ocs?.data ?? []) as Array<Record<string, unknown>>
	return rows.filter(
		(n) => n.app === 'portaliq' && String(n.subject ?? '').includes(text),
	)
}

test.describe('traffic reporting: segments, roll-ups, reports, alerts, export, server API, log import and errors', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		test.skip(CONTAINER === '', 'set E2E_CONTAINER to the Nextcloud container')
		await setTraffic(request, reportingTraffic())
	})

	test.afterAll(async ({ request }) => {
		if (CONTAINER === '') {
			return
		}
		await setTraffic(request, seededTraffic())
		aggregate()
	})

	// @e2e portal-traffic-reporting::a-segment-produces-its-own-daily-record-and-the-page-switches-to-it
	test('a deviceType segment produces a second daily record and the page switches to it', async ({
		request,
		page,
	}) => {
		const desktop = await post(
			request,
			[event(0, PATH), event(1, `${PATH}/b`)],
			visitor('desktop'),
		)
		expect(desktop.status()).toBe(204)
		const mobile = await post(
			request,
			[event(0, `${PATH}/m`)],
			visitor('mobile', MOBILE_UA),
		)
		expect(mobile.status()).toBe(204)
		aggregate()

		const all = await todaysRollup(request)
		const segmentRows = await rollupsOf(request, ENABLED, 'desktop')
		const segment = segmentRows.find((r) => r.date === today())
		expect(segment, "the segment's record for today exists").toBeTruthy()
		expect(segment!.segment).toBe('desktop')
		expect(Number(segment!.pageViews)).toBeGreaterThanOrEqual(2)
		expect(Number(segment!.pageViews)).toBeLessThan(Number(all.pageViews))
		// The devices map is the proof the filter held: the segment's day
		// has desktop sessions only, while the day as a whole has the
		// mobile one too. (The pages list is a top hundred and a busy rig
		// pushes a one-view path out of it, so it is not the witness.)
		const devices = segment!.devices as Record<string, number>
		expect(devices).not.toHaveProperty('mobile')
		expect(Number(devices.desktop)).toBeGreaterThanOrEqual(1)
		expect(
			Number((all.devices as Record<string, number>).mobile),
		).toBeGreaterThanOrEqual(1)

		// The page: the tile shows the segment's page views over the range,
		// which is the sum of the segment's daily records.
		const expected = segmentRows
			.filter((r) => inRange(String(r.date)))
			.reduce((sum, r) => sum + Number(r.pageViews || 0), 0)
		await login(page)
		await page.goto(`${APP}/traffic`)
		const select = page.getByTestId('traffic-segment-select')
		await expect(select).toBeVisible({ timeout: 30_000 })
		await select.locator('input').first().click()
		await select.locator('input').first().fill('Desktop')
		await page.keyboard.press('Enter')
		await expect
			.poll(
				async () => {
					const text = await page
						.getByTestId('traffic-tile-page-views')
						.innerText()
					return firstNumber(text)
				},
				{ timeout: 15_000 },
			)
			.toBe(expected)
	})

	// @e2e portal-traffic-reporting::a-roll-up-portal-shows-the-summed-page-views
	test('the roll-up portal sums its members and the page says so', async ({
		request,
		page,
	}) => {
		aggregate()
		const tilburg = await todaysRollup(request)
		const venray = (await rollupsOf(request, 'open-venray')).find(
			(r) => r.date === today(),
		)
		const summed = (await rollupsOf(request, ROLLUP)).find(
			(r) => r.date === today(),
		)
		expect(summed, "the roll-up's record for today exists").toBeTruthy()
		expect(summed!.pageViews).toBe(
			Number(tilburg.pageViews) + Number(venray?.pageViews ?? 0),
		)
		expect(summed!.rollupOf).toEqual(['open-tilburg', 'open-venray'])
		expect(summed!.members).toBe(venray ? 2 : 1)

		const expected = (await rollupsOf(request, ROLLUP))
			.filter((r) => inRange(String(r.date)))
			.reduce((sum, r) => sum + Number(r.pageViews || 0), 0)
		await login(page)
		await page.goto(`${APP}/traffic`)
		const select = page.getByTestId('traffic-portal-select')
		await expect(select).toBeVisible({ timeout: 30_000 })
		await select.locator('input').first().click()
		await select.locator('input').first().fill('Venray samen')
		await page.keyboard.press('Enter')
		const note = page.getByTestId('traffic-rollup-note')
		await expect(note).toBeVisible({ timeout: 15_000 })
		await expect(note).toContainText('Roll-up of 2 portals')
		await expect
			.poll(
				async () => {
					const text = await page
						.getByTestId('traffic-tile-page-views')
						.innerText()
					return firstNumber(text)
				},
				{ timeout: 15_000 },
			)
			.toBe(expected)
	})

	// @e2e portal-traffic-reporting::a-due-daily-report-is-sent-once-and-appears-as-a-notification
	test('a due daily report is sent once and appears as a notification for the recipient', async ({
		request,
	}) => {
		reportJob()
		let found = await notificationsWith(request, `Daily report ${RUN}`)
		expect(found, 'the report reached the admin as a notification').toHaveLength(
			1,
		)
		expect(String(found[0].link)).toContain('/apps/portaliq/traffic')

		reportJob()
		found = await notificationsWith(request, `Daily report ${RUN}`)
		expect(found, 'the same period is not sent twice').toHaveLength(1)
	})

	// @e2e portal-traffic-reporting::an-alert-crosses-its-threshold-and-appears-as-a-notification-once
	test('an alert that crossed its threshold appears as a notification once', async ({
		request,
	}) => {
		// The first report job run above already evaluated the alert with
		// today's page views above zero, so it fired there; a second and
		// a third run must not add another.
		reportJob()
		const found = await notificationsWith(request, `Busy day ${RUN}`)
		expect(found, 'the alert fired once for today').toHaveLength(1)
		expect(String(found[0].message)).toContain('pageViews')
	})

	// @e2e portal-traffic-reporting::the-export-returns-csv-with-the-header-and-one-row-per-day
	test('the export returns CSV with the header and one row per day, and refuses an anonymous caller', async ({
		request,
		playwright,
	}) => {
		const rows = await rollupsOf(request)
		const dates = rows.map((r) => String(r.date)).sort()
		const from = dates[0]
		const to = today()
		const url = `${APP}/api/traffic/export?portal=${ENABLED}&from=${from}&to=${to}&format=csv`

		const res = await request.get(url, { headers: ADMIN_HEADERS })
		expect(res.status()).toBe(200)
		expect(res.headers()['content-type']).toContain('text/csv')
		expect(res.headers()['content-disposition']).toContain('attachment')
		const lines = (await res.text()).trim().split('\r\n')
		expect(lines[0]).toBe(
			'portal,date,segment,pageViews,sessions,visitors,newVisitors,returningVisitors,accounts,engagedSessions,avgEngagementSeconds,bounceRate,conversionRate',
		)
		const inRange = rows.filter(
			(r) => String(r.date) >= from && String(r.date) <= to,
		)
		expect(lines.length - 1).toBe(inRange.length)
		const todays = lines.find((l) => l.startsWith(`${ENABLED},${today()},,`))
		expect(todays, "today's row is there, for all visits").toBeTruthy()
		expect(todays!.split(',')[3]).toBe(
			String((await todaysRollup(request)).pageViews),
		)

		// A fresh context: the admin call above left a session cookie in
		// `request`, and an "anonymous" call from it would not be one.
		const fresh = await playwright.request.newContext()
		const anonymous = await fresh.get(url, { maxRedirects: 0 })
		expect(anonymous.status(), 'an anonymous caller is refused').toBe(401)
		await fresh.dispose()
		const bad = await request.get(
			`${APP}/api/traffic/export?portal=${ENABLED}&from=x&to=y`,
			{
				headers: ADMIN_HEADERS,
			},
		)
		expect(bad.status()).toBe(400)
	})

	// @e2e portal-traffic-reporting::the-server-api-accepts-a-valid-token-and-refuses-a-wrong-one
	test('the server API accepts a batch with the minted token and refuses a wrong one with 401', async ({
		request,
	}) => {
		const output = occ('portaliq:traffic:token', ENABLED)
		const token = output
			.split('\n')
			.map((l) => l.trim())
			.find((l) => /^[A-Za-z0-9_-]{43}$/.test(l))
		expect(token, 'the command printed the token').toBeTruthy()

		const serverPath = `${PATH}/server`
		const batch = {
			portal: ENABLED,
			consent: true,
			remoteAddress: '198.51.100.23',
			userAgent: `${DESKTOP_UA} reporting/${RUN}/server`,
			events: [event(0, serverPath)],
		}
		const accepted = await request.post(`${APP}/api/traffic/server`, {
			data: JSON.stringify(batch),
			headers: {
				'Content-Type': 'text/plain',
				Authorization: `Bearer ${token}`,
			},
		})
		expect(accepted.status()).toBe(204)
		await expect
			.poll(
				async () =>
					(await newestEvents(request)).some(
						(e) =>
							String(e.pagePath) === serverPath
							&& e.deviceType === 'desktop',
					),
				{ timeout: 20_000 },
			)
			.toBe(true)

		const wrong = await request.post(`${APP}/api/traffic/server`, {
			data: JSON.stringify(batch),
			headers: { 'Content-Type': 'text/plain', Authorization: 'Bearer nope' },
		})
		expect(wrong.status()).toBe(401)
		const missing = await request.post(`${APP}/api/traffic/server`, {
			data: JSON.stringify(batch),
			headers: { 'Content-Type': 'text/plain' },
		})
		expect(missing.status()).toBe(401)
	})

	// @e2e portal-traffic-reporting::a-sample-log-imports-and-the-rollup-counts-the-views
	test('the log import command imports a sample file and the rollup counts the views', async ({
		request,
	}) => {
		const importPath = `${PATH}/imported`
		const at = new Date(Date.now() - 120_000)
		const months = [
			'Jan',
			'Feb',
			'Mar',
			'Apr',
			'May',
			'Jun',
			'Jul',
			'Aug',
			'Sep',
			'Oct',
			'Nov',
			'Dec',
		]
		const pad = (n: number) => String(n).padStart(2, '0')
		const stamp = `${pad(at.getUTCDate())}/${months[at.getUTCMonth()]}/${at.getUTCFullYear()}:${pad(at.getUTCHours())}:${pad(at.getUTCMinutes())}:${pad(at.getUTCSeconds())} +0000`
		const line = (ip: string, path: string, ua: string) =>
			`${ip} - - [${stamp}] "GET ${path} HTTP/1.1" 200 512 "https://www.google.nl/" "${ua}"`
		const lines = [
			line('203.0.113.51', importPath, `${DESKTOP_UA} reporting/${RUN}/log-a`),
			line('203.0.113.52', importPath, `${DESKTOP_UA} reporting/${RUN}/log-b`),
			line(
				'203.0.113.52',
				`${importPath}/site.css`,
				`${DESKTOP_UA} reporting/${RUN}/log-b`,
			),
			line(
				'203.0.113.53',
				importPath,
				'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
			),
			line('203.0.113.51', importPath, `${DESKTOP_UA} reporting/${RUN}/log-a`),
		].join('\n')
		const file = `/tmp/traffic-${RUN}.log`
		execFileSync(
			'docker',
			['exec', '-i', CONTAINER, 'sh', '-c', `cat > ${file}`],
			{
				input: lines + '\n',
			},
		)

		const output = occ(
			'portaliq:traffic:import-log',
			ENABLED,
			file,
			'--format=combined',
			`--host=${BASE}`,
		)
		expect(output).toContain('Lines read: 5')
		expect(output).toContain('Page views found: 2')
		expect(output).toContain('Accepted: 2')

		aggregate()
		const daily = await todaysRollup(request)
		const row = (daily.pages as Array<Record<string, unknown>>).find(
			(p) => p.path === importPath,
		)
		expect(row, 'the imported path is on the rollup').toBeTruthy()
		expect(row!.views).toBe(2)
	})

	// @e2e portal-traffic-reporting::a-script-error-lands-and-the-page-lists-it
	test('a script error on the built-in site is reported without its stack and listed on the page', async ({
		page,
		request,
	}) => {
		const message = `reporting-error-${RUN}`
		const configured = page.waitForResponse((res) =>
			res.url().includes('/api/content/site'),
		)
		await page.goto(`${APP}/site?portal=${ENABLED}`)
		await expect(page.getByTestId('site-title')).toHaveText('Open Tilburg')
		await configured

		const beacon = nextBeacon(page, 'js_error')
		await page.evaluate((text) => {
			window.setTimeout(() => {
				throw new Error(text)
			}, 50)
		}, message)
		const sent = await beacon
		const params = sent.params as Record<string, unknown>
		expect(String(params.message)).toContain(message)
		expect(params).not.toHaveProperty('stack')
		expect(JSON.stringify(sent)).not.toContain('at ')

		await expect
			.poll(
				async () =>
					(await newestEvents(request)).some(
						(e) =>
							e.name === 'js_error'
							&& String(
								(e.params as Record<string, unknown>)?.message,
							).includes(message),
					),
				{ timeout: 20_000 },
			)
			.toBe(true)
		aggregate()
		const daily = await todaysRollup(request)
		const error = (daily.errors as Array<Record<string, unknown>>).find((e) =>
			String(e.message).includes(message),
		)
		expect(error, 'the error is on the rollup').toBeTruthy()
		expect(Number(error!.hits)).toBeGreaterThanOrEqual(1)

		await login(page)
		await page.goto(`${APP}/traffic`)
		const table = page.getByTestId('traffic-errors-table')
		await expect(table).toBeVisible({ timeout: 30_000 })
		await expect(table).toContainText(message)
	})
})
