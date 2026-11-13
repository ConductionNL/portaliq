#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// submission-row-actions.spec.mjs: the Submissions list offers Edit and
// Register again on a row, and never Copy or Delete, as a row action or on a
// selection. Register again shows on a failed or pending row only and calls
// the staff route that the route table declares.
//
// Usage:
//   node --test tests/submission-row-actions.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

// nextcloud-vue's visibleWhen module reads window at import; a stand-in is enough here.
globalThis.window = { localStorage: { getItem: () => null, setItem: () => {} }, sessionStorage: { getItem: () => null, setItem: () => {} }, OC: {} }
const { evaluateVisibleWhenLocal, isLocallyDecidableVisibleWhen } = await import(new URL('../node_modules/@conduction/nextcloud-vue/src/utils/visibleWhen.js', import.meta.url))

const manifest = JSON.parse(readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'))
const routes = readFileSync(new URL('../appinfo/routes.php', import.meta.url), 'utf8')
const en = JSON.parse(readFileSync(new URL('../l10n/en.json', import.meta.url), 'utf8')).translations
const nl = JSON.parse(readFileSync(new URL('../l10n/nl.json', import.meta.url), 'utf8')).translations

/**
 * The manifest page with this id.
 *
 * @param {string} id The page id.
 * @return {object}
 */
function page(id) {
	const found = manifest.pages.find((candidate) => candidate.id === id)
	assert.ok(found, `${id} is in the manifest`)
	return found
}

const config = page('PortalSubmissions').config
const registerAgain = config.actions.find((action) => typeof action === 'object' && action.id === 'register-again')

test('a submission cannot be copied or deleted from the list', () => {
	assert.equal(config.showCopyAction, false)
	assert.equal(config.showDeleteAction, false)
	assert.equal(config.showMassCopy, false)
	assert.equal(config.showMassDelete, false)
	assert.ok(!config.actions.includes('builtin:copy'))
	assert.ok(!config.actions.includes('builtin:delete'))
})

test('the row menu reads Edit, then Register again', () => {
	assert.deepEqual(config.actions.map((action) => (typeof action === 'string' ? action : action.id)), ['builtin:edit', 'register-again'])
})

test('Register again posts to the staff route for the row', () => {
	assert.equal(registerAgain.type, 'api-call')
	assert.equal(registerAgain.method, 'POST')
	assert.equal(registerAgain.url, '/apps/portaliq/api/submissions/@objectId/register-again')
	assert.match(routes, /'submissionAdmin#registerAgain', 'url' => '\/api\/submissions\/\{id\}\/register-again', 'verb' => 'POST'/)
})

// The rule CnRowActions applies: a locally decidable visibleWhen that is false hides the entry.
test('Register again shows on a failed or pending row, never on a delivered one', () => {
	assert.equal(isLocallyDecidableVisibleWhen(registerAgain.visibleWhen), true)
	assert.equal(evaluateVisibleWhenLocal(registerAgain.visibleWhen, { deliveryStatus: 'failed' }), true)
	assert.equal(evaluateVisibleWhenLocal(registerAgain.visibleWhen, { deliveryStatus: 'pending' }), true)
	assert.equal(evaluateVisibleWhenLocal(registerAgain.visibleWhen, { deliveryStatus: 'delivered' }), false)
})

test('every new string is in English and Dutch', () => {
	for (const key of [registerAgain.label, registerAgain.successMessage, registerAgain.errorMessage]) {
		assert.equal(en[key], key, `${key} in en`)
		assert.ok(typeof nl[key] === 'string' && nl[key] !== '' && nl[key] !== key, `${key} in nl`)
	}
	assert.equal(nl['Register again'], 'Opnieuw registreren')
})
