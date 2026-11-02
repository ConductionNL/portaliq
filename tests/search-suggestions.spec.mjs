// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// search-suggestions-while-typing T03 (REQ-SST-001, REQ-SST-002): the
// threshold, the debounce, the abort, the failure and the keyboard.
//
// Usage:
//   node --test tests/search-suggestions.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	announcement,
	createSuggester,
	moveActive,
	suggestionRoute,
	suggestionUrl,
	toSuggestions,
	typedPart,
} from '../src/site/lib/searchSuggestions.js'
import { mountSfc } from './support/mount-sfc.mjs'

const ANSWER = {
	results: [
		{ name: 'fietspad Lindelaan verlichting', '@self': { id: 'p-1' } },
		{
			name: 'Rapport fietspad.pdf',
			resultType: 'document',
			'@self': { id: 'd-2' },
		},
		{ name: 'Fietspad Noord', '@self': { id: 'p-3' } },
		{ name: 'fietspad vier', '@self': { id: 'p-4' } },
		{ name: 'fietspad vijf', '@self': { id: 'p-5' } },
		{ name: 'fietspad zes', '@self': { id: 'p-6' } },
		{ name: '', '@self': { id: 'p-7' } },
	],
}

/** A clock the test moves by hand. */
function fakeClock() {
	let now = 0
	let next = 1
	const timers = new Map()
	return {
		setTimer: (fn, ms) => {
			timers.set(next, { fn, at: now + ms })
			return next++
		},
		clearTimer: (id) => timers.delete(id),
		async tick(ms) {
			now += ms
			for (const [id, timer] of [...timers]) {
				if (timer.at <= now) {
					timers.delete(id)
					timer.fn()
				}
			}
			await new Promise((resolve) => setImmediate(resolve))
		},
	}
}

/** A fetch that records its calls and answers from a function. */
function fakeFetch(answer) {
	const calls = []
	const impl = async (url, init) => {
		calls.push({ url: new URL(url), signal: init.signal })
		return answer(init.signal)
	}
	return { calls, impl }
}

const ok = (body = ANSWER) => ({ ok: true, json: async () => body })

function suggester(clock, fetcher, lists) {
	return createSuggester({
		endpoint: '/api/federation/publications',
		origin: 'https://portaal.example',
		onChange: (list) => lists.push(list.map((s) => s.title)),
		fetchImpl: fetcher.impl,
		setTimer: clock.setTimer,
		clearTimer: clock.clearTimer,
	})
}

test('two characters ask nothing and show no list', async () => {
	const clock = fakeClock()
	const fetcher = fakeFetch(() => ok())
	const lists = []
	suggester(clock, fetcher, lists).input('fi')
	await clock.tick(1000)
	assert.equal(fetcher.calls.length, 0)
	assert.deepEqual(lists.at(-1), [])
})

test('three characters ask once, after 250 ms of quiet, for five rows and few fields', async () => {
	const clock = fakeClock()
	const fetcher = fakeFetch(() => ok())
	const lists = []
	const s = suggester(clock, fetcher, lists)
	s.input('fie')
	await clock.tick(200)
	s.input('fiet')
	await clock.tick(200)
	assert.equal(fetcher.calls.length, 0, 'still typing')
	await clock.tick(100)
	assert.equal(fetcher.calls.length, 1)
	const url = fetcher.calls[0].url
	assert.equal(url.searchParams.get('_search'), 'fiet')
	assert.equal(url.searchParams.get('_limit'), '5')
	assert.match(url.searchParams.get('_fields'), /name/)
	assert.equal(lists.at(-1).length, 5, 'at most five, an untitled row left out')
})

test('a newer keystroke aborts the older request, and its answer is dropped', async () => {
	const clock = fakeClock()
	let release
	const fetcher = fakeFetch(
		(signal) =>
			new Promise((resolve) => {
				release = () => resolve(ok())
				signal.addEventListener('abort', () => resolve(ok()))
			}),
	)
	const lists = []
	const s = suggester(clock, fetcher, lists)
	s.input('fiets')
	await clock.tick(250)
	assert.equal(fetcher.calls.length, 1)
	s.input('fietsp')
	assert.equal(fetcher.calls[0].signal.aborted, true)
	await clock.tick(0)
	assert.equal(
		lists.filter((l) => l.length > 0).length,
		0,
		'the stale answer shows nothing',
	)
	release?.()
})

test('a failing or refused request shows no list and no error', async () => {
	for (const answer of [
		() => {
			throw new Error('down')
		},
		() => ({ ok: false, json: async () => ({}) }),
	]) {
		const clock = fakeClock()
		const lists = []
		suggester(clock, fakeFetch(answer), lists).input('fiets')
		await clock.tick(250)
		assert.deepEqual(lists.at(-1), [])
	}
})

test('a request slower than one second is abandoned', async () => {
	const clock = fakeClock()
	const fetcher = fakeFetch(
		(signal) =>
			new Promise((_, reject) =>
				signal.addEventListener('abort', () => reject(new Error('aborted'))),
			),
	)
	const lists = []
	suggester(clock, fetcher, lists).input('fiets')
	await clock.tick(250)
	await clock.tick(1000)
	assert.equal(fetcher.calls[0].signal.aborted, true)
	assert.deepEqual(lists.at(-1), [])
})

test('the keys: down twice is the second row, up and down stop at the ends', () => {
	assert.equal(moveActive(-1, 3, 'ArrowDown'), 0)
	assert.equal(moveActive(0, 3, 'ArrowDown'), 1)
	assert.equal(moveActive(2, 3, 'ArrowDown'), 2)
	assert.equal(moveActive(0, 3, 'ArrowUp'), 0)
	assert.equal(moveActive(-1, 3, 'ArrowUp'), 2)
	assert.equal(moveActive(0, 3, 'a'), null)
	assert.equal(moveActive(0, 0, 'ArrowDown'), null)
})

test('the words: bold typed part, kind route, announcement, url', () => {
	assert.deepEqual(typedPart('fietspad Lindelaan', 'FIETSpad Lin'), {
		typed: 'fietspad Lin',
		rest: 'delaan',
	})
	assert.deepEqual(typedPart('Noord fietspad', 'fiets'), {
		typed: '',
		rest: 'Noord fietspad',
	})
	assert.equal(suggestionRoute({ id: 'd-2', kind: 'document' }), '/document/d-2')
	assert.equal(
		suggestionRoute({ id: 'p-1', kind: 'publication' }),
		'/publicatie/p-1',
	)
	assert.equal(announcement(3), '3 suggesties')
	assert.equal(announcement(1), '1 suggestie')
	assert.equal(toSuggestions({}).length, 0)
	assert.match(
		suggestionUrl({ endpoint: '/x', origin: 'https://a.b', query: 'a b' }),
		/_search=a\+b/,
	)
})

test('the list: keyboard only opens the second suggestion, Escape keeps the text', async () => {
	globalThis.window = { location: { origin: 'https://portaal.example' } }
	const fetchCalls = []
	globalThis.fetch = async (url) => {
		fetchCalls.push(url)
		return ok()
	}
	const list = await mountSfc('src/site/components/SearchSuggestions.vue', {
		query: '',
		endpoint: '/api/federation/publications',
	})
	// The host's typed text reaches the engine through the `query` watcher; the
	// mount helper cannot change a prop, so the engine is driven directly.
	list.vm.suggester.input('fietspad')
	await new Promise((resolve) => setTimeout(resolve, 400))
	await list.flush()

	assert.ok(list.find('search-suggestions-list') !== null, 'the list opened')
	assert.equal(
		list.textOf(list.find('search-suggestions-live')).trim(),
		'5 suggesties',
	)
	const key = (name) => ({ key: name, preventDefault() {} })
	assert.equal(
		list.vm.onKey(key('Enter')),
		false,
		'nothing picked: Enter runs the search',
	)
	list.vm.onKey(key('ArrowDown'))
	list.vm.onKey(key('ArrowDown'))
	assert.equal(list.vm.onKey(key('Enter')), true)
	assert.deepEqual(list.emitted.choose.at(-1)[0], {
		id: 'd-2',
		title: 'Rapport fietspad.pdf',
		kind: 'document',
	})
	assert.equal(list.vm.onKey(key('Escape')), false, 'already closed by the choice')
})
