#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// hero-heading.spec.mjs: a hero shows its heading, its lead and its search
// box together (site-hero-shows-its-heading).
//
// The old rule hid the heading and the lead (`sr-only`) whenever the band held
// a search box. The mbo and training portals declare a hero with a search box
// and their boards draw the heading above it, so a visitor saw a search box
// with no heading. Putting the old rule back fails the first test.
//
// Usage:
//   node --test tests/site-look/hero-heading.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { renderSfc } from '../support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

const LIBRARY = {
	'@conduction/nextcloud-vue/public': [
		"import { h } from 'vue'",
		"export const CnSiteIcon = { props: ['name', 'size'], render() { return h('svg') } }",
		"export const CnSiteSection = { props: ['variant', 'backgroundImage'], render() { return h('section', { class: 'ac-hero' }, this.$slots.default?.()) } }",
		"export const CnSiteSearch = { props: ['label', 'labelVisible', 'placeholder', 'submitLabel', 'value', 'inputId'], render() { return h('form', { role: 'search', 'aria-label': this.label }, [h('label', { class: this.labelVisible ? 'search-label' : 'search-label sr-only' }, this.label)]) } }",
	].join('\n'),
}

const HERO = 'src/site/components/HeroBlock.vue'

test('a hero with a search box still shows its heading and its lead', async () => {
	const html = await renderSfc(
		HERO,
		{
			title: 'Een vak leer je door het te doen',
			subtitle: 'Kies een mbo-opleiding in Zuiddrecht.',
			search: true,
			searchLabel: 'Zoek een opleiding',
		},
		LIBRARY,
	)
	assert.match(
		html,
		/<h1 class="ac-hero__title">(<!--v-if-->)?\s*Een vak leer je door het te doen/,
	)
	assert.match(html, /<p class="ac-hero__subtitle">\s*Kies een mbo-opleiding/)
	assert.match(html, /<label class="search-label">Zoek een opleiding<\/label>/)
})

test('without a label of its own, the box is named by the button word, for screen readers only', async () => {
	const html = await renderSfc(
		HERO,
		{ title: 'Wat wilt u regelen?', search: true, searchSubmitLabel: 'Zoeken' },
		LIBRARY,
	)
	assert.match(html, /<h1 class="ac-hero__title">/)
	assert.match(html, /<label class="search-label sr-only">Zoeken<\/label>/)
	assert.doesNotMatch(
		html,
		/<label[^>]*>Wat wilt u regelen\?<\/label>/,
		'the heading is not read twice',
	)
})

test('an author who hides the heading gets the old band: the heading names the box', async () => {
	const html = await renderSfc(
		HERO,
		{ title: 'Wat wilt u regelen?', search: true, headingVisible: false },
		LIBRARY,
	)
	assert.match(html, /<h1 class="ac-hero__title sr-only">/)
	assert.match(html, /<label class="search-label">Wat wilt u regelen\?<\/label>/)
})

test('the plain hero gives its lead the board size', () => {
	const css = readFileSync(join(ROOT, 'css/site-theme.css'), 'utf8')
	const at = css.indexOf('.pq-site .ac-hero.pq-hero--plain .ac-hero__subtitle {')
	assert.ok(at > 0, 'the plain lead has a rule')
	const rule = css.slice(at, css.indexOf('}', at))
	assert.match(
		rule,
		/font-size: var\(--nldesign-website-hero-lead-size, 1\.25rem\)/,
	)
	assert.match(rule, /max-inline-size: 40rem/)
})
