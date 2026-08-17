#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-surfaces.spec.mjs — what a visitor can actually read on a rendered page.
//
// Usage:
//   PORTALIQ_BASE=http://localhost:8080 node tests/site-surfaces.spec.mjs
//
// WHY THIS EXISTS AS A COMMITTED CHECK.
//
// Every contrast, heading and overflow figure this app has been tuned against
// was produced by a throwaway script that was deleted the same day. That makes
// each fix a one-time measurement: the next token change can put a heading back
// on a band it fails against and nothing says so. Three defects in this app were
// exactly that — a hero title at 2.31:1, header sign-in links at 1.84:1, and
// translucent white footer text on a band whose background a "neutral" default
// had removed, at 1.0:1.
//
// It answers three questions per page, all of which are properties of the
// RENDERED result and none of which can be checked from the stylesheet:
//
//   1. Does every piece of text meet WCAG AA against what is painted behind it?
//   2. Is there exactly one `h1`?
//   3. Does the page scroll sideways?
//
// SKIPS RATHER THAN FAILS when no instance is reachable, because this runs in
// environments that have no Nextcloud — but it distinguishes "skipped" from
// "passed" in its output, and a page it could not measure is a FAILURE, never a
// silent pass. A page that renders nothing scores zero contrast failures.
//
// @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md

import { chromium } from '@playwright/test'

const BASE = process.env.PORTALIQ_BASE || 'http://localhost:8080'

// The demo portals and every route they publish. Two portals on two different
// token sets is the point: a token that only one of them names is exactly the
// kind of change that breaks the other.
const PAGES = [
	['conduction-klant', '/'],
	['lafranken', '/'],
	['lafranken', '/mijn-zaken'],
	['lafranken', '/aanvragen'],
	['lafranken', '/begrippen'],
]

const WIDTHS = [1440, 390]

// A page with fewer measurable text nodes than this did not render.
const MIN_NODES = 5

/**
 * Everything the check needs from one rendered page.
 *
 * Runs inside the browser, so it is written as a single self-contained
 * function with no imports.
 *
 * @return {object} Findings for this page.
 */
const probe = () => {
	// `rgb()` / `rgba()` to [r, g, b, a]. The ALPHA is the part that matters and
	// the part an obvious implementation drops.
	const parse = (colour) => {
		const n = (colour.match(/[\d.]+/g) || ['0', '0', '0']).map(Number)
		return [n[0] || 0, n[1] || 0, n[2] || 0, n.length > 3 ? n[3] : 1]
	}

	const luminance = ([r, g, b]) => {
		const channel = (c) => {
			c /= 255
			return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
		}
		return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
	}

	// TRANSLUCENT TEXT IS COMPOSITED OVER ITS BACKDROP BEFORE IT IS MEASURED.
	//
	// Without this, `rgba(255,255,255,0.22)` is read as opaque white and scores
	// 17.85:1 against a navy band it can barely be seen on. That is not a
	// hypothetical: this footer is built from white at 0.55 to 0.85, and a
	// deliberate break to 0.22 passed this check until the compositing was
	// added. A contrast check that ignores alpha reports the colour somebody
	// typed, not the colour anybody sees.
	const composite = (fg, bg) => {
		const [fr, fg_, fb, fa] = fg
		const [br, bg_, bb] = bg
		return [
			fr * fa + br * (1 - fa),
			fg_ * fa + bg_ * (1 - fa),
			fb * fa + bb * (1 - fa),
		]
	}

	// THE BACKDROP IS THE NEAREST ANCESTOR THAT ACTUALLY PAINTS ONE.
	//
	// Not the nearest NAMED band: comparing against that produced a false
	// failure in this codebase once already. Nearly-transparent scrims are
	// skipped too — an 8% white overlay is not the surface a reader perceives,
	// the band under it is — and when nothing paints, the page's own background
	// is the answer rather than an assumed white, which is what manufactured a
	// 1.0 ratio for white text on a dark footer.
	const backdrop = (el) => {
		for (let node = el; node; node = node.parentElement) {
			const bg = getComputedStyle(node).backgroundColor
			const alpha = parseFloat((bg.match(/[\d.]+\)$/) || ['1)'])[0])
			if (bg && !/rgba\(0, 0, 0, 0\)/.test(bg) && alpha > 0.3) {
				return bg
			}
		}
		return getComputedStyle(document.body).backgroundColor || 'rgb(255,255,255)'
	}

	const failures = []
	let measured = 0

	for (const el of document.querySelectorAll(
		'h1,h2,h3,h4,p,a,span,button,li,dt,dd',
	)) {
		// Decoration is exempt: it carries no text a reader has to make out.
		if (el.closest('[aria-hidden="true"]')) continue

		const text = (el.textContent || '').trim()
		if (!text || el.children.length) continue

		const box = el.getBoundingClientRect()
		if (box.width < 4 || box.height < 4) continue

		const style = getComputedStyle(el)
		const behind = parse(backdrop(el))
		const front = composite(parse(style.color), behind)
		const [lighter, darker] = [luminance(front), luminance(behind)].sort(
			(a, b) => b - a,
		)
		const ratio = (lighter + 0.05) / (darker + 0.05)
		measured++

		// AA for body text. Large text is allowed 3:1, and treating it as 4.5
		// only ever over-reports — which is the safe direction for a check.
		if (ratio < 4.5) {
			failures.push({
				ratio: Number(ratio.toFixed(2)),
				text: text.slice(0, 30),
				colour: style.color,
				on: backdrop(el),
			})
		}
	}

	return {
		failures,
		measured,
		headings: [...document.querySelectorAll('h1')].map((h) =>
			(h.textContent || '').trim().slice(0, 40),
		),
		overflow:
			document.documentElement.scrollWidth
			- document.documentElement.clientWidth,
	}
}

/**
 * Whether an instance is reachable at all.
 *
 * @return {Promise<boolean>} True when the site endpoint answers.
 */
async function reachable() {
	try {
		const res = await fetch(
			`${BASE}/index.php/apps/portaliq/api/content/site?portal=${PAGES[0][0]}`,
		)
		return res.ok
	} catch {
		return false
	}
}

if ((await reachable()) === false) {
	console.log(`SKIPPED — no portaliq instance at ${BASE}`)
	console.log('(this is a skip, not a pass: nothing was measured)')
	process.exit(0)
}

const browser = await chromium.launch()
let findings = 0

/**
 * Prove the instrument can FAIL before letting it report a pass.
 *
 * A check that has quietly stopped detecting anything is indistinguishable
 * from a codebase with nothing to detect — both print "all pass". This one has
 * already been that: its first version dropped the alpha channel, so
 * `rgba(255,255,255,0.22)` on navy scored 17.85:1 and a deliberately broken
 * footer colour sailed through.
 *
 * So every run starts by injecting three defects into a real rendered page —
 * one per property the check claims to measure — and demanding that each one be
 * caught. Injected in the BROWSER, never in the token files: a control that
 * edits the repo can leave it dirty if the run dies halfway.
 *
 * @return {Promise<Array>} The names of any properties that failed to detect.
 */
async function selfTest() {
	const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } })
	const [portal, route] = PAGES[0]
	await page.goto(
		`${BASE}/index.php/apps/portaliq/site?portal=${portal}&route=${encodeURIComponent(route)}`,
		{ waitUntil: 'domcontentloaded' },
	)
	await page
		.waitForFunction(
			() => !document.body.innerText.includes('Bezig met laden'),
			{
				timeout: 60000,
			},
		)
		.catch(() => {})
	await page.waitForTimeout(1700)

	const missed = []

	// 1. CONTRAST — text the reader cannot make out. Deliberately TRANSLUCENT,
	//    because opaque-only detection is the exact defect this file shipped
	//    with: it must fail on the alpha, not on the three channels.
	const contrast = await page.evaluate((probeSource) => {
		const style = document.createElement('style')
		style.textContent =
			'.ac-footer__links a, .ac-footer__links a span { color: rgba(255,255,255,0.18) !important; }'
		document.head.appendChild(style)
		// eslint-disable-next-line no-eval
		const result = eval(`(${probeSource})`)()
		style.remove()
		return result
	}, probe.toString())
	if (contrast.failures.length === 0) {
		missed.push('contrast (translucent text on a dark band went undetected)')
	}

	// 2. HEADING COUNT — a second `h1`.
	const headings = await page.evaluate((probeSource) => {
		const extra = document.createElement('h1')
		extra.textContent = 'Injected second heading'
		document.body.appendChild(extra)
		// eslint-disable-next-line no-eval
		const result = eval(`(${probeSource})`)()
		extra.remove()
		return result
	}, probe.toString())
	if (headings.headings.length < 2) {
		missed.push('heading count (a second h1 went uncounted)')
	}

	// 3. HORIZONTAL OVERFLOW — something wider than the viewport.
	const overflow = await page.evaluate((probeSource) => {
		const wide = document.createElement('div')
		wide.style.cssText = 'inline-size: 3000px; block-size: 4px;'
		document.body.appendChild(wide)
		// eslint-disable-next-line no-eval
		const result = eval(`(${probeSource})`)()
		wide.remove()
		return result
	}, probe.toString())
	if (overflow.overflow <= 0) {
		missed.push('overflow (a 3000px element did not widen the document)')
	}

	await page.close()
	return missed
}

const undetected = await selfTest()
if (undetected.length > 0) {
	console.error('SELF-TEST FAILED — this check cannot detect what it claims to:')
	undetected.forEach((m) => console.error(`  - ${m}`))
	console.error(
		'\nRefusing to report a pass from an instrument that does not fire.',
	)
	await browser.close()
	process.exit(1)
}
console.log('  self-test ok — contrast, heading count and overflow all detected\n')

for (const width of WIDTHS) {
	const page = await browser.newPage({ viewport: { width, height: 1000 } })

	for (const [portal, route] of PAGES) {
		const url = `${BASE}/index.php/apps/portaliq/site?portal=${portal}&route=${encodeURIComponent(route)}`
		await page.goto(url, { waitUntil: 'domcontentloaded' })
		await page
			.waitForFunction(
				() => !document.body.innerText.includes('Bezig met laden'),
				{ timeout: 60000 },
			)
			.catch(() => {})
		await page.waitForTimeout(1700)
		// The footer is below the fold, and a band nobody scrolled to is a band
		// nobody measured.
		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
		await page.waitForTimeout(300)

		const r = await page.evaluate(probe)
		const problems = []

		if (r.measured < MIN_NODES) {
			problems.push(
				`measured only ${r.measured} text nodes — the page did not render`,
			)
		}
		if (r.failures.length) {
			problems.push(`${r.failures.length} of ${r.measured} below AA`)
		}
		if (r.headings.length !== 1) {
			problems.push(
				`${r.headings.length} h1 elements: ${JSON.stringify(r.headings)}`,
			)
		}
		if (r.overflow > 0) {
			problems.push(`${r.overflow}px of horizontal overflow`)
		}

		const label = `@${String(width).padEnd(5)} ${(portal + route).padEnd(26)}`
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

	await page.close()
}

await browser.close()

if (findings > 0) {
	console.error(`\n${findings} page/width combination(s) with findings`)
	process.exit(1)
}

console.log(`\nall ${PAGES.length * WIDTHS.length} page/width combinations pass`)
