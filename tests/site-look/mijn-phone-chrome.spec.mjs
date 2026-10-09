#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mijn-phone-chrome.spec.mjs: the own area on a phone as the school
// MobielHome boards draw it, for a portal that asks for it: the person's
// initials in the header instead of "Uitloggen", signing out at the end of
// the menu, no "Home" crumb, no empty room above the footer, and the short
// footer (mijn-phone-chrome).
//
// Usage:
//   node --test tests/site-look/mijn-phone-chrome.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { renderSfc } from '../support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const read = (file) => readFileSync(join(ROOT, file), 'utf8')

test('the short footer: the logo with the portal name, one line, the links', async () => {
	const html = await renderSfc('src/site/components/chrome/CompactFooter.vue', {
		title: 'Vaartveld College',
		footer: {
			text: 'Telefoon: [telefoonnummer]',
			links: [
				{ label: 'Toegankelijkheid', href: '/toegankelijkheid' },
				{ label: 'Privacy', href: '/privacy' },
				{ label: '', href: '/leeg' },
			],
		},
	})
	assert.match(html, /class="ac-footer pq-site__footer pq-compact-footer"/)
	assert.match(html, /role="img" aria-label="Vaartveld College"/)
	assert.match(html, /Telefoon: \[telefoonnummer\]/)
	assert.match(html, /href="\/toegankelijkheid"[^>]*>Toegankelijkheid<\/a>/)
	assert.equal(html.split('<li>').length - 1, 2)
})

test('the menu ends in "Uitloggen" when the header shows the person', async () => {
	const html = await renderSfc('src/site/components/ResidentMenu.vue', {
		groups: [
			{
				key: 'g',
				title: 'Mijn Vaartveld',
				items: [
					{ key: 'o', name: 'Overzicht', link: '/mijn', href: '/mijn' },
				],
			},
		],
		signOutLabel: 'Uitloggen',
	})
	assert.match(html, /data-testid="site-resident-menu-signout">\s*Uitloggen/)
	const plain = await renderSfc('src/site/components/ResidentMenu.vue', {
		groups: [],
	})
	assert.equal(plain.includes('site-resident-menu-signout'), false)
})

test('only a portal that asks for it gets the phone chrome, and the rules are for phones only', () => {
	const area = read('src/site/components/AccountArea.vue')
	assert.match(area, /this\.portal\?\.residentMenu\?\.phoneHeader === 'person'/)
	assert.match(area, /'pq-account--compact-phone': withMenu && compactPhone/)
	const phone = area.slice(area.indexOf('@media (max-width: 767px)'))
	assert.match(
		phone,
		/pq-account--compact-phone\)\s+\.pq-site__header--designed\s+\.pq-header-tools__chip \{\s*display: inline-flex;/,
	)
	assert.match(phone, /\.pq-header-tools__signout \{\s*display: none;/)
	assert.match(phone, /\.ac-header__navigation-breadcrumb \{\s*display: none;/)
	assert.match(phone, /\.pq-site__main \{\s*padding-block-end: 24px;/)
	assert.match(phone, /\.pq-compact-footer \{\s*display: block;/)
	const app = read('src/site/App.vue')
	assert.match(
		app,
		/v-if="accountRoute && session && site\.footer && site\.footer\.compact"/,
	)
	assert.match(app, /import\('\.\/components\/chrome\/CompactFooter\.vue'\)/)
})
