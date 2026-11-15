#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mail-template-editing.spec.mjs: the Mail templates page edits a portal's
// mail text in CnMarkdownEditor with the kind's variables, refuses a text with
// an unknown variable, previews, sends a test mail and puts the default back
// (mail-templates-admin-screen).
//
// Usage:
//   node --test tests/mail-template-editing.spec.mjs
//
// @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	createEditorActions,
	createMailTemplateApi,
	createMailTemplateHandlers,
	formFromRow,
	missingFields,
	unknownMessage,
	variablesOf,
} from '../src/lib/mailTemplateEditing.js'

const translate = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? vars[name] : match))
const generateUrl = (path, params = {}) => path.replace(/\{(\w+)\}/g, (match, name) => params[name] ?? match)
const STATUS = { key: 'status', label: 'Status', variables: ['portal', 'link'] }
const ROW = { id: 'r1', templateKey: 'status', portal: 'zuiddrecht', subject: 'Nieuws over {portal}', body: 'Bekijk {link}', active: false }

/**
 * A recording HTTP double: every call is kept, the answers are given per path.
 *
 * @param {object} answers The response data per path.
 * @return {{calls: Array, http: object}}
 */
function recorder(answers = {}) {
	const calls = []
	const answer = (verb) => async (url, body) => {
		calls.push({ verb, url, body })
		if (answers[url] instanceof Error) {
			throw answers[url]
		}
		return { data: answers[url] }
	}
	return { calls, http: { get: answer('get'), post: answer('post'), put: answer('put'), del: answer('delete'), generateUrl } }
}

test('the editor gets the kind\'s variables and a row\'s fields', () => {
	assert.deepEqual(variablesOf(STATUS), [{ name: 'portal', label: '{portal}' }, { name: 'link', label: '{link}' }])
	assert.deepEqual(variablesOf(undefined), [])
	assert.deepEqual(formFromRow(ROW), { id: 'r1', templateKey: 'status', portal: 'zuiddrecht', subject: 'Nieuws over {portal}', body: 'Bekijk {link}', active: false })
	assert.equal(formFromRow({ uuid: 'u1' }).id, 'u1')
	assert.deepEqual(formFromRow(null), { id: '', templateKey: '', portal: '', subject: '', body: '', active: true })
	assert.deepEqual(missingFields(formFromRow(null)), ['templateKey', 'portal', 'subject', 'body'])
	assert.deepEqual(missingFields({ ...ROW, subject: '  ' }), ['subject'])
})

test('a text with an unknown variable is refused with its name and not stored', async () => {
	const { calls, http } = recorder({
		'/apps/portaliq/api/mail-templates/preview': { ok: false, unknownVariables: ['besluit'] },
	})
	const actions = createEditorActions(createMailTemplateApi(http), translate)
	const outcome = await actions.submit({ ...formFromRow(ROW), body: 'Het besluit: {besluit}' })
	assert.deepEqual(outcome, { ok: false, message: 'Unknown variable: {besluit}. Not saved.' })
	assert.deepEqual(calls.map((call) => call.verb), ['post'], 'checked, never stored')
	assert.deepEqual(calls[0].body, { templateKey: 'status', subject: 'Nieuws over {portal}', body: 'Het besluit: {besluit}' })
	assert.equal(unknownMessage(['a', 'b'], translate), 'Unknown variable: {a}, {b}. Not saved.')
})

test('a known text is stored: a stored row is changed, a new one is created', async () => {
	const { calls, http } = recorder({ '/apps/portaliq/api/mail-templates/preview': { ok: true, unknownVariables: [] } })
	const actions = createEditorActions(createMailTemplateApi(http), translate)
	assert.deepEqual(await actions.submit(formFromRow(ROW)), { ok: true, message: 'The mail text is saved.' })
	assert.equal(calls[1].verb, 'put')
	assert.equal(calls[1].url, '/apps/openregister/api/objects/portaliq/portalMailTemplate/r1')
	assert.equal('id' in calls[1].body, false, 'the id stays in the address')
	await actions.submit({ ...formFromRow(ROW), id: '' })
	assert.deepEqual([calls[3].verb, calls[3].url], ['post', '/apps/openregister/api/objects/portaliq/portalMailTemplate'])
	const empty = await actions.submit(formFromRow(null))
	assert.equal(empty.ok, false)
	assert.equal(calls.length, 4, 'an empty form sends nothing')
})

test('preview and test mail answer in the editor, and a failure says so', async () => {
	const preview = { ok: true, unknownVariables: [], subject: 'Nieuws over Gemeente Voorbeeld', body: 'Bekijk https://portaal.example/mijn' }
	const { calls, http } = recorder({ '/apps/portaliq/api/mail-templates/preview': preview })
	const actions = createEditorActions(createMailTemplateApi(http), translate)
	assert.deepEqual(await actions.preview(formFromRow(ROW)), { ok: true, message: '', preview })
	assert.deepEqual(await actions.sendTest(formFromRow(ROW)), { ok: true, message: 'The test mail went to your own address.' })
	assert.equal(calls[1].url, '/apps/portaliq/api/mail-templates/test')
	const broken = createEditorActions(createMailTemplateApi(recorder({ '/apps/portaliq/api/mail-templates/test': new Error('500') }).http), translate)
	assert.deepEqual(await broken.sendTest(formFromRow(ROW)), { ok: false, message: 'The change could not be saved. Try again.' })
})

/**
 * The page's handlers over a recorder, with what they opened and said.
 *
 * @param {object} answers The response data per path.
 * @param {object} [overrides] Replaced dependencies.
 * @return {object} handlers, calls, said, opened, reloaded.
 */
function page(answers, overrides = {}) {
	const { calls, http } = recorder(answers)
	const seen = { said: [], errors: [], opened: [], reloaded: 0 }
	const handlers = createMailTemplateHandlers({
		api: createMailTemplateApi(http),
		openEditor: async (props) => { seen.opened.push(props); return 'The mail text is saved.' },
		confirmReset: async () => true,
		notify: (message) => seen.said.push(message),
		notifyError: (message) => seen.errors.push(message),
		translate,
		reload: () => { seen.reloaded++ },
		...overrides,
	})
	return { handlers, calls, seen }
}

test('New mail text opens the editor with the kinds and portals, Change with the row', async () => {
	const { handlers, seen } = page({
		'/apps/portaliq/api/mail-templates': { kinds: [STATUS] },
		'/apps/openregister/api/objects/portaliq/portal?_limit=200': { results: [{ slug: 'zuiddrecht', title: 'Zuiddrecht' }] },
	})
	assert.equal(await handlers.newMailTemplate(), true)
	assert.deepEqual(seen.opened[0].kinds, [STATUS])
	assert.deepEqual(seen.opened[0].portals, [{ slug: 'zuiddrecht', title: 'Zuiddrecht' }])
	assert.equal(typeof seen.opened[0].actions.submit, 'function')
	await handlers.editMailTemplate({ item: ROW })
	assert.equal(seen.opened[1].form.id, 'r1')
	assert.deepEqual(seen.opened[1].portals, [], 'a stored text keeps its portal')
	assert.deepEqual([seen.said.length, seen.reloaded], [2, 2])

	const cancelled = page({ '/apps/portaliq/api/mail-templates': { kinds: [] } }, { openEditor: async () => null })
	assert.equal(await cancelled.handlers.editMailTemplate({ item: ROW }), false)
	assert.equal(cancelled.seen.reloaded, 0, 'a cancelled editor changes nothing')
	const offline = page({ '/apps/portaliq/api/mail-templates': new Error('offline') })
	assert.equal(await offline.handlers.newMailTemplate(), false)
	assert.deepEqual(offline.seen.errors, ['The kinds of mail could not be loaded. Try again.'])
	assert.equal(offline.seen.opened.length, 0)
})

test('Back to the default text removes the stored row only after the admin confirms', async () => {
	const { handlers, calls, seen } = page({})
	assert.equal(await handlers.resetMailTemplate({ item: ROW }), true)
	assert.deepEqual(calls.map((call) => [call.verb, call.url]), [['delete', '/apps/openregister/api/objects/portaliq/portalMailTemplate/r1']])
	assert.deepEqual(seen.said, ['The default text is sent again.'])

	const declined = page({}, { confirmReset: async () => false })
	assert.equal(await declined.handlers.resetMailTemplate({ item: ROW }), false)
	assert.equal(declined.calls.length, 0)
	const failing = page({ '/apps/openregister/api/objects/portaliq/portalMailTemplate/r1': new Error('403') })
	assert.equal(await failing.handlers.resetMailTemplate({ item: ROW }), false)
	assert.deepEqual(failing.seen.errors, ['The default text could not be put back. Try again.'])
	assert.equal(await page({}).handlers.resetMailTemplate({ item: {} }), false, 'a row without an id is left alone')
})

test('the dialog writes the text in CnMarkdownEditor with the kind\'s variables, and the page offers the editor', () => {
	const dialog = readFileSync('src/dialogs/MailTemplateDialog.vue', 'utf8')
	assert.match(dialog, /<CnMarkdownEditor[^>]*:variables="variables"[^>]*:unknownVariableText=/s)
	assert.match(dialog, /@unknownVariables="unknown = \$event"/)
	assert.match(dialog, /:disabled="busy \|\| unknown\.length > 0"/, 'Save waits until every variable is known')
	const manifest = JSON.parse(readFileSync('src/manifest.json', 'utf8'))
	const config = manifest.pages.find((p) => p.id === 'MailTemplates').config
	assert.deepEqual(config.actions.map((a) => a.handler), ['editMailTemplate', 'resetMailTemplate'])
	assert.equal(config.headerActions[0].handler, 'newMailTemplate')
	const registry = readFileSync('src/customComponents.js', 'utf8')
	assert.match(registry, /\.\.\.mailTemplateHandlers,/)
})
