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
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { branchOptions } from '../src/shared/branch.js'
import { createPortalApi } from '../src/shared/portalApi.js'
import { instance } from './support/page-instance.mjs'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const BASE = '/apps/portaliq/portal/api'
const SWITCHER = 'src/site/components/BranchSwitcher.vue'
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

test('the switcher lists the branches with a label, and is absent without two branches', async () => {
	const session = { branch: '000087654321', branchRestricted: false }
	const html = await renderSfc(SWITCHER, { t, api: {}, session, initialBranches: BRANCHES })
	assert.match(html, /<label for="pq-branch-choice" class="utrecht-form-label">Acting for branch<\/label>/)
	assert.match(html, /<select id="pq-branch-choice" class="utrecht-select"/)
	assert.match(html, /Whole company/)
	assert.match(html, /<option value="000087654321" selected>Korenschoof Zuid, Laan 40, 3521CD Utrecht<\/option>/)

	// One branch: nothing to choose, so the branch in effect is text.
	const one = await renderSfc(SWITCHER, { t, api: {}, session, initialBranches: [BRANCHES[0]] })
	assert.doesNotMatch(one, /pq-branch-choice/)
	assert.match(one, /data-testid="branch-in-effect"[^>]*>Branch 000087654321</)

	// A whole-company session without branches shows nothing at all.
	const none = await renderSfc(SWITCHER, { t, api: {}, session: { branch: '' }, initialBranches: [] })
	assert.doesNotMatch(none, /data-testid="branch/)
})

test('a session the login restricted to a branch never gets the choice, and shows its branch', async () => {
	const session = { branch: '000012345678', branchRestricted: true }
	const html = await renderSfc(SWITCHER, { t, api: {}, session, initialBranches: BRANCHES })
	assert.doesNotMatch(html, /pq-branch-choice/)
	assert.match(html, /data-testid="branch-in-effect"[^>]*>Signed in for branch 000012345678</)

	// And it reads no branches.
	const Switcher = await loadSfc(SWITCHER)
	let asked = 0
	const ctx = instance(Switcher, { t, api: { fetchBranches: async () => { asked += 1 } }, session })
	await ctx.load()
	assert.equal(asked, 0)
	assert.deepEqual(ctx.branches, [])
})

test('a whole-company session reads the branches, and a restricted answer reads as none', async () => {
	const Switcher = await loadSfc(SWITCHER)
	const session = { branch: '', branchRestricted: false }
	const ctx = instance(Switcher, { t, session, api: { fetchBranches: async () => ({ branch: '', restricted: false, branches: BRANCHES }) } })
	await ctx.load()
	assert.deepEqual(ctx.branches, BRANCHES)
	assert.equal(ctx.offersChoice, true)

	const refused = instance(Switcher, { t, session, api: { fetchBranches: async () => ({ branch: '', restricted: true, branches: [] }) } })
	await refused.load()
	assert.deepEqual(refused.branches, [])
	assert.equal(refused.offersChoice, false)
})

test('a chosen branch reloads with the new bearer, and a refusal says so and keeps the page', async () => {
	const Switcher = await loadSfc(SWITCHER)
	const session = { branch: '', branchRestricted: false }
	const chosen = []
	let reloaded = 0
	const ok = instance(Switcher, {
		t,
		session,
		initialBranches: BRANCHES,
		reload: () => { reloaded += 1 },
		api: { chooseBranch: async (branch) => { chosen.push(branch); return { ok: true } } },
	})
	await ok.choose('000087654321')
	assert.deepEqual(chosen, ['000087654321'])
	assert.equal(reloaded, 1)
	assert.equal(ok.refused, false)

	const no = instance(Switcher, {
		t,
		session,
		initialBranches: BRANCHES,
		reload: () => { reloaded += 1 },
		api: { chooseBranch: async () => ({ ok: false }) },
	})
	await no.choose('000099999999')
	assert.equal(no.refused, true)
	assert.equal(reloaded, 1)

	const html = await renderSfc(SWITCHER, { t, api: {}, session, initialBranches: BRANCHES })
	assert.doesNotMatch(html, /That branch could not be chosen\./, 'no refusal before a choice')
	const refusedSource = readFileSync(join(ROOT, SWITCHER), 'utf8')
	assert.match(refusedSource, /role="alert"[\s\S]*That branch could not be chosen\./)
})

test('the site header mounts the switcher for a signed-in session', () => {
	const app = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.match(app, /import\('\.\/components\/BranchSwitcher\.vue'\)/)
	assert.match(app, /<BranchSwitcher\s+v-if="session"\s+:t="t"\s+:api="api"\s+:session="session" \/>/)
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
