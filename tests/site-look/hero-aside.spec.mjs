#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// hero-aside.spec.mjs: a hero may hold a list block in a card or a photo
// beside its text, as the academy and Esdoornveen boards draw their home
// pages (hero-aside).
//
// Usage:
//   node --test tests/site-look/hero-aside.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { loadSfc, renderSfc } from '../support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const LIBRARY = {
	'@conduction/nextcloud-vue/public': [
		"import { h } from 'vue'",
		"export const CnSiteIcon = { render() { return h('svg') } }",
		"export const CnSiteSection = { props: ['variant', 'backgroundImage'], render() { return h('section', { class: 'ac-hero' }, [h('div', { class: 'container' }, this.$slots.default?.())]) } }",
		"export const CnSiteSearch = { render() { return h('form') } }",
	].join('\n'),
}
const HERO = 'src/site/components/HeroBlock.vue'

test('a photo stands beside the text, with its alternative text', async () => {
	const html = await renderSfc(
		HERO,
		{
			title: 'Een vak leer je door het te doen',
			variant: 'plain',
			asideImage: {
				src: '/apps/portaliq/media/1',
				alt: 'Een student stelt een sensor af',
			},
		},
		LIBRARY,
	)
	assert.match(html, /class="ac-hero pq-hero--plain pq-hero--aside"/)
	assert.match(
		html,
		/<img class="pq-hero__aside pq-hero__photo" src="\/apps\/portaliq\/media\/1" alt="Een student stelt een sensor af"/,
	)
})

test('a design that only marks the photo shows its words; an unsafe address does not render', async () => {
	const marked = await renderSfc(
		HERO,
		{ title: 'X', asideImage: { label: '[FOTO: student stelt een sensor af]' } },
		LIBRARY,
	)
	assert.match(
		marked,
		/pq-hero__photo--label[^>]*>\s*\[FOTO: student stelt een sensor af\]/,
	)
	const unsafe = await renderSfc(
		HERO,
		{ title: 'X', asideImage: { src: 'javascript:alert(1)', alt: 'x' } },
		LIBRARY,
	)
	assert.doesNotMatch(unsafe, /pq-hero--aside|javascript:/)
})

test('a list block is allowed beside the text, another band is not', async () => {
	const hero = await loadSfc(HERO, LIBRARY)
	const widgetOf = (aside) => hero.computed.asideWidget.call({ aside })
	assert.notEqual(widgetOf({ widgetKey: 'nlEventList', props: {} }), null)
	assert.notEqual(widgetOf({ widgetKey: 'nlLinkList' }), null)
	assert.equal(widgetOf({ widgetKey: 'hero' }), null)
	assert.equal(widgetOf({ widgetKey: 'nlDialog' }), null)
	assert.equal(widgetOf(null), null)
	assert.deepEqual(hero.computed.asideProps.call({ aside: { props: ['x'] } }), {})
})

test('without an aside the band is as before: the main column is no box', async () => {
	const html = await renderSfc(HERO, { title: 'Welkom' }, LIBRARY)
	assert.doesNotMatch(html, /pq-hero--aside|hero-aside/)
	const css = readFileSync(join(ROOT, 'css/site-theme.css'), 'utf8')
	assert.match(css, /\.pq-site \.ac-hero \.pq-hero__main \{\s*display: contents;/)
	assert.match(css, /clip-path: var\(--nldesign-hero-image-clip-path, none\)/)
})
