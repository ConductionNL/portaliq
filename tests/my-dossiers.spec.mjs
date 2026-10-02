#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// my-dossiers.spec.mjs: the dossier's items, removing one, and the share link
// (my-dossiers, REQ-MYD-002 to REQ-MYD-004).
//
// Usage:
//   node --test tests/my-dossiers.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { itemRows, removeItem, withoutRemoveAction } from '../src/shared/itemList.js'
import { answerLink, runRowAction } from '../src/shared/rowAction.js'
import { mountSfc } from './support/mount-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

test('not public marker', () => {
	const rows = itemRows({
		items: [
			{
				id: 'i1',
				title: 'Besluit fietspad',
				url: '/p/1',
				note: 'Lees p. 3',
				public: true,
			},
			{ id: 'i2', title: 'Inventaris', url: '', note: '', public: false },
			'rubbish',
		],
	})
	assert.deepEqual(rows, [
		{
			id: 'i1',
			title: 'Besluit fietspad',
			href: '/p/1',
			note: 'Lees p. 3',
			notPublic: false,
		},
		{ id: 'i2', title: 'Inventaris', href: '', note: '', notPublic: true },
	])
	assert.deepEqual(itemRows(null), [])
})

test('remove body', async () => {
	const calls = []
	const api = {
		forwardRowAction: async (...args) => {
			calls.push(args)
			return { ok: true, status: 200, body: {} }
		},
	}
	const collection = {
		id: 'mijnDossiers',
		register: 'opencatalogi',
		schema: 'collection',
		itemList: { removeAction: 'removeCollectionItem' },
	}
	const result = await removeItem(api, collection, { id: 'dos-1' }, 'i1')
	assert.deepEqual(calls[0], [
		collection,
		'dos-1',
		'removeCollectionItem',
		{ itemId: 'i1' },
	])
	assert.equal(result.ok, true)

	const none = await removeItem(
		api,
		{ ...collection, itemList: {} },
		{ id: 'dos-1' },
		'i1',
	)
	assert.equal(none.ok, false)
	assert.equal(calls.length, 1)
})

test('the remove action is not a table row button', () => {
	const collection = { itemList: { removeAction: 'removeCollectionItem' } }
	assert.deepEqual(
		withoutRemoveAction(collection, [
			{ id: 'removeCollectionItem' },
			{ id: 'share' },
		]).map((a) => a.id),
		['share'],
	)
})

test('answer link', async () => {
	assert.equal(
		answerLink({
			ok: true,
			body: { link: 'https://gemeente.example/shared/abc' },
		}),
		'https://gemeente.example/shared/abc',
	)
	assert.equal(
		answerLink({
			ok: true,
			body: {
				link: '/index.php/apps/opencatalogi/api/collections/shared/abc',
			},
		}),
		'/index.php/apps/opencatalogi/api/collections/shared/abc',
	)
	assert.equal(answerLink({ ok: true, body: { link: 'javascript:alert(1)' } }), '')
	assert.equal(answerLink({ ok: false, body: { link: 'https://x.example' } }), '')

	const api = {
		forwardRowAction: async () => ({
			ok: true,
			status: 200,
			body: { link: 'https://gemeente.example/shared/abc' },
		}),
	}
	const outcome = await runRowAction(api, {}, { id: 'dos-1' }, { id: 'share' })
	assert.equal(outcome.link, 'https://gemeente.example/shared/abc')
})

test('the site confirm step shows the link the action answered', async () => {
	const api = {
		forwardRowAction: async () => ({
			ok: true,
			status: 200,
			body: { link: 'https://gemeente.example/shared/abc' },
		}),
	}
	const step = await mountSfc('src/site/modals/c/RowActionConfirm.vue', {
		action: { id: 'share', label: 'Delen' },
		collection: { id: 'mijnDossiers' },
		row: { id: 'dos-1' },
		api,
	})
	await step.fire(step.find('rowaction-continue'), 'click')
	assert.equal(
		step.find('rowaction-link').props.value,
		'https://gemeente.example/shared/abc',
	)
})

// The site's Vue pages (site-reaches-portal-parity slice b, REQ-SRP-017,
// REQ-SRP-020).

const { loadSfc, renderSfc } = await import('./support/render-sfc.mjs')
const VUE_ITEMS = 'src/site/components/collections/ItemList.vue'
const dossier = {
	id: 'mijnDossiers',
	itemList: { label: 'In dit dossier', removeAction: 'removeItem' },
}
const answer = {
	items: [
		{ id: 'i1', title: 'Besluit fietspad', url: 'https://example.org/p/1' },
		{ id: 'i2', title: 'Oude notitie', public: false, note: 'Ingetrokken' },
	],
}

test('site: the item list marks an item that is no longer public and offers remove per item', async () => {
	const html = await renderSfc(VUE_ITEMS, {
		collection: dossier,
		row: { id: 'dos-1' },
		t: (key, vars) => (vars ? `${key}:${vars.title}` : key),
		initialAnswer: answer,
	})

	assert.equal((html.match(/data-testid="item-list-item"/g) || []).length, 2)
	assert.match(
		html,
		/<a class="utrecht-link" href="https:\/\/example.org\/p\/1">Besluit fietspad<\/a>/,
	)
	assert.equal((html.match(/data-testid="item-not-public"/g) || []).length, 1)
	assert.equal((html.match(/data-testid="item-list-remove"/g) || []).length, 2)
	assert.match(html, /aria-label="Remove \{title\}:Oude notitie"/)
})

test('site: removing one item forwards the remove action and reads the list again', async () => {
	const component = await loadSfc(VUE_ITEMS)
	const calls = []
	const self = {
		api: {
			forwardRowAction: async (...args) => {
				calls.push(args)
				return { ok: true, status: 200 }
			},
		},
		collection: dossier,
		row: { id: 'dos-1' },
		t: (key) => key,
		message: '',
		load: async () => calls.push(['load']),
	}
	await component.methods.remove.call(self, 'i2')

	assert.deepEqual(calls[0], [dossier, 'dos-1', 'removeItem', { itemId: 'i2' }])
	assert.deepEqual(calls[1], ['load'])
	assert.equal(self.message, 'Removed.')
})

test('site: the detail card renders the item list, the table drops the remove action', () => {
	const card = readFileSync(
		join(ROOT, 'src/site/components/collections/DetailCard.vue'),
		'utf8',
	)
	assert.match(card, /<ItemList\s/)
	const blocks = readFileSync(
		join(ROOT, 'src/site/pages/collections/pageBlocks.js'),
		'utf8',
	)
	assert.match(blocks, /withoutRemoveAction\(\s*collection,/)
})

test('site: a file downloads through the portal api with the record and the file', async () => {
	const component = await loadSfc('src/site/components/collections/DetailCard.vue')
	const calls = []
	const self = {
		rowId: 'dos-1',
		collection: dossier,
		api: {
			downloadFile: async (...args) => {
				calls.push(args)
				return { ok: true }
			},
		},
		t: (key) => key,
		download: { busyId: null, message: '' },
	}
	await component.methods.onDownload.call(self, { id: 'f1', name: 'besluit.pdf' })

	assert.deepEqual(calls, [[dossier, 'dos-1', { id: 'f1', name: 'besluit.pdf' }]])
	assert.deepEqual(self.download, { busyId: null, message: '' })
})
