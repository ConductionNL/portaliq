#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// resident-actions.spec.mjs: "Mijn acties" and the action dialog
// (personal-action-list REQ-RAL-001, REQ-RAL-002): the words and rules, the
// api calls, the menu entry, and what the dialog sends. The server decides
// who may change what; these tests cover what the page offers and sends.
//
// Usage:
//   node --test tests/resident-actions.spec.mjs
//
// @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { actionsApi } from '../src/shared/areaApi.js'
import { createPortalApi } from '../src/shared/portalApi.js'
import { buildNav, shellSections } from '../src/shared/portalNav.js'
import { actionWords, daysLeftLine, dayText, fileAllowed, historyText, leadText, momentText } from '../src/site/lib/actions.js'
import { sitePageLoader } from '../src/site/pages/registry.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const PAGE = 'src/site/pages/e/ActionsPage.vue'
const MODAL = 'src/site/modals/e/ActionEditModal.vue'
const nl = actionWords('nl')
const t = (key) => key

test('the words follow the board, and a file must be a PDF, JPG or PNG up to 10 MB', () => {
	assert.equal(actionWords('en-GB').title, 'My actions')
	assert.equal(nl.fileRule, 'Eén bestand per actie. PDF, JPG of PNG, maximaal 10 MB.')
	assert.equal(fileAllowed({ name: 'bankafschrift-juli.pdf', size: 212 * 1024 }), true)
	assert.equal(fileAllowed({ name: 'foto.PNG', size: 1000 }), true)
	assert.equal(fileAllowed({ name: 'groot.pdf', size: 12 * 1024 * 1024 }), false, 'a 12 MB PDF is refused')
	assert.equal(fileAllowed({ name: 'brief.docx', size: 1000 }), false)
	assert.equal(fileAllowed(null), false)
	assert.equal(dayText('2026-10-14', 'nl'), '14 oktober 2026')
	assert.equal(momentText('2026-10-06 14:02:11', 'nl'), '6 oktober 2026, 14:02')
	assert.equal(daysLeftLine({ endDate: '2026-10-14', status: 'todo' }, '2026-10-08', nl), 'nog 6 dagen')
	assert.equal(daysLeftLine({ endDate: '2026-10-14', status: 'done' }, '2026-10-08', nl), '')
	assert.equal(daysLeftLine({ endDate: '2026-10-01', status: 'doing' }, '2026-10-08', nl), 'verlopen')
})

test('a history line reads as the board writes it', () => {
	assert.equal(historyText({ kind: 'status', field: 'status', from: 'todo', to: 'doing' }, nl, 'nl'), 'Status van Te doen naar Bezig')
	assert.equal(historyText({ kind: 'file', field: 'file', from: '', to: 'bankafschrift-juli.pdf' }, nl, 'nl'), 'Bestand bankafschrift-juli.pdf toegevoegd')
	assert.equal(historyText({ kind: 'created', field: '', from: '', to: '2026-10-14' }, nl, 'nl'), 'Actie gemaakt, uiterlijk 14 oktober 2026')
	assert.equal(historyText({ kind: 'field', field: 'assignee', from: 'Sanne', to: 'Mark Jansen' }, nl, 'nl'), 'Toegewezen aan Mark Jansen')
	assert.equal(historyText({ kind: 'field', field: 'title', from: 'a', to: 'b' }, nl, 'nl'), 'Titel gewijzigd')
})

test('the lead names the plan and who else sees the changes', () => {
	const people = [{ ref: 'sanne', displayName: 'Sanne de Vries' }, { ref: 'mark', displayName: 'Mark Jansen' }]
	assert.equal(leadText({ planTitle: 'Schuldhulp op orde', role: 'member' }, people, 'sanne', nl), 'In het plan Schuldhulp op orde. Wijzigingen ziet Mark Jansen ook.')
	assert.equal(leadText({ planTitle: '', role: 'owner' }, people, 'sanne', nl), '')
	assert.equal(leadText({ planTitle: '', role: 'assignee' }, people, 'mark', nl), nl.givenBy)
})

test('the menu entry is Mijn acties under /mijn/acties, only when the portal switched it on', () => {
	const session = { subjectRef: 'a' }
	const areaPages = [{ special: 'acties', label: 'My actions', icon: 'CheckboxMarkedOutline' }]
	const entry = buildNav([], t, shellSections({ session, contributions: { contributions: [], areaPages }, threads: [], news: [] })).find((e) => e.special === 'acties')
	assert.equal(entry.key, '__acties__')
	assert.equal(typeof sitePageLoader(entry), 'function', 'the entry opens Mijn acties')
	assert.equal(buildNav([], t, shellSections({ session, contributions: { contributions: [], areaPages: [] }, threads: [], news: [] })).some((e) => e.special === 'acties'), false)
})

test('the api reaches the action routes with the right verbs, the plan and the bearer', async () => {
	const asked = []
	globalThis.fetch = async (url, init = {}) => {
		asked.push({ url, method: init.method || 'GET', body: init.body, headers: init.headers || {} })
		return { ok: true, status: 200, json: async () => ({ actions: [], history: [{ kind: 'created' }], id: 'a9' }), blob: async () => new Blob(['x']) }
	}
	try {
		const api = actionsApi(createPortalApi({ apiBase: '/portal/api' }, { getToken: () => 'tok', setToken: () => {} }))
		await api.fetchActions()
		assert.equal((await api.createAction({ title: 'x' })).id, 'a9')
		await api.updateAction('a 1', { status: 'doing' })
		await api.updateAction('a1', { status: 'done' }, 'p1')
		await api.deleteAction('a1')
		assert.deepEqual(await api.fetchHistory('a1', 'p1'), [{ kind: 'created' }])
		await api.attachActionFile('a1', new Blob(['%PDF-']))
	} finally {
		delete globalThis.fetch
	}
	assert.deepEqual(
		asked.map((a) => `${a.method} ${a.url}`),
		[
			'GET /portal/api/my-actions',
			'POST /portal/api/my-actions',
			'PATCH /portal/api/my-actions/a%201',
			'PATCH /portal/api/my-actions/a1?plan=p1',
			'DELETE /portal/api/my-actions/a1',
			'GET /portal/api/my-actions/a1/history?plan=p1',
			'POST /portal/api/my-actions/a1/file',
		],
	)
	assert.ok(asked[6].body instanceof FormData, 'the file goes as multipart')
	assert.equal(asked[6].headers['Content-Type'], undefined)
	assert.ok(asked.every((a) => a.headers.Authorization === 'Bearer tok'))
})

test('the page lists the actions with the count done, and a refusal in words', async () => {
	const page = await loadSfc(PAGE)
	const refused = { words: nl, list: { actions: [] }, loading: true, problem: '', api: { request: async () => ({ ok: false, status: 500, json: {} }) } }
	await page.methods.load.call(refused)
	assert.equal(refused.problem, nl.failed)
	assert.equal(refused.loading, false)
	const html = await renderSfc(PAGE, { api: {}, locale: 'nl' })
	assert.match(html, /Mijn acties/)
	assert.match(html, /Actie toevoegen/)
})

/**
 * The dialog's state with an api that records what it was asked.
 *
 * @param {object|null} action The action.
 * @param {Array} sent Where the calls go.
 * @param {object} [answer] What the server answers.
 * @return {object} The state.
 */
function dialog(action, sent, answer = { ok: true, status: 200, json: { ok: true, id: 'a9' } }) {
	return {
		action,
		planId: action?.plan || '',
		words: nl,
		draft: { title: action?.title || '', description: '', kind: 'once', status: action?.status || 'todo', endDate: '', assignee: 'sanne' },
		problem: '',
		titleMissing: false,
		busy: false,
		emitted: [],
		api: { request: async (method, path, body) => { sent.push([method, path, body]); return answer } },
		$emit(...args) { this.emitted.push(args) },
	}
}

test('the dialog sends a new action, a plan action through the plan, and only status and description for an assignee', async () => {
	const modal = await loadSfc(MODAL)
	const bind = (vm) => Object.assign(vm, {
		get isNew() { return !vm.action?.id },
		get limited() { return vm.action?.role === 'assignee' },
		fields: modal.methods.fields,
		send: modal.methods.send,
	})
	const sent = []

	const fresh = bind(dialog(null, sent))
	fresh.draft.title = ' Bankafschriften van drie maanden uploaden '
	fresh.draft.endDate = '2026-10-14'
	await modal.methods.submit.call(fresh)
	assert.deepEqual(sent[0], ['POST', '/my-actions', { title: 'Bankafschriften van drie maanden uploaden', description: '', kind: 'once', status: 'todo', endDate: '2026-10-14', assignee: 'sanne' }])
	assert.deepEqual(fresh.emitted, [['saved']])

	const inPlan = bind(dialog(null, sent))
	inPlan.planId = 'p1'
	inPlan.draft.title = 'Budgetcoach bellen'
	await modal.methods.submit.call(inPlan)
	assert.equal(sent[1][1], '/plans/p1/actions')

	const given = bind(dialog({ id: 'a1', role: 'assignee', title: 'Aanvraag indienen', status: 'todo' }, sent))
	given.draft.status = 'doing'
	await modal.methods.submit.call(given)
	assert.deepEqual(sent[2], ['PATCH', '/my-actions/a1', { status: 'doing', description: '' }])

	const empty = bind(dialog(null, sent))
	await modal.methods.submit.call(empty)
	assert.equal(sent.length, 3, 'an action without a title sends nothing')
	assert.equal(empty.problem, nl.titleNeeded)

	const refused = bind(dialog({ id: 'a1', role: 'owner', title: 'x' }, [], { ok: false, status: 403, json: { error: 'forbidden' } }))
	await modal.methods.submit.call(refused)
	assert.equal(refused.problem, nl.failed)
	assert.deepEqual(refused.emitted, [])
})

test('a file that is too large is refused before it is sent', async () => {
	const modal = await loadSfc(MODAL)
	const sent = []
	const vm = dialog({ id: 'a1', role: 'owner' }, sent)
	vm.fileRefused = false
	await modal.methods.pickFile.call(vm, { target: { files: [{ name: 'groot.pdf', size: 12 * 1024 * 1024 }], value: 'x' } })
	assert.equal(vm.fileRefused, true)
	assert.equal(sent.length, 0)
	assert.match(await renderSfc(MODAL, { api: {}, locale: 'nl', action: { id: 'a1', title: 'x', role: 'owner', mayDelete: true } }), /Actie verwijderen/)
	assert.doesNotMatch(await renderSfc(MODAL, { api: {}, locale: 'nl', action: { id: 'a1', title: 'x', role: 'assignee', mayDelete: false } }), /Actie verwijderen/)
})
