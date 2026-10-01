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
import {
	itemRows,
	removeItem,
	withoutRemoveAction,
} from '../src/portal/lib/itemList.js'
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

test('the detail card renders the item list and the confirm shows the link', () => {
	const page = readFileSync(
		join(ROOT, 'src/portal/components/PageView.jsx'),
		'utf8',
	)
	assert.match(page, /<ItemList\s/)
	assert.match(page, /withoutRemoveAction\(collection,/)
	const confirm = readFileSync(
		join(ROOT, 'src/portal/components/RowActionConfirm.jsx'),
		'utf8',
	)
	assert.match(confirm, /data-testid="rowaction-link"/)
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
