#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// event-list-source.spec.mjs: what the dated list asks the catalogue for its
// source (an app's own index, upcoming or the school year; the old list of
// types; nothing) and what it keeps when the catalogue fails, pinned before
// the source read was split into named steps.
//
// Usage:
//   node --test tests/event-list-source.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instance } from './support/page-instance.mjs'
import { loadSfc } from './support/render-sfc.mjs'

const NlEventList = await loadSfc('src/site/widgets/nlEventList/NlEventList.vue')

/**
 * A browser with a fake catalogue that answers or fails.
 *
 * @param {number} status The answer's status.
 * @return {{calls: Array<URL>}} The requests.
 */
function catalogue(status = 200) {
	const calls = []
	globalThis.document = { getElementById: () => null, querySelector: () => null }
	globalThis.window = {
		location: { origin: 'https://school.example', search: '', hash: '' },
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		sessionStorage: { getItem: () => null, setItem() {} },
	}
	globalThis.fetch = async (url) => {
		calls.push(new URL(String(url)))
		return { ok: status < 300, status, json: async () => ({ items: [{ id: 'x:1', type: 'course', title: 'Dag', date: '2026-10-08' }], total: 1, page: 1, pages: 1, facets: [] }) }
	}
	return { calls }
}

/**
 * The query parameters of a request, as one object of lists.
 *
 * @param {URL} url The request.
 * @return {object} The parameters.
 */
function paramsOf(url) {
	const out = {}
	for (const [key, value] of url.searchParams.entries()) {
		out[key] = [...(out[key] || []), value]
	}
	return out
}

test('an app\'s own index: upcoming by default, the whole school year when asked', async () => {
	const { calls } = catalogue()
	const upcoming = instance(NlEventList, { portal: 'school', source: { app: 'learniq', kind: 'course', limit: 5 }, items: [] })
	await upcoming.loadSource()
	const year = instance(NlEventList, { portal: 'school', source: { app: 'learniq', kind: 'course', range: 'schoolYear', categories: ['a'] }, items: [] })
	await year.loadSource()
	assert.equal(calls.length, 2)
	const first = paramsOf(calls[0])
	const second = paramsOf(calls[1])
	assert.deepEqual(first.upcoming, ['1'])
	assert.equal(second.upcoming, undefined)
	assert.ok(calls[1].search.includes('a'), 'the categories go along')
	assert.equal(upcoming.fetched[0].title, 'Dag')
	assert.equal(year.fetched.length, 1)
})

test('the old list of types, nothing without a source, and a failed catalogue', async () => {
	const { calls } = catalogue()
	const typed = instance(NlEventList, { portal: 'school', source: { types: ['course', 5, 'news'] }, items: [] })
	await typed.loadSource()
	assert.deepEqual(paramsOf(calls[0]).types, ['course,news'])
	for (const source of [null, { types: [] }, { types: 'course' }, { app: 'learniq' }]) {
		const none = instance(NlEventList, { portal: 'school', source, items: [] })
		await none.loadSource()
		assert.equal(none.fetched === undefined || none.fetched === null || Array.isArray(none.fetched), true)
	}
	assert.equal(calls.length, 1, 'nothing is asked without a usable source')

	catalogue(500)
	for (const source of [{ app: 'learniq', kind: 'course' }, { types: ['course'] }]) {
		const failed = instance(NlEventList, { portal: 'school', source, items: [] })
		failed.fetched = []
		await failed.loadSource()
		assert.equal(failed.fetched, null)
	}
})
