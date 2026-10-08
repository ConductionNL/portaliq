#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// editor-text-toolbar.spec.mjs: the toolbar over a "Tekst" block's textarea
// (editor-text-toolbar, REQ-ETT-001).
//
// The transformations are pure functions (selection in, text and selection
// out), so every button runs here without a DOM. The wiring is checked
// against the component's source: the textarea keeps the test id the film
// recorder types into, and every button and string the toolbar shows exists.
//
// Usage:
//   node --test tests/editor-text-toolbar.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	applyBold,
	applyHeading,
	applyItalic,
	applyLink,
	applyList,
	rovingTabindexes,
	shortcutFor,
	toolbarIndexFor,
} from '../src/editor/markdownToolbar.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

/**
 * A selection over `value`, written with `[` and `]` around the selected text
 * (or `|` for a cursor), so a case reads like what the editor sees.
 *
 * @param {string} marked The text with its selection marked.
 * @return {{value: string, start: number, end: number}} The selection.
 */
function sel(marked) {
	const cursor = marked.indexOf('|')
	if (cursor !== -1) {
		return { value: marked.replace('|', ''), start: cursor, end: cursor }
	}
	const start = marked.indexOf('[')
	const end = marked.indexOf(']') - 1
	return { value: marked.replace('[', '').replace(']', ''), start, end }
}

/**
 * Write a result back in the same notation, for an assertion that reads.
 *
 * @param {{value: string, start: number, end: number}} result The result.
 * @return {string} The text with its selection marked.
 */
function show({ value, start, end }) {
	if (start === end) {
		return value.slice(0, start) + '|' + value.slice(start)
	}
	return (
		value.slice(0, start)
		+ '['
		+ value.slice(start, end)
		+ ']'
		+ value.slice(end)
	)
}

test('Kop puts "## " at the start of the line and keeps the selection on the words', () => {
	assert.equal(
		show(applyHeading(sel('Fietspad [Lindelaan]'))),
		'## Fietspad [Lindelaan]',
	)
	assert.equal(
		show(applyHeading(sel('intro\nFiets|pad\nrest'))),
		'intro\n## Fiets|pad\nrest',
	)
})

test('Kop with nothing selected on an empty line starts a heading there', () => {
	assert.equal(show(applyHeading(sel('|'))), '## |')
	assert.equal(show(applyHeading(sel('intro\n|'))), 'intro\n## |')
})

test('Kop on a heading makes it a plain line again, and other levels become "## "', () => {
	assert.equal(show(applyHeading(sel('## Fiets|pad'))), 'Fiets|pad')
	assert.equal(show(applyHeading(sel('#|# Fietspad'))), '|Fietspad')
	assert.equal(show(applyHeading(sel('### Fiets|pad'))), '## Fiets|pad')
})

test('Vet wraps the selection in ** and keeps it selected', () => {
	assert.equal(
		show(applyBold(sel('de [Lindelaan] gaat dicht'))),
		'de **[Lindelaan]** gaat dicht',
	)
})

test('Vet leaves whitespace at the edges outside the marks', () => {
	assert.equal(
		show(applyBold(sel('de [Lindelaan ]gaat'))),
		'de **[Lindelaan]** gaat',
	)
})

test('Vet with nothing selected writes the marks with the cursor between them', () => {
	assert.equal(show(applyBold(sel('de |'))), 'de **|**')
	assert.equal(show(applyBold(sel('|'))), '**|**')
})

test('Vet on bold text takes the marks away', () => {
	assert.equal(
		show(applyBold(sel('de **[Lindelaan]** gaat'))),
		'de [Lindelaan] gaat',
	)
})

test('Cursief wraps the selection in _ and takes it away again', () => {
	const once = applyItalic(sel('een [korte] tekst'))
	assert.equal(show(once), 'een _[korte]_ tekst')
	assert.equal(show(applyItalic(once)), 'een [korte] tekst')
})

test('Cursief with nothing selected writes the marks with the cursor between them', () => {
	assert.equal(show(applyItalic(sel('|'))), '_|_')
})

test('Lijst puts "- " before every selected line and skips the empty ones', () => {
	assert.equal(
		show(applyList(sel('Werk:\n[Asfalt\n\nStoep\nFietspad]\nEinde'))),
		'Werk:\n[- Asfalt\n\n- Stoep\n- Fietspad]\nEinde',
	)
})

test('Lijst takes in a line that is only partly selected', () => {
	assert.equal(show(applyList(sel('Asf[alt\nSto]ep'))), '[- Asfalt\n- Stoep]')
})

test('Lijst does not take in the line after a selection that ends on a newline', () => {
	assert.equal(
		show(applyList(sel('[Asfalt\nStoep\n]Einde'))),
		'[- Asfalt\n- Stoep]\nEinde',
	)
})

test('Lijst on a list makes the lines plain again', () => {
	assert.equal(show(applyList(sel('[- Asfalt\n- Stoep]'))), '[Asfalt\nStoep]')
})

test('Lijst with a mix of items and plain lines makes them all items', () => {
	assert.equal(show(applyList(sel('[- Asfalt\nStoep]'))), '[- Asfalt\n- Stoep]')
})

test('Lijst with nothing selected makes the cursor line an item, also an empty one', () => {
	assert.equal(show(applyList(sel('Asf|alt'))), '- Asf|alt')
	assert.equal(show(applyList(sel('intro\n|'))), 'intro\n- |')
	assert.equal(show(applyList(sel('- Asf|alt'))), 'Asf|alt')
})

test('Link turns the selection into [text](url) and puts the cursor after it', () => {
	assert.equal(
		show(
			applyLink(sel('lees [het besluit] hier'), 'https://example.nl/besluit'),
		),
		'lees [het besluit](https://example.nl/besluit)| hier',
	)
})

test('Link with nothing selected uses the address as the text', () => {
	assert.equal(
		show(applyLink(sel('zie |'), ' https://example.nl ')),
		'zie [https://example.nl](https://example.nl)|',
	)
})

test('Link without an address changes nothing', () => {
	assert.equal(show(applyLink(sel('lees [dit]'), '   ')), 'lees [dit]')
	assert.equal(show(applyLink(sel('lees [dit]'), undefined)), 'lees [dit]')
})

test('a stale selection past the end is clamped, not sliced past the text', () => {
	assert.equal(show(applyBold({ value: 'ab', start: 5, end: 9 })), 'ab**|**')
})

test('Ctrl+B and Ctrl+I (or Cmd) are the shortcuts, with Shift or Alt they are not', () => {
	assert.equal(shortcutFor({ key: 'b', ctrlKey: true }), applyBold)
	assert.equal(shortcutFor({ key: 'B', metaKey: true }), applyBold)
	assert.equal(shortcutFor({ key: 'i', ctrlKey: true }), applyItalic)
	assert.equal(shortcutFor({ key: 'i', metaKey: true }), applyItalic)
	assert.equal(shortcutFor({ key: 'b' }), null)
	assert.equal(shortcutFor({ key: 'b', ctrlKey: true, shiftKey: true }), null)
	assert.equal(shortcutFor({ key: 'i', ctrlKey: true, altKey: true }), null)
	assert.equal(shortcutFor({ key: 'k', ctrlKey: true }), null)
})

test('the text field keeps its test id, a labelled toolbar and real buttons', () => {
	const source = readFileSync(
		join(ROOT, 'src', 'editor', 'MarkdownField.vue'),
		'utf8',
	)
	assert.match(source, /data-testid="designer-field-markdown"/)
	assert.match(source, /role="toolbar"/)
	assert.match(source, /:aria-label="t\('portaliq', 'Text formatting'\)"/)
	assert.match(source, /type="button"[\s\S]*:aria-label="action\.label"/)
	assert.doesNotMatch(source, /window\.prompt|\bprompt\(/)
	for (const name of ['heading', 'bold', 'italic', 'list', 'link']) {
		assert.match(source, new RegExp(`name: '${name}'`), `button ${name}`)
	}

	const designer = readFileSync(
		join(ROOT, 'src', 'editor', 'PageGridEditor.vue'),
		'utf8',
	)
	assert.match(designer, /<MarkdownField\s+v-if="field\.kind === 'markdown'"/)

	const catalogue = readFileSync(
		join(ROOT, 'src', 'lib', 'pageWidgetCatalogue.js'),
		'utf8',
	)
	assert.match(
		catalogue,
		/name: 'markdown',[\s\S]*?kind: 'markdown',\s*label: 'Text',/,
	)
})

test('every string the toolbar shows reads Dutch for the editor', () => {
	const nl = JSON.parse(
		readFileSync(join(ROOT, 'l10n', 'nl.json'), 'utf8'),
	).translations
	const expected = {
		Text: 'Tekst',
		'Text formatting': 'Tekstopmaak',
		Heading: 'Kop',
		Bold: 'Vet',
		'Bold ({shortcut})': 'Vet ({shortcut})',
		Italic: 'Cursief',
		'Italic ({shortcut})': 'Cursief ({shortcut})',
		List: 'Lijst',
		Link: 'Link',
		'Web address': 'Webadres',
		'Insert link': 'Link invoegen',
		Cancel: 'Annuleren',
	}
	for (const [key, value] of Object.entries(expected)) {
		assert.equal(nl[key], value, key)
	}
})

test('Right and Left move between the five buttons and wrap around', () => {
	assert.equal(toolbarIndexFor(0, 'ArrowRight', 5), 1)
	assert.equal(toolbarIndexFor(4, 'ArrowRight', 5), 0)
	assert.equal(toolbarIndexFor(2, 'ArrowLeft', 5), 1)
	assert.equal(toolbarIndexFor(0, 'ArrowLeft', 5), 4)
})

test('Home and End go to the first and the last button', () => {
	assert.equal(toolbarIndexFor(3, 'Home', 5), 0)
	assert.equal(toolbarIndexFor(1, 'End', 5), 4)
})

test('other keys leave the focus where it is, so Enter and Space still press the button', () => {
	for (const key of ['Enter', ' ', 'Tab', 'ArrowUp', 'ArrowDown', 'b']) {
		assert.equal(toolbarIndexFor(2, key, 5), null, key)
	}
	assert.equal(toolbarIndexFor(0, 'ArrowRight', 0), null)
})

test('the toolbar is one Tab stop: only the current button has tabindex 0', () => {
	assert.deepEqual(rovingTabindexes(0, 5), [0, -1, -1, -1, -1])
	assert.deepEqual(rovingTabindexes(3, 5), [-1, -1, -1, 0, -1])
	assert.deepEqual(rovingTabindexes(9, 5), [-1, -1, -1, -1, 0])
	assert.deepEqual(rovingTabindexes(0, 0), [])
})

test('the toolbar buttons are wired to the roving tabindex and the arrow keys', () => {
	const source = readFileSync(
		join(ROOT, 'src', 'editor', 'MarkdownField.vue'),
		'utf8',
	)
	assert.match(source, /:tabindex="tabindexes\[index\]"/)
	assert.match(source, /@keydown="onToolbarKeydown\(\$event, index\)"/)
	assert.match(source, /@focus="current = index"/)
	assert.match(source, /rovingTabindexes\(this\.current, this\.actions\.length\)/)
})
