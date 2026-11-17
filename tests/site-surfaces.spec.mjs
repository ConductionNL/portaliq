#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-surfaces.spec.mjs: what a visitor can actually read on a rendered
// portal page (nldesign-theme-integration 3.3).
//
// Usage:
//   PLAYWRIGHT_BASE_URL=http://<instance> node tests/site-surfaces.spec.mjs
//   PORTALIQ_SURFACE_PAGES="conduction-klant:/,lafranken:/mijn-zaken" ...
//
// The target comes from PLAYWRIGHT_BASE_URL, NEXTCLOUD_URL, NC_BASE_URL or
// BASE_URL, the same names tests/e2e/base-url.ts accepts. There is no default:
// with none set the run SKIPS and says that nothing was measured.
//
// Every text node of each page is compared with the first ancestor that
// PAINTS a background (src/site/lib/contrast.js), so a label on a card is
// judged against the card, not against the band the card sits in. Comparing
// with the nearest named band once reported a passing label as a 1.06 failure.
//
// Before reporting a pass the check proves it can fail: it injects translucent
// text into the rendered page and requires a finding, and it puts white text on
// a painted card inside a white band and requires NO finding. A page with fewer
// than MIN_NODES measured text nodes did not render and is a failure.
//
// Visitors are anonymous: each page opens in a fresh context with an empty
// storage state.
//
// @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md

import { chromium } from '@playwright/test'
import { auditContrast } from '../src/site/lib/contrast.js'

const BASE = (
	process.env.PLAYWRIGHT_BASE_URL
	?? process.env.NEXTCLOUD_URL
	?? process.env.NC_BASE_URL
	?? process.env.BASE_URL
	?? ''
).replace(/\/+$/, '')

/**
 * The pages to measure, from PORTALIQ_SURFACE_PAGES or the demo portals.
 *
 * Two portals on two token sets: a token only one of them names is exactly
 * the change that breaks the other.
 *
 * @return {Array<[string, string]>} `[portal, route]` pairs.
 */
function pages() {
	const raw =
		process.env.PORTALIQ_SURFACE_PAGES
		?? 'conduction-klant:/,lafranken:/,lafranken:/mijn-zaken,lafranken:/aanvragen'
	return raw
		.split(',')
		.map((pair) => pair.trim())
		.filter((pair) => pair !== '')
		.map((pair) => {
			const at = pair.indexOf(':')
			return at === -1
				? [pair, '/']
				: [pair.slice(0, at), pair.slice(at + 1) || '/']
		})
}

const PAGES = pages()
const WIDTHS = [1440, 390]
const MIN_NODES = 5

/**
 * The page address of one portal route.
 *
 * @param {string} portal The portal slug.
 * @param {string} route  The route on that portal.
 * @return {string} The URL.
 */
function siteUrl(portal, route) {
	return `${BASE}/index.php/apps/portaliq/site?portal=${encodeURIComponent(portal)}&route=${encodeURIComponent(route)}`
}

/**
 * Run the audit in the page. evaluate() carries no imports into the page, so
 * the module's function travels as source, evaluated as a string expression.
 *
 * @param {object} page The page.
 * @return {Promise<{failures: Array<object>, measured: number}>} The findings.
 */
function audit(page) {
	return page.evaluate(`(${auditContrast.toString()})(document.body, window)`)
}

/**
 * Append a band to the page with one text element inside it.
 *
 * @param {object} page The page.
 * @param {object} spec `{band, card, colour, text}` backgrounds and text.
 * @return {Promise<void>}
 */
function inject(page, spec) {
	return page.evaluate((s) => {
		const band = document.createElement('div')
		band.id = 'pq-surface-selftest'
		band.style.cssText = `background: ${s.band}; padding: 16px;`
		let parent = band
		if (s.card) {
			parent = document.createElement('div')
			parent.style.cssText = `background: ${s.card}; padding: 16px;`
			band.appendChild(parent)
		}
		const text = document.createElement('p')
		text.textContent = s.text
		text.style.color = s.colour
		parent.appendChild(text)
		document.body.appendChild(band)
	}, spec)
}

/**
 * Remove what inject() appended.
 *
 * @param {object} page The page.
 * @return {Promise<void>}
 */
function uninject(page) {
	return page.evaluate(() =>
		document.getElementById('pq-surface-selftest')?.remove(),
	)
}

/**
 * Open a page as an anonymous visitor and wait for the site to render.
 *
 * @param {object} browser The browser.
 * @param {number} width   The viewport width.
 * @param {string} url     The page address.
 * @return {Promise<object>} `{context, page}`.
 */
async function open(browser, width, url) {
	const context = await browser.newContext({
		viewport: { width, height: 1000 },
		storageState: { cookies: [], origins: [] },
	})
	const page = await context.newPage()
	await page.goto(url, { waitUntil: 'domcontentloaded' })
	await page
		.waitForFunction(
			() => !document.body.innerText.includes('Bezig met laden'),
			null,
			{
				timeout: 60000,
			},
		)
		.catch(() => {})
	await page.waitForTimeout(1500)
	// The footer is below the fold; a band nobody scrolled to is not measured.
	await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
	await page.waitForTimeout(300)
	return { context, page }
}

/**
 * Prove the instrument fires, and that it does not fire on a painted card.
 *
 * Injected in the browser, never in the token files, so a run that dies
 * halfway cannot leave the repository dirty.
 *
 * @param {object} browser The browser.
 * @return {Promise<Array<string>>} What the check failed to do.
 */
async function selfTest(browser) {
	const [portal, route] = PAGES[0]
	const { context, page } = await open(browser, 1440, siteUrl(portal, route))
	const missed = []

	await inject(page, {
		band: 'rgb(1, 21, 72)',
		colour: 'rgba(255, 255, 255, 0.18)',
		text: 'Injected translucent text',
	})
	const translucent = (await audit(page)).failures.some(
		(f) => f.text === 'Injected translucent text',
	)
	await uninject(page)
	if (!translucent) {
		missed.push('translucent text on a dark band went undetected')
	}

	await inject(page, {
		band: 'rgb(255, 255, 255)',
		card: 'rgb(0, 51, 102)',
		colour: 'rgb(255, 255, 255)',
		text: 'Injected card label',
	})
	const cardOnBand = (await audit(page)).failures.some(
		(f) => f.text === 'Injected card label',
	)
	await uninject(page)
	if (cardOnBand) {
		missed.push(
			'white text on a painted card was judged against the band behind it',
		)
	}

	await context.close()
	return missed
}

if (BASE === '') {
	console.log(
		'SKIPPED: no PLAYWRIGHT_BASE_URL / NEXTCLOUD_URL / NC_BASE_URL / BASE_URL set',
	)
	console.log('(this is a skip, not a pass: nothing was measured)')
	process.exit(0)
}

const browser = await chromium.launch()
const missed = await selfTest(browser)
if (missed.length > 0) {
	console.error('SELF-TEST FAILED: this check cannot measure what it claims to:')
	missed.forEach((m) => console.error(`  - ${m}`))
	await browser.close()
	process.exit(1)
}
console.log(
	'  self-test ok: translucent text caught, painted card judged against itself\n',
)

let findings = 0
for (const width of WIDTHS) {
	for (const [portal, route] of PAGES) {
		const { context, page } = await open(browser, width, siteUrl(portal, route))
		const r = await audit(page)
		await context.close()

		const problems = []
		if (r.measured < MIN_NODES) {
			problems.push(
				`measured only ${r.measured} text nodes: the page did not render`,
			)
		}
		if (r.failures.length > 0) {
			problems.push(`${r.failures.length} of ${r.measured} below AA`)
		}

		const label = `@${String(width).padEnd(5)} ${(portal + route).padEnd(30)}`
		if (problems.length === 0) {
			console.log(`  ok   ${label} ${r.measured} nodes`)
			continue
		}
		findings++
		console.error(`  FAIL ${label}`)
		problems.forEach((p) => console.error(`         ${p}`))
		r.failures
			.slice(0, 5)
			.forEach((f) =>
				console.error(
					`         ${f.ratio}  ${f.colour} on ${f.on}  "${f.text}"`,
				),
			)
	}
}
await browser.close()

if (findings > 0) {
	console.error(`\n${findings} page/width combination(s) with findings`)
	process.exit(1)
}
console.log(`\nall ${PAGES.length * WIDTHS.length} page/width combinations pass`)
