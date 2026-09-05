/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-traffic-outcomes, phase 3: goals, funnels, form analytics,
 * missing pages, custom dimensions and the searches list.
 *
 * Runs between traffic-analytics.spec.ts and traffic-visitors.spec.ts
 * (alphabetical, one worker) against the same seeded portal. Everything
 * that needs the container (the aggregation job) skips with a stated
 * reason when E2E_CONTAINER is unset.
 *
 * ISOLATION. Every other traffic spec posts page views for `/` on the
 * same UTC day, so a funnel whose first step is `/` would count their
 * sessions too. The goal and the funnel this spec proves are therefore
 * written to the portal in beforeAll on paths only this spec posts, and
 * the seeded block is put back in afterAll. Each posted session carries
 * its own User-Agent, because in cookieless mode the visitor is a hash of
 * the address and the agent, and two batches with the same agent are one
 * visitor.
 */

import { expect, test } from '@playwright/test'
import {
	aggregate,
	APP,
	CONTAINER,
	DESKTOP_UA,
	ENABLED,
	event,
	login,
	newestEvents,
	nextBeacon,
	post,
	seededTraffic,
	setTraffic,
	todaysRollup,
} from './lib/traffic.ts'

/**
 * A run-unique suffix so a rerun on the same day does not read the
 * previous run's events as its own.
 */
const RUN = Date.now().toString(36)
const START = `/outcomes-${RUN}/start`
const DONE = `/outcomes-${RUN}/bedankt`
const FORM = `outcomes-form-${RUN}`
const MISSING = `outcomes-missing-${RUN}`

/**
 * The traffic block this spec proves against.
 */
function outcomesTraffic(): Record<string, unknown> {
	return seededTraffic({
		goals: [
			{
				id: 'bedankt',
				name: 'Thank-you page reached',
				type: 'page_reached',
				match: { pathEquals: DONE },
				value: 10,
			},
		],
		funnels: [
			{
				id: 'outcomes',
				name: 'Start to thank-you',
				steps: [
					{ name: 'Start', match: { pathEquals: START } },
					{ name: 'Thank you', match: { pathEquals: DONE } },
				],
			},
		],
	})
}

/**
 * A visitor of its own: the agent is half the daily hash.
 *
 * @param tag Which visitor.
 */
function visitor(tag: string): Record<string, string> {
	return { 'User-Agent': `${DESKTOP_UA} outcomes/${RUN}/${tag}` }
}

test.describe('traffic outcomes: goals, funnels, forms, missing pages and dimensions', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		test.skip(CONTAINER === '', 'set E2E_CONTAINER to the Nextcloud container')
		await setTraffic(request, outcomesTraffic())
	})

	test.afterAll(async ({ request }) => {
		if (CONTAINER === '') {
			return
		}
		await setTraffic(request, seededTraffic())
	})

	// @e2e portal-traffic-outcomes::a-page-reached-goal-converts-once-per-session
	// @e2e portal-traffic-outcomes::steps-are-counted-in-sequence
	test('a goal converts once per session and a funnel counts its steps in order, on the rollup and on the page', async ({
		request,
		page,
	}) => {
		// Visitor A walks start, thank-you, thank-you again: one conversion,
		// two completions, both funnel steps. Visitor B sees start only.
		const a = await post(
			request,
			[event(0, START), event(1, DONE), event(2, DONE)],
			visitor('a'),
		)
		expect(a.status()).toBe(204)
		const b = await post(request, [event(0, START)], visitor('b'))
		expect(b.status()).toBe(204)
		aggregate()

		const daily = await todaysRollup(request)
		const goals = daily.goals as Array<Record<string, unknown>>
		const goal = goals.find((g) => g.id === 'bedankt')
		expect(goal, 'the goal is on the rollup').toBeTruthy()
		expect(goal!.conversions).toBe(1)
		expect(goal!.completions).toBe(2)
		expect(goal!.value).toBe(10)
		expect(Number(daily.conversionRate)).toBeGreaterThan(0)

		const funnels = daily.funnels as Array<Record<string, unknown>>
		const funnel = funnels.find((f) => f.id === 'outcomes')
		expect(funnel, 'the funnel is on the rollup').toBeTruthy()
		const steps = funnel!.steps as Array<Record<string, unknown>>
		expect(steps.map((s) => s.sessions)).toEqual([2, 1])
		expect(steps[1].dropOff).toBe(0.5)

		await login(page)
		await page.goto(`${APP}/traffic`)
		const goalRow = page.getByTestId('traffic-goal-bedankt')
		await expect(goalRow).toBeVisible({ timeout: 30_000 })
		await expect(goalRow).toContainText('Thank-you page reached')
		await expect(goalRow.getByTestId('traffic-goal-conversions')).toHaveText(
			/^\s*1\s*$/,
		)

		const funnelBlock = page.getByTestId('traffic-funnel-outcomes')
		await expect(funnelBlock).toBeVisible()
		const counts = funnelBlock.getByTestId('traffic-funnel-sessions')
		await expect(counts).toHaveCount(2)
		await expect(counts.nth(0)).toHaveText(/^\s*2\s*$/)
		await expect(counts.nth(1)).toHaveText(/^\s*1\s*$/)
		await expect(funnelBlock).toContainText('50% dropped off')
	})

	// @e2e portal-traffic-outcomes::an-abandoned-form-is-counted
	test('form start, field and abandon are stored with ids and times only, and the rollup counts the abandon', async ({
		request,
	}) => {
		const res = await post(
			request,
			[
				event(0, START),
				event(1, START, 'form_start', { formId: FORM }),
				event(2, START, 'form_field', {
					formId: FORM,
					fieldId: 'email',
					ms: 1500,
				}),
				event(3, START, 'form_abandon', {
					formId: FORM,
					lastFieldId: 'email',
				}),
			],
			visitor('form'),
		)
		expect(res.status()).toBe(204)

		const stored = (await newestEvents(request)).find(
			(e) =>
				e.name === 'form_field'
				&& (e.params as Record<string, unknown>)?.formId === FORM,
		)
		expect(stored, 'the field event is stored').toBeTruthy()
		expect(
			Object.keys(stored!.params as Record<string, unknown>).sort(),
		).toEqual(['fieldId', 'formId', 'ms'])

		aggregate()
		const daily = await todaysRollup(request)
		const forms = daily.forms as Array<Record<string, unknown>>
		const form = forms.find((f) => f.formId === FORM)
		expect(form, 'the form is on the rollup').toBeTruthy()
		expect(form!.starts).toBe(1)
		expect(form!.abandons).toBe(1)
		expect(form!.submits).toBe(0)
		const fields = form!.fields as Array<Record<string, unknown>>
		expect(fields[0]).toEqual({
			fieldId: 'email',
			avgMs: 1500,
			abandonedHere: 1,
		})
	})

	// @e2e portal-traffic-outcomes::a-field-event-with-an-extra-parameter-is-stored-without-it
	test('a form_field with a value-shaped parameter is stored without it', async ({
		request,
	}) => {
		const fieldId = `secret-${RUN}`
		const res = await post(
			request,
			[
				event(0, START, 'form_field', {
					formId: FORM,
					fieldId,
					ms: 10,
					value: 'jan@example.org',
					label: 'E-mail',
				}),
			],
			visitor('leak'),
		)
		expect(res.status()).toBe(204)

		const stored = (await newestEvents(request)).find(
			(e) =>
				e.name === 'form_field'
				&& (e.params as Record<string, unknown>)?.fieldId === fieldId,
		)
		expect(stored, 'the field event is stored').toBeTruthy()
		const params = stored!.params as Record<string, unknown>
		expect(params).not.toHaveProperty('value')
		expect(params).not.toHaveProperty('label')
		expect(JSON.stringify(stored)).not.toContain('jan@example.org')
	})

	// @e2e portal-traffic-outcomes::the-form-blocks-fields-are-observed
	test('the form block reports form_start and form_field with the field id and no value', async ({
		page,
	}) => {
		await page.goto(
			`${APP}/site?portal=${ENABLED}&route=/campagne/e2e-form-test`,
		)
		const form = page.getByTestId('site-form')
		await expect(form).toBeVisible({ timeout: 30_000 })
		const formId = await form.getAttribute('data-portaliq-form')
		expect(formId, 'the form block names itself').toBeTruthy()

		const started = nextBeacon(page, 'form_start')
		await page.getByTestId('form-field-name').fill('Testpersoon')
		const start = await started
		expect((start.params as Record<string, unknown>).formId).toBe(formId)

		const left = nextBeacon(page, 'form_field')
		await page.getByTestId('form-field-email').click()
		const field = await left
		const params = field.params as Record<string, unknown>
		expect(params.formId).toBe(formId)
		expect(params.fieldId).toContain('name')
		expect(typeof params.ms).toBe('number')
		expect(JSON.stringify(field)).not.toContain('Testpersoon')
	})

	// @e2e portal-traffic-outcomes::a-missing-route-sends-page_not_found
	test('a missing route on the built-in site sends page_not_found, and the rollup and the page list it', async ({
		page,
		request,
	}) => {
		const beacon = nextBeacon(page, 'page_not_found')
		await page.goto(`${APP}/site?portal=${ENABLED}&route=/${MISSING}`)
		await expect(page.getByTestId('site-error')).toHaveAttribute(
			'data-portaliq-status',
			'404',
		)
		const sent = await beacon
		expect(String(sent.pageLocation)).toContain(MISSING)
		expect((sent.params as Record<string, unknown>).path).toBe(`/${MISSING}`)

		// The batch is posted on a timer; give the collector a moment
		// before aggregating so the event is in the day.
		await expect
			.poll(
				async () =>
					(await newestEvents(request)).some(
						(e) =>
							e.name === 'page_not_found'
							&& String(
								(e.params as Record<string, unknown>)?.path,
							).includes(MISSING),
					),
				{ timeout: 20_000 },
			)
			.toBe(true)
		aggregate()

		const daily = await todaysRollup(request)
		const notFound = daily.notFound as Array<Record<string, unknown>>
		const row = notFound.find((r) => String(r.path).includes(MISSING))
		expect(row, 'the missing path is on the rollup').toBeTruthy()
		expect(Number(row!.hits)).toBeGreaterThanOrEqual(1)

		await login(page)
		await page.goto(`${APP}/traffic`)
		const table = page.getByTestId('traffic-missing-pages')
		await expect(table).toBeVisible({ timeout: 30_000 })
		await expect(table).toContainText(MISSING)
	})

	// @e2e portal-traffic-outcomes::a-declared-dimension-is-stored-and-an-undeclared-one-is-stripped
	test('a declared custom dimension is stored, an undeclared one is stripped, and the widget lists the declared one', async ({
		request,
		page,
	}) => {
		const value = `inwoner-${RUN}`
		const res = await post(
			request,
			[event(0, START, 'page_view', { cd_audience: value, cd_secret: 'bsn' })],
			visitor('dimension'),
		)
		expect(res.status()).toBe(204)

		const stored = (await newestEvents(request)).find(
			(e) => (e.params as Record<string, unknown>)?.cd_audience === value,
		)
		expect(
			stored,
			'the event with the declared dimension is stored',
		).toBeTruthy()
		expect(stored!.params).not.toHaveProperty('cd_secret')

		aggregate()
		const daily = await todaysRollup(request)
		const dimensions = daily.customDimensions as Record<
			string,
			Record<string, number>
		>
		expect(dimensions.audience[value]).toBe(1)
		expect(dimensions).not.toHaveProperty('secret')

		await login(page)
		await page.goto(`${APP}/traffic`)
		const list = page.getByTestId('traffic-dimension-audience')
		await expect(list).toBeVisible({ timeout: 30_000 })
		await expect(list).toContainText('Audience')
		await expect(list).toContainText(value)
	})

	// @e2e portal-traffic-outcomes::searched-terms-are-listed
	test('a search event with a term is listed under Sources', async ({
		request,
		page,
	}) => {
		const term = `zoekterm ${RUN}`
		const res = await post(
			request,
			[{ ...event(0, START, 'search', { results: 3 }), searchTerm: term }],
			visitor('search'),
		)
		expect(res.status()).toBe(204)
		aggregate()

		const daily = await todaysRollup(request)
		const searches = daily.searches as Array<Record<string, unknown>>
		expect(searches.find((s) => s.term === term)).toBeTruthy()

		await login(page)
		await page.goto(`${APP}/traffic`)
		const table = page.getByTestId('traffic-searches')
		await expect(table).toBeVisible({ timeout: 30_000 })
		await expect(table).toContainText(term)
	})

	// @e2e portal-traffic-outcomes::a-portal-without-goals-says-so-and-links-to-its-settings
	test('a portal without goals says so and links to its detail page', async ({
		request,
		page,
	}) => {
		await setTraffic(request, seededTraffic({ goals: [], funnels: [] }))
		aggregate()

		await login(page)
		await page.goto(`${APP}/traffic`)
		const empty = page.getByTestId('traffic-goals-empty')
		await expect(empty).toBeVisible({ timeout: 30_000 })
		const link = page.getByTestId('traffic-goals-settings-link')
		await expect(link).toBeVisible()
		await link.click()
		await expect(page).toHaveURL(/\/portals\//)
	})
})
