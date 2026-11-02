#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-page-column.spec.mjs: a Mijn page that is only a form (a start point
// such as "Woo-verzoek indienen") stands on one centred column without the
// resident menu, as the WooVerzoek, FormulierStart, FormulierFouten and
// FormulierHulp boards draw it: the title at x 280, not 380 beside the menu.
//
// Usage:
//   node --test tests/site-look/form-page-column.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { isFormPage } from '../../src/site/pages/registry.js'
import { loadSfc } from '../support/render-sfc.mjs'

const SESSION = { subject: 'sanne' }
const START = {
	key: 'startWooVerzoekAlgemeen',
	page: { blocks: [{ type: 'action', action: 'startWooVerzoekAlgemeen' }] },
}

test('a page whose one block is an action is a form page', () => {
	assert.equal(isFormPage(START), true)
	assert.equal(
		isFormPage({ page: { blocks: [{ type: 'action' }, { type: 'cases' }] } }),
		false,
	)
	assert.equal(isFormPage({ page: { blocks: [{ type: 'cases' }] } }), false)
	assert.equal(isFormPage({ special: 'account' }), false)
	assert.equal(isFormPage(null), false)
})

test('a form page leaves the resident menu out', async () => {
	const area = await loadSfc('src/site/components/AccountArea.vue')
	const { withMenu, formPage } = area.computed
	const groups = [{ key: 'cases' }]
	const form = { session: SESSION, entry: START, menuGroups: groups }
	form.formPage = formPage.call(form)
	assert.equal(form.formPage, true)
	assert.equal(withMenu.call(form), false)
	const other = {
		session: SESSION,
		entry: { special: 'account' },
		menuGroups: groups,
	}
	other.formPage = formPage.call(other)
	assert.equal(withMenu.call(other), true)
})

test('a form page centres its content on a reading column', () => {
	const source = readFileSync(
		new URL('../../src/site/components/AccountArea.vue', import.meta.url),
		'utf8',
	)
	const css = source.slice(source.indexOf('<style')).replace(/\s+/g, ' ')
	assert.match(
		css,
		/\.pq-account--form \.pq-account__content \{[^}]*margin-inline: auto;[^}]*max-inline-size: 55rem/,
	)
})
