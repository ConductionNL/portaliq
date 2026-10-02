#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// attached-actions.spec.mjs: another app's action on a collection's detail in
// the signed-in site (woo-journey-entry-points, REQ-WJE-004).
//
// Usage:
//   node --test tests/attached-actions.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	attachedActionsOf,
	attachedBody,
	fieldLabel,
	runAttachedAction,
} from '../src/shared/attachedActions.js'
import { mountSfc } from './support/mount-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const ASK = {
	app: 'pipelinq',
	id: 'askAboutDossier',
	label: 'Stel een vraag over dit dossier',
	fields: ['question', 'title'],
	fieldConfigs: { question: { label: 'Uw vraag' } },
}

test('the collection lists only well-formed attached actions', () => {
	const collection = {
		attachedActions: [ASK, { app: 'dossiq' }, 'x', { id: 'y' }],
	}
	assert.deepEqual(attachedActionsOf(collection), [ASK])
	assert.deepEqual(attachedActionsOf({}), [])
})

const REPLY = {
	app: 'pipelinq',
	id: 'replyToQuestion',
	label: 'Reageren op het antwoord',
	fields: ['message'],
	rowWhen: { field: 'status', in: ['awaiting_customer'] },
}

// attach-to-own-collection REQ-ATO-002: the row decides, as the server will.
test('an attached action shows only on the rows its rowWhen names', () => {
	const collection = { attachedActions: [ASK, REPLY] }
	assert.deepEqual(
		attachedActionsOf(collection, { status: 'awaiting_customer' }),
		[ASK, REPLY],
	)
	assert.deepEqual(attachedActionsOf(collection, { status: 'converted' }), [ASK])
	assert.deepEqual(attachedActionsOf(collection, {}), [ASK])
	const malformed = { ...REPLY, rowWhen: { field: 'status' } }
	assert.deepEqual(
		attachedActionsOf({ attachedActions: [malformed] }, { status: 'x' }),
		[],
	)
	// Without a row (a renderer that does not pass it yet) nothing is hidden;
	// the server still refuses the wrong row with 409.
	assert.deepEqual(attachedActionsOf(collection), [ASK, REPLY])
})

test('the body carries only the declared fields', () => {
	assert.deepEqual(
		attachedBody(ASK, {
			question: ' Wanneer? ',
			title: '',
			collectionId: 'forged',
		}),
		{ question: 'Wanneer?', title: '' },
	)
})

test('a field label comes from fieldConfigs, else the field name', () => {
	assert.equal(fieldLabel(ASK, 'question'), 'Uw vraag')
	assert.equal(fieldLabel(ASK, 'title'), 'title')
})

test('the forward names the action app', async () => {
	const calls = []
	const api = {
		forwardRowAction: async (...args) => {
			calls.push(args)
			return { ok: true, status: 201, body: { id: 't-1' } }
		},
	}
	const collection = {
		id: 'mijnDossiers',
		register: 'opencatalogi',
		schema: 'collection',
	}
	const result = await runAttachedAction(api, collection, { id: 'dos-1' }, ASK, {
		question: 'Wanneer?',
	})
	assert.deepEqual(calls[0], [
		collection,
		'dos-1',
		'askAboutDossier',
		{ question: 'Wanneer?', title: '' },
		'pipelinq',
	])
	assert.equal(result.ok, true)
	assert.equal(result.messageKey, 'Done.')

	const refused = await runAttachedAction(
		{ forwardRowAction: async () => ({ ok: false, status: 404, body: {} }) },
		collection,
		{ id: 'dos-1' },
		ASK,
		{},
	)
	assert.equal(refused.messageKey, 'This can no longer be done for this item.')
})

test('the api sends actionApp and the site fills the detail slot with the actions', () => {
	const api = readFileSync(join(ROOT, 'src/shared/portalApi.js'), 'utf8')
	assert.match(
		api,
		/actionApp\s*\?\s*`&actionApp=\$\{encodeURIComponent\(actionApp\)\}`\s*:\s*''/,
	)
	const sliceC = readFileSync(join(ROOT, 'src/site/pages/c/index.js'), 'utf8')
	assert.match(
		sliceC,
		/registerBlockSlot\(\s*'attachedActions',\s*\(\) => import\('\.\.\/\.\.\/components\/c\/AttachedActions\.vue'\)/,
	)
})

// The site's Vue port (site-reaches-portal-parity T13, REQ-SRP-028).

test('the site shows one button per attached action and forwards with actionApp', async () => {
	const calls = []
	const api = {
		forwardRowAction: async (...args) => {
			calls.push(args)
			return { ok: true, status: 201, body: {} }
		},
	}
	const collection = {
		id: 'mijnDossiers',
		register: 'opencatalogi',
		schema: 'collection',
		attachedActions: [
			ASK,
			{ app: 'dossiq', id: 'startWoo', label: 'Start een Woo-verzoek' },
		],
	}
	const block = await mountSfc('src/site/components/c/AttachedActions.vue', {
		collection,
		row: { id: 'dos-1' },
		api,
	})

	assert.match(
		block.text(),
		/^Stel een vraag over dit dossier Start een Woo-verzoek/,
	)
	await block.fire(block.find('attached-action-askAboutDossier'), 'click')
	const question = block.findAll(
		(n) => n.props.id === 'attached-askAboutDossier-question',
	)[0]
	assert.equal(
		block.textOf(
			block.findAll(
				(n) => n.tag === 'label' && n.props.for === question.props.id,
			)[0],
		),
		'Uw vraag',
	)
	await block.fire(question, 'input', { value: ' Wanneer? ' })
	await block.fire(block.findAll((n) => n.tag === 'form')[0], 'submit')

	assert.deepEqual(calls[0].slice(1), [
		'dos-1',
		'askAboutDossier',
		{ question: 'Wanneer?', title: '' },
		'pipelinq',
	])
	assert.equal(block.textOf(block.find('attached-actions-status')), 'Done.')
	assert.ok(
		block.find('attached-action-askAboutDossier'),
		'the buttons come back after sending',
	)
})

// attach-to-own-collection T04 on the site: the record on screen decides, as
// it did in the portal, so the reply shows only on a question that waits for
// the resident.
test('the site offers an attached action only on the records its rowWhen names', async () => {
	const collection = { id: 'vragen', attachedActions: [REPLY] }
	const waiting = await mountSfc('src/site/components/c/AttachedActions.vue', {
		collection,
		row: { id: 'q-1', status: 'awaiting_customer' },
		api: {},
	})
	assert.ok(waiting.find('attached-action-replyToQuestion'))

	for (const status of ['in_progress', 'converted']) {
		const other = await mountSfc('src/site/components/c/AttachedActions.vue', {
			collection,
			row: { id: 'q-2', status },
			api: {},
		})
		assert.ok(!other.find('attached-action-replyToQuestion'), status)
		assert.equal(other.text(), '', `nothing renders on a ${status} question`)
	}
})

test('the site renders nothing without attached actions or without a record', async () => {
	const none = await mountSfc('src/site/components/c/AttachedActions.vue', {
		collection: { id: 'x' },
		row: { id: 'r' },
		api: {},
	})
	assert.equal(none.text(), '')
	const noRow = await mountSfc('src/site/components/c/AttachedActions.vue', {
		collection: { id: 'x', attachedActions: [ASK] },
		row: null,
		api: {},
	})
	assert.equal(noRow.text(), '')
})

test('site: the detail card leaves a place for the attached actions', () => {
	const card = readFileSync(
		join(ROOT, 'src/site/components/collections/DetailCard.vue'),
		'utf8',
	)
	assert.match(card, /<SlotHost\s+name="attachedActions"/)
})
