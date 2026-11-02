#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// collection-intro.spec.mjs: a contributed collection's `intro` shows as
// plain text above its list (collection-intro T2). The helper reads it, the
// site's collection renderer puts it above the table, and "My cases" shows
// the intros of the cases collections under its heading. Neither renders it
// as HTML.
//
// Usage:
//   node --test tests/collection-intro.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { casesIntros, introOf } from '../src/shared/collectionIntro.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

test("a collection's intro is its text, trimmed, or ''", () => {
	assert.equal(introOf({ intro: '  Uw zaken.  ' }), 'Uw zaken.')
	assert.equal(introOf({ intro: '' }), '')
	assert.equal(introOf({ intro: 42 }), '')
	assert.equal(introOf({}), '')
	assert.equal(introOf(null), '')
})

test('My cases reads the intros of the cases collections, once each', () => {
	const contributions = {
		contributions: [
			{
				app: 'dossiq',
				collections: [
					{ id: 'mijnZaken', kind: 'cases', intro: 'Uw lopende zaken.' },
					{ id: 'taken', intro: 'Niet over zaken.' },
				],
			},
			{
				app: 'pipelinq',
				collections: [
					{ id: 'mijnAanvragen', kind: 'cases', intro: 'Uw lopende zaken.' },
					{ id: 'leeg', kind: 'cases', intro: '  ' },
				],
			},
		],
	}
	assert.deepEqual(casesIntros(contributions), ['Uw lopende zaken.'])
	assert.deepEqual(casesIntros(contributions.contributions), ['Uw lopende zaken.'])
	assert.deepEqual(casesIntros(null), [])
	assert.deepEqual(casesIntros({ contributions: [{ collections: null }] }), [])
})

test("the collection renderer shows the intro as text above the collection's table", () => {
	const page = readFileSync(
		join(ROOT, 'src/site/pages/collections/ContributionPage.vue'),
		'utf8',
	)
	assert.match(page, /from '\.\.\/\.\.\/\.\.\/shared\/collectionIntro\.js'/)
	const block = page.slice(
		page.indexOf('data-testid="contribution-page-collection"'),
		page.indexOf('<CollectionTable'),
	)
	assert.match(block, /v-if="introOf\(item\.collection\)"/)
	assert.match(block, /\{\{ introOf\(item\.collection\) \}\}/)
	assert.doesNotMatch(page, /v-html="introOf/)
})

test('My cases shows the intros as text under its heading', () => {
	const page = readFileSync(join(ROOT, 'src/site/pages/e/MyCasesPage.vue'), 'utf8')
	assert.match(page, /from '\.\.\/\.\.\/\.\.\/shared\/collectionIntro\.js'/)
	const head = page.slice(page.indexOf('id="pq-cases-title"'), page.indexOf('my-cases-loading'))
	assert.match(head, /data-testid="my-cases-intro"/)
	assert.match(head, /\{\{ intro \}\}/)
	assert.doesNotMatch(page, /v-html="intro/)
})
