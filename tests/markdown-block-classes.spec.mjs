#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// markdown-block-classes.spec.mjs: which design-system class the markdown
// block gives each rendered element, in which order it asks for them, and
// that an element with a class of its own keeps it, pinned before the class
// map moved out of the method. Node has no DOMParser, so a stand-in records
// what is asked and parsed.
//
// Usage:
//   node --test tests/markdown-block-classes.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const MarkdownBlock = await loadSfc('src/site/components/MarkdownBlock.vue', {
	'@conduction/nextcloud-vue': 'export const cnRenderMarkdown = (source) => source\n',
})

test('each bare element gets its class, in the map order; an own class stays', () => {
	const asked = []
	const elements = {
		h2: [{ className: '' }, { className: 'own' }],
		p: [{ className: '' }],
		strong: [{ className: '' }],
		img: [{ className: '' }],
	}
	let written = ''
	globalThis.DOMParser = class {
		parseFromString(html, type) {
			asked.push(['parse', type])
			written = html
			return {
				body: {
					get innerHTML() {
						return `rendered:${written}`
					},
					querySelectorAll: (tag) => {
						asked.push(['query', tag])
						return elements[tag] || []
					},
				},
			}
		}
	}
	const out = MarkdownBlock.methods.withDesignSystemClasses.call({}, '<h2>x</h2>')
	assert.equal(out, 'rendered:<h2>x</h2>')
	assert.deepEqual(asked, [
		['parse', 'text/html'],
		...['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'ul', 'ol', 'blockquote', 'strong', 'em', 'sub', 'sup', 'mark', 'code', 'pre', 'hr', 'img'].map((tag) => ['query', tag]),
	])
	assert.deepEqual(
		Object.fromEntries(Object.entries(elements).map(([tag, list]) => [tag, list.map((el) => el.className)])),
		{ h2: ['utrecht-heading-2', 'own'], p: ['utrecht-paragraph'], strong: ['utrecht-strong'], img: ['utrecht-img'] },
	)
})
