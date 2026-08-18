#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// editor-parity.spec.mjs — the canvas and the public route render the same DOM.
//
// Usage:
//   PORTALIQ_BASE=http://localhost:8080 node tests/editor-parity.spec.mjs
//
// THIS IS THE PROPERTY THE WHOLE EDITOR RESTS ON (task 4.7).
//
// An editor that draws its own approximation of a block is an editor that lies:
// the author tunes something that is not what ships, and the difference only
// appears after publishing — on a live government page, to visitors. Every
// other decision in the editor follows from wanting this to be true: the canvas
// mounts the same components, its document includes the same asset partial, and
// its chrome is scoped so it cannot reach into a block.
//
// IT HAS ALREADY EARNED ITS PLACE. Written after the editor worked, it found
// that the canvas rendered no page-title `h1` — which the public route does —
// and the omission was invisible until the heading guardrail stayed silent on a
// page whose outline was genuinely broken.
//
// SKIPS RATHER THAN FAILS with no instance, but says which it did.

import { chromium } from '@playwright/test'

const BASE = process.env.PORTALIQ_BASE || 'http://localhost:8080'
const USER = process.env.PORTALIQ_USER || 'admin'
const PASSWORD = process.env.PORTALIQ_PASSWORD || 'admin'

// Pages with different block shapes: markdown + cards, a hero, and a glossary.
const PAGES = [
	['lafranken', '/aanvragen'],
	['lafranken', '/'],
	['lafranken', '/begrippen'],
]

/**
 * The markup a page renders, reduced to what both sides must agree on.
 *
 * NORMALISATION IS THE RISK HERE, so it is kept to things that are provably
 * not the block's own rendering: Vue's per-build scoped-style attribute, the
 * canvas's own selection state, and its block wrappers. Normalising away
 * anything else would be normalising away the differences this test exists to
 * find — so nothing about a block's tags, classes, text or ARIA is touched.
 *
 * @param {string} selector The root to read.
 * @return {string} The normalised markup.
 */
function markupOf(selector) {
	const root = document.querySelector(selector)
	if (!root) {
		return ''
	}

	const clone = root.cloneNode(true)

	// The editor's own affordances, which are chrome rather than content.
	for (const wrapper of clone.querySelectorAll('.pq-canvas__block')) {
		wrapper.replaceWith(...wrapper.childNodes)
	}
	for (const el of clone.querySelectorAll('[data-selected]')) {
		el.removeAttribute('data-selected')
	}
	for (const el of clone.querySelectorAll('[data-region]')) {
		el.removeAttribute('data-region')
	}
	// The empty-region invitation exists only in the editor: a public page
	// renders nothing for an empty region, which is correct, but an author
	// cannot drop a block somewhere they cannot see.
	for (const el of clone.querySelectorAll('.pq-canvas__empty')) {
		el.remove()
	}

	return clone.innerHTML
		.replace(/ data-v-[0-9a-f]+=""/g, '')
		.replace(/\?v=\d+/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		// `returnTo` ENCODES THE DOCUMENT'S OWN URL, so it is necessarily
		// different between `/site` and `/editor` — this is the one value that
		// SHOULD differ, and normalising it is not looking away: the link's
		// destination, its mode, its test id and its label are all still
		// compared, and a sign-in control that went missing (which is what the
		// first run of this test found) still fails.
		.replace(/returnTo=[^"&]*/g, 'returnTo=«document»')
		.replace(/\s+/g, ' ')
		.trim()
}

/**
 * Whether an instance is reachable.
 *
 * @return {Promise<boolean>} True when the site endpoint answers.
 */
async function reachable() {
	try {
		const res = await fetch(`${BASE}/index.php/apps/portaliq/api/content/site?portal=lafranken`)
		return res.ok
	} catch {
		return false
	}
}

if ((await reachable()) === false) {
	console.log(`SKIPPED — no portaliq instance at ${BASE}`)
	console.log('(this is a skip, not a pass: nothing was compared)')
	process.exit(0)
}

const browser = await chromium.launch()
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
const page = await context.newPage()

/**
 * Wait for a rendered portal page or canvas.
 *
 * @param {string} selector What must appear.
 * @return {Promise<void>} Resolves when it has.
 */
async function settle(selector) {
	await page.waitForSelector(selector, { timeout: 60000 }).catch(() => {})
	await page.waitForTimeout(1800)
}

// THE EDITOR IS BEHIND A LOGIN, so this signs in first. A parity test that
// silently compared a login page against a portal would report a difference
// nobody could act on — or, worse, two empty strings and a pass.
await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' })
await page.fill('input[name="user"]', USER)
await page.fill('input[name="password"]', PASSWORD)
await page.click('button[type="submit"]')
await page.waitForTimeout(2500)

let differences = 0

for (const [portal, route] of PAGES) {
	const query = `portal=${portal}&route=${encodeURIComponent(route)}`

	await page.goto(`${BASE}/index.php/apps/portaliq/site?${query}`, { waitUntil: 'domcontentloaded' })
	await settle('header.ac-header')
	const live = await page.evaluate(
		([sel]) => ({
			header: window.__markup(sel.header),
			footer: window.__markup(sel.footer),
			main: window.__markup(sel.main),
		}),
		[{ header: 'header.ac-header', footer: 'footer.ac-footer', main: 'main' }],
	).catch(() => null)

	// `__markup` has to exist in the page; inject it rather than assuming.
	const readLive = live || (await page.evaluate((source) => {
		// eslint-disable-next-line no-eval
		window.__markup = eval(`(${source})`)
		return {
			header: window.__markup('header.ac-header'),
			footer: window.__markup('footer.ac-footer'),
			main: window.__markup('main'),
		}
	}, markupOf.toString()))

	await page.goto(`${BASE}/index.php/apps/portaliq/editor?${query}`, { waitUntil: 'domcontentloaded' })
	await settle('[data-testid="editor-canvas"] header.ac-header')
	const canvas = await page.evaluate((source) => {
		// eslint-disable-next-line no-eval
		window.__markup = eval(`(${source})`)
		return {
			header: window.__markup('[data-testid="editor-canvas"] header.ac-header'),
			footer: window.__markup('[data-testid="editor-canvas"] footer.ac-footer'),
			main: window.__markup('[data-testid="editor-canvas"] main'),
		}
	}, markupOf.toString())

	// A COMPARISON OF TWO EMPTY STRINGS IS NOT A PASS. Either side failing to
	// render would otherwise report perfect agreement.
	const empty = Object.entries({ live: readLive, canvas })
		.flatMap(([side, m]) => Object.entries(m).filter(([, v]) => !v).map(([part]) => `${side}.${part}`))
	if (empty.length > 0) {
		console.error(`  FAIL ${portal}${route} — nothing rendered: ${empty.join(', ')}`)
		differences++
		continue
	}

	const parts = ['header', 'main', 'footer'].filter((part) => readLive[part] !== canvas[part])
	if (parts.length === 0) {
		console.log(`  ok   ${portal}${route}`)
		continue
	}

	differences++
	console.error(`  DIFF ${portal}${route} — ${parts.join(', ')}`)
	for (const part of parts) {
		const a = readLive[part]
		const b = canvas[part]
		let i = 0
		while (i < a.length && i < b.length && a[i] === b[i]) i++
		console.error(`    ${part} diverges at ${i}:`)
		console.error(`      public: …${a.slice(Math.max(0, i - 60), i + 100)}`)
		console.error(`      canvas: …${b.slice(Math.max(0, i - 60), i + 100)}`)
	}
}

await browser.close()

if (differences > 0) {
	console.error(`\n${differences} page(s) where the canvas and the public route disagree`)
	process.exit(1)
}

console.log('\nthe canvas renders what the public route renders')
