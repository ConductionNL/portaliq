#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// faq-list.spec.mjs: the FAQ block shows the entries the portal serves for
// its page, or all of them grouped by topic, as an accordion whose questions
// are real buttons (public-faq-and-product-finder).
//
// Usage:
//   node --test tests/faq-list.spec.mjs
//
// @spec openspec/changes/public-faq-and-product-finder/specs/portaliq-cms/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const ENTRIES = [
	{ question: 'Hoeveel vergunningen per adres?', answer: 'Twee **vergunningen**.', topic: 'Parkeren', pages: ['/parkeren'] },
	{ question: 'Hoe lang duurt het?', answer: 'Acht weken.', topic: 'Parkeren', pages: ['/parkeren'] },
	{ question: 'Wanneer wordt afval opgehaald?', answer: 'Op dinsdag.', topic: '', pages: [] },
]
const STUBS = { '@conduction/nextcloud-vue': 'export const cnRenderMarkdown = (s) => `<p>${s}</p>`\n' }
const FILE = 'src/site/widgets/nlFaqList/NlFaqList.vue'

test('entries group by topic only when asked for all of them', async () => {
	const component = await loadSfc(FILE, STUBS)
	const grouped = component.computed.groups.call({ entries: ENTRIES, all: true })
	assert.deepEqual(grouped.map((g) => [g.topic, g.entries.length]), [['Parkeren', 2], ['', 1]])

	const flat = component.computed.groups.call({ entries: ENTRIES.slice(0, 2), all: false })
	assert.equal(flat.length, 1)
	assert.equal(flat[0].entries[0].html, '<p>Twee **vergunningen**.</p>', 'the answer goes through the markdown renderer')
})

test('the question is a button in a heading and its answer panel is closed until opened', async () => {
	const component = await loadSfc(FILE, STUBS)
	const vm = { openKeys: [], isOpen(key) { return this.openKeys.includes(key) } }
	component.methods.toggle.call(vm, 'k')
	assert.deepEqual(vm.openKeys, ['k'])
	component.methods.toggle.call(vm, 'k')
	assert.deepEqual(vm.openKeys, [])

	const html = await renderComponent(component, { portal: 'p' })
	assert.match(html, /data-testid="nl-faq-list"/)
	assert.match(html, /De vragen worden geladen\./, 'the first paint is the loading note')
})

test('the heading level follows the grouping', async () => {
	const component = await loadSfc(FILE, STUBS)
	assert.equal(component.computed.entryTag.call({ all: true }), 'h4')
	assert.equal(component.computed.entryTag.call({ all: false }), 'h3')
})

test('a link to all questions appears only with a label, and only as a safe link', async () => {
	const component = await loadSfc(FILE, STUBS)
	assert.equal(component.computed.more.call({ moreLabel: '', moreHref: '/veelgestelde-vragen' }), null)
	assert.equal(component.computed.more.call({ moreLabel: 'Alle veelgestelde vragen', moreHref: '/veelgestelde-vragen' }).route, '/veelgestelde-vragen')
	assert.equal(component.computed.more.call({ moreLabel: 'x', moreHref: 'javascript:alert(1)' }), null)
})
