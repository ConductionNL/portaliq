#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// widget-registry.spec.mjs: the NL Design System widget registry and the
// coverage record (site-nlds-widget-palette REQ-SNW-010, REQ-SNW-011).
//
// It reads the registry and the record as DATA, so it runs without Vue: a
// meta carries no imports by design, which is what makes that possible and
// is also what keeps the editor bundle out of the site's chunks.
//
// Usage:
//   node --test tests/widget-registry.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	coverageByPlacement,
	NLDS_COVERAGE,
	NLDS_PLACEMENTS,
	SITE_COMPOSITIONS,
} from '../src/site/widgets/coverage.js'
import {
	loaders,
	metaProblems,
	metas,
	registryProblems,
	WIDGET_GROUPS,
} from '../src/site/widgets/index.js'

/**
 * The shared registry's keys, read from the library's source rather than
 * imported: its entry point pulls `.vue` files, which node cannot load, and
 * what this test needs is the KEY LIST, not the components.
 *
 * @return {Set<string>} The keys the shared site registry declares.
 */
function sharedKeys() {
	const source = readFileSync(
		new URL(
			'../node_modules/@conduction/nextcloud-vue/src/public/index.js',
			import.meta.url,
		),
		'utf8',
	)
	const block = source.slice(source.indexOf('siteBlockRegistry'))
	const keys = new Set()
	for (const match of block.matchAll(/^\t([A-Za-z][A-Za-z0-9]*):/gm)) {
		keys.add(match[1])
	}

	return keys
}

/** The design that fixes the record, read as the authority it is. */
const DESIGN = new URL(
	'../openspec/changes/site-nlds-widget-palette/design.md',
	import.meta.url,
)

/**
 * Design D1's table, as rows.
 *
 * @return {Array<Array<string>>} The cells of each numbered row.
 */
function designRows() {
	return readFileSync(DESIGN, 'utf8')
		.split('\n')
		.filter((line) => /^\|\s*\d+\s*\|/.test(line))
		.map((line) =>
			line
				.trim()
				.replace(/^\|/, '')
				.replace(/\|$/, '')
				.split('|')
				.map((cell) => cell.trim()),
		)
}

test('every meta describes itself, and every widget has both halves', () => {
	assert.deepEqual(registryProblems(), [], 'the registry must be sound')
	assert.ok(
		Object.keys(metas).length > 0,
		'a registry nothing is registered in proves nothing',
	)

	// The check can fail: a meta missing its group, its label or its size is
	// reported, with the key named.
	const broken = metaProblems(
		{
			key: 'nlBroken',
			group: 'nowhere',
			label: '',
			nlds: '',
			synonyms: 'no',
			fields: 'no',
			defaultSize: {},
			scope: 'maybe',
		},
		'nlBroken',
	)
	assert.equal(broken.length, 7, broken.join('; '))
	assert.deepEqual(metaProblems(null, 'nlGone'), ['nlGone: no meta'])
})

test('no widget key is a key of the shared dashboard registry', () => {
	// REQ-SNW-011: the shared registry already has `table` and friends, and a
	// widget renders publicly if and only if it is in the public map. A key
	// reused here would take over what that key renders, which is why they are
	// all `nl`-prefixed.
	const shared = sharedKeys()
	for (const key of Object.keys(metas)) {
		assert.ok(!shared.has(key), `${key} is also a shared registry key`)
		assert.match(key, /^nl[A-Z]/, `${key} must be nl-prefixed`)
	}

	// The guard is real: were `table` registered here, this is what would fire.
	assert.ok(
		shared.size > 0,
		'the shared registry must be readable for this to mean anything',
	)
})

test('the groups are the six of REQ-SNW-001, in order', () => {
	assert.deepEqual(
		WIDGET_GROUPS.map((entry) => entry.label),
		[
			'Inhoud',
			'Navigatie',
			'Formulieren',
			'Terugkoppeling',
			'Mijn omgeving',
			'Opmaak',
		],
	)
	for (const key of Object.keys(metas)) {
		assert.ok(
			WIDGET_GROUPS.some((entry) => entry.group === metas[key].group),
			`${key} sits in no group, so no heading would show it`,
		)
	}
})

test('each of the 101 components has exactly one placement', () => {
	// REQ-SNW-010 scenario "The count adds up".
	assert.equal(NLDS_COVERAGE.length, 101)

	const seen = new Map()
	for (const entry of NLDS_COVERAGE) {
		assert.ok(
			NLDS_PLACEMENTS.includes(entry.placement),
			`${entry.component}: ${entry.placement} is not a placement`,
		)
		assert.ok(entry.component !== '', `component ${entry.number} has no name`)
		assert.ok(
			!seen.has(entry.number),
			`component ${entry.number} is recorded twice`,
		)
		seen.set(entry.number, entry)
	}

	// Numbered 1 to 101 with nothing missing, so "exactly one each" is a
	// statement about every component rather than about the rows that happen
	// to be here.
	for (let number = 1; number <= 101; number++) {
		assert.ok(seen.has(number), `component ${number} is not recorded`)
	}

	const counts = coverageByPlacement()
	const total = Object.values(counts).reduce((sum, count) => sum + count, 0)
	assert.equal(total, 101, JSON.stringify(counts))
	assert.ok(
		counts.none <= 3,
		'a component that is not offered needs its reason read, not grown',
	)
})

test('a widget or field placement names a key, and the others say where instead', () => {
	for (const entry of NLDS_COVERAGE) {
		if (entry.placement === 'widget' || entry.placement === 'field') {
			assert.notEqual(
				entry.key,
				'',
				`${entry.component} is placeable but names no key`,
			)
			continue
		}

		assert.equal(
			entry.key,
			'',
			`${entry.component} is ${entry.placement} and should name no key`,
		)

		// `shell` and `inline` are their own reason: the page frame draws it,
		// or the text widget does. A `part` has to say WHICH widget draws it,
		// and a component that is not offered at all has to say why, or
		// "not offered" is a shrug rather than a decision (REQ-SNW-010).
		if (entry.placement === 'part' || entry.placement === 'none') {
			assert.notEqual(
				entry.note,
				'',
				`${entry.component} is ${entry.placement} and must say where it is drawn or why not`,
			)
		}
	}
})

test('the record and design D1 say the same thing', () => {
	// The record lives beside the registry so a test can count it; the design
	// is where it was decided. Two copies drift unless something compares
	// them, and this is that something.
	const rows = designRows()
	assert.equal(rows.length, 101, 'design D1 must still hold 101 rows')

	for (const row of rows) {
		const [number, component, , placement] = row
		const entry = NLDS_COVERAGE.find(
			(candidate) => candidate.number === Number(number),
		)
		assert.ok(entry, `design row ${number} is not in the record`)
		assert.equal(entry.component, component, `row ${number}: the name differs`)
		assert.equal(
			entry.placement,
			placement,
			`row ${number} (${component}): the placement differs`,
		)
	}
})

test('every widget renders its NL Design System classes and imports its CSS', () => {
	// REQ-SNW-010: a widget must render the component's class structure and
	// import that component's CSS package where one exists. Read from the
	// source, because a widget that imported no stylesheet would render
	// unstyled on a portal and look like a theming problem instead of a
	// missing import.
	const own = ['nlCodeBlock', 'nlVideo', 'nlYouTube']
	for (const key of Object.keys(loaders)) {
		const name = 'Nl' + key.slice(2)
		const source = readFileSync(
			new URL(`../src/site/widgets/${key}/${name}.vue`, import.meta.url),
			'utf8',
		)

		assert.match(
			source,
			/^import '(@utrecht|@nl-design-system-candidate)\/[^']+\.css'/m,
			`${key} imports no design-system stylesheet`,
		)
		// Either written on the element, or computed: nlHeading's class is
		// `utrecht-heading-${level}`, which is the component's job to decide.
		assert.match(
			source,
			/(class="[^"]*(utrecht-|nl-))|(`(utrecht|nl)-[a-z-]*\$\{)/,
			`${key} renders no design-system class`,
		)

		// A widget whose component has no upstream CSS may style itself, but
		// only from tokens (REQ-SNW-010's second scenario). None of these
		// three sets a colour at all; the check is that no hex or rgb() ever
		// appears in one.
		if (own.includes(key)) {
			assert.ok(
				/#[0-9a-f]{3,8}\b/i.test(source) === false && /rgba?\(/i.test(source) === false,
				`${key} names a colour of its own instead of a token`,
			)
		}
	}
})

test('the markdown class map covers the marks an author writes', () => {
	// T6: every tag in the map is a tag `cnRenderMarkdown` can produce, and
	// the new ones carry a design-system class so a portal's tokens reach
	// them. LI is deliberately absent: the list element styles its items.
	const source = readFileSync(
		new URL('../src/site/components/MarkdownBlock.vue', import.meta.url),
		'utf8',
	)
	for (const tag of ['STRONG', 'EM', 'SUB', 'SUP', 'MARK', 'CODE', 'PRE', 'HR', 'IMG']) {
		assert.match(source, new RegExp(`\\b${tag}: 'utrecht-`), `${tag} has no class`)
	}

	assert.ok(/\bLI: /.test(source) === false, 'LI must not be in the map')
})

test('every built widget is in the record as a widget, under its own key', () => {
	// A widget that exists but is recorded as a part, or under another key,
	// would make the count read as covered while the palette offered
	// something else.
	const composed = SITE_COMPOSITIONS.map((entry) => entry.key)
	for (const key of Object.keys(loaders).filter((k) => !composed.includes(k))) {
		const recorded = NLDS_COVERAGE.filter((entry) => entry.key === key)
		assert.equal(
			recorded.length,
			1,
			`${key} is recorded ${recorded.length} times`,
		)
		assert.equal(
			recorded[0].placement,
			'widget',
			`${key} is recorded as ${recorded[0].placement}`,
		)
		assert.equal(
			recorded[0].group,
			metas[key].group,
			`${key}: the record says ${recorded[0].group}, the meta says ${metas[key].group}`,
		)
	}
})

test('a composed widget is listed once, made of recorded components, and kept out of the record', () => {
	// site-school-blocks: the school portals' blocks are compositions, not NL
	// Design System components. They must be accounted for somewhere, and
	// only in one place, or the 101-row record would drift from design D1.
	const components = new Set(NLDS_COVERAGE.map((entry) => entry.component))
	const keys = SITE_COMPOSITIONS.map((entry) => entry.key)
	assert.equal(new Set(keys).size, keys.length, 'a composition is listed twice')
	for (const entry of SITE_COMPOSITIONS) {
		assert.ok(Object.hasOwn(loaders, entry.key), `${entry.key} has no loader`)
		assert.ok(Object.hasOwn(metas, entry.key), `${entry.key} has no meta`)
		assert.equal(
			NLDS_COVERAGE.some((row) => row.key === entry.key),
			false,
			`${entry.key} is in the NL Design System record as well`,
		)
		assert.ok(entry.composes.length > 0 && entry.why.trim() !== '', `${entry.key} says nothing about what it is`)
		for (const name of entry.composes) {
			assert.ok(components.has(name), `${entry.key} names "${name}", which is not in the record`)
		}
	}

	// And every built widget is in exactly one of the two lists.
	for (const key of Object.keys(loaders)) {
		const inRecord = NLDS_COVERAGE.some((row) => row.key === key)
		assert.ok(inRecord !== keys.includes(key), `${key} must be in exactly one of the record and the compositions`)
	}
})

test('the subject widgets are registered with a meta and a loader (home-and-theme-landing-pages)', () => {
	// Spec calls them featuredSubjects and portalCounts; the registry keeps
	// every key `nl`-prefixed (REQ-SNW-011), so they are nlFeaturedSubjects,
	// nlPortalCounts and nlSubjectLanding.
	const wanted = {
		nlFeaturedSubjects: 'Uitgelichte onderwerpen',
		nlPortalCounts: 'Wat we publiceren, in aantallen',
		nlSubjectLanding: 'Pagina van een onderwerp',
	}
	for (const [key, label] of Object.entries(wanted)) {
		assert.equal(metas[key]?.label, label, `${key} has no meta`)
		assert.equal(typeof loaders[key], 'function', `${key} has no loader`)
		assert.deepEqual(metaProblems(metas[key], key), [], `${key} meta is unsound`)
	}
})
