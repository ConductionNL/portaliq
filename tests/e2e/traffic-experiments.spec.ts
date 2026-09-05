/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-traffic-experiments, phase 4b: page experiments, and heatmaps
 * and session recording behind their warned switches.
 *
 * Runs after traffic-analytics.spec.ts and before traffic-outcomes.spec.ts
 * (alphabetical, one worker) against the same seeded portal, plus the
 * external portal the seed creates with recording switched on. The tests
 * that need the container (the aggregation job) skip with a stated
 * reason when E2E_CONTAINER is unset.
 *
 * ISOLATION. The experiment, the heat events and the recordings are all
 * behind the portal's traffic block, which every test here writes for
 * itself and afterAll puts back to the seed, so the other specs meet the
 * portal as the seed described it. Ids carry a run-unique suffix.
 *
 * WHY FRESH CONTEXTS. In cookieless mode the variant is picked per page
 * load and stored nowhere, so a new browser context is a new visitor;
 * ten of them at even weights miss a variant with a probability of one
 * in five hundred.
 */

import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	ADMIN_HEADERS,
	aggregate,
	APP,
	BASE,
	CONTAINER,
	DESKTOP_UA,
	ENABLED,
	event,
	EXTERNAL,
	login,
	newestEvents,
	nextBeacon,
	OR_OBJECTS,
	portalRecord,
	post,
	seededTraffic,
	setTraffic,
	todaysRollup,
} from './lib/traffic.ts'

const RUN = Date.now().toString(36)
const EXPERIMENT = `hero-${RUN}`
const REDIRECT = `move-${RUN}`
const PAGE = '/over-ons'
const CHANGED = `Over ons ${RUN}`
const H1 = 'Open Tilburg'

/**
 * The seed plus one running text experiment on the About page, with a
 * control and a variant that changes the page title.
 */
function textExperiment(): Record<string, unknown> {
	return seededTraffic({
		experiments: [
			{
				id: EXPERIMENT,
				name: 'About title',
				status: 'running',
				page: PAGE,
				goal: 'contact',
				variants: [
					{ id: 'a', name: 'Control', weight: 1, changes: [] },
					{
						id: 'b',
						name: 'Run title',
						weight: 1,
						changes: [
							{
								selector: '[data-testid="page-title"]',
								text: CHANGED,
							},
						],
					},
				],
			},
		],
	})
}

/**
 * The seed plus one running experiment whose variant b is the contact
 * page shown in place of the About page.
 */
function redirectExperiment(): Record<string, unknown> {
	return seededTraffic({
		experiments: [
			{
				id: REDIRECT,
				name: 'About or contact',
				status: 'running',
				page: PAGE,
				goal: 'contact',
				variants: [
					{ id: 'a', name: 'About', weight: 1, changes: [] },
					{
						id: 'b',
						name: 'Contact instead',
						weight: 1,
						pageRoute: '/contact',
					},
				],
			},
		],
	})
}

/**
 * A visitor of its own for a posted batch: the agent is half the hash.
 *
 * @param tag Which visitor.
 */
function visitor(tag: string): Record<string, string> {
	return { 'User-Agent': `${DESKTOP_UA} experiments/${RUN}/${tag}` }
}

/**
 * Open the built-in site's About page in a fresh context and return the
 * first page view the client posted, plus the page.
 *
 * @param browser The browser.
 */
async function freshVisit(browser: Browser): Promise<{
	page: Page
	view: Record<string, unknown>
	close: () => Promise<void>
}> {
	const context = await browser.newContext({
		userAgent: `${DESKTOP_UA} experiments/${RUN}/${Math.random()}`,
	})
	const page = await context.newPage()
	const beacon = nextBeacon(page, 'page_view')
	await page.goto(
		`${APP}/site?portal=${ENABLED}&route=${encodeURIComponent(PAGE)}`,
	)
	const view = await beacon
	return { page, view, close: () => context.close() }
}

/**
 * The portal's recordings, newest first, as the admin.
 *
 * @param request The request context.
 * @param portal The portal slug.
 */
async function recordingsOf(
	request: APIRequestContext,
	portal = ENABLED,
): Promise<Array<Record<string, unknown>>> {
	const res = await request.get(
		`${OR_OBJECTS}/portalTrafficRecording?portal=${portal}&_limit=200&_order[startedAt]=desc`,
		{ headers: ADMIN_HEADERS },
	)
	expect(res.status()).toBe(200)
	const body = await res.json()
	const rows = Array.isArray(body) ? body : (body.results ?? [])
	return rows
		.filter((r: Record<string, unknown>) => r.portal === portal)
		.sort((a: Record<string, unknown>, b: Record<string, unknown>) =>
			String(b.startedAt ?? '').localeCompare(String(a.startedAt ?? '')),
		)
}

/**
 * Write the portal's `kind`, keeping everything else.
 *
 * @param request The request context.
 * @param kind site or external.
 */
async function setKind(request: APIRequestContext, kind: string): Promise<void> {
	const portal = await portalRecord(request)
	const self = (portal['@self'] ?? {}) as Record<string, unknown>
	const id = String(self.id ?? self.uuid ?? portal.id ?? '')
	const { '@self': _self, ...fields } = portal
	const res = await request.put(`${OR_OBJECTS}/portal/${id}`, {
		headers: { ...ADMIN_HEADERS, 'Content-Type': 'application/json' },
		data: { ...fields, kind },
	})
	expect(res.status()).toBeLessThan(300)
}

/**
 * Post one recording chunk as the recorder would.
 *
 * @param request The request context.
 * @param portal The portal slug.
 * @param id The recording id.
 */
async function postChunk(request: APIRequestContext, portal: string, id: string) {
	return request.post(`${APP}/api/traffic/recording`, {
		data: JSON.stringify({
			portal,
			consent: true,
			recording: id,
			page: '/',
			elapsed: 100,
			events: [
				{ k: 's', t: 0, w: 800, h: 600, n: { n: 'body', c: [{ l: 4 }] } },
			],
		}),
		headers: { 'Content-Type': 'text/plain', 'User-Agent': DESKTOP_UA },
	})
}

test.describe('portal-traffic-experiments', () => {
	test.afterAll(async ({ request }) => {
		await setTraffic(request, seededTraffic())
		await setKind(request, 'site')
	})

	// @e2e portal-traffic-experiments::visitors-are-split-over-the-variants-and-a-session-stays-on-its-variant
	test('visitors are split over two text variants, one sees the changed title, and a visit stays on its variant', async ({
		browser,
		request,
	}) => {
		test.setTimeout(240_000)
		await setTraffic(request, textExperiment())

		const seen: Record<string, number> = {}
		let changedSeen = false
		for (let i = 0; i < 10 && Object.keys(seen).length < 2; i++) {
			const { page, view, close } = await freshVisit(browser)
			const params = view.params as Record<string, unknown>
			expect(params.experiment).toBe(EXPERIMENT)
			const variant = String(params.variant)
			seen[variant] = (seen[variant] ?? 0) + 1
			if (variant === 'b') {
				await expect(page.getByTestId('page-title')).toHaveText(CHANGED)
				changedSeen = true
			} else {
				await expect(page.getByTestId('page-title')).toHaveText('Over ons')
			}
			// The same visit navigates on (client side) and stays on its
			// variant: the next page view carries the same tag.
			const next = nextBeacon(page, 'page_view')
			await page.evaluate(() => {
				window.history.pushState(
					null,
					'',
					window.location.href.replace(/route=[^&]*/, 'route=%2Fcontact'),
				)
				window.dispatchEvent(new PopStateEvent('popstate'))
			})
			const second = (await next).params as Record<string, unknown>
			expect(second.experiment).toBe(EXPERIMENT)
			expect(second.variant).toBe(variant)
			await close()
		}
		expect(
			Object.keys(seen).sort(),
			`variants seen: ${JSON.stringify(seen)}`,
		).toEqual(['a', 'b'])
		expect(changedSeen).toBe(true)

		// Both variant ids are among the stored events, tagged.
		await expect
			.poll(
				async () => {
					const tagged = (await newestEvents(request)).filter(
						(e) =>
							(e.params as Record<string, unknown>)?.experiment
							=== EXPERIMENT,
					)
					return [
						...new Set(
							tagged.map(
								(e) => (e.params as Record<string, unknown>).variant,
							),
						),
					].sort()
				},
				{ timeout: 20_000 },
			)
			.toEqual(['a', 'b'])
	})

	// @e2e portal-traffic-experiments::a-variant-page-is-shown-in-place-of-the-experiments-page
	test('a variant page is shown in place of the experiment page without a reload', async ({
		browser,
		request,
	}) => {
		test.setTimeout(240_000)
		await setTraffic(request, redirectExperiment())

		let redirected = false
		for (let i = 0; i < 10 && !redirected; i++) {
			const { page, view, close } = await freshVisit(browser)
			const params = view.params as Record<string, unknown>
			expect(params.experiment).toBe(REDIRECT)
			if (params.variant === 'b') {
				expect(String(view.pageLocation)).toContain(
					encodeURIComponent('/contact'),
				)
				await expect(page.getByTestId('page-title')).toHaveText('Contact')
				expect(page.url()).toContain('route=%2Fcontact')
				redirected = true
			} else {
				await expect(page.getByTestId('page-title')).toHaveText('Over ons')
			}
			await close()
		}
		expect(redirected, 'variant b was met within ten fresh visits').toBe(true)
	})

	// @e2e portal-traffic-experiments::the-rollup-lists-the-experiment-per-variant-and-the-widget-says-not-enough-data
	test('the rollup lists the experiment per variant and the widget says not enough data', async ({
		page,
		request,
	}) => {
		test.skip(CONTAINER === '', 'set E2E_CONTAINER to the Nextcloud container')
		await setTraffic(request, textExperiment())

		// Three tagged visits posted straight to the collector, one of
		// which converts on the contact goal.
		const tag = (variant: string) => ({ experiment: EXPERIMENT, variant })
		expect(
			(
				await post(
					request,
					[
						event(0, PAGE, 'page_view', tag('a')),
						event(1, '/contact', 'page_view', tag('a')),
					],
					visitor('one'),
				)
			).status(),
		).toBe(204)
		expect(
			(
				await post(
					request,
					[event(0, PAGE, 'page_view', tag('a'))],
					visitor('two'),
				)
			).status(),
		).toBe(204)
		expect(
			(
				await post(
					request,
					[event(0, PAGE, 'page_view', tag('b'))],
					visitor('three'),
				)
			).status(),
		).toBe(204)
		// A tag for an experiment that is not running is stripped.
		expect(
			(
				await post(
					request,
					[
						event(0, PAGE, 'page_view', {
							experiment: 'nope',
							variant: 'a',
						}),
					],
					visitor('four'),
				)
			).status(),
		).toBe(204)
		await expect
			.poll(
				async () =>
					(await newestEvents(request)).filter(
						(e) =>
							(e.params as Record<string, unknown>)?.experiment
							=== EXPERIMENT,
					).length,
				{ timeout: 20_000 },
			)
			.toBeGreaterThanOrEqual(4)
		const stripped = (await newestEvents(request)).find(
			(e) => (e.params as Record<string, unknown>)?.experiment === 'nope',
		)
		expect(
			stripped,
			'a tag for an unknown experiment is stripped',
		).toBeUndefined()

		aggregate()
		const daily = await todaysRollup(request)
		const experiments = daily.experiments as Array<Record<string, unknown>>
		const row = experiments.find((e) => e.id === EXPERIMENT)
		expect(row, 'the experiment is on the rollup').toBeTruthy()
		const variants = row!.variants as Array<Record<string, unknown>>
		const a = variants.find((v) => v.id === 'a')!
		const b = variants.find((v) => v.id === 'b')!
		expect(Number(a.sessions)).toBeGreaterThanOrEqual(2)
		expect(Number(a.conversions)).toBeGreaterThanOrEqual(1)
		expect(Number(b.sessions)).toBeGreaterThanOrEqual(1)
		// The object store hands an empty string back as null.
		expect(row!.winner ?? '').toBe('')

		await login(page)
		await page.goto(`${APP}/traffic`)
		const widget = page.getByTestId(`traffic-experiment-${EXPERIMENT}`)
		await expect(widget).toBeVisible({ timeout: 30_000 })
		await expect(widget.getByTestId('traffic-experiment-verdict')).toContainText(
			'Not enough data',
		)
		await expect(widget.getByTestId('traffic-variant-a')).toBeVisible()
		await expect(widget.getByTestId('traffic-variant-b')).toBeVisible()
	})

	// @e2e portal-traffic-experiments::a-heatmap-event-is-refused-while-the-switch-is-off
	test('a heat_click is refused as sensitive-off while the switch is off', async ({
		request,
	}) => {
		await setTraffic(request, seededTraffic())
		const before = await request.get(`${APP}/api/metrics`, {
			headers: ADMIN_HEADERS,
		})
		const count = (text: string) =>
			Number(
				(text.match(
					/portaliq_traffic_refused_total\{reason="sensitive-off"\} (\d+)/,
				) ?? [])[1] ?? 0,
			)
		const was = count(await before.text())

		const path = `/heat-off-${RUN}`
		const res = await post(
			request,
			[event(0, path, 'heat_click', { x: 0.5, y: 0.5, vw: 1280 })],
			visitor('heat-off'),
		)
		expect(res.status()).toBe(204)

		await expect
			.poll(
				async () =>
					count(
						await (
							await request.get(`${APP}/api/metrics`, {
								headers: ADMIN_HEADERS,
							})
						).text(),
					),
				{ timeout: 10_000 },
			)
			.toBeGreaterThan(was)
		const stored = (await newestEvents(request)).find(
			(e) => e.name === 'heat_click' && String(e.pagePath) === path,
		)
		expect(stored).toBeUndefined()
	})

	// @e2e portal-traffic-experiments::a-click-lands-on-the-grid-and-the-widget-draws-it
	test('with heatmaps on, a click on the site sends heat_click, the rollup has a cell and the widget draws', async ({
		page,
		request,
	}) => {
		test.skip(CONTAINER === '', 'set E2E_CONTAINER to the Nextcloud container')
		await setTraffic(request, seededTraffic({ sensitive: { heatmaps: true } }))

		const beacon = nextBeacon(page, 'heat_click')
		await page.goto(
			`${APP}/site?portal=${ENABLED}&route=${encodeURIComponent(PAGE)}`,
		)
		await expect(page.getByTestId('page-title')).toHaveText('Over ons')
		await page.getByTestId('page-title').click()
		const sent = await beacon
		const params = sent.params as Record<string, unknown>
		expect(Number(params.x)).toBeGreaterThanOrEqual(0)
		expect(Number(params.x)).toBeLessThanOrEqual(1)
		expect(Number(params.y)).toBeGreaterThanOrEqual(0)
		expect(Number(params.y)).toBeLessThanOrEqual(1)
		expect(String(params.tag)).toBe('h2')
		expect(String(params.selector)).not.toContain('#')
		expect(params).not.toHaveProperty('text')

		await expect
			.poll(
				async () =>
					(await newestEvents(request)).some(
						(e) => e.name === 'heat_click',
					),
				{ timeout: 20_000 },
			)
			.toBe(true)
		aggregate()
		const daily = await todaysRollup(request)
		const heatmaps = daily.heatmaps as Array<Record<string, unknown>>
		const row = heatmaps.find(
			(h) => String(h.path).includes('site') || String(h.path) === PAGE,
		)
		expect(
			row,
			`a heatmap row exists: ${JSON.stringify(heatmaps.map((h) => h.path))}`,
		).toBeTruthy()
		expect((row!.clicks as Array<unknown>).length).toBeGreaterThanOrEqual(1)

		await login(page)
		await page.goto(`${APP}/traffic`)
		await expect(page.getByTestId('traffic-heatmap-canvas')).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByTestId('traffic-heatmap-scroll')).toBeVisible()
	})

	// @e2e portal-traffic-experiments::the-recorder-is-served-but-never-requested-while-the-switch-is-off
	test('the recorder is served to anyone but never requested while the switch is off', async ({
		page,
		request,
	}) => {
		await setTraffic(request, seededTraffic())
		const served = await request.get(`${APP}/api/traffic-recorder.js`)
		expect(served.status()).toBe(200)
		expect(served.headers()['content-type']).toContain('javascript')
		expect(await served.text()).toContain('portaliqTraffic')

		const requested: string[] = []
		page.on('request', (req) => {
			if (req.url().includes('traffic-recorder')) {
				requested.push(req.url())
			}
		})
		const beacon = nextBeacon(page, 'page_view')
		await page.goto(
			`${APP}/site?portal=${ENABLED}&route=${encodeURIComponent(PAGE)}`,
		)
		await beacon
		await page.waitForTimeout(3000)
		expect(requested).toEqual([])
		expect(
			await page.evaluate(
				() =>
					(
						window as unknown as {
							portaliqTraffic: { recording: unknown }
						}
					).portaliqTraffic.recording,
			),
		).toBeNull()
	})

	// @e2e portal-traffic-experiments::a-consented-visit-produces-a-masked-recording-listed-with-a-player
	test('with recording on, a visit produces a masked recording, listed with a player', async ({
		page,
		request,
	}) => {
		test.setTimeout(240_000)
		await setTraffic(
			request,
			seededTraffic({ sensitive: { sessionRecording: true } }),
		)

		const chunk = page.waitForRequest(
			(req) =>
				req.method() === 'POST'
				&& req.url().includes('/api/traffic/recording'),
			{ timeout: 30_000 },
		)
		await page.goto(
			`${APP}/site?portal=${ENABLED}&route=${encodeURIComponent(PAGE)}`,
		)
		await expect(page.getByTestId('site-title')).toHaveText(H1)
		await page.mouse.move(100, 100)
		await page.mouse.move(200, 150)
		await page.getByTestId('page-title').click()
		const posted = await chunk
		const body = JSON.parse(posted.postData() ?? '{}')
		expect(String(body.recording)).toMatch(/^[a-f0-9]{32}$/)
		expect(JSON.stringify(body.events)).not.toContain(H1)

		// The recorder posts the stylesheets and the snapshot in separate
		// posts when they do not fit one; wait for the snapshot to land.
		let recording: Record<string, unknown> | undefined
		await expect
			.poll(
				async () => {
					recording = (await recordingsOf(request)).find(
						(r) => r.recordingId === body.recording,
					)
					return (
						Boolean(recording)
						&& JSON.stringify(recording!.chunks).includes('"k":"s"')
					)
				},
				{ timeout: 40_000 },
			)
			.toBe(true)
		const stored = JSON.stringify(recording!.chunks)
		expect(stored).not.toContain(H1)
		expect(stored).not.toContain('Over ons')
		expect(stored).toContain('"k":"s"')
		expect(String(recording!.expires)).toMatch(/^\d{4}-\d{2}-\d{2}T/)

		await login(page)
		await page.goto(`${APP}/traffic`)
		const rows = page.getByTestId('traffic-recording-row')
		await expect(rows.first()).toBeVisible({ timeout: 30_000 })
		await expect(page.getByTestId('traffic-sensitive-warning')).toContainText(
			'recording',
		)
		await rows.first().getByTestId('traffic-recording-play').click()
		await expect(page.getByTestId('traffic-recording-player')).toBeVisible()
		const frame = page.getByTestId('traffic-recording-frame')
		await expect(frame).toBeVisible()
		expect(await frame.getAttribute('sandbox')).toBe('allow-same-origin')
		await expect
			.poll(
				async () =>
					String((await frame.getAttribute('srcdoc')) ?? '').length,
				{ timeout: 15_000 },
			)
			.toBeGreaterThan(0)
		expect(String(await frame.getAttribute('srcdoc'))).not.toContain(H1)
	})

	// @e2e portal-traffic-experiments::recording-waits-for-consent-where-consent-is-required
	test('recording waits for consent where the portal requires it', async ({
		page,
		request,
	}) => {
		await setTraffic(
			request,
			seededTraffic({
				sensitive: { sessionRecording: true },
				consent: { required: true, preConsentEvents: ['page_view'] },
			}),
		)
		const requested: string[] = []
		page.on('request', (req) => {
			if (req.url().includes('traffic-recorder')) {
				requested.push(req.url())
			}
		})
		const beacon = nextBeacon(page, 'page_view')
		await page.goto(
			`${APP}/site?portal=${ENABLED}&route=${encodeURIComponent(PAGE)}`,
		)
		await beacon
		await page.waitForTimeout(2000)
		expect(requested).toEqual([])

		const loaded = page.waitForRequest(
			(req) => req.url().includes('traffic-recorder'),
			{ timeout: 15_000 },
		)
		await page.evaluate(() =>
			(
				window as unknown as {
					portaliqTraffic: { consent: (g: boolean) => void }
				}
			).portaliqTraffic.consent(true),
		)
		await loaded
		expect(requested.length).toBe(1)
	})

	// @e2e portal-traffic-experiments::an-external-portal-never-records
	test('an external portal never records, even with the switch on', async ({
		page,
		request,
	}) => {
		// The client half: the seeded external portal has recording ON,
		// and the client loaded for it must not request the recorder.
		const site = await request.get(`${APP}/api/content/site?portal=${EXTERNAL}`)
		expect(site.status()).toBe(200)
		const traffic = (await site.json()).traffic as Record<string, unknown>
		expect(traffic.kind).toBe('external')
		expect((traffic.sensitive as Record<string, unknown>).sessionRecording).toBe(
			true,
		)

		await page.route(`${BASE}/e2e-external-${RUN}.html`, (route) =>
			route.fulfill({
				contentType: 'text/html',
				body: `<!doctype html><html><body><h1>${H1} extern</h1><script src="${APP}/api/traffic-client.js" data-origin="${BASE}" data-portal="${EXTERNAL}" data-appPath="/index.php/apps/portaliq"></script></body></html>`,
			}),
		)
		const requested: string[] = []
		page.on('request', (req) => {
			if (req.url().includes('traffic-recorder')) {
				requested.push(req.url())
			}
		})
		const siteCall = page.waitForResponse(
			(res) => res.url().includes('/api/content/site'),
			{ timeout: 15_000 },
		)
		await page.goto(`${BASE}/e2e-external-${RUN}.html`)
		await siteCall
		await page.waitForTimeout(3000)
		expect(requested).toEqual([])

		// The server half: a chunk for a portal that is external is
		// refused whatever the switch says. The collector resolves the
		// seeded site by host, so the site itself is made external for
		// the length of this assertion.
		await setTraffic(
			request,
			seededTraffic({ sensitive: { sessionRecording: true } }),
		)
		await setKind(request, 'external')
		const id = `e${RUN}`.padEnd(32, '0').replace(/[^a-f0-9]/g, '0')
		const before = await request.get(`${APP}/api/metrics`, {
			headers: ADMIN_HEADERS,
		})
		const count = (text: string) =>
			Number(
				(text.match(
					/portaliq_traffic_refused_total\{reason="external-portal"\} (\d+)/,
				) ?? [])[1] ?? 0,
			)
		const was = count(await before.text())
		expect((await postChunk(request, ENABLED, id)).status()).toBe(204)
		await expect
			.poll(
				async () =>
					count(
						await (
							await request.get(`${APP}/api/metrics`, {
								headers: ADMIN_HEADERS,
							})
						).text(),
					),
				{ timeout: 10_000 },
			)
			.toBeGreaterThan(was)
		expect(
			(await recordingsOf(request)).find((r) => r.recordingId === id),
		).toBeUndefined()
		await setKind(request, 'site')
	})
})
