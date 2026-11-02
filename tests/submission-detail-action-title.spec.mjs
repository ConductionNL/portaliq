#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// submission-detail-action-title.spec.mjs: a submission's page names the
// action by the title the resident read (board PtInzending: "Grof afval laten
// ophalen") in its header, its breadcrumb and its "Handeling" fact, and a row
// written before `actionTitle` existed still reads by its action id. All of it
// is manifest config over nextcloud-vue's own mechanisms, evaluated here with
// the installed package: `titleTemplate` empties on a blank token and falls
// back to `titleField`, and a data-widget field shows by `visibleWhen`.
//
// Usage:
//   node --test tests/submission-detail-action-title.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

/**
 * A Storage with nothing in it.
 *
 * @return {Storage}
 */
function memoryStorage() {
	const items = new Map()
	return {
		getItem: (key) => (items.has(key) ? items.get(key) : null),
		setItem: (key, value) => items.set(key, String(value)),
		removeItem: (key) => items.delete(key),
		key: (index) => [...items.keys()][index] ?? null,
		clear: () => items.clear(),
		get length() {
			return items.size
		},
	}
}

globalThis.window = { localStorage: memoryStorage(), sessionStorage: memoryStorage(), OC: {} }

const ncvue = new URL('../node_modules/@conduction/nextcloud-vue/src/utils/', import.meta.url)
const { fillTemplate } = await import(new URL('fillTemplate.js', ncvue))
const { evaluateVisibleWhenLocal } = await import(new URL('visibleWhen.js', ncvue))

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const NL = JSON.parse(read('l10n/nl.json')).translations
const DETAIL = MANIFEST.pages.find((page) => page.id === 'PortalSubmissionDetail').config
const FACTS = DETAIL.widgets.find((w) => w.id === 'submission-data').content

const TITLED = { actionId: 'createGrofAfval', actionTitle: 'Grof afval laten ophalen' }
const UNTITLED = { actionId: 'createExample', actionTitle: '' }
const OLD = { actionId: 'createExample' }

/**
 * The header text the way CnDetailPage's objectDisplayName picks it:
 * the filled `titleTemplate`, else the `titleField` value.
 *
 * @param {object} row The submission.
 * @return {string}
 */
function headerTitle(row) {
	const filled = DETAIL.titleTemplate ? fillTemplate(DETAIL.titleTemplate, row) : ''
	return filled !== '' ? filled : String(row[DETAIL.titleField] ?? '')
}

/**
 * The facts the data widget shows for a row, in include order.
 *
 * @param {object} row The submission.
 * @return {string[]}
 */
function shownFacts(row) {
	return FACTS.include.filter((key) => {
		const rule = FACTS.overrides?.[key]?.visibleWhen
		return rule === undefined || evaluateVisibleWhenLocal(rule, row)
	})
}

test('the header names the submission by its action title, else by its action id', () => {
	assert.equal(headerTitle(TITLED), 'Grof afval laten ophalen')
	assert.equal(headerTitle(UNTITLED), 'createExample')
	assert.equal(headerTitle(OLD), 'createExample')
})

test('the breadcrumb ends on the same title as the header', () => {
	assert.equal(DETAIL.breadcrumb.label, 'Submissions')
	assert.equal(DETAIL.breadcrumb.currentField, undefined, 'a currentField would name the crumb by the raw id')
})

test('the Handeling fact shows the action title, else the action id, never both', () => {
	assert.deepEqual(shownFacts(TITLED).slice(0, 2), ['actionTitle', 'appId'])
	assert.deepEqual(shownFacts(UNTITLED).slice(0, 2), ['actionId', 'appId'])
	assert.deepEqual(shownFacts(OLD).slice(0, 2), ['actionId', 'appId'])
	for (const key of ['actionTitle', 'actionId']) {
		assert.equal(NL[FACTS.overrides[key].label], 'Handeling', `${key} reads "Handeling" as drawn`)
	}
})
