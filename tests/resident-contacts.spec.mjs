#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// resident-contacts.spec.mjs: "My contacts" (own-contacts-and-invitations).
// The page rules, the api calls, the invitation link kept across sign-in, the
// menu entry that is there only when the portal switched contacts on, and the
// dialog that shows a refusal in words and sends nothing until pressed.
//
// Usage:
//   node --test tests/resident-contacts.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { contactsApi } from '../src/shared/areaApi.js'
import {
	CONTACT_INVITATION_KEY,
	keepContactInvitation,
	redeemKeptContactInvitation,
} from '../src/shared/contactInvitation.js'
import { createPortalApi } from '../src/shared/portalApi.js'
import { buildNav, shellSections } from '../src/shared/portalNav.js'
import { contactsOfRole, initialsOf, inviteProblem } from '../src/site/pages/e/contacts.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const PAGE = 'src/site/pages/e/ContactsPage.vue'
const MODAL = 'src/site/modals/e/InviteContactModal.vue'
const CONTACT_MODAL = 'src/site/modals/e/ContactMessageModal.vue'
const t = (key, vars = {}) => key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))

const OVERVIEW = {
	incoming: [{ id: 'r1', displayName: 'Bea Jansen', state: 'requested', role: 'contact', sentAt: '2026-10-01T10:00:00+00:00' }],
	outgoing: [
		{ id: 'r2', displayName: 'new@example.nl', email: 'new@example.nl', state: 'invited', role: 'contact', sentAt: '2026-10-02T10:00:00+00:00' },
		{ id: 'r3', displayName: 'Cor', state: 'declined', role: 'contact' },
	],
	contacts: [
		{ id: 'r4', displayName: 'Ada Lovelace', role: 'begeleider', line: 'Gemeente Zuiddrecht', state: 'approved' },
		{ id: 'r5', displayName: 'Dirk', role: 'contact', line: '', state: 'approved' },
	],
	counts: { all: 2, begeleider: 1, contact: 1, organisatie: 0 },
}

test('the role chips filter the approved contacts, and initials stay at two letters', () => {
	assert.equal(contactsOfRole(OVERVIEW.contacts, 'all').length, 2)
	assert.deepEqual(contactsOfRole(OVERVIEW.contacts, 'begeleider').map((c) => c.id), ['r4'])
	assert.deepEqual(contactsOfRole(OVERVIEW.contacts, 'organisatie'), [])
	assert.deepEqual(contactsOfRole(null, 'all'), [])
	assert.equal(initialsOf('Ada Lovelace'), 'AL')
	assert.equal(initialsOf('ada king of lovelace'), 'AL')
	assert.equal(initialsOf('Dirk'), 'D')
	assert.equal(initialsOf(''), '?')
})

test('a refused invitation is told in words, never as a code', () => {
	assert.equal(inviteProblem({ error: 'invalid' }), 'Fill in a valid e-mail address.')
	assert.match(inviteProblem({ error: 'duplicate' }), /already invited/)
	assert.match(inviteProblem({ error: 'limit' }), /most invitations/)
	assert.equal(inviteProblem({ error: 'failed' }), 'That did not work. Try again later.')
	assert.equal(inviteProblem(null), 'That did not work. Try again later.')
})

test('the page lists what waits, the contacts with counts, and what a contact sees', async () => {
	const html = await renderSfc(PAGE, { api: {}, t, locale: 'nl' }, {})
	assert.match(html, /Invite someone/)
	assert.match(html, /Loading/, 'the rows come after the read')
	const page = await loadSfc(PAGE)
	const vm = { overview: { incoming: [], outgoing: [], contacts: [], counts: {} }, loading: true, problem: '', client: { fetchContacts: async () => OVERVIEW } }
	await page.methods.load.call(vm)
	assert.equal(vm.loading, false)
	assert.equal(page.computed.waiting.call(vm).length, 3)
	assert.equal(page.computed.shown.call({ ...vm, role: 'contact' }).length, 1)
	assert.equal(page.methods.count.call(vm, 'begeleider'), 1)
	const refused = { overview: vm.overview, loading: true, problem: '', client: { fetchContacts: async () => null } }
	await page.methods.load.call(refused)
	assert.equal(refused.problem, 'That did not work. Try again later.')
})

test('an action asks the api with the row and reads the list again', async () => {
	const page = await loadSfc(PAGE)
	const calls = []
	const vm = {
		notice: 'x',
		problem: '',
		client: { contactAction: async (action, args) => { calls.push([action, args]); return { ok: action !== 'remove' } } },
		load: async () => { calls.push(['load']) },
	}
	await page.methods.act.call(vm, 'respond', { id: 'r1' }, { accept: true })
	assert.deepEqual(calls, [['respond', { id: 'r1', accept: true }], ['load']])
	assert.equal(vm.notice, '')
	await page.methods.act.call(vm, 'remove', { id: 'r4' })
	assert.equal(vm.problem, 'That did not work. Try again later.')
})

test('the dialog sends the trimmed address and message, and keeps the dialog open on a refusal', async () => {
	const modal = await loadSfc(MODAL)
	const sent = []
	const emitted = []
	const make = (answer) => ({
		email: ' a@b.nl ',
		message: ' hello ',
		problem: '',
		busy: false,
		api: { request: async (method, path, body) => { sent.push([method, path, body]); return { ok: answer.ok, status: answer.status, json: { error: answer.error } } } },
		$emit: (name) => emitted.push(name),
	})
	const refused = make({ ok: false, status: 409, error: 'duplicate' })
	await modal.methods.submit.call(refused)
	assert.deepEqual(sent[0], ['POST', '/contacts/invite', { email: 'a@b.nl', message: 'hello' }])
	assert.match(refused.problem, /already invited/)
	assert.deepEqual(emitted, [])
	await modal.methods.submit.call(make({ ok: true, status: 200, error: '' }))
	assert.deepEqual(emitted, ['sent'])
	const html = await renderSfc(MODAL, { api: {}, t })
	assert.match(html, /You become contacts once they accept/)
})

test('"Send message" opens a dialog that starts the conversation with that contact, or keeps the text on a refusal (T07)', async () => {
	const source = readFileSync(new URL(`../${PAGE}`, import.meta.url), 'utf8')
	const row = source.slice(source.indexOf('v-for="row in shown"'), source.indexOf('data-testid="contact-remove"'))
	assert.match(row, /data-testid="contact-message-open"[\s\S]*@click="openMessage\(row\)"/, 'each approved contact has the button')
	assert.doesNotMatch(source.slice(0, source.indexOf('v-for="row in shown"')), /contact-message-open/, 'a pending row has none')

	const page = await loadSfc(PAGE)
	const state = { notice: 'x', problem: 'y', writingTo: null }
	page.methods.openMessage.call(state, OVERVIEW.contacts[1])
	assert.deepEqual([state.writingTo, state.notice, state.problem], [OVERVIEW.contacts[1], '', ''])
	page.methods.messageSent.call(state)
	assert.equal(state.writingTo, null)
	assert.match(state.notice, /under Messages/)

	const modal = await loadSfc(CONTACT_MODAL)
	const calls = []
	const emitted = []
	const make = (answer, body = ' Kijk je mee? ') => ({
		body,
		problem: '',
		busy: false,
		contact: { id: 'r5', displayName: 'Dirk' },
		api: { messaging: async (method, path, payload) => { calls.push([method, path, payload]); return answer } },
		$emit: (...args) => emitted.push(args),
	})
	const empty = make({ ok: true, data: { id: 't1' } }, '   ')
	await modal.methods.submit.call(empty)
	assert.equal(empty.problem, 'Write a message first.')
	assert.deepEqual(calls, [], 'nothing is sent without a message')

	const refused = make({ ok: false, status: 403, data: { error: 'forbidden' } })
	await modal.methods.submit.call(refused)
	assert.deepEqual(calls[0], ['POST', '/threads', { contactId: 'r5', body: 'Kijk je mee?' }])
	assert.equal(refused.problem, 'Your message could not be sent. Try again.')
	assert.equal(refused.body, ' Kijk je mee? ', 'the text stays')
	assert.deepEqual(emitted, [])

	await modal.methods.submit.call(make({ ok: true, status: 200, data: { id: 't9' } }))
	assert.deepEqual(emitted, [['sent', 't9']])
	assert.equal(await contactsApi({}).sendMessage('r5', 'x'), null, 'no messaging door, no message')

	const html = await renderSfc(CONTACT_MODAL, { api: {}, t, contact: { id: 'r5', displayName: 'Dirk' } })
	assert.match(html, /Message to Dirk/)
	assert.match(html, /<label for="pq-contact-message-body"/)
})

test('the api reaches the contact routes with the bearer and the right verbs', async () => {
	const asked = []
	globalThis.fetch = async (url, init = {}) => {
		asked.push({ url, method: init.method || 'GET', body: init.body, auth: init.headers?.Authorization })
		return { ok: true, status: 200, json: async () => ({ incoming: [], sent: true }) }
	}
	try {
		const api = createPortalApi({ apiBase: '/portal/api' }, { getToken: () => 'tok', setToken: () => {} })
		const contacts = contactsApi(api)
		await contacts.fetchContacts()
		await contacts.contactAction('invite', { email: 'a@b.nl', message: 'hi' })
		await contacts.contactAction('respond', { id: 'a b', accept: true })
		await contacts.contactAction('resend', { id: '1' })
		await contacts.contactAction('withdraw', { id: '1' })
		await contacts.contactAction('remove', { id: '1' })
		await contacts.contactAction('accept', { token: 'tt' })
		assert.deepEqual(await contacts.contactAction('nope'), { ok: false, status: 0, error: 'unknown' })
	} finally {
		delete globalThis.fetch
	}
	assert.deepEqual(
		asked.map((a) => `${a.method} ${a.url}`),
		[
			'GET /portal/api/contacts',
			'POST /portal/api/contacts/invite',
			'POST /portal/api/contacts/a%20b/respond',
			'POST /portal/api/contacts/1/resend',
			'POST /portal/api/contacts/1/withdraw',
			'DELETE /portal/api/contacts/1',
			'POST /portal/api/contacts/accept-invitation',
		],
	)
	assert.equal(JSON.parse(asked[1].body).email, 'a@b.nl')
	assert.equal(JSON.parse(asked[2].body).accept, true)
	assert.equal(asked[5].body, undefined)
	assert.ok(asked.every((a) => a.auth === 'Bearer tok' || a.auth === undefined))
	assert.equal(asked[1].auth, 'Bearer tok')
})

test('the menu entry is there only when the portal switched contacts on', () => {
	const session = { subjectRef: 'a' }
	const off = shellSections({ session, contributions: { contributions: [], areaPages: [] }, threads: [], news: [] })
	assert.deepEqual(off.pages, [])
	assert.equal(buildNav([], t, off).some((e) => e.special === 'contacts'), false)
	const areaPages = [{ special: 'contacts', label: 'My contacts', icon: 'AccountMultiple' }]
	const on = shellSections({ session, contributions: { contributions: [], areaPages }, threads: [], news: [] })
	const entry = buildNav([], t, on).find((e) => e.special === 'contacts')
	assert.equal(entry.key, '__contacts__')
	assert.equal(entry.label, 'My contacts')
	assert.deepEqual(shellSections({ session: null, contributions: { areaPages } }).pages, [], 'signed out: none')
})

test('an invitation link is kept across sign-in, handed back once, and forgotten when answered', async () => {
	const store = new Map()
	const storage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v), removeItem: (k) => store.delete(k) }
	const replaced = []
	const location = { hash: '#contact-invitation=abc%20123', pathname: '/mijn', search: '?x=1' }
	assert.equal(keepContactInvitation(location, { replaceState: (...a) => replaced.push(a) }, storage), 'abc 123')
	assert.deepEqual(replaced[0], [null, '', '/mijn?x=1'])
	assert.equal(store.get(CONTACT_INVITATION_KEY), 'abc 123')
	assert.equal(keepContactInvitation({ hash: '#claim=zzz' }, null, storage), '')

	assert.equal(await redeemKeptContactInvitation({ api: {}, session: { subjectRef: 'a' }, t, storage: { getItem: () => null } }), null)
	const signedOut = await redeemKeptContactInvitation({ api: {}, session: null, t, storage })
	assert.equal(signedOut.claimed, false)
	assert.equal(store.has(CONTACT_INVITATION_KEY), true, 'kept until signed in')

	const calls = []
	const api = { request: async (method, path, body) => { calls.push([method, path, body]); return { ok: false, status: 0, json: {} } } }
	const unreachable = await redeemKeptContactInvitation({ api, session: { subjectRef: 'a' }, t, storage })
	assert.equal(store.has(CONTACT_INVITATION_KEY), true, 'a server that could not be reached keeps it')
	assert.match(unreachable.text, /Try again later/, 'an unreachable server is not an invalid invitation')
	assert.doesNotMatch(unreachable.text, /no longer valid/)

	api.request = async (method, path, body) => { calls.push([method, path, body]); return { ok: false, status: 503, json: {} } }
	const failing = await redeemKeptContactInvitation({ api, session: { subjectRef: 'a' }, t, storage })
	assert.equal(store.has(CONTACT_INVITATION_KEY), true, 'a failing server keeps it')
	assert.match(failing.text, /Try again later/, 'a 5xx is not an invalid invitation')

	api.request = async (method, path, body) => { calls.push([method, path, body]); return { ok: false, status: 410, json: {} } }
	const gone = await redeemKeptContactInvitation({ api, session: { subjectRef: 'a' }, t, storage })
	assert.match(gone.text, /no longer valid/, 'a 4xx answer is about the invitation itself')
	assert.equal(store.has(CONTACT_INVITATION_KEY), false)
	store.set(CONTACT_INVITATION_KEY, 'abc 123')

	api.request = async (method, path, body) => { calls.push([method, path, body]); return { ok: true, status: 200, json: {} } }
	const done = await redeemKeptContactInvitation({ api, session: { subjectRef: 'a' }, t, storage })
	assert.equal(done.claimed, true)
	assert.deepEqual(calls.at(-1), ['POST', '/contacts/accept-invitation', { token: 'abc 123' }])
	assert.equal(store.has(CONTACT_INVITATION_KEY), false)
})
