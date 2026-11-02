#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// family-members.spec.mjs: the family block offers the partner and children
// the BRP holds, one card each with name, relation and birth year, and says so
// when the register cannot be asked (data-lookups-and-checks-in-forms REQ-DIF-004).
//
// Usage:
//   node --test tests/family-members.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { chosenNames, toggleRef } from '../src/site/components/forms/family.js'
import { fetchFamily, initialValues } from '../src/site/lib/intakeApi.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const FAMILY = 'src/site/components/forms/FamilyMembers.vue'
const PEOPLE = [
	{ ref: 'partner-aaaaaaaaaaaaaaaaaaaa', name: 'Henk de Vries', relation: 'partner', birthYear: '1983' },
	{ ref: 'child-bbbbbbbbbbbbbbbbbbbb', name: 'Sanne de Vries', relation: 'child', birthYear: '2012' },
]

test('toggleRef adds, removes and never doubles a reference', () => {
	assert.deepEqual(toggleRef([], 'a'), ['a'])
	assert.deepEqual(toggleRef(['a', 'b'], 'a'), ['b'])
	assert.deepEqual(toggleRef(undefined, 'a'), ['a'])
	assert.equal(chosenNames(['child-bbbbbbbbbbbbbbbbbbbb', 'ghost'], PEOPLE), 'Sanne de Vries')
})

test('fetchFamily sends the bearer and turns every failure into null', async () => {
	let seen = null
	const ok = async (url, init) => {
		seen = { url, init }
		return { ok: true, json: async () => ({ members: PEOPLE }) }
	}
	assert.deepEqual(await fetchFamily('/api', 'tok', true, ok), PEOPLE)
	assert.match(seen.url, /\/api\/intake\/family\?sameAddressOnly=1$/)
	assert.equal(seen.init.headers.Authorization, 'Bearer tok')

	assert.equal(await fetchFamily('/api', 'tok', true, async () => ({ ok: false })), null)
	assert.equal(await fetchFamily('/api', 'tok', true, async () => { throw new Error('x') }), null)
	assert.equal(await fetchFamily('/api', 'tok', true, async () => ({ ok: true, json: async () => ({}) })), null)
})

test('a family field starts with nobody chosen', () => {
	assert.deepEqual(initialValues([{ name: 'mee', type: 'familyMembers' }], {}).mee, [])
})

test('site: one card per person with the relation and the birth year, and the fixed line', async () => {
	const html = await renderSfc(FAMILY, { initialPeople: PEOPLE, modelValue: ['child-bbbbbbbbbbbbbbbbbbbb'], locale: 'nl' })
	assert.match(html, /Henk de Vries/)
	assert.match(html, /Partner, geboren in 1983/)
	assert.match(html, /Kind, geboren in 2012/)
	assert.match(html, /data-testid="family-note"[^>]*>\s*Staat er iemand niet bij/)
	assert.match(html, /<input type="checkbox" checked data-testid="family-child-bbbbbbbbbbbbbbbbbbbb">/)
	assert.match(html, /<input type="checkbox" data-testid="family-partner-aaaaaaaaaaaaaaaaaaaa">/)
})

test('load shows the unavailable sentence and no cards when the register is down', async () => {
	const screen = await loadSfc(FAMILY)
	globalThis.window = {
		location: { hash: '', pathname: '/', search: '' },
		sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		history: { replaceState() {} },
	}
	globalThis.fetch = async () => ({ ok: false })
	const vm = { base: '/api', sameAddressOnly: true, people: [], state: 'loading' }
	await screen.methods.load.call(vm)
	assert.equal(vm.state, 'unavailable')
	assert.deepEqual(vm.people, [])
})

test('toggling hands the new list to the form', async () => {
	const screen = await loadSfc(FAMILY)
	const emitted = []
	const vm = { chosen: ['a'], $emit: (...args) => emitted.push(args) }
	screen.methods.toggle.call(vm, 'b')
	assert.deepEqual(emitted, [['update:modelValue', ['a', 'b']]])
})
