#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// news-list-refresh.spec.mjs: after a save or publish on the News page, the
// list is fetched again on the page, sort and filters staff were looking at,
// instead of reloading the browser page and landing on page 1
// (news-list-keeps-its-page). The store is a real Pinia store with the same
// `fetchCollection` action the shared object store has.
//
// Usage:
//   node --test tests/news-list-refresh.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { beforeEach, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { createPinia, defineStore } from 'pinia'
import {
	recordListFetches,
	refreshList,
	resetListFetches,
} from '../src/lib/listRefresh.js'
import { NEWS_LIST } from '../src/lib/newsAuthoring.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

/**
 * A store shaped like the shared object store: `fetchCollection(type, params)`
 * records every call.
 *
 * @return {object} The store.
 */
function objectStore() {
	const useStore = defineStore('object-under-test', {
		state: () => ({ calls: [] }),
		actions: {
			fetchCollection(type, params = {}) {
				this.calls.push({ type, params })
				return []
			},
		},
	})
	return useStore(createPinia())
}

beforeEach(() => resetListFetches())

test('the News list is fetched again on the page, sort and filters it had', () => {
	const store = objectStore()
	recordListFetches(store)
	const page3 = {
		_limit: 20,
		_page: 3,
		_order: '[{"key":"title","order":"asc"}]',
		status: 'draft',
	}
	store.fetchCollection(NEWS_LIST, page3)

	assert.equal(refreshList(NEWS_LIST), true)
	assert.deepEqual(store.calls.at(-1), { type: NEWS_LIST, params: page3 })
})

test('a list never fetched here says so, so the caller can reload instead', () => {
	const store = objectStore()
	recordListFetches(store)
	store.fetchCollection('portaliq-portal', { _page: 2 })

	assert.equal(refreshList(NEWS_LIST), false)
	assert.equal(store.calls.length, 1)
})

test('without a recorded store nothing is fetched', () => {
	assert.equal(refreshList(NEWS_LIST), false)
})

test("the News list key is the manifest page's register and schema", () => {
	const manifest = JSON.parse(
		readFileSync(join(ROOT, 'src', 'manifest.json'), 'utf8'),
	)
	const news = manifest.pages.find((page) => page.id === 'News')
	assert.equal(NEWS_LIST, `${news.config.register}-${news.config.schema}`)
})

test('the News handlers refresh the list, and reload the page only as a fallback', () => {
	const source = readFileSync(join(ROOT, 'src', 'customComponents.js'), 'utf8')
	const news = source.slice(
		source.indexOf('const newsHandlers'),
		source.indexOf('// Features & Roadmap'),
	)
	assert.match(
		news,
		/reload: \(\) => refreshList\(NEWS_LIST\) \|\| window\.location\.reload\(\)/,
	)
	const main = readFileSync(join(ROOT, 'src', 'main.js'), 'utf8')
	assert.match(main, /recordListFetches\(useObjectStore\(pinia\)\)/)
})
