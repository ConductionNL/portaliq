#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// shell-snapshot.mjs — capture or compare the rendered shell, exactly.
//
// Usage:
//   node tests/shell-snapshot.mjs capture <dir>
//   node tests/shell-snapshot.mjs compare <dir>
//
// WHY THIS EXISTS. Moving the header and footer out of hard-coded markup and
// into region data is a migration, and "a migration that changes every portal's
// appearance is not a migration" (task 2.3). The only way to say that honestly
// is to hold the rendered DOM against the version before the move — not a
// screenshot, which compresses away attribute and structure changes, and not a
// visual review, which cannot see a lost `aria-label`.
//
// It captures the SHELL, not the page body: the body is what regions are
// supposed to make configurable, while the header, footer and the main
// element's own attributes are what must not move.

import { chromium } from 'playwright'
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs'
import { join } from 'node:path'

const BASE = process.env.PORTALIQ_BASE || 'http://localhost:8080'
const MODE = process.argv[2]
const DIR = process.argv[3]

const PAGES = [
	['conduction-klant', '/'],
	['lafranken', '/'],
	['lafranken', '/mijn-zaken'],
	['lafranken', '/aanvragen'],
	['lafranken', '/begrippen'],
	['lafranken', '/diensten/portaliq/meldingen'],
]

if (!MODE || !DIR) {
	console.error('usage: shell-snapshot.mjs <capture|compare> <dir>')
	process.exit(2)
}

/**
 * The shell's markup, normalised.
 *
 * Normalisation is deliberately MINIMAL — only things that legitimately vary
 * between two runs of the same code are removed. Anything else surviving into
 * the comparison is the point: a changed class, a dropped attribute or a
 * different element order must show up as a difference.
 *
 * @return {object} `{header, footer, mainAttrs}`.
 */
function capture() {
	const clean = (html) =>
		String(html || '')
			// Vue's scoped-style attribute changes when a component moves file,
			// which is exactly the refactor under test and is not a rendering
			// difference.
			.replace(/ data-v-[0-9a-f]+=""/g, '')
			// Cache-busting query strings on assets.
			.replace(/\?v=\d+/g, '')
			.replace(/\s+/g, ' ')
			.trim()

	const header = document.querySelector('header.ac-header')
	const footer = document.querySelector('footer.ac-footer')
	const main = document.querySelector('main')

	return {
		header: clean(header && header.outerHTML),
		footer: clean(footer && footer.outerHTML),
		// THE SAME NORMALISATION HAS TO APPLY HERE, and it did not at first.
		// Vue's scoped-style attribute is regenerated on every build of the
		// SFC, so comparing the raw attribute list reported a difference on
		// every single run — an instrument that always fires teaches you to
		// ignore it, which is worse than one that never fires. Caught by the
		// positive control: adding one attribute to the header produced the
		// expected header diff AND an unrelated `mainAttrs` diff.
		mainAttrs: main
			? [...main.attributes]
				.filter((a) => a.name.startsWith('data-v-') === false)
				.map((a) => `${a.name}="${a.value}"`)
				.sort()
				.join(' ')
			: '',
	}
}

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } })

mkdirSync(DIR, { recursive: true })
let differences = 0

for (const [portal, route] of PAGES) {
	const url = `${BASE}/index.php/apps/portaliq/site?portal=${portal}&route=${encodeURIComponent(route)}`
	await page.goto(url, { waitUntil: 'domcontentloaded' })
	await page
		.waitForFunction(() => !document.body.innerText.includes('Bezig met laden'), {
			timeout: 60000,
		})
		.catch(() => {})
	await page.waitForTimeout(1500)

	const shot = await page.evaluate(capture)
	const name = `${portal}${route}`.replace(/[^a-z0-9]/gi, '_') + '.json'
	const path = join(DIR, name)

	if (MODE === 'capture') {
		writeFileSync(path, JSON.stringify(shot, null, 1))
		console.log(`  captured ${portal}${route}`)
		continue
	}

	if (existsSync(path) === false) {
		console.error(`  MISSING baseline for ${portal}${route}`)
		differences++
		continue
	}

	const before = JSON.parse(readFileSync(path, 'utf8'))
	const changed = Object.keys(shot).filter((k) => shot[k] !== before[k])
	if (changed.length === 0) {
		console.log(`  ok   ${portal}${route}`)
		continue
	}

	differences++
	console.error(`  DIFF ${portal}${route} — ${changed.join(', ')}`)
	for (const key of changed) {
		// Print the first divergence rather than two walls of markup.
		const a = before[key]
		const b = shot[key]
		let i = 0
		while (i < a.length && i < b.length && a[i] === b[i]) i++
		console.error(`    ${key} diverges at ${i}:`)
		console.error(`      before: …${a.slice(Math.max(0, i - 60), i + 90)}`)
		console.error(`      after : …${b.slice(Math.max(0, i - 60), i + 90)}`)
	}
}

await browser.close()

if (MODE === 'compare' && differences > 0) {
	console.error(`\n${differences} page(s) whose shell changed`)
	process.exit(1)
}

console.log(MODE === 'capture' ? '\nbaseline captured' : '\nthe shell is unchanged')
