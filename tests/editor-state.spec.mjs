#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// editor-state.spec.mjs — the editor's undo stack and its edit operations.
//
// Usage:
//   node tests/editor-state.spec.mjs
//
// WHY THESE TWO FILES ARE PURE, AND TESTED HERE RATHER THAN THROUGH THE UI.
//
// Undo is the feature an editor is judged by and the one that fails invisibly.
// A stack that keeps a REFERENCE instead of a copy passes every obvious test —
// push, undo, get the old value back — and then fails three edits later, when
// the object it kept has been mutated underneath it and "the old value" is the
// present. That failure is nearly impossible to see through a browser and
// trivial to see here.
//
// Run as a plain node script to match tests/site-auth.spec.mjs.

import {
	canRedo,
	canUndo,
	createHistory,
	record,
	redo,
	undo,
} from '../src/editor/lib/history.js'
import {
	insertBlock,
	moveBlock,
	removeBlock,
	resizeBlock,
	setField,
} from '../src/editor/lib/operations.js'
import { BLOCK_CATALOG, blocksForRegion, unrenderableFields } from '../src/site/lib/blockCatalog.js'
import { REGIONS } from '../src/site/lib/regions.js'

let failures = 0

/**
 * Assert deep equality, reporting the difference rather than a bare boolean.
 *
 * @param {string} what     What is being asserted.
 * @param {*}      actual   The value produced.
 * @param {*}      expected The value wanted.
 * @return {void}
 */
function assertEqual(what, actual, expected) {
	const a = JSON.stringify(actual)
	const e = JSON.stringify(expected)
	if (a === e) {
		console.log(`  ok   ${what}`)
		return
	}
	console.error(`  FAIL ${what}\n       expected ${e}\n       actual   ${a}`)
	failures += 1
}

const EMPTY = { header: [], hero: [], main: [], aside: [], footer: [] }

console.log('history')
{
	const h0 = createHistory(EMPTY)
	assertEqual('a fresh history cannot undo', canUndo(h0), false)
	assertEqual('nor redo', canRedo(h0), false)

	const one = insertBlock(EMPTY, 'main', 'markdown')
	const h1 = record(h0, one)
	assertEqual('after one edit, undo is available', canUndo(h1), true)
	assertEqual('the present is the edit', h1.present.main.length, 1)

	const h2 = undo(h1)
	assertEqual('undo returns the earlier state', h2.present.main.length, 0)
	assertEqual('and redo becomes available', canRedo(h2), true)

	const h3 = redo(h2)
	assertEqual('redo returns the later state', h3.present.main.length, 1)
}

{
	// THE DEFECT THIS FILE EXISTS FOR. Mutate the object that was recorded and
	// then undo: a stack holding a reference returns the mutated present and
	// this reads as "undo did nothing".
	const state = insertBlock(EMPTY, 'main', 'markdown')
	const h = record(createHistory(EMPTY), state)

	state.main[0].props.markdown = 'edited after recording'
	state.main.push({ widgetKey: 'cardGrid', props: {} })

	assertEqual('a recorded state is not aliased to the caller', h.present.main.length, 1)
	assertEqual(
		'nor are its blocks',
		h.present.main[0].props.markdown,
		undefined,
	)
}

{
	// A PROXY-WRAPPED STATE, which is the only kind the editor ever passes.
	//
	// Vue's reactivity wraps component state in a Proxy, and `structuredClone`
	// throws `DataCloneError` on one. Both modules used it, every test here
	// passed because every test here passes plain objects, and the failure only
	// appeared in a browser — as an edit that silently did not commit and an
	// undo button that stayed disabled. This is that browser, in one assertion.
	const reactive = (value) =>
		new Proxy(value, {
			get: (target, key) => {
				const inner = Reflect.get(target, key)
				return inner && typeof inner === 'object' ? reactive(inner) : inner
			},
		})

	const proxied = reactive(insertBlock(EMPTY, 'main', 'markdown'))
	const h = record(createHistory(EMPTY), proxied)

	assertEqual('a reactive state can be recorded', h.present.main.length, 1)
	assertEqual('and undone', undo(h).present.main.length, 0)
	assertEqual(
		'and edited',
		setField(proxied, 'main', 0, 'markdown', '# Titel').main[0].props.markdown,
		'# Titel',
	)
}

{
	// An edit after undoing clears the redo branch — every editor does this,
	// and keeping the branch would need an interface that can show it.
	const h = record(record(createHistory(EMPTY), insertBlock(EMPTY, 'main', 'markdown')), insertBlock(insertBlock(EMPTY, 'main', 'markdown'), 'main', 'cardGrid'))
	const back = undo(h)
	assertEqual('redo is available after undo', canRedo(back), true)
	const branched = record(back, insertBlock(back.present, 'aside', 'search'))
	assertEqual('a new edit clears the redo branch', canRedo(branched), false)
}

{
	// A no-op edit must not cost an undo step, or pressing undo appears to do
	// nothing — a drag that ends where it started is the common case.
	const h = record(createHistory(EMPTY), EMPTY)
	assertEqual('an identical state is not recorded', canUndo(h), false)
}

{
	// Undoing past the beginning, and redoing past the end, are no-ops rather
	// than errors: both are reachable by holding a keyboard shortcut down.
	const h = createHistory(EMPTY)
	assertEqual('undo at the start is a no-op', undo(h), h)
	assertEqual('redo at the end is a no-op', redo(h), h)
}

console.log('\noperations')
{
	const one = insertBlock(EMPTY, 'main', 'markdown')
	assertEqual('insert places the block', one.main.map((b) => b.widgetKey), ['markdown'])
	assertEqual('the input is not mutated', EMPTY.main.length, 0)

	const two = insertBlock(one, 'main', 'cardGrid')
	assertEqual('a second block goes below the first', two.main[1].gridY, 4)

	const removed = removeBlock(two, 'main', 0)
	assertEqual('remove takes the named block', removed.main.map((b) => b.widgetKey), ['cardGrid'])
}

{
	const start = insertBlock(insertBlock(EMPTY, 'main', 'markdown'), 'aside', 'search')
	const moved = moveBlock(start, 'main', 0, 'aside')

	// A BLOCK IS IN EXACTLY ONE REGION. The failure mode of a move implemented
	// as remove-then-insert is a state where it is in both, or in neither.
	assertEqual('the block left its region', moved.main.length, 0)
	assertEqual('and arrived in the other', moved.aside.map((b) => b.widgetKey), ['search', 'markdown'])
}

{
	const start = insertBlock(EMPTY, 'main', 'cardGrid')

	// CLAMPED TO THE GRID — task 5.3 in force. A page must not be editable into
	// a layout the public renderer would not honour.
	const tooWide = resizeBlock(start, 'main', 0, { gridWidth: 99 })
	assertEqual('width is clamped to the grid', tooWide.main[0].gridWidth, 12)

	const offGrid = resizeBlock(start, 'main', 0, { gridWidth: 6, gridX: 11 })
	assertEqual('x is clamped so the block stays on the grid', offGrid.main[0].gridX, 6)

	const negative = resizeBlock(start, 'main', 0, { gridWidth: 0, gridHeight: -3 })
	assertEqual('width cannot be zero', negative.main[0].gridWidth, 1)
	assertEqual('nor height', negative.main[0].gridHeight, 1)
}

{
	const start = insertBlock(EMPTY, 'main', 'markdown')
	const edited = setField(start, 'main', 0, 'markdown', '# Titel')
	assertEqual('a field is set', edited.main[0].props.markdown, '# Titel')

	// The inspector only offers declared fields, so a styling key arriving here
	// came from somewhere it should not — and is dropped, exactly as it is on
	// the render path.
	const smuggled = setField(start, 'main', 0, 'style', 'position:absolute')
	assertEqual('a styling key cannot be set from the inspector either', smuggled.main[0].props.style, undefined)
}

console.log('\ncatalogue')
assertEqual('every declared field type is one the inspector renders', unrenderableFields(), [])

// A BLOCK DECLARES WHERE IT BELONGS, and every region it names must exist —
// otherwise the library offers a block in a region the renderer will never
// render, and the author's work disappears on save.
const badRegions = Object.entries(BLOCK_CATALOG).flatMap(([key, info]) =>
	info.regions.filter((r) => REGIONS.includes(r) === false).map((r) => `${key}: ${r}`),
)
assertEqual('no block claims a region that does not exist', badRegions, [])

// Every region an author can select must offer something, or the library is an
// empty panel with no explanation.
const emptyRegions = REGIONS.filter((r) => blocksForRegion(r).length === 0)
assertEqual('every region has at least one placeable block', emptyRegions, [])

if (failures > 0) {
	console.error(`\n${failures} failure(s)`)
	process.exit(1)
}

console.log('\nall assertions passed')
