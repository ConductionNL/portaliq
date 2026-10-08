#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// hero-on-the-school-boards.spec.mjs: the hero hands its portal to the block
// beside it, and the plain hero draws its search on the band with the set's
// label weight (hero-on-the-school-boards).
//
// Usage:
//   node --test tests/site-look/hero-on-the-school-boards.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { loadSfc } from '../support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const LIBRARY = {
	'@conduction/nextcloud-vue': "export const cnRenderMarkdown = () => ''\n",
	'@conduction/nextcloud-vue/public':
		'export const CnSiteIcon = {}\nexport const CnSiteSearch = {}\nexport const CnSiteSection = {}\nexport const siteBlockIsBand = () => false\nexport const siteBlockRegistry = {}\n',
}

test('the block beside the hero gets the portal after its own props', async () => {
	const hero = await loadSfc('src/site/components/HeroBlock.vue', LIBRARY)
	const asideProps = hero.computed.asideProps
	assert.deepEqual(
		asideProps.call({
			aside: {
				props: { heading: 'Eerstvolgende cursusdagen', portal: 'elders' },
			},
			portal: 'warmtepompacademie',
		}),
		{ heading: 'Eerstvolgende cursusdagen', portal: 'warmtepompacademie' },
	)
	assert.deepEqual(
		asideProps.call({ aside: { props: { heading: 'X' } }, portal: '' }),
		{ heading: 'X' },
	)
})

test('the grid hands the hero its portal', async () => {
	const grid = await loadSfc('src/site/components/WidgetGrid.vue', LIBRARY)
	const props = grid.methods.propsFor.call(
		{ portal: 'warmtepompacademie' },
		{ widgetKey: 'hero', props: { title: 'Cursussen' } },
	)
	assert.equal(props.portal, 'warmtepompacademie')
	assert.equal(props.title, 'Cursussen')
})

test('the plain hero draws its search on the band, the label in the set weight', () => {
	const css = readFileSync(join(ROOT, 'css/site-theme.css'), 'utf8')
	assert.match(
		css,
		/\.pq-site \.ac-hero\.pq-hero--plain \.ac-search-box \{\s*background: transparent;/,
	)
	assert.match(
		css,
		/font-weight: var\(--nldesign-website-hero-search-label-font-weight, 400\)/,
	)
})
