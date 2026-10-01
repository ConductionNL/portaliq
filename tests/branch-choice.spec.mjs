#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// branch-choice.spec.mjs: a whole-company eHerkenning session narrows to one
// of the company's branches in the header, and back to the whole company
// (signin-eherkenning-branch T06, REQ-SEB-003).
//
// Usage:
//   node --test tests/branch-choice.spec.mjs

import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { branchOptions } from '../src/portal/lib/branch.js'

const require = createRequire(import.meta.url)
const babel = require('@babel/core')
const { createElement } = require('react')
const { renderToStaticMarkup } = require('react-dom/server')

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests')
const BASE = '/apps/portaliq/portal/api'
/**
 * An identity translator with {name} substitution.
 *
 * @param {string} key The English source key.
 * @param {object} vars The substitutions.
 * @return {string} The text.
 */
function t(key, vars = {}) {
	return key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))
}

/**
 * Compile one portal source file with the portal build's React preset and
 * import it from where `react` resolves; relative imports point at src/portal.
 *
 * @param {string} relative The path under src/portal.
 * @return {Promise<object>} The module.
 */
async function load(relative) {
	const source = join(ROOT, 'src', 'portal', relative)
	const compiled = babel.transformSync(readFileSync(source, 'utf8'), {
		filename: source,
		babelrc: false,
		configFile: false,
		presets: [['@babel/preset-react', { runtime: 'automatic' }]],
	})
	mkdirSync(OUT_DIR, { recursive: true })
	const out = join(
		OUT_DIR,
		relative.replace(/[\\/]/g, '_').replace(/\.jsx?$/, '.mjs'),
	)
	const branchLib = pathToFileURL(
		join(ROOT, 'src', 'portal', 'lib', 'branch.js'),
	).href
	writeFileSync(out, compiled.code.replace("'../lib/branch.js'", `'${branchLib}'`))
	return import(pathToFileURL(out).href)
}

const { createPortalApi } = await load('../shared/portalApi.js')
const { default: BranchSwitcher } = await load('components/BranchSwitcher.jsx')

const BRANCHES = [
	{
		number: '000012345678',
		name: 'Bakkerij de Korenschoof',
		address: 'Marktplein 1, 3511AB Utrecht',
		main: true,
	},
	{
		number: '000087654321',
		name: 'Korenschoof Zuid',
		address: 'Laan 40, 3521CD Utrecht',
		main: false,
	},
]

/**
 * Stub the browser: a stored bearer and a recording fetch.
 *
 * @param {Array<{status: number, body: object}>} answers The answers, in order.
 * @return {{calls: Array<object>, stored: Array<string>}} What was called and stored.
 */
function stubBrowser(answers) {
	const calls = []
	const stored = []
	const queue = [...answers]
	globalThis.window = {
		localStorage: {
			getItem: () => 'token-1',
			setItem: (_key, value) => stored.push(value),
			removeItem() {},
		},
	}
	globalThis.fetch = async (url, init = {}) => {
		calls.push({ url: String(url), init })
		const next = queue.shift() || { status: 200, body: {} }
		return {
			ok: next.status >= 200 && next.status < 300,
			status: next.status,
			json: async () => next.body,
		}
	}
	return { calls, stored }
}

test('the options are the whole company first, then each branch by name and address', () => {
	assert.deepEqual(branchOptions(BRANCHES, t), [
		{ id: '', label: 'Whole company' },
		{
			id: '000012345678',
			label: 'Bakkerij de Korenschoof, Marktplein 1, 3511AB Utrecht',
		},
		{ id: '000087654321', label: 'Korenschoof Zuid, Laan 40, 3521CD Utrecht' },
	])
	assert.deepEqual(branchOptions([], t), [])
	assert.deepEqual(branchOptions(null, t), [])
})

test('the branches are read with the bearer, and a failure reads as none', async () => {
	const { calls } = stubBrowser([
		{ status: 200, body: { branch: '', restricted: false, branches: BRANCHES } },
		{ status: 401, body: {} },
	])
	const api = createPortalApi({ apiBase: BASE })
	assert.deepEqual(await api.fetchBranches(), {
		branch: '',
		restricted: false,
		branches: BRANCHES,
	})
	assert.equal(calls[0].url, `${BASE}/session/branches`)
	assert.equal(calls[0].init.headers.Authorization, 'Bearer token-1')
	assert.deepEqual(await api.fetchBranches(), {
		branch: '',
		restricted: true,
		branches: [],
	})
})

test('choosing a branch stores the new bearer, and a refusal stores nothing', async () => {
	const { calls, stored } = stubBrowser([
		{ status: 200, body: { token: 'token-2', branch: '000087654321' } },
		{ status: 403, body: { error: 'branch_refused' } },
	])
	const api = createPortalApi({ apiBase: BASE })
	assert.deepEqual(await api.chooseBranch('000087654321'), { ok: true })
	assert.equal(calls[0].url, `${BASE}/session/branch`)
	assert.equal(calls[0].init.method, 'POST')
	assert.deepEqual(JSON.parse(calls[0].init.body), { branch: '000087654321' })
	assert.deepEqual(stored, ['token-2'])
	assert.deepEqual(await api.chooseBranch('000099999999'), { ok: false })
	assert.deepEqual(stored, ['token-2'])
})

test('the switcher lists the branches with a label, and is absent without two branches', () => {
	const html = renderToStaticMarkup(
		createElement(BranchSwitcher, {
			t,
			branches: BRANCHES,
			value: '000087654321',
			onChange() {},
		}),
	)
	assert.match(
		html,
		/<label for="portaliq-branch-choice">Acting for branch<\/label>/,
	)
	assert.match(html, /Whole company/)
	assert.match(
		html,
		/<option value="000087654321" selected="">Korenschoof Zuid, Laan 40, 3521CD Utrecht<\/option>/,
	)
	assert.equal(
		renderToStaticMarkup(
			createElement(BranchSwitcher, {
				t,
				branches: [BRANCHES[0]],
				value: '',
				onChange() {},
			}),
		),
		'',
	)
	assert.equal(
		renderToStaticMarkup(
			createElement(BranchSwitcher, {
				t,
				branches: [],
				value: '',
				onChange() {},
			}),
		),
		'',
	)
})

test('the header offers the choice only to a session the login did not restrict', () => {
	const app = readFileSync(join(ROOT, 'src', 'portal', 'App.jsx'), 'utf8')
	assert.match(
		app,
		/import BranchSwitcher from '@portal\/components\/BranchSwitcher\.jsx'/,
	)
	assert.match(app, /state\.session\.branchRestricted !== true/)
	assert.match(app, /api\.fetchBranches\(\)/)
	assert.match(app, /api\.chooseBranch\(/)
})

test('the new strings are translated for every locale the portal ships', () => {
	for (const locale of ['nl', 'en']) {
		const strings = JSON.parse(
			readFileSync(
				join(ROOT, 'src', 'shared', 'i18n', `${locale}.json`),
				'utf8',
			),
		)
		for (const key of [
			'Whole company',
			'Acting for branch',
			'That branch could not be chosen.',
		]) {
			assert.ok(
				typeof strings[key] === 'string' && strings[key] !== '',
				`${locale}: ${key}`,
			)
		}
	}
})
