#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
//
// site-surfaces.spec.mjs — the public chrome meets WCAG 2.1 AA on what is
// actually painted (portal-theme-blocks-and-contributed-pages REQ-PTB-013).
//
// For every page at 1440px and 390px: no text below 4.5:1 (3:1 when large)
// against the first ancestor that paints a background, exactly one h1, no
// heading level skipped downwards, and no horizontal scroll. Before judging a
// single page it proves it can fail: it injects low-contrast text, a second
// h1, a skipped level and a 3000px element, and refuses to report anything
// when one goes undetected.
//
// Usage:
//   PLAYWRIGHT_BASE_URL=http://localhost:8095 npm run check:surfaces
//
// Optional: PORTALIQ_SURFACE_PAGES="zuiddrecht:/,zuiddrecht:/contact" names
// the portal:route pairs to check. There is deliberately no default instance:
// with no PLAYWRIGHT_BASE_URL / NEXTCLOUD_URL / NC_BASE_URL / BASE_URL the run
// says it measured nothing and stops. Each page opens in a fresh browser
// context with an empty storage state, so it is checked as a signed-out
// visitor sees it.

import { chromium } from '@playwright/test'
import { auditContrast } from '../src/site/lib/contrast.js'

const BASE = (
	process.env.PLAYWRIGHT_BASE_URL
	?? process.env.NEXTCLOUD_URL
	?? process.env.NC_BASE_URL
	?? process.env.BASE_URL
	?? ''
).replace(/\/+$/, '')

const DEFAULT_PAGES = [
	'zuiddrecht:/',
	'zuiddrecht:/afval',
	'zuiddrecht:/contact',
	'zuiddrecht:/contact/vraag',
	'zuiddrecht:/bestuur',
	'zuiddrecht:/onderwerpen',
]

const PAGES = (process.env.PORTALIQ_SURFACE_PAGES || DEFAULT_PAGES.join(','))
	.split(',')
	.map((pair) => pair.trim())
	.filter(Boolean)
	.map((pair) => {
		const at = pair.indexOf(':')
		return [pair.slice(0, at), pair.slice(at + 1) || '/']
	})

const WIDTHS = [1440, 390]

/**
 * Everything the check needs from one rendered page. Runs in the browser,
 * with `auditContrast` carried in beside it (see probeSource()).
 *
 * @return {object} The findings for the page.
 */
function probe () {
	const { failures, measured } = auditContrast(document.body)

	const outline = [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')]
		.filter((h) => h.getClientRects().length > 0)
		.map((h) => ({ level: Number(h.tagName.slice(1)), text: (h.textContent || '').trim().slice(0, 40) }))

	// Only a DOWNWARD jump skips a level; going back up is normal.
	const skipped = []
	for (let i = 1; i < outline.length; i++) {
		if (outline[i].level - outline[i - 1].level > 1) {
			skipped.push(`h${outline[i - 1].level} → h${outline[i].level} at "${outline[i].text}"`)
		}
	}

	return {
		failures,
		measured,
		skipped,
		h1: outline.filter((h) => h.level === 1).map((h) => h.text),
		overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
	}
}

/**
 * The probe's source with the shared contrast audit carried in: one
 * definition of what fails AA, not a second copy in this file.
 *
 * @return {string} An expression evaluating to the probe.
 */
function probeSource() {
	return `() => { const auditContrast = ${auditContrast.toString()}; return (${probe.toString()})() }`
}

/**
 * The site URL of one portal route.
 *
 * @param {string} portal The portal slug.
 * @param {string} route  The in-portal route.
 * @return {string} The URL.
 */
function siteUrl(portal, route) {
	return `${BASE}/index.php/apps/portaliq/site?portal=${encodeURIComponent(portal)}&route=${encodeURIComponent(route)}`
}

/**
 * Open one page as a signed-out visitor and wait for it to settle.
 *
 * @param {object} browser The browser.
 * @param {number} width   The viewport width.
 * @param {string} portal  The portal slug.
 * @param {string} route   The route.
 * @return {Promise<object>} The page and its context.
 */
async function open(browser, width, portal, route) {
	const context = await browser.newContext({
		viewport: { width, height: 1000 },
		storageState: { cookies: [], origins: [] },
	})
	const page = await context.newPage()
	await page.goto(siteUrl(portal, route), { waitUntil: 'domcontentloaded' })
	await page
		.waitForFunction(() => document.querySelector('[data-testid="site-loading"]') === null, null, { timeout: 60000 })
		.catch(() => {})
	await page.waitForTimeout(1000)
	await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
	await page.waitForTimeout(300)
	return { page, context }
}

/**
 * Run the probe after an injected defect, then remove the defect.
 *
 * @param {object} page   The page.
 * @param {string} inject What to inject: contrast, h1, level or overflow.
 * @return {Promise<object>} The findings with the defect in place.
 */
function probeWith(page, inject) {
	return page.evaluate(([source, kind]) => {
		const added = []
		if (kind === 'contrast') {
			const p = document.createElement('p')
			p.textContent = 'Injected faint text'
			p.style.cssText = 'color: rgba(255,255,255,0.15); background: rgb(255,255,255); padding: 8px;'
			document.body.appendChild(p)
			added.push(p)
		}
		if (kind === 'h1') {
			const h = document.createElement('h1')
			h.textContent = 'Injected second heading'
			document.body.appendChild(h)
			added.push(h)
		}
		if (kind === 'level') {
			const h2 = document.createElement('h2')
			h2.textContent = 'Injected section'
			const h4 = document.createElement('h4')
			h4.textContent = 'Injected deep heading'
			document.body.append(h2, h4)
			added.push(h2, h4)
		}
		if (kind === 'overflow') {
			const wide = document.createElement('div')
			wide.style.cssText = 'inline-size: 3000px; block-size: 4px;'
			document.body.appendChild(wide)
			added.push(wide)
		}
		const result = new Function(`return (${source})`)()()
		added.forEach((node) => node.remove())
		return result
	}, [probeSource(), inject])
}

/**
 * Prove the instrument fires before it may report a pass.
 *
 * @param {object} browser The browser.
 * @return {Promise<Array<string>>} What went undetected.
 */
async function selfTest(browser) {
	const [portal, route] = PAGES[0]
	const { page, context } = await open(browser, 1440, portal, route)
	const missed = []
	const base = await probeWith(page, '')

	if ((await probeWith(page, 'contrast')).failures.length <= base.failures.length) {
		missed.push('contrast (faint text went undetected)')
	}
	if ((await probeWith(page, 'h1')).h1.length <= base.h1.length) {
		missed.push('heading count (a second h1 went uncounted)')
	}
	if ((await probeWith(page, 'level')).skipped.length <= base.skipped.length) {
		missed.push('heading order (an h4 straight after an h2 went undetected)')
	}
	if ((await probeWith(page, 'overflow')).overflow <= 0) {
		missed.push('overflow (a 3000px element did not widen the document)')
	}

	await context.close()
	return missed
}

if (BASE === '') {
	console.log('SKIPPED: no PLAYWRIGHT_BASE_URL / NEXTCLOUD_URL / NC_BASE_URL / BASE_URL set.')
	console.log('This is not a pass: nothing was measured.')
	process.exit(0)
}

const browser = await chromium.launch()
const undetected = await selfTest(browser)
if (undetected.length > 0) {
	console.error('SELF-TEST FAILED: this check cannot detect what it claims to:')
	undetected.forEach((m) => console.error(`  - ${m}`))
	await browser.close()
	process.exit(1)
}
console.log('self-test ok: contrast, heading count, heading order and overflow all detected')

let findings = 0
for (const width of WIDTHS) {
	for (const [portal, route] of PAGES) {
		const { page, context } = await open(browser, width, portal, route)
		const r = await probeWith(page, '')
		await context.close()

		const problems = []
		r.failures.forEach((f) => problems.push(`contrast ${f.ratio}:1 < ${f.required}:1 "${f.text}"`))
		if (r.h1.length !== 1) {
			problems.push(`${r.h1.length} h1 elements (${r.h1.join(' | ')})`)
		}
		r.skipped.forEach((s) => problems.push(`skipped heading level: ${s}`))
		if (r.overflow > 0) {
			problems.push(`scrolls ${r.overflow}px horizontally`)
		}
		if (r.measured === 0) {
			problems.push('no text measured: the page did not render')
		}

		findings += problems.length
		console.log(`${problems.length === 0 ? 'ok  ' : 'FAIL'} ${portal}${route} @${width}px (${r.measured} text nodes)`)
		problems.forEach((p) => console.log(`       ${p}`))
	}
}

await browser.close()
if (findings > 0) {
	console.error(`\n${findings} finding(s)`)
	process.exit(1)
}
console.log('\nall surfaces pass')
