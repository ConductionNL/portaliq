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
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	addToCollectionBody,
	dossierCollectionOf,
	forwardApi,
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
			actions: [{ id: 'addToDossier' }, { id: 'saveSearch' }],
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
		{ app: 'pipelinq', actions: [{ id: 'addToDossier' }] },
	],
}

test('anonymous sees no save action', () => {
	const offered = offeredActions(MANIFEST, 'opencatalogi')
	assert.equal(saveVisible(false, offered, 'addToDossier'), false)
	assert.equal(saveVisible(false, offered, 'saveSearch'), false)
})

test('offered actions', () => {
	const offered = offeredActions(MANIFEST, 'opencatalogi')
	assert.equal(saveVisible(true, offered, 'addToDossier'), true)
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
		/\.\.\.props,\s*subjectId: this\.routeParam,\s*signedIn: this\.signedIn === true,?(?:\s*\/\/[^\n]*|\s*\w+: this\.\w+,)*\s*\}/,
	)
	assert.match(
		grid,
		/widgetKey === 'federatedSearch'[\s\S]{0,120}\{\s*\.\.\.props,\s*signedIn: this\.signedIn === true,/,
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
		{
			authBase: '/index.php/apps/portaliq/portal/api',
			appId: 'opencatalogi',
			actionId: 'saveSearch',
			body: { title: 'x' },
			token: 'tok',
		},
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

	const failed = await postAction(
		{ authBase: '/b', appId: 'a', actionId: 'x', body: {}, token: 'tok' },
		async () => {
			throw new Error('offline')
		},
	)
	assert.deepEqual(failed, { ok: false, status: 0, body: {} })
})

test('the defaults name the actions opencatalogi declares', async () => {
	const { RESIDENT_ACTION_DEFAULTS } =
		await import('../src/site/lib/residentActions.js')
	assert.equal(RESIDENT_ACTION_DEFAULTS.addAction, 'addToDossier')
	assert.equal(RESIDENT_ACTION_DEFAULTS.saveSearchAction, 'saveSearch')
	assert.equal(RESIDENT_ACTION_DEFAULTS.dossierSchema, 'collection')
})

// Golden record of postAction and forwardApi: every fetch call and every
// result, per scenario. PORTALIQ_GOLDEN_RECORD=1 rewrites the record.
const POST_ACTION_GOLDEN = join(ROOT, 'tests/fixtures/post-action-golden.json')

const POST_ACTION_SCENARIOS = {
	'accepted with a body': {
		input: {
			authBase: '/portal/api',
			appId: 'opencatalogi',
			actionId: 'saveSearch',
			body: { title: 'x' },
			token: 'tok',
		},
		answer: { ok: true, status: 201, json: { id: 'dos-1' } },
	},
	refused: {
		input: {
			authBase: '/portal/api',
			appId: 'opencatalogi',
			actionId: 'addToDossier',
			body: { a: 1 },
			token: 'tok',
		},
		answer: { ok: false, status: 403, json: { error: 'no' } },
	},
	'no token and no body': {
		input: { authBase: '/b', appId: 'a', actionId: 'x', body: null, token: '' },
		answer: { ok: true, status: 200, json: {} },
	},
	'names needing encoding': {
		input: {
			authBase: '/b',
			appId: 'app/one two',
			actionId: 'act?#',
			body: {},
			token: 't',
		},
		answer: { ok: true, status: 200, json: null },
	},
	'an unreadable answer': {
		input: {
			authBase: '/b',
			appId: 'a',
			actionId: 'x',
			body: { q: 'y' },
			token: 't',
		},
		answer: { ok: true, status: 204, throwsJson: true },
	},
	'a network failure': {
		input: { authBase: '/b', appId: 'a', actionId: 'x', body: {}, token: 't' },
		answer: { throwsFetch: true },
	},
	'an answer without ok': {
		input: { authBase: '/b', appId: 'a', actionId: 'x', body: {}, token: 't' },
		answer: { status: 200, json: { maybe: true } },
	},
	'a valid submission key': {
		input: {
			authBase: '/b',
			appId: 'a',
			actionId: 'x',
			body: {},
			token: 't',
			options: { submissionKey: 'abcdefghijklmnop1234' },
		},
		answer: { ok: true, status: 200, json: { done: true } },
	},
	'an invalid submission key': {
		input: {
			authBase: '/b',
			appId: 'a',
			actionId: 'x',
			body: {},
			token: 't',
			options: { submissionKey: 'short' },
		},
		answer: { ok: true, status: 200, json: {} },
	},
}

/**
 * A fetch double that records its calls and answers per scenario.
 *
 * @param {object} answer The scenario's answer.
 * @param {Array} calls  The call log.
 * @return {Function} The double.
 */
function recordingFetch(answer, calls) {
	return async (url, init) => {
		calls.push({ url, init })
		if (answer.throwsFetch) {
			throw new Error('offline')
		}
		return {
			ok: answer.ok,
			status: answer.status,
			json: async () => {
				if (answer.throwsJson) {
					throw new Error('not json')
				}
				return answer.json
			},
		}
	}
}

/**
 * Run one scenario through postAction directly and through forwardApi.
 *
 * @param {object} scenario The scenario.
 * @return {Promise<object>} The recorded calls and results.
 */
async function runPostAction(scenario) {
	const { input, answer } = scenario
	const direct = []
	const viaDirect = recordingFetch(answer, direct)
	const directResult = await postAction(input, viaDirect)

	const forwarded = []
	const api = forwardApi(
		input.authBase,
		() => input.token,
		recordingFetch(answer, forwarded),
	)
	const forwardResult =
		input.options === undefined
			? await api.forwardAction(input.appId, input.actionId, input.body)
			: await api.forwardAction(
					input.appId,
					input.actionId,
					input.body,
					input.options,
				)

	return {
		direct: { calls: direct, result: directResult },
		forward: { calls: forwarded, result: forwardResult },
	}
}

test('postAction and forwardApi match the golden record', async () => {
	const recorded = {}
	for (const [name, scenario] of Object.entries(POST_ACTION_SCENARIOS)) {
		recorded[name] = await runPostAction(scenario)
	}
	const json = JSON.parse(JSON.stringify(recorded))
	if (process.env.PORTALIQ_GOLDEN_RECORD === '1') {
		writeFileSync(POST_ACTION_GOLDEN, JSON.stringify(json, null, '\t') + '\n')
	}
	assert.deepEqual(json, JSON.parse(readFileSync(POST_ACTION_GOLDEN, 'utf8')))
})
