#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// news-authoring-steps.spec.mjs: the staff News screen's form, its checks,
// who an item is for, its calls and its handlers, pinned before they were
// split into named steps.
//
// Usage:
//   node --test tests/news-authoring-steps.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	audienceLine,
	audienceOf,
	createNewsApi,
	createNewsHandlers,
	emptyForm,
	failureKey,
	formFromItem,
	idOf,
	missingFields,
	targetFromForm,
} from '../src/lib/newsAuthoring.js'

test('failureKey names each refusal', () => {
	assert.equal(failureKey({ response: { status: 401 } }), 'You may not write news. Ask an administrator for this right.')
	assert.equal(failureKey({ response: { status: 403 } }), 'You may not write news. Ask an administrator for this right.')
	assert.equal(failureKey({ response: { status: 404 } }), 'This news item no longer exists.')
	assert.equal(failureKey({ response: { status: 400, data: { error: 'invalid_target' } } }), 'Give a title, a text and who the news is for.')
	assert.equal(failureKey({ response: { status: 500 } }), 'The news item could not be saved. Try again.')
	assert.equal(failureKey(null), 'The news item could not be saved. Try again.')
})

test('idOf reads the id wherever the register put it', () => {
	assert.equal(idOf({ id: 'a', uuid: 'b' }), 'a')
	assert.equal(idOf({ uuid: 'b' }), 'b')
	assert.equal(idOf({ '@self': { id: 'c' } }), 'c')
	assert.equal(idOf(null), '')
})

test('formFromItem picks the audience from the target', () => {
	assert.deepEqual(formFromItem(null), { ...emptyForm(), audience: 'school' })
	assert.deepEqual(formFromItem({ title: ' T ', body: ' B ', target: { schoolRef: ' s1 ', groupRefs: ['g1', '', 3], childRefs: ['c1'] } }), {
		title: 'T',
		body: ' B ',
		audience: 'groups',
		schoolRef: 's1',
		groupRefs: ['g1'],
		childRefs: ['c1'],
		eventRef: '',
	})
	assert.equal(formFromItem({ eventRef: ' ev-1 ' }).eventRef, 'ev-1')
	assert.equal(formFromItem({ target: { childRefs: ['c1'] } }).audience, 'children')
	assert.equal(formFromItem({ target: { schoolRef: 's', childRefs: ['c1'] } }).audience, 'school')
	assert.equal(formFromItem({ target: 'x', body: 5 }).body, '')
})

test('targetFromForm and missingFields', () => {
	assert.deepEqual(targetFromForm({ audience: 'groups', groupRefs: ['g', ''] }), { groupRefs: ['g'] })
	assert.deepEqual(targetFromForm({ audience: 'children', childRefs: ['c'] }), { childRefs: ['c'] })
	assert.deepEqual(targetFromForm({ audience: 'school', schoolRef: ' s ' }), { schoolRef: 's' })
	assert.deepEqual(missingFields({ title: '', body: ' ', audience: 'school', schoolRef: '' }), [
		'Give the news item a title.',
		'Write the text of the news item.',
		'Choose the school.',
	])
	assert.deepEqual(missingFields({ title: 'T', body: 'B', audience: 'groups', groupRefs: [] }), ['Choose at least one group.'])
	assert.deepEqual(missingFields({ title: 'T', body: 'B', audience: 'children', childRefs: [] }), ['Choose the school.'])
	assert.deepEqual(missingFields({ title: 'T', body: 'B', audience: 'groups', groupRefs: ['g'] }), [])
	assert.deepEqual(missingFields({ title: 'T', body: 'B', audience: 'school', schoolRef: 's' }), [])
})

test('audienceOf and audienceLine name who an item is for', () => {
	const options = { schools: [{ id: 's1', label: 'De Linde' }], groups: [{ id: 'g1', label: 'Groep 3' }] }
	const say = (text, vars) => (vars ? `${text}|${JSON.stringify(vars)}` : text)
	assert.deepEqual(audienceOf({ target: { groupRefs: ['g1', 'g2'] } }, options), { kind: 'groups', names: ['Groep 3', 'g2'] })
	assert.deepEqual(audienceOf({ target: { childRefs: ['c'] } }, options), { kind: 'children', names: [] })
	assert.deepEqual(audienceOf({ target: { schoolRef: 's1' } }, options), { kind: 'school', names: ['De Linde'] })
	assert.deepEqual(audienceOf({ target: { schoolRef: 's9' } }), { kind: 'school', names: ['s9'] })
	assert.deepEqual(audienceOf({ target: {} }), { kind: 'school', names: [] })
	assert.deepEqual(audienceOf({ target: { groupRefs: ['g1'] } }, { schools: [] }), { kind: 'groups', names: ['g1'] })
	assert.equal(audienceLine({ target: { childRefs: ['c'] } }, options, say), 'Specific children')
	assert.equal(audienceLine({ target: { groupRefs: ['g1', 'g2'] } }, options, say), 'Groups: {names}|{"names":"Groep 3, g2"}')
	assert.equal(audienceLine({ target: { schoolRef: 's1' } }, options, say), 'Whole school: {name}|{"name":"De Linde"}')
	assert.equal(audienceLine({ target: {} }, options, say), 'Whole school')
})

/**
 * The News calls over a transport that records each call.
 *
 * @param {object} answer What every call answers, or `{fail}` to reject.
 * @return {{api: object, calls: Array<object>}} The calls and the api.
 */
function newsApi(answer = { data: { ok: 1 } }) {
	const calls = []
	const reply = (verb) => async (url, body) => {
		calls.push({ verb, url, body })
		if (answer.fail) {
			throw answer.fail
		}
		return answer
	}
	const api = createNewsApi({ get: reply('get'), post: reply('post'), put: reply('put'), generateUrl: (path) => `/nc${path}` })
	return { api, calls }
}

test('the News calls: audiences, a new draft, a change and publishing', async () => {
	const lists = newsApi({ data: { schools: [{ id: 's' }], groups: 'x' } })
	assert.deepEqual(await lists.api.audiences(), { schools: [{ id: 's' }], groups: [] })
	assert.equal(lists.calls[0].url, '/nc/apps/portaliq/api/news/audiences')
	assert.deepEqual(await newsApi({ data: null }).api.audiences(), { schools: [], groups: [] })

	const { api, calls } = newsApi()
	const form = { title: ' T ', body: ' B ', audience: 'groups', groupRefs: ['g'] }
	assert.deepEqual(await api.save(form, '', 'staff-1'), { ok: 1 })
	assert.deepEqual(calls[0], { verb: 'post', url: '/nc/apps/portaliq/api/news', body: { title: 'T', body: 'B', target: { groupRefs: ['g'] }, eventRef: '', authorRef: 'staff-1' } })
	await api.save(form, 'a/b', 'staff-1')
	assert.deepEqual(calls[1], { verb: 'put', url: '/nc/apps/portaliq/api/news/a%2Fb', body: { title: 'T', body: 'B', target: { groupRefs: ['g'] }, eventRef: '' } })
	await api.setPublished('n1', true)
	await api.setPublished('n1', false)
	await api.setPublished(undefined, true)
	assert.deepEqual(calls.slice(2).map((call) => [call.verb, call.url, call.body]), [
		['put', '/nc/apps/portaliq/api/news/n1/publish', undefined],
		['put', '/nc/apps/portaliq/api/news/n1/unpublish', undefined],
		['put', '/nc/apps/portaliq/api/news//publish', undefined],
	])
})

/**
 * Handlers over fake collaborators.
 *
 * @param {object} setup The api and the dialog's answer.
 * @return {object} The handlers and what they did.
 */
function handlers(setup) {
	const done = { notified: [], errors: [], reloads: 0, dialogs: [], saves: [] }
	const api = {
		audiences: setup.audiences || (async () => ({ schools: [{ id: 's' }], groups: [] })),
		events: setup.events,
		save: async (form, id, author) => {
			done.saves.push([form, id, author])
			if (setup.saveFails) {
				throw setup.saveFails
			}
		},
		setPublished: async (id, published) => {
			done.saves.push(['publish', id, published])
			if (setup.publishFails) {
				throw setup.publishFails
			}
		},
	}
	const made = createNewsHandlers({
		api,
		openDialog: async (request) => {
			done.dialogs.push(request)
			const answer = request.submit ? await request.submit({ title: 'T' }) : null
			done.submitted = answer
			return setup.dialogAnswer === undefined ? answer.message : setup.dialogAnswer
		},
		currentUser: () => 'staff-1',
		translate: (text) => `t:${text}`,
		notify: (message) => done.notified.push(message),
		notifyError: (message) => done.errors.push(message),
		reload: () => { done.reloads += 1 },
	})
	return { made, done }
}

test('a new item and a change are saved through the dialog', async () => {
	const fresh = handlers({})
	assert.equal(await fresh.made.newNewsItem(), true)
	assert.equal(fresh.done.dialogs[0].item, null)
	assert.deepEqual(fresh.done.dialogs[0].options, { schools: [{ id: 's' }], groups: [] })
	assert.deepEqual(fresh.done.saves[0], [{ title: 'T' }, '', 'staff-1'])
	assert.deepEqual(fresh.done.submitted, { ok: true, message: 't:The news item is saved as a draft. Publish it when it is ready.' })
	assert.deepEqual(fresh.done.notified, ['t:The news item is saved as a draft. Publish it when it is ready.'])
	assert.equal(fresh.done.reloads, 1)

	const change = handlers({ audiences: async () => { throw new Error('down') } })
	assert.equal(await change.made.changeNewsItem({ item: { id: 'n1' } }), true)
	assert.deepEqual(change.done.dialogs[0].options, { schools: [], groups: [] })
	assert.deepEqual(change.done.saves[0], [{ title: 'T' }, 'n1', 'staff-1'])
	assert.equal(change.done.submitted.message, 't:The news item is changed.')
})

test('the event choices: latest first from the server, labelled with their day (event-sign-up-by-a-pupil-with-seats task 4)', async () => {
	const lists = newsApi({ data: { events: [
		{ id: 'e2', title: 'Kerstviering', start: '2026-12-18T18:00:00+00:00' },
		{ id: '', title: 'No id' },
		{ id: 'e1', title: '', start: '' },
	] } })
	assert.deepEqual(await lists.api.events(), [
		{ id: 'e2', label: 'Kerstviering (2026-12-18)' },
		{ id: 'e1', label: 'e1' },
	])
	assert.equal(lists.calls[0].url, '/nc/apps/portaliq/api/events')
	assert.deepEqual(await newsApi({ data: { events: 'x' } }).api.events(), [])
})

test('the dialog is offered the events, or none when they cannot be read', async () => {
	const offered = handlers({ events: async () => [{ id: 'e1', label: 'Avond (2026-11-03)' }] })
	await offered.made.newNewsItem()
	assert.deepEqual(offered.done.dialogs[0].events, [{ id: 'e1', label: 'Avond (2026-11-03)' }])

	const down = handlers({ events: async () => { throw new Error('down') } })
	await down.made.newNewsItem()
	assert.deepEqual(down.done.dialogs[0].events, [])
})

test('a refused save stays in the dialog, a closed dialog changes nothing', async () => {
	const refused = handlers({ saveFails: { response: { status: 403 } }, dialogAnswer: null })
	assert.equal(await refused.made.newNewsItem(), false)
	assert.deepEqual(refused.done.submitted, { ok: false, message: 't:You may not write news. Ask an administrator for this right.' })
	assert.deepEqual(refused.done.notified, [])
	assert.equal(refused.done.reloads, 0)
	const empty = handlers({ dialogAnswer: '' })
	assert.equal(await empty.made.newNewsItem(), false)
})

test('publishing and taking back, and a refusal', async () => {
	const ok = handlers({})
	assert.equal(await ok.made.publishNewsItem({ item: { id: 'n1' } }), true)
	assert.equal(await ok.made.unpublishNewsItem({ item: { uuid: 'n2' } }), true)
	assert.deepEqual(ok.done.saves, [['publish', 'n1', true], ['publish', 'n2', false]])
	assert.deepEqual(ok.done.notified, ['t:The news item is published.', 't:The news item is back to a draft. Parents no longer see it.'])
	assert.equal(ok.done.reloads, 2)
	const refused = handlers({ publishFails: { response: { status: 404 } } })
	assert.equal(await refused.made.publishNewsItem({ item: { id: 'n1' } }), false)
	assert.deepEqual(refused.done.errors, ['t:This news item no longer exists.'])
	assert.equal(refused.done.reloads, 0)
})
