#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-layout.spec.mjs: one title per page, the menu marks the section of
// the page on screen, and the designed footer keeps every column on one row
// (site-page-layout).
//
// Usage:
//   node --test tests/site-look/page-layout.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { menuCurrent } from '../../src/site/lib/menuCurrent.js'
import { blocksOwnHeading } from '../../src/site/lib/pageHeading.js'
import { renderSfc } from '../support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const read = (rel) => readFileSync(join(ROOT, rel), 'utf8')
const css = read('css/site-theme.css').replace(/\/\*[\s\S]*?\*\//g, '')

test('a page that opens with a level 1 heading block gets no second title', () => {
	assert.equal(
		blocksOwnHeading([
			{
				widgetKey: 'nlHeading',
				props: { level: 1, text: 'Uw kind afwezig melden' },
			},
		]),
		true,
	)
	assert.equal(
		blocksOwnHeading([{ widgetKey: 'nlHeading', props: { level: '1' } }]),
		true,
	)
	assert.equal(blocksOwnHeading([{ widgetKey: 'hero' }]), true)
	assert.equal(blocksOwnHeading([{ widgetKey: 'publicationDetail' }]), true)
	// A section heading leaves the title to the renderer.
	assert.equal(
		blocksOwnHeading([{ widgetKey: 'nlHeading', props: { level: 2 } }]),
		false,
	)
	assert.equal(blocksOwnHeading([{ widgetKey: 'nlHeading', props: {} }]), false)
	assert.equal(blocksOwnHeading([{ widgetKey: 'nlParagraph' }]), false)
	assert.equal(blocksOwnHeading(null), false)
})

test('App.vue asks the helper whether the body owns the heading', () => {
	const app = read('src/site/App.vue')
	assert.match(
		app,
		/import \{ blocksOwnHeading \} from '\.\/lib\/pageHeading\.js'/,
	)
	assert.match(
		app,
		/return blocksOwnHeading\(\[\.\.\.this\.regions\.hero, \.\.\.main\]\)/,
	)
})

test('the menu marks the page itself and the section it sits in', () => {
	assert.equal(menuCurrent('/praktisch', '/praktisch'), 'page')
	assert.equal(menuCurrent('/praktisch', '/praktisch/afwezig-melden'), 'true')
	assert.equal(menuCurrent('/praktisch/', '/praktisch/afwezig-melden?x=1'), 'true')
	assert.equal(menuCurrent('/', '/'), 'page')
	assert.equal(
		menuCurrent('/', '/praktisch'),
		undefined,
		'home is not the section of every page',
	)
	assert.equal(
		menuCurrent('/prak', '/praktisch'),
		undefined,
		'a prefix of a word is no section',
	)
	assert.equal(menuCurrent('https://example.org', '/'), undefined)
})

test('the rendered menu says aria-current="true" on the section item', async () => {
	const html = await renderSfc('src/site/components/SiteMenu.vue', {
		menu: {
			title: 'Hoofdmenu',
			items: [
				{ name: 'Home', link: '/' },
				{ name: 'Praktisch', link: '/praktisch' },
			],
		},
		currentRoute: '/praktisch/afwezig-melden',
	})
	assert.match(html, /href="\/praktisch" aria-current="true"/)
	assert.doesNotMatch(html, /href="\/" aria-current/)
})

test('the section item wears the same bar as the page item', () => {
	assert.ok(
		css.includes(
			".pq-site .ac-header .ac-c-navigation__link-container[aria-current='true']::after,",
		),
	)
	assert.ok(
		css.includes(
			".pq-site .ac-header .ac-c-navigation__link-container[aria-current='true'] .ac-c-navigation__label,",
		) || /\[aria-current='true'\]\s+\.ac-c-navigation__label,/.test(css),
	)
})

test('the designed footer puts every column on one row on a desktop', () => {
	const rules = [
		...css.matchAll(
			/\.ac-footer section\.pq-footer__band--designed\.pq-footer__band \.container \{([^}]*)\}/g,
		),
	].map((m) => m[1].replace(/\s+/g, ' '))
	assert.ok(
		rules.some((r) => /grid-auto-flow: column/.test(r)),
		'columns flow on one row',
	)
	assert.ok(
		rules.some((r) => /grid-auto-flow: row/.test(r)),
		'a tablet goes back to rows',
	)
	for (const r of rules) {
		assert.doesNotMatch(r, /repeat\(3,/, 'no fixed three menu columns')
	}
})
