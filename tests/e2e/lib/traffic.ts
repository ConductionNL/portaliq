/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the traffic specs share (portal-traffic-analytics,
 * portal-traffic-visitors-and-geo, portal-traffic-outcomes): the
 * measured portal, posting a batch as the client would, running the
 * aggregation job inside the container, reading the raw events and the
 * rollups back through the object API, and the seeded traffic block a
 * spec restores after changing it.
 *
 * `seededTraffic()` MUST match tests/e2e/fixtures/seed-cms.sh: a spec that
 * writes the portal's traffic block back with this value is putting the
 * fixture back, and a drift between the two makes the next spec run
 * against a portal the seed never described.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { resolveBaseURL } from '../base-url.ts'

export const BASE = resolveBaseURL()
export const APP = `${BASE}/index.php/apps/portaliq`
export const OR_OBJECTS = `${BASE}/index.php/apps/openregister/api/objects/portaliq`
export const ENABLED = 'open-tilburg'
export const ADMIN_USER = process.env.ADMIN_USER ?? 'admin'
export const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? 'admin'
export const ADMIN_BASIC =
	'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
export const CONTAINER = process.env.E2E_CONTAINER ?? ''
export const DESKTOP_UA =
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'
export const ADMIN_HEADERS = {
	Authorization: ADMIN_BASIC,
	'OCS-APIRequest': 'true',
}

/**
 * The measured portal's traffic block exactly as seed-cms.sh wrote it.
 *
 * @param extra Overrides.
 */
export function seededTraffic(
	extra: Record<string, unknown> = {},
): Record<string, unknown> {
	return {
		enabled: true,
		events: [
			'page_view',
			'session_start',
			'scroll',
			'outbound_click',
			'search',
			'form_submit',
			'form_start',
			'form_field',
			'form_abandon',
			'page_not_found',
		],
		dimensions: [
			'pageReferrer',
			'pageTitle',
			'searchTerm',
			'linkUrl',
			'referrerHost',
			'channel',
			'deviceType',
			'browser',
			'os',
			'language',
		],
		goals: [
			{
				id: 'contact',
				name: 'Contact page opened',
				type: 'page_reached',
				match: { pathEquals: '/contact' },
				value: 10,
			},
		],
		funnels: [
			{
				id: 'contact-journey',
				name: 'Home to contact',
				steps: [
					{ name: 'Home', match: { pathEquals: '/' } },
					{ name: 'Contact', match: { pathEquals: '/contact' } },
				],
			},
		],
		customDimensions: [{ id: 'audience', name: 'Audience', scope: 'session' }],
		...extra,
	}
}

/**
 * One event as the client would post it.
 *
 * @param sequence The in-session sequence.
 * @param pagePath The page path.
 * @param name The event name.
 * @param params The params.
 */
export function event(
	sequence: number,
	pagePath: string,
	name = 'page_view',
	params: Record<string, unknown> = {},
): Record<string, unknown> {
	return {
		name,
		timestamp: new Date().toISOString(),
		sequence,
		pageLocation: `${BASE}${pagePath}`,
		pageReferrer: '',
		pageTitle: 'Open Tilburg',
		params,
	}
}

/**
 * Post a batch as text/plain, as the client does.
 *
 * @param request The request context.
 * @param events The events.
 * @param headers Extra headers; a distinct User-Agent is a distinct visitor.
 */
export async function post(
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
export function occ(...args: string[]): string {
	return execFileSync(
		'docker',
		['exec', '-u', 'www-data', CONTAINER, 'php', 'occ', ...args],
		{ encoding: 'utf8', timeout: 300_000 },
	)
}

/**
 * Run the aggregation job once.
 */
export function aggregate(): void {
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
export async function portalRecord(
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
export async function setTraffic(
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
export async function rollups(
	request: APIRequestContext,
): Promise<Array<Record<string, unknown>>> {
	const res = await request.get(
		`${OR_OBJECTS}/portalTrafficDaily?portal=${ENABLED}&_limit=100`,
		{ headers: ADMIN_HEADERS },
	)
	expect(res.status()).toBe(200)
	const body = await res.json()
	const rows = Array.isArray(body) ? body : (body.results ?? [])
	return rows.filter((r: Record<string, unknown>) => r.portal === ENABLED)
}

/**
 * Today's rollup for the measured portal, after an aggregation.
 *
 * @param request The request context.
 */
export async function todaysRollup(
	request: APIRequestContext,
): Promise<Record<string, unknown>> {
	const today = new Date().toISOString().substring(0, 10)
	const daily = (await rollups(request)).find((r) => r.date === today)
	expect(daily, "today's rollup exists").toBeTruthy()
	return daily!
}

/**
 * The newest raw events for the measured portal.
 *
 * @param request The request context.
 */
export async function newestEvents(
	request: APIRequestContext,
): Promise<Array<Record<string, unknown>>> {
	const res = await request.get(
		`${OR_OBJECTS}/portalTrafficEvent?portal=${ENABLED}&_limit=500`,
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
export async function login(page: Page): Promise<void> {
	await page.goto(`${BASE}/index.php/login`)
	await page.locator('input[name="user"]').fill(ADMIN_USER)
	await page.locator('input[name="password"]').fill(ADMIN_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 60_000 })
}

/**
 * Wait for the next collector POST whose batch carries an event of the
 * name, and return that event.
 *
 * @param page The page.
 * @param name The event name.
 * @param timeout How long to wait.
 */
export async function nextBeacon(
	page: Page,
	name: string,
	timeout = 20_000,
): Promise<Record<string, unknown>> {
	const request = await page.waitForRequest(
		(req) =>
			req.method() === 'POST'
			&& req.url().includes('/apps/portaliq/api/traffic')
			&& String(req.postData() ?? '').includes(`"name":"${name}"`),
		{ timeout },
	)
	const body = JSON.parse(request.postData() ?? '{}')
	const found = (body.events ?? []).find(
		(e: Record<string, unknown>) => e.name === name,
	)
	expect(found, `a ${name} event in the batch`).toBeTruthy()
	return found
}
