#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// woo-entry-points.spec.mjs: the resident's save actions on the public site
// (woo-journey-entry-points, REQ-WJE-001 to REQ-WJE-003).
//
// Usage:
//   node --test tests/woo-entry-points.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	addToCollectionBody,
	dossierCollectionOf,
	offeredActions,
	postAction,
	saveSearchBody,
	saveVisible,
	withSignedIn,
} from '../src/site/lib/residentActions.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const MANIFEST = {
	contributions: [
		{
			app: 'opencatalogi',
			actions: [{ id: 'addToCollection' }, { id: 'saveSearch' }],
			collections: [
				{
					id: 'mijnDossiers',
					register: 'opencatalogi',
					schema: 'collection',
				},
				{
					id: 'mijnZoekopdrachten',
					register: 'opencatalogi',
					schema: 'savedSearch',
				},
			],
		},
		{ app: 'pipelinq', actions: [{ id: 'addToCollection' }] },
	],
}

test('anonymous sees no save action', () => {
	const offered = offeredActions(MANIFEST, 'opencatalogi')
	assert.equal(saveVisible(false, offered, 'addToCollection'), false)
	assert.equal(saveVisible(false, offered, 'saveSearch'), false)
})

test('offered actions', () => {
	const offered = offeredActions(MANIFEST, 'opencatalogi')
	assert.equal(saveVisible(true, offered, 'addToCollection'), true)
	assert.equal(saveVisible(true, offered, 'removeEverything'), false)

	const without = offeredActions(
		{ contributions: [MANIFEST.contributions[1]] },
		'opencatalogi',
	)
	assert.equal(saveVisible(true, without, 'addToCollection'), false)
	assert.equal(
		saveVisible(true, offeredActions(null, 'opencatalogi'), 'saveSearch'),
		false,
	)
})

test('host props win', () => {
	assert.deepEqual(withSignedIn({ signedIn: true, title: 'Zoeken' }, false), {
		signedIn: false,
		title: 'Zoeken',
	})
	assert.equal(withSignedIn({}, 'yes').signedIn, false)

	// The grid hands `signedIn` to both blocks AFTER the authored props.
	const grid = readFileSync(
		join(ROOT, 'src/site/components/WidgetGrid.vue'),
		'utf8',
	)
	assert.match(
		grid,
		/\.\.\.props,\s*subjectId: this\.routeParam,\s*signedIn: this\.signedIn === true,?\s*\}/,
	)
	assert.match(
		grid,
		/widgetKey === 'federatedSearch'[\s\S]{0,120}\{ \.\.\.props, signedIn: this\.signedIn === true \}/,
	)
	const app = readFileSync(join(ROOT, 'src/site/App.vue'), 'utf8')
	assert.match(app, /signedIn: this\.session !== null/)
})

test('the dossier collection comes from the manifest', () => {
	assert.deepEqual(dossierCollectionOf(MANIFEST, 'opencatalogi', 'collection'), {
		id: 'mijnDossiers',
		register: 'opencatalogi',
		schema: 'collection',
	})
	assert.equal(dossierCollectionOf(MANIFEST, 'opencatalogi', 'nothing'), null)
})

test('add to collection body', () => {
	assert.deepEqual(
		addToCollectionBody({ title: ' Fietspad Oost ', publication: 'pub-1' }),
		{ title: 'Fietspad Oost', publication: 'pub-1', attachment: null },
	)
	assert.deepEqual(
		addToCollectionBody({
			collection: 'dos-1',
			title: 'ignored',
			publication: 'pub-1',
			attachment: 42,
		}),
		{ collection: 'dos-1', publication: 'pub-1', attachment: '42' },
	)
})

test('save search body', () => {
	const query = {
		text: 'fietspad',
		filters: {
			informatiecategorie: ['infocat014'],
			organisation: [],
			periodFrom: '',
			periodTo: '',
		},
		catalog: '',
	}
	assert.deepEqual(saveSearchBody({ title: 'Fietspaden', query }), {
		title: 'Fietspaden',
		frequency: 'daily',
		query,
	})
	assert.equal(
		saveSearchBody({ title: 'x', frequency: 'hourly', query }).frequency,
		'daily',
	)
	assert.equal(
		saveSearchBody({ title: 'x', frequency: 'weekly', query }).frequency,
		'weekly',
	)
})

test('post action sends the bearer and the body', async () => {
	const calls = []
	const fetchImpl = async (url, init) => {
		calls.push({ url, init })
		return { ok: true, status: 201, json: async () => ({ id: 'dos-1' }) }
	}
	const result = await postAction(
		'/index.php/apps/portaliq/portal/api',
		'opencatalogi',
		'saveSearch',
		{ title: 'x' },
		'tok',
		fetchImpl,
	)
	assert.deepEqual(result, { ok: true, status: 201, body: { id: 'dos-1' } })
	assert.equal(
		calls[0].url,
		'/index.php/apps/portaliq/portal/api/actions/opencatalogi/saveSearch',
	)
	assert.equal(calls[0].init.method, 'POST')
	assert.equal(calls[0].init.headers.Authorization, 'Bearer tok')
	assert.equal(calls[0].init.body, JSON.stringify({ title: 'x' }))

	const failed = await postAction('/b', 'a', 'x', {}, 'tok', async () => {
		throw new Error('offline')
	})
	assert.deepEqual(failed, { ok: false, status: 0, body: {} })
})
