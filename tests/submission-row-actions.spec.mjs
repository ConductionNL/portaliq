#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// submission-row-actions.spec.mjs: the Submissions list offers Edit, Link a
// form, Conversation in Talk (while Talk is installed) and Register again on a
// row, and never Copy or Delete, as a row action or on a selection. Register
// again shows on a failed or pending row only and calls the staff route that
// the route table declares; Talk and the form link are app handlers.
//
// Usage:
//   node --test tests/submission-row-actions.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

// nextcloud-vue's visibleWhen module reads window at import; a stand-in is enough here.
globalThis.window = { localStorage: { getItem: () => null, setItem: () => {} }, sessionStorage: { getItem: () => null, setItem: () => {} }, OC: {} }
const { evaluateVisibleWhenLocal, isLocallyDecidableVisibleWhen } = await import(new URL('../node_modules/@conduction/nextcloud-vue/src/utils/visibleWhen.js', import.meta.url))
const { __resetAppInstalledCacheForTests: resetAppInstalledCache } = await import(new URL('../node_modules/@conduction/nextcloud-vue/src/utils/appInstalled.js', import.meta.url))
const { conversationName, createSubmissionHandlers, formOptions, roomAddress } = await import(new URL('../src/lib/submissionActions.js', import.meta.url))

const manifest = JSON.parse(readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'))
const routes = readFileSync(new URL('../appinfo/routes.php', import.meta.url), 'utf8')
const en = JSON.parse(readFileSync(new URL('../l10n/en.json', import.meta.url), 'utf8')).translations
const nl = JSON.parse(readFileSync(new URL('../l10n/nl.json', import.meta.url), 'utf8')).translations

/**
 * The manifest page with this id.
 *
 * @param {string} id The page id.
 * @return {object}
 */
function page(id) {
	const found = manifest.pages.find((candidate) => candidate.id === id)
	assert.ok(found, `${id} is in the manifest`)
	return found
}

const config = page('PortalSubmissions').config
const registerAgain = config.actions.find((action) => typeof action === 'object' && action.id === 'register-again')
const linkForm = config.actions.find((action) => typeof action === 'object' && action.id === 'link-form')
const talk = config.actions.find((action) => typeof action === 'object' && action.id === 'conversation-in-talk')

test('a submission cannot be copied or deleted from the list', () => {
	assert.equal(config.showCopyAction, false)
	assert.equal(config.showDeleteAction, false)
	assert.equal(config.showMassCopy, false)
	assert.equal(config.showMassDelete, false)
	assert.ok(!config.actions.includes('builtin:copy'))
	assert.ok(!config.actions.includes('builtin:delete'))
})

test('the row menu reads Edit, Link a form, Conversation in Talk, Register again', () => {
	assert.deepEqual(
		config.actions.map((action) => (typeof action === 'string' ? action : action.id)),
		['builtin:edit', 'link-form', 'conversation-in-talk', 'register-again'],
	)
	assert.equal(linkForm.type, 'handler')
	assert.equal(linkForm.handler, 'linkSubmissionForm')
	assert.equal(linkForm.visibleWhen, undefined, 'every row offers Link a form')
	assert.equal(talk.type, 'handler')
	assert.equal(talk.handler, 'startSubmissionConversation')
})

test('Conversation in Talk shows only while Talk is installed', () => {
	assert.equal(isLocallyDecidableVisibleWhen(talk.visibleWhen), true)
	globalThis.OC = { appswebroots: { spreed: '/apps/spreed' } }
	resetAppInstalledCache()
	assert.equal(evaluateVisibleWhenLocal(talk.visibleWhen, { deliveryStatus: 'delivered' }), true)
	globalThis.OC = { appswebroots: {} }
	resetAppInstalledCache()
	assert.equal(evaluateVisibleWhenLocal(talk.visibleWhen, { deliveryStatus: 'delivered' }), false)
	delete globalThis.OC
	resetAppInstalledCache()
})

test('Register again posts to the staff route for the row', () => {
	assert.equal(registerAgain.type, 'api-call')
	assert.equal(registerAgain.method, 'POST')
	assert.equal(registerAgain.url, '/apps/portaliq/api/submissions/@objectId/register-again')
	assert.match(routes, /'submissionAdmin#registerAgain', 'url' => '\/api\/submissions\/\{id\}\/register-again', 'verb' => 'POST'/)
})

// The rule CnRowActions applies: a locally decidable visibleWhen that is false hides the entry.
test('Register again shows on a failed or pending row, never on a delivered one', () => {
	assert.equal(isLocallyDecidableVisibleWhen(registerAgain.visibleWhen), true)
	assert.equal(evaluateVisibleWhenLocal(registerAgain.visibleWhen, { deliveryStatus: 'failed' }), true)
	assert.equal(evaluateVisibleWhenLocal(registerAgain.visibleWhen, { deliveryStatus: 'pending' }), true)
	assert.equal(evaluateVisibleWhenLocal(registerAgain.visibleWhen, { deliveryStatus: 'delivered' }), false)
})

test('every new string is in English and Dutch', () => {
	for (const key of [
		registerAgain.label, registerAgain.successMessage, registerAgain.errorMessage, linkForm.label, talk.label,
		'Choose the request form this submission came through', 'Request form', 'Choose a request form.',
		'The conversation could not be started.', 'The request forms could not be loaded.',
		'This portal has no request forms to link.', 'The form could not be linked.', 'The form is linked.', 'Submission',
	]) {
		assert.equal(en[key], key, `${key} in en`)
		assert.ok(typeof nl[key] === 'string' && nl[key] !== '' && nl[key] !== key, `${key} in nl`)
	}
	assert.equal(nl['Register again'], 'Opnieuw registreren')
})

/**
 * Handlers over recorded doubles.
 *
 * @param {object} answers What each GET address answers, by address prefix.
 * @param {object} [overrides] Replaced collaborators.
 * @return {{handlers: object, calls: Array<Array>}}
 */
function handlersOver(answers, overrides = {}) {
	const calls = []
	const answer = (url) => Object.entries(answers).find(([prefix]) => url.startsWith(prefix))?.[1]
	const handlers = createSubmissionHandlers({
		get: async (url) => {
			calls.push(['get', url])
			const found = answer(url)
			return { data: typeof found === 'function' ? found() : found }
		},
		post: async (url, body) => {
			calls.push(['post', url, body])
			return { data: { roomToken: 'newroom' } }
		},
		patch: async (url, body) => {
			calls.push(['patch', url, body])
			return { data: {} }
		},
		generateUrl: (path) => `/index.php${path}`,
		open: (url) => calls.push(['open', url]),
		chooseForm: async (options, current) => {
			calls.push(['choose', options, current])
			return options[0]?.id ?? null
		},
		notify: (text) => calls.push(['notify', text]),
		notifyError: (text) => calls.push(['error', text]),
		translate: (text) => text,
		reload: () => calls.push(['reload']),
		...overrides,
	})
	return { handlers, calls }
}

const SUBMISSION = { id: 's1', organisation: 'org-1', actionTitle: 'Grof afval laten ophalen', receiptMessageRef: 'WMEBV-2026-0042' }
const TALK = '/index.php/apps/openregister/api/objects/portaliq/portalSubmission/s1/talk'

test('the room is named after the action and its receipt reference', () => {
	assert.equal(conversationName(SUBMISSION), 'Grof afval laten ophalen WMEBV-2026-0042')
	assert.equal(conversationName({ actionId: 'createExample' }), 'createExample')
	assert.equal(roomAddress({ roomToken: 'abc' }), '/index.php/call/abc')
	assert.equal(roomAddress({ url: '/index.php/call/xyz', roomToken: 'abc' }), '/index.php/call/xyz')
	assert.equal(roomAddress({ url: 'javascript:alert(1)' }), '')
	assert.equal(roomAddress({}), '')
})

test('a conversation starts from the row, linked to the submission, and opens', async () => {
	const { handlers, calls } = handlersOver({ [TALK]: { results: [] } })
	assert.equal(await handlers.startSubmissionConversation({ item: SUBMISSION }), true)
	assert.deepEqual(calls, [
		['get', TALK],
		['post', `${TALK}/new`, { name: 'Grof afval laten ophalen WMEBV-2026-0042', description: '', type: 2 }],
		['open', '/index.php/call/newroom'],
	])
})

test('the second time opens the linked room and makes no second one', async () => {
	const { handlers, calls } = handlersOver({ [TALK]: { results: [{ roomToken: 'first' }] } })
	assert.equal(await handlers.startSubmissionConversation({ item: SUBMISSION }), true)
	assert.deepEqual(calls, [['get', TALK], ['open', '/index.php/call/first']])
})

test('a Talk failure is said, and a row without an id asks nothing', async () => {
	const failing = handlersOver({}, { get: async () => { throw new Error('503') } })
	assert.equal(await failing.handlers.startSubmissionConversation({ item: SUBMISSION }), false)
	assert.deepEqual(failing.calls, [['error', 'The conversation could not be started.']])

	const empty = handlersOver({})
	assert.equal(await empty.handlers.startSubmissionConversation({ item: {} }), false)
	assert.deepEqual(empty.calls, [['error', 'The conversation could not be started.']])
})

test('only the forms of the submission\'s portals are offered, by name', () => {
	const options = formOptions(
		[{ slug: 'zuid' }, { slug: '' }],
		[
			{ id: 'f2', portal: 'zuid', formName: 'Grof afval aanvragen' },
			{ id: 'f1', portal: 'zuid', formName: 'Afvalpas aanvragen' },
			{ id: 'f3', portal: 'noord', formName: 'Elders' },
			{ portal: 'zuid', formName: 'Zonder id' },
		],
	)
	assert.deepEqual(options, [
		{ id: 'f1', label: 'Afvalpas aanvragen' },
		{ id: 'f2', label: 'Grof afval aanvragen' },
	])
})

test('linking a form writes formBinding only and shows the list again', async () => {
	const { handlers, calls } = handlersOver({
		'/index.php/apps/openregister/api/objects/portaliq/portal?': { results: [{ slug: 'zuid' }] },
		'/index.php/apps/openregister/api/objects/portaliq/portalFormBinding?': { results: [{ id: 'f2', portal: 'zuid', formName: 'Grof afval aanvragen' }] },
	})
	assert.equal(await handlers.linkSubmissionForm({ item: SUBMISSION }), true)
	assert.deepEqual(calls[0], ['get', '/index.php/apps/openregister/api/objects/portaliq/portal?organisation=org-1&_limit=100'])
	assert.deepEqual(calls.slice(2), [
		['choose', [{ id: 'f2', label: 'Grof afval aanvragen' }], ''],
		['patch', '/index.php/apps/openregister/api/objects/portaliq/portalSubmission/s1', { formBinding: 'f2' }],
		['notify', 'The form is linked.'],
		['reload'],
	])
})

test('no forms, a cancelled choice or a refused save writes nothing more', async () => {
	const none = handlersOver({ '/index.php/apps/openregister/api/objects/portaliq/portal': { results: [] } })
	assert.equal(await none.handlers.linkSubmissionForm({ item: SUBMISSION }), false)
	assert.deepEqual(none.calls.at(-1), ['error', 'This portal has no request forms to link.'])

	const noOrganisation = handlersOver({})
	assert.equal(await noOrganisation.handlers.linkSubmissionForm({ item: { id: 's1' } }), false)
	assert.deepEqual(noOrganisation.calls, [['error', 'This portal has no request forms to link.']])

	const answers = {
		'/index.php/apps/openregister/api/objects/portaliq/portal?': { results: [{ slug: 'zuid' }] },
		'/index.php/apps/openregister/api/objects/portaliq/portalFormBinding?': { results: [{ id: 'f2', portal: 'zuid' }] },
	}
	const cancelled = handlersOver(answers, { chooseForm: async () => null })
	assert.equal(await cancelled.handlers.linkSubmissionForm({ item: SUBMISSION }), false)
	assert.ok(!cancelled.calls.some(([kind]) => kind === 'patch'))

	const refused = handlersOver(answers, { patch: async () => { throw new Error('403') } })
	assert.equal(await refused.handlers.linkSubmissionForm({ item: SUBMISSION }), false)
	assert.deepEqual(refused.calls.at(-1), ['error', 'The form could not be linked.'])
})
