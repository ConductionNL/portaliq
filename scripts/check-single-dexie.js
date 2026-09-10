#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// check-single-dexie.js: the Dexie singleton guard.
//
// PROVENANCE
//
//   Taken from openregister, where it landed as
//   ConductionNL/openregister#3307 ("build(guard): fail the build when two
//   Dexie versions ship in one chunk set"). Kept deliberately close to that
//   original so a future fix on either side ports across as a small diff.
//   Two changes only, both described below: the bundle set this repo emits,
//   and a second version-literal pattern so the guard also reads development
//   bundles. Ported under WOO-562.
//
// WHY THIS EXISTS
//
//   Dexie refuses to initialise twice in one page: when a second copy loads
//   at a different version it throws "Two different versions of Dexie loaded
//   in the same app" at module init, before the SPA mounts. Nextcloud loads
//   openregister's integration-global script on EVERY page of the instance,
//   next to whichever app's own bundles, so a version drift between any two
//   built chunks anywhere on the instance blanks an app SPA. That is not
//   hypothetical, and this app was on both ends of it:
//
//     2026-09-04  openregister at dexie 4.4.4 beside opencatalogi at 4.4.5
//                 -> the opencatalogi dashboard rendered as bare chrome.
//     2026-09-07  a stale portaliq bundle at 4.4.4 (nextcloud-vue 2.19.0
//                 still vendored its own copy) beside openregister at 4.4.5
//                 -> portaliq admin rendered as bare chrome.
//
//   Two things have to hold, and this script checks both:
//
//     1. every built chunk that embeds a Dexie copy embeds the SAME version;
//     2. that version is the one package-lock.json resolves, so a stale
//        chunk left over from an earlier build (or a dependency that vendors
//        its own copy, as @conduction/nextcloud-vue did before its build
//        externalised dexie) cannot ship unnoticed.
//
// HOW IT DETECTS A COPY
//
//   Dexie's own duplicate check ships in every copy of the library, so a
//   built chunk embeds Dexie exactly when it contains the error string
//   "Two different versions of Dexie". Inside such a chunk the version
//   literal takes one of two shapes, and the guard reads both:
//
//     production   `semVer:"x.y.z"`         (Dexie.semVer, minified)
//     development  `DEXIE_VERSION = 'x.y.z'` (the source constant, kept
//                                             verbatim by an unminified build)
//
//   openregister's original only matched the production shape, which is all
//   `postbuild` ever sees. Standalone `npm run check:dexie` after a
//   `npm run dev` build then hit the "sentinel but no version" branch and
//   failed on a perfectly good bundle set, which is the one failure mode a
//   guard must not have. Both patterns were verified against this repo's
//   built chunks (development) and openregister's released bundles
//   (production).
//
// WHAT IT SCANS
//
//   Every `*.js` in `js/`, so the bundle set is whatever the build emits and
//   there is no per-entry list here to fall out of date. That matters in this
//   repo more than in openregister: `npm run build` is a composite of FOUR
//   webpack configs (admin, portal, site, traffic) emitting
//   `portaliq-main.js`, `portaliq-portal.js`, `portaliq-site.js`,
//   `portaliq-traffic.js` and `portaliq-traffic-recorder.js` plus their split
//   chunks. Any pair of them can meet openregister's integration-global in
//   one page, and the set has grown twice already — a per-entry list here
//   would be a gate that goes quiet the next time an entry is added.
//
// WHEN IT RUNS
//
//   As `postbuild`, so it runs wherever `npm run build` runs: locally, in the
//   shared quality workflow's Frontend Build job, and in the shared release
//   workflow, which builds before it packages `js/` into the App Store
//   tarball. Standalone via `npm run check:dexie`. When js/ does not exist
//   yet it skips loudly instead of failing.
//
// Exit codes:
//   0: zero or one Dexie version across js/, matching the lockfile
//   1: two or more versions, or a version the lockfile does not resolve

const fs = require('fs')
const path = require('path')

const repoRoot = path.join(__dirname, '..')
const jsDir = path.join(repoRoot, 'js')

const SENTINEL = 'Two different versions of Dexie'
const VERSION_PATTERNS = [
	// Production: Dexie.semVer survives minification as a property literal.
	/semVer\s*[:=]\s*["']([0-9][0-9A-Za-z.+-]*)["']/g,
	// Development: the unminified source constant Dexie.semVer is assigned from.
	/DEXIE_VERSION\s*=\s*["']([0-9][0-9A-Za-z.+-]*)["']/g,
]

if (!fs.existsSync(jsDir)) {
	console.log(
		'i dexie singleton: js/ not built yet, skipping (run npm run build first)',
	)
	process.exit(0)
}

let expected = null
try {
	const lock = JSON.parse(
		fs.readFileSync(path.join(repoRoot, 'package-lock.json'), 'utf8'),
	)
	expected =
		(lock.packages
			&& lock.packages['node_modules/dexie']
			&& lock.packages['node_modules/dexie'].version)
		|| null
} catch (e) {
	console.log(
		`i dexie singleton: could not read package-lock.json (${e.message}); checking chunk agreement only`,
	)
}

const findings = []
for (const name of fs.readdirSync(jsDir).sort()) {
	if (!name.endsWith('.js')) {
		continue
	}
	const text = fs.readFileSync(path.join(jsDir, name), 'utf8')
	if (!text.includes(SENTINEL)) {
		continue
	}
	const versions = new Set()
	for (const re of VERSION_PATTERNS) {
		for (const m of text.matchAll(re)) {
			versions.add(m[1])
		}
	}
	if (versions.size === 0) {
		// A chunk carries Dexie's error string but no recognisable version
		// literal. The marker contract changed, so the guard can no longer
		// see, and a guard that cannot see must say so rather than pass.
		console.error(
			`x dexie singleton: js/${name} embeds Dexie (sentinel found) but no version literal matched; update VERSION_PATTERNS in ${path.basename(__filename)}`,
		)
		process.exit(1)
	}
	for (const v of versions) {
		findings.push({ file: name, version: v })
	}
}

if (findings.length === 0) {
	console.log('+ dexie singleton: no built chunk embeds Dexie')
	process.exit(0)
}

const distinct = [...new Set(findings.map((f) => f.version))].sort()

for (const f of findings) {
	console.log(`  js/${f.file}: dexie ${f.version}`)
}

if (distinct.length > 1) {
	console.error(
		`x dexie singleton: ${distinct.length} different Dexie versions in one chunk set (${distinct.join(', ')}). Loading any two of these chunks in one page throws at module init and the SPA never mounts. Rebuild from a clean js/ with a single resolved dexie.`,
	)
	process.exit(1)
}

if (expected && distinct[0] !== expected) {
	console.error(
		`x dexie singleton: built chunks carry dexie ${distinct[0]} but package-lock.json resolves ${expected}. A chunk is stale, or a dependency vendors its own copy. Rebuild from a clean js/.`,
	)
	process.exit(1)
}

console.log(
	`+ dexie singleton: one Dexie version (${distinct[0]}) across ${findings.length} chunk(s)${expected ? ', matching the lockfile' : ''}`,
)
