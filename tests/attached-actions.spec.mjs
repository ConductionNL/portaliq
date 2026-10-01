#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// attached-actions.spec.mjs: another app's action on a collection's detail in
// the signed-in portal (woo-journey-entry-points, REQ-WJE-004).
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
} from '../src/portal/lib/attachedActions.js'

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

test('the api sends actionApp and the detail card renders the actions', () => {
	const api = readFileSync(join(ROOT, 'src/shared/portalApi.js'), 'utf8')
	assert.match(
		api,
		/actionApp\s*\?\s*`&actionApp=\$\{encodeURIComponent\(actionApp\)\}`\s*:\s*''/,
	)
	const page = readFileSync(
		join(ROOT, 'src/portal/components/PageView.jsx'),
		'utf8',
	)
	assert.match(page, /<AttachedActions\s/)
})

test('site: the detail card leaves a place for the attached actions (slice c fills it)', () => {
	const card = readFileSync(join(ROOT, 'src/site/components/collections/DetailCard.vue'), 'utf8')
	assert.match(card, /<SlotHost\s+name="attachedActions"/)
})
