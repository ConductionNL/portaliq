#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// admin-lists-as-drawn.spec.mjs: the staff lists name their rows and label
// their header buttons as the boards draw them (PtInzendingen, PtNieuws,
// PtAanvraagformulieren, PtPortals, PtAccounts, PtUitnodigingen,
// PtToegangsverzoeken).
//
// Usage:
//   node --test tests/admin-lists-as-drawn.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const manifest = JSON.parse(readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'))
const icons = readFileSync(new URL('../src/icons.js', import.meta.url), 'utf8')

/**
 * The config of the page with this id.
 *
 * @param {string} id The page id.
 * @return {object} The page's config.
 */
function config(id) {
	const page = manifest.pages.find((p) => p.id === id)
	assert.ok(page, `page ${id} exists`)
	return page.config
}

/**
 * The icon of the header button with this label on a page.
 *
 * @param {string} id The page id.
 * @param {string} label The button's label.
 * @return {string|undefined} Its icon name.
 */
function buttonIcon(id, label) {
	const button = config(id).headerButtons.find((b) => b.label === label)
	assert.ok(button, `${id} has a "${label}" header button`)
	return button.icon
}

test('PtInzendingen and PtNieuws name their rows as a 14px name in the main ink', () => {
	assert.equal(config('PortalSubmissions').rowTitle, 'strong')
	assert.equal(config('News').rowTitle, 'strong')
})

test('PtAanvraagformulieren starts its rows with an ordinary cell', () => {
	assert.equal(config('FormBindings').rowTitle, 'text')
})

test('PtPortals underlines the portal name, as every index list does', () => {
	assert.equal(config('Portals').rowTitle, undefined)
})

test('PtToegangsverzoeken shows the requester with initials', () => {
	const requester = config('AccessRequests').columns.find((c) => c.key === 'displayName')
	assert.equal(requester.widget, 'avatar')
})

test('the header buttons carry the icons the boards draw', () => {
	const drawn = [
		['News', 'Nieuw nieuwsbericht', 'Plus'],
		['Portals', 'Import', 'Upload'],
		['Portals', 'New portal', 'Plus'],
		['PortalAccounts', 'Import', 'Upload'],
		['PortalAccounts', 'Iemand uitnodigen', 'EmailOutline'],
		['PortalAccounts', 'Account uitgeven', 'Plus'],
		['Invitations', 'Iemand uitnodigen', 'EmailFastOutline'],
	]
	for (const [id, label, icon] of drawn) {
		assert.equal(buttonIcon(id, label), icon, `${id} "${label}"`)
		assert.match(icons, new RegExp(`^\\t${icon},$`, 'm'), `${icon} is registered`)
	}
})
