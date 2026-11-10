#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// recording-player-html-golden.spec.mjs: the HTML the session replay builds
// from a masked recording: text as blocks of its length, only safe
// attributes (no event handlers, escaped values), boxes, masked values,
// scripts and unsafe tags dropped, stylesheets without a closing tag, pinned
// in a golden file before the builder was split into named steps.
//
// Usage:
//   node --test tests/recording-player-html-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/recording-player-html-golden.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { golden } from './support/golden.mjs'
import { loadSfc } from './support/render-sfc.mjs'

const PLAYER = await loadSfc('src/modals/TrafficRecordingPlayer.vue', {
	'@nextcloud/vue': 'export const NcButton = {}, NcModal = {}',
})

/**
 * The player with its builder and escaping bound, and the stylesheets sent.
 *
 * @return {object} The player.
 */
function player() {
	const vm = { styles: { abc: 'body{color:red}</style><script>x()</script>' } }
	vm.escape = PLAYER.methods.escape.bind(vm)
	vm.html = PLAYER.methods.html.bind(vm)
	return vm
}

test('the replay HTML of a masked page', () => {
	const file = golden('recording-player-html')
	const vm = player()
	const nodes = [
		null,
		'text',
		{ l: 0 },
		{ l: 5 },
		{ l: 9000 },
		{ l: 'x' },
		{ n: 'script', a: {}, c: [{ l: 3 }] },
		{ n: '<img>', a: {} },
		{ n: '', a: {} },
		{ n: 'DIV', a: {} },
		{ n: 'div', a: { class: 'a"b<c>&', onclick: 'x()', ONLOAD: 'y', 'data-x': '1', 'bad name': 'z', id: 5 }, c: [{ l: 2 }, { n: 'span', a: {}, c: [] }, null] },
		{ n: 'img', a: { width: '10', class: 'hero' } },
		{ n: 'iframe', a: {} , c: [{ l: 4 }] },
		{ n: 'input', a: { type: 'text' }, v: 7 },
		{ n: 'input', a: {}, v: 999 },
		{ n: 'input', a: {}, v: 0 },
		{ n: 'textarea', a: {}, v: 3, c: [] },
		{ n: 'br', a: {} },
		{ n: 'style', a: {}, s: 'p{}</STYLE><b>' },
		{ n: 'style', a: {}, h: 'abc' },
		{ n: 'style', a: {}, h: 'missing' },
		{ n: 'ul', a: 'not attrs', c: 'not children' },
	]
	const html = nodes.map((node) => vm.html(node))
	assert.equal(html.some((part) => /on\w+=/i.test(part)), false, 'no event handler survives')
	assert.equal(html.some((part) => /<\/style.*<\/style/is.test(part)), false, 'a stylesheet cannot close its own element')
	assert.equal(html[6], '', 'a script element is dropped')
	file.check('html', html)
	file.save()
})
