#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// shared-plans.spec.mjs: "Samenwerken", the plans a resident works on with
// their contacts (shared-plans-with-a-caseworker REQ-SPL-001 to REQ-SPL-005):
// the rules of the cards, the api calls, the menu entry and what each page
// sends. The server decides who may do what; these tests cover what the pages
// offer and send.
//
// Usage:
//   node --test tests/shared-plans.spec.mjs
//
// @spec openspec/changes/shared-plans-with-a-caseworker/specs/shared-plans/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { plansApi } from '../src/shared/areaApi.js'
import { createPortalApi } from '../src/shared/portalApi.js'
import { buildNav, shellSections } from '../src/shared/portalNav.js'
import { chipCount, daysLine, fill, personLine, plansOfChip, planWords } from '../src/site/lib/plans.js'
import { sitePageLoader } from '../src/site/pages/registry.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const PLANS = 'src/site/pages/e/PlansPage.vue'
const PLAN = 'src/site/pages/e/PlanPage.vue'
const MODAL = 'src/site/modals/e/PlanStartModal.vue'
const t = (key) => key

/**
 * An api whose `request` decodes the plan routes back into the call that was
 * made, so a test can watch what a page asks for.
 *
 * @param {(action: string, args: object) => object} handler Answers a call with `{ok, status, error, id}`.
 * @return {{request: Function}} The api.
 */
function apiFor(handler) {
	return {
		request: async (method, path, body) => {
			const parts = path.split('/').filter(Boolean).map(decodeURIComponent)
			let call = ['', {}]
			if (method === 'POST' && parts.length === 1) {
				call = ['start', { data: body }]
			} else if (method === 'PATCH' && parts.length === 2) {
				call = ['update', { id: parts[1], data: body }]
			} else if (method === 'DELETE' && parts.length === 2) {
				call = ['delete', { id: parts[1] }]
			} else if (parts[2] === 'participants') {
				call = method === 'POST' ? ['addParticipants', { id: parts[1], data: body }] : ['removeParticipant', { id: parts[1], ref: parts[3] }]
			} else if (parts[2] === 'actions') {
				call = method === 'POST' ? ['addAction', { id: parts[1], data: body }] : ['updateAction', { id: parts[1], actionId: parts[3], data: body }]
			}
			const answer = handler(call[0], call[1])
			return { ok: answer.ok, status: answer.status, json: { error: answer.error, id: answer.id } }
		},
	}
}

const CARDS = [
	{ id: 'p1', title: 'Schuldhulp op orde', state: 'action', role: 'owner', daysLeft: 12, openActions: 3, doneActions: 2, totalActions: 5 },
	{ id: 'p2', title: 'Terug naar werk', state: 'running', role: 'participant', sharedBy: 'Linda Smit', daysLeft: 150, openActions: 1, doneActions: 0, totalActions: 1 },
	{ id: 'p3', title: 'Verhuizing', state: 'done', role: 'owner', daysLeft: -30, openActions: 0, doneActions: 2, totalActions: 2 },
]

test('the chips count and filter the plans, and a plan that needs action still runs', () => {
	const counts = { running: 1, action: 1, done: 1 }
	assert.deepEqual(['all', 'running', 'action', 'done'].map((chip) => chipCount(counts, chip)), [3, 2, 1, 1])
	assert.deepEqual(plansOfChip(CARDS, 'running').map((p) => p.id), ['p1', 'p2'])
	assert.deepEqual(plansOfChip(CARDS, 'action').map((p) => p.id), ['p1'])
	assert.deepEqual(plansOfChip(CARDS, 'done').map((p) => p.id), ['p3'])
	assert.equal(plansOfChip(CARDS, 'all').length, 3)
	assert.deepEqual(plansOfChip(null, 'all'), [])
	assert.equal(chipCount(null, 'all'), 0)
})

test('the words are Dutch and English with the same keys, and no em-dash', () => {
	const nl = planWords('nl')
	const en = planWords('en-GB')
	assert.deepEqual(Object.keys(nl).sort(), Object.keys(en).sort())
	for (const text of [...Object.values(nl), ...Object.values(en)]) {
		assert.doesNotMatch(text, /—/)
	}
	assert.equal(planWords('').title, 'Samenwerken')
	assert.equal(fill(nl.progress, { done: 2, total: 5 }), '2 van 5 acties klaar')
	assert.equal(fill(nl.sharedBy, { name: 'Linda Smit' }), 'Door Linda Smit met u gedeeld')
	assert.equal(daysLine(12, nl), 'Loopt over 12 dagen af')
	assert.equal(daysLine(1, nl), 'Loopt morgen af')
	assert.equal(daysLine(0, nl), 'Loopt vandaag af')
	assert.equal(daysLine(-2, nl), 'Einddatum verstreken')
	assert.equal(daysLine(null, nl), '')
	assert.equal(personLine({ ref: 'sanne', displayName: 'Sanne', isOwner: true }, 'sanne', nl), 'U, maker van het plan')
	assert.equal(personLine({ ref: 'mark', displayName: 'Mark Jansen', isOwner: false }, 'sanne', nl), 'Mark Jansen')
})

test('the menu entry is Samenwerken under /mijn/samenwerken, only when the portal switched plans on', () => {
	const session = { subjectRef: 'a' }
	const areaPages = [{ special: 'samenwerken', label: 'Collaborate', icon: 'ClipboardCheckOutline' }]
	const entry = buildNav([], t, shellSections({ session, contributions: { contributions: [], areaPages }, threads: [], news: [] })).find((e) => e.special === 'samenwerken')
	assert.equal(entry.key, '__samenwerken__')
	assert.equal(typeof sitePageLoader(entry), 'function', 'the entry opens the plans page')
	assert.equal(buildNav([], t, shellSections({ session, contributions: { contributions: [], areaPages: [] }, threads: [], news: [] })).some((e) => e.special === 'samenwerken'), false)
})

test('the api reaches the plan routes with the right verbs and the bearer', async () => {
	const asked = []
	globalThis.fetch = async (url, init = {}) => {
		asked.push({ url, method: init.method || 'GET', body: init.body, auth: init.headers?.Authorization })
		return { ok: true, status: 200, json: async () => ({ plans: [], templates: [{ id: 't' }], id: 'p9', ok: true }) }
	}
	try {
		const api = plansApi(createPortalApi({ apiBase: '/portal/api' }, { getToken: () => 'tok', setToken: () => {} }))
		await api.fetchPlans()
		await api.fetchPlan('a b')
		assert.deepEqual(await api.fetchPlanTemplates(), [{ id: 't' }])
		assert.equal((await api.planAction('start', { data: { templateId: 't' } })).id, 'p9')
		await api.planAction('update', { id: 'p1', data: { goal: 'g' } })
		await api.planAction('delete', { id: 'p1' })
		await api.planAction('addParticipants', { id: 'p1', data: { contactIds: ['c'] } })
		await api.planAction('removeParticipant', { id: 'p1', ref: 'mark' })
		await api.planAction('addAction', { id: 'p1', data: { title: 'x' } })
		await api.planAction('updateAction', { id: 'p1', actionId: 'a1', data: { status: 'done' } })
		assert.deepEqual(await api.planAction('nope'), { ok: false, status: 0, error: 'unknown', id: '' })
	} finally {
		delete globalThis.fetch
	}
	assert.deepEqual(
		asked.map((a) => `${a.method} ${a.url}`),
		[
			'GET /portal/api/plans',
			'GET /portal/api/plans/a%20b',
			'GET /portal/api/plans/templates',
			'POST /portal/api/plans',
			'PATCH /portal/api/plans/p1',
			'DELETE /portal/api/plans/p1',
			'POST /portal/api/plans/p1/participants',
			'DELETE /portal/api/plans/p1/participants/mark',
			'POST /portal/api/plans/p1/actions',
			'PATCH /portal/api/plans/p1/actions/a1',
		],
	)
	assert.equal(JSON.parse(asked[3].body).templateId, 't')
	assert.equal(asked[5].body, undefined)
	assert.ok(asked.every((a) => a.auth === 'Bearer tok'))
})

test('the list shows a card per plan under the chosen chip, in words', async () => {
	const page = await loadSfc(PLANS)
	const words = planWords('nl')
	const vm = { words, overview: { plans: CARDS, counts: { running: 1, action: 1, done: 1 } }, chipChosen: 'all' }
	assert.equal(page.computed.shown.call(vm).length, 3)
	vm.chipChosen = 'done'
	assert.deepEqual(page.computed.shown.call(vm).map((p) => p.id), ['p3'])
	assert.equal(page.methods.count.call(vm, 'running'), 2)
	assert.equal(page.methods.sharedText.call(vm, CARDS[0]), 'Door u gemaakt')
	assert.equal(page.methods.sharedText.call(vm, CARDS[1]), 'Door Linda Smit met u gedeeld')
	assert.equal(page.methods.daysText.call(vm, CARDS[0]), 'Loopt over 12 dagen af')
	assert.equal(page.methods.daysText.call(vm, CARDS[2]), '', 'a done plan has no days left')

	const refused = { words, overview: { plans: [], counts: {} }, loading: true, problem: '', api: { request: async () => ({ ok: false, status: 500, json: {} }) } }
	await page.methods.load.call(refused)
	assert.equal(refused.problem, words.failed)
	assert.equal(refused.loading, false)
	const html = await renderSfc(PLANS, { api: {}, locale: 'nl' })
	assert.match(html, /Samenwerken/)
	assert.match(html, /Nieuw plan/)
})

test('starting a plan sends the template and the chosen contacts, and tells a refusal in words', async () => {
	const modal = await loadSfc(MODAL)
	const sent = []
	const emitted = []
	const make = (answer) => ({
		words: planWords('nl'),
		templateId: 't1',
		title: ' ',
		chosen: ['c-mark'],
		problem: '',
		busy: false,
		api: apiFor((action, args) => { sent.push([action, args]); return answer }),
		$emit: (...a) => emitted.push(a),
	})
	const refused = make({ ok: false, status: 403, error: 'forbidden', id: '' })
	await modal.methods.submit.call(refused)
	assert.deepEqual(sent[0], ['start', { data: { templateId: 't1', title: '', contactIds: ['c-mark'] } }])
	assert.equal(refused.problem, planWords('nl').failed)
	assert.deepEqual(emitted, [])
	await modal.methods.submit.call(make({ ok: true, status: 201, error: '', id: 'p9' }))
	assert.deepEqual(emitted, [['started', 'p9']])
	assert.match(await renderSfc(MODAL, { api: {}, locale: 'nl' }), /Een nieuw plan starten/)
})

test('the plan page offers what the viewer may do and sends each change to the right call', async () => {
	const page = await loadSfc(PLAN)
	const calls = []
	const base = {
		planId: 'p1',
		words: planWords('nl'),
		problem: '',
		notice: '',
		editing: '',
		draft: '',
		endDraft: '2026-10-27',
		newAction: { title: '', endDate: '', assignee: 'sanne' },
		newPerson: '',
		session: { subjectRef: 'sanne' },
		$emit: () => {},
		load: async () => {},
		api: apiFor((action, args) => { calls.push([action, args]); return { ok: true, status: 200, error: '', id: '' } }),
	}
	const vm = { ...base, run: page.methods.run }
	await page.methods.saveField.call({ ...vm, draft: 'Nieuw doel', run: page.methods.run }, 'goal')
	await page.methods.setStatus.call(vm, { id: 'a1' }, 'done')
	await page.methods.saveEnd.call(vm)
	await page.methods.finish.call(vm)
	await page.methods.removePerson.call(vm, { ref: 'mark' })
	await page.methods.addAction.call({ ...vm, newAction: { title: ' Brieven ', endDate: '2026-09-20', assignee: 'mark' } })
	await page.methods.addPerson.call({ ...vm, newPerson: 'c-mark' })
	assert.deepEqual(calls.map(([action]) => action), ['update', 'updateAction', 'update', 'update', 'removeParticipant', 'addAction', 'addParticipants'])
	assert.deepEqual(calls[0][1], { id: 'p1', data: { goal: 'Nieuw doel' } })
	assert.deepEqual(calls[1][1], { id: 'p1', actionId: 'a1', data: { status: 'done' } })
	assert.deepEqual(calls[2][1].data, { endDate: '2026-10-27' })
	assert.deepEqual(calls[3][1].data, { status: 'done' })
	assert.deepEqual(calls[4][1], { id: 'p1', ref: 'mark' })
	assert.deepEqual(calls[5][1].data, { title: 'Brieven', assignee: 'mark', endDate: '2026-09-20' })
	assert.deepEqual(calls[6][1].data, { contactIds: ['c-mark'] })

	const empty = { ...vm, newAction: { title: '  ', endDate: '', assignee: 'sanne' } }
	await page.methods.addAction.call(empty)
	assert.equal(calls.length, 7, 'an action without a title sends nothing')
	assert.equal(empty.problem, planWords('nl').failed)

	const refused = { ...vm, api: apiFor(() => ({ ok: false, status: 403, error: 'forbidden', id: '' })) }
	assert.equal(await page.methods.run.call(refused, 'update', { data: { endDate: '2026-12-01' } }), false)
	assert.equal(refused.problem, planWords('nl').failed, 'a refusal is told in words')

	const addable = page.computed.addable.call({ plan: { participants: [{ ref: 'sanne' }, { ref: 'mark' }], contacts: [{ id: 'c1', ref: 'mark' }, { id: 'c2', ref: 'bea' }] } })
	assert.deepEqual(addable.map((c) => c.id), ['c2'], 'a person already in the plan is not offered again')
})

test('the owner-only controls show for the owner of a running plan only', () => {
	const source = readFileSync(PLAN, 'utf8')
	for (const control of ['plan-person-remove', 'plan-add-person-submit', 'plan-end-save', 'plan-done', 'plan-delete']) {
		const block = source.slice(source.lastIndexOf('<', source.indexOf(`data-testid="${control}"`)))
		assert.ok(source.includes(`data-testid="${control}"`), control)
		const around = source.slice(Math.max(0, source.indexOf(`data-testid="${control}"`) - 900), source.indexOf(`data-testid="${control}"`))
		assert.match(around, /plan\.isOwner && plan\.canEdit/, `${control} is for the owner of a plan that is not done`)
		assert.ok(block.length > 0)
	}
	assert.match(source, /:disabled="!plan\.canEdit"/, 'a done plan\'s statuses are read-only')
	assert.match(source, /plan\.state === 'action'[\s\S]*data-testid="plan-alert"/, 'the alert shows when the plan asks for action')
})
