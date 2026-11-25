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
		table: [{ className: '' }],
		tr: [{ className: '' }, { className: '' }],
		th: [{ className: '' }],
		td: [{ className: 'own' }],
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
		...['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'ul', 'ol', 'blockquote', 'strong', 'em', 'sub', 'sup', 'mark', 'code', 'pre', 'hr', 'img', 'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td'].map((tag) => ['query', tag]),
	])
	assert.deepEqual(
		Object.fromEntries(Object.entries(elements).map(([tag, list]) => [tag, list.map((el) => el.className)])),
		{ h2: ['utrecht-heading-2', 'own'], p: ['utrecht-paragraph'], strong: ['utrecht-strong'], img: ['utrecht-img'], table: ['utrecht-table'], tr: ['utrecht-table__row', 'utrecht-table__row'], th: ['utrecht-table__header-cell'], td: ['own'] },
	)
})

// site-nlds-widget-palette T8c: a markdown link to a site page follows the
// site's own address, so it opens under the app path too and keeps the
// portal and the chosen language; a link elsewhere stays as written.
test('a link to a site page carries the site address, the portal and the language', () => {
	const links = [
		{ href: '/grofvuil' },
		{ href: 'https://example.org/x' },
		{ href: '//cdn.example.org/y' },
	]
	globalThis.window = {
		location: { href: 'http://nc.test/index.php/apps/portaliq/site/open-tilburg/afval?lang=en&x=1' },
	}
	globalThis.DOMParser = class {
		parseFromString() {
			return {
				body: {
					get innerHTML() {
						return links.map((link) => link.href).join(' ')
					},
					querySelectorAll: () =>
						links.map((link) => ({
							getAttribute: () => link.href,
							setAttribute: (name, value) => {
								link.href = value
							},
						})),
				},
			}
		}
	}
	const out = MarkdownBlock.methods.withSiteAddresses.call({}, '<a href="/grofvuil">x</a>')
	const [site, other, protocolRelative] = out.split(' ')
	assert.match(decodeURIComponent(site), /\/grofvuil/)
	assert.match(site, /^http:\/\/nc\.test\/index\.php\/apps\/portaliq\/site/)
	assert.match(site, /[?&]lang=en(&|$)/)
	assert.doesNotMatch(site, /[?&]x=1/)
	assert.notEqual(site, '/grofvuil')
	assert.equal(other, 'https://example.org/x')
	assert.equal(protocolRelative, '//cdn.example.org/y')
	assert.equal(MarkdownBlock.methods.withSiteAddresses.call({}, '<p>no links</p>'), '<p>no links</p>')
	delete globalThis.window
})
