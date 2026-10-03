#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// check-site-chunks.js: after `npm run build:site`, prove from the source maps
// that no site chunk holds React or a JavaScript module of a Den Haag package,
// and that the site's entry holds nothing of a Den Haag package at all
// (site-mijn-omgeving-components REQ-SMO-001, design D1).
//
// The Den Haag packages declare `react` as a peer, so npm installs it. It must
// never reach a visitor: the components import only each package's CSS. The
// CSS itself loads on demand, with the component that uses it, so the entry
// stays inside its budget.
//
// A build without source maps proves nothing, so finding none is a failure,
// not a pass.
//
// Usage:
//   node scripts/check-site-chunks.js        (runs after build:site)
//
// Exit codes:
//   0 every map read, nothing forbidden found
//   1 a forbidden module in a chunk, Den Haag code in the entry, or no maps

'use strict'

const fs = require('fs')
const path = require('path')

const DIR = path.join(__dirname, '..', 'js')
const ENTRY = 'portaliq-site.js.map'

/** Modules no site chunk may hold. */
const FORBIDDEN = [
	/node_modules\/(react|react-dom|scheduler)\//,
	/node_modules\/@gemeente-denhaag\/[^/]+\/dist\/(mjs|cjs)\//,
]

const maps = fs.existsSync(DIR)
	? fs
			.readdirSync(DIR)
			.filter((f) => /^portaliq-site.*\.js\.map$/.test(f))
			.filter((f) => !f.startsWith('portaliq-site-editor'))
	: []

if (!maps.includes(ENTRY)) {
	console.error(
		`check-site-chunks: no ${ENTRY} in js/; run npm run build:site first.`,
	)
	process.exit(1)
}

const problems = []
const cssChunks = []
for (const file of maps) {
	const sources =
		JSON.parse(fs.readFileSync(path.join(DIR, file), 'utf8')).sources || []
	for (const source of sources) {
		if (FORBIDDEN.some((re) => re.test(source))) {
			problems.push(`${file}: ${source}`)
		}
		if (/@gemeente-denhaag\//.test(source)) {
			if (file === ENTRY) {
				problems.push(`${file} (the entry): ${source}`)
			} else if (!cssChunks.includes(file)) {
				cssChunks.push(file)
			}
		}
	}
}

if (problems.length > 0) {
	console.error('check-site-chunks: forbidden modules in the site bundle:')
	for (const line of problems) {
		console.error(`  ${line}`)
	}
	process.exit(1)
}

console.log(
	`check-site-chunks: ${maps.length} maps read, no React or Den Haag JavaScript; Den Haag CSS only in ${cssChunks.length} lazy chunk(s).`,
)
