#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// accessibility-widget-steps.spec.mjs: what the accessibility widget says
// about the statement's status, how it words a refusal, and what it saves
// (the audit, the register entry, the added pages), pinned before those were
// split into named steps.
//
// Usage:
//   node --test tests/accessibility-widget-steps.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

globalThis.t = (app, text, vars) => (vars ? `t:${text}|${JSON.stringify(vars)}` : `t:${text}`)
const WIDGET = await loadSfc('src/widgets/PortalAccessibility.vue', {
	'@nextcloud/axios': 'export default { get: async () => ({ data: {} }), put: async () => ({ data: {} }), post: async () => ({ data: {} }) }',
	'@nextcloud/router': 'export const generateUrl = (path) => path',
	'@nextcloud/vue': 'export const NcButton = {}, NcCheckboxRadioSwitch = {}, NcLoadingIcon = {}, NcNoteCard = {}, NcTextArea = {}, NcTextField = {}',
})

/**
 * The widget's state with every method bound and an api that answers once.
 *
 * @param {object} answer What the save answers.
 * @return {object} The widget.
 */
function widget(answer) {
	const vm = { ...WIDGET.data(), slug: 'school', saves: [] }
	for (const [name, method] of Object.entries(WIDGET.methods)) {
		vm[name] = method.bind(vm)
	}
	vm.api = { save: async (slug, body) => { vm.saves.push([slug, body]); return answer } }
	return vm
}

test('the status line: none until measured, automated only, from the audit', () => {
	const line = (statement) => WIDGET.computed.statusLine.call({ statement })
	assert.equal(line({ status: null }), 't:The statement shows no status until the portal is measured.')
	assert.equal(line({ status: 'C', automatedOnly: true }), 't:The statement shows status {status}. Without an audit it cannot show A or B.|{"status":"C"}')
	assert.equal(line({ status: 'A' }), 't:The statement shows status {status}, from the audit.|{"status":"A"}')
})

test('every refusal in words', () => {
	const vm = widget({})
	assert.equal(vm.auditMessage('audit_incomplete'), 't:Status A or B needs the party, the date and an https link to the audit report.')
	assert.equal(vm.auditMessage('incomplete'), vm.auditMessage('audit_incomplete'))
	assert.equal(vm.auditMessage('audit_expired'), 't:This audit is older than three years. It no longer supports status A or B.')
	assert.equal(vm.auditMessage('register_url_invalid'), 't:The register entry must be an https address.')
	assert.equal(vm.auditMessage('audit_register_url_invalid'), vm.auditMessage('register_url_invalid'))
	assert.equal(vm.auditMessage('other'), 't:The accessibility settings could not be saved.')
	assert.equal(vm.auditMessage(undefined), 't:The accessibility settings could not be saved.')
	assert.equal(vm.auditMessage('constructor'), 't:The accessibility settings could not be saved.')
})

test('saving: an incomplete audit is not sent, a save applies the answer, a refusal says why', async () => {
	const incomplete = widget({ ok: true, data: {} })
	incomplete.audit = { party: '', date: '', reportUrl: '', result: 'A' }
	await incomplete.save()
	assert.deepEqual(incomplete.saves, [])
	assert.equal(incomplete.noticeType, 'error')
	assert.equal(incomplete.notice, incomplete.auditMessage('incomplete'))

	const saved = widget({ ok: true, data: { pages: ['/'], audit: { party: 'X' }, registerUrl: 'https://r', addedPages: ['/a', '/b'], statement: { status: 'C' } } })
	saved.audit = { party: '', date: '', reportUrl: '', result: '' }
	saved.registerUrl = 'https://register'
	saved.addedPages = ' /a \n\n/b\n '
	await saved.save()
	assert.deepEqual(saved.saves, [['school', { audit: { party: '', date: '', reportUrl: '', result: '' }, registerUrl: 'https://register', pages: ['/a', '/b'] }]])
	assert.equal(saved.saving, false)
	assert.equal(saved.noticeType, 'success')
	assert.equal(saved.notice, 't:The accessibility settings are saved.')
	assert.deepEqual(saved.audit, { party: 'X', date: '', reportUrl: '', result: '' })
	assert.equal(saved.addedPages, '/a\n/b')
	assert.deepEqual(saved.statement, { status: 'C' })

	const refused = widget({ ok: false, error: 'audit_register_url_invalid' })
	await refused.save()
	assert.equal(refused.noticeType, 'error')
	assert.equal(refused.notice, refused.auditMessage('register_url_invalid'))
	assert.equal(refused.saving, false)
})
