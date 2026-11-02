#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-editor-in-the-page.spec.mjs: the layout editor is the Indeling tab of
// the page (PtPagina), its toolbar and block cards read as the board, and the
// example site seeds the board's draft for "Afval scheiden en ophalen".
//
// Usage:
//   node --test tests/page-editor-in-the-page.spec.mjs
//
// @spec openspec/specs/portal-page-designer/spec.md#requirement-the-layout-editor-is-a-tab-of-the-page

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { widgetLabel, widgetWidthLine } from '../src/lib/widgetLabels.js'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const SITE = JSON.parse(read('lib/Settings/sites/zuiddrecht.json'))
const DESIGNER = read('src/views/PageLayoutDesigner.vue')
const REGISTRY = read('src/registry.js')
const DETAIL = MANIFEST.pages.find((page) => page.id === 'PageDetail')

test('the page carries an Indeling tab holding the embedded editor, in the address', () => {
	const tabs = DETAIL.config.widgets.find((widget) => widget.id === 'page-tabs').content.tabs
	assert.deepEqual(tabs[1], { id: 'layout', widgetId: 'page-layout', label: 'Indeling' })
	assert.equal(DETAIL.config.widgets.find((widget) => widget.id === 'page-layout').type, 'PageLayoutEditor')
	assert.equal(DETAIL.tabInAddress, true)
	assert.match(REGISTRY, /PageLayoutEditor: \{\n\t\tkind: 'widget',\n\t\tcomponent: PageLayoutEditor,/)
	assert.match(read('src/widgets/PageLayoutEditor.vue'), /<PageLayoutDesigner embedded/)
	assert.match(DESIGNER, /<div v-if="!embedded" class="designer__identity">/)
})

// @spec openspec/specs/portal-page-designer/spec.md#requirement-the-editor-toolbar-and-block-cards-read-as-the-board
test('the toolbar leads with the primary Add block and says when changes are unsaved', () => {
	const add = DESIGNER.indexOf('data-testid="designer-add-widget"')
	assert.ok(add > 0)
	assert.match(DESIGNER.slice(DESIGNER.lastIndexOf('<NcButton', add), add), /variant="primary"/)
	assert.ok(add < DESIGNER.indexOf('data-testid="designer-undo"'))
	assert.match(DESIGNER, /v-if="state\.dirty"[\s\S]{0,120}data-testid="designer-unsaved"/)
})

// @spec openspec/specs/portal-page-designer/spec.md#requirement-the-editor-toolbar-and-block-cards-read-as-the-board
test('every NL Design System block in the example site has a name, and a card says its width', () => {
	const keys = new Set()
	const walk = (node) => {
		if (Array.isArray(node)) {
			node.forEach(walk)
		} else if (node && typeof node === 'object') {
			if (typeof node.widgetKey === 'string') {
				keys.add(node.widgetKey)
			}
			Object.values(node).forEach(walk)
		}
	}
	walk(SITE)
	for (const key of [...keys].filter((k) => k.startsWith('nl'))) {
		assert.doesNotMatch(widgetLabel(key, {}), /^Nl /, key)
	}
	assert.equal(widgetLabel('nlLookupForm', {}), 'Opzoekformulier')
	assert.equal(widgetWidthLine(8), '8 kolommen')
	assert.equal(widgetWidthLine(12), 'over de hele breedte')
	assert.equal(widgetWidthLine(0), '')
})

// @spec openspec/specs/portal-page-designer/spec.md#requirement-the-example-site-seeds-the-boards-draft
test('the example site seeds the board draft and keeps the published body', () => {
	let afval = null
	const walk = (node) => {
		if (Array.isArray(node)) {
			node.forEach(walk)
		} else if (node && typeof node === 'object') {
			if (node.route === '/afval') {
				afval = node
			}
			Object.values(node).forEach(walk)
		}
	}
	walk(SITE)
	assert.deepEqual(afval.draftBody.widgets.map((w) => w.widgetKey), ['hero', 'nlAlert', 'nlLookupForm', 'nlLinkList'])
	assert.equal(afval.draftBody.widgets[1].props.kind, 'warning')
	assert.equal(afval.body.widgets[0].widgetKey, 'nlParagraph', 'the published body is untouched')
})
