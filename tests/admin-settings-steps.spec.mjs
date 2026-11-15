#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// admin-settings-steps.spec.mjs: what the admin settings screen loads and
// saves for the editor groups (who may edit pages), the geography, the
// e-mail link switch and the internal address, and how it revokes an
// organisation's sessions: allowed, refused and failed, pinned before those
// methods were split into named steps.
//
// Usage:
//   node --test tests/admin-settings-steps.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

globalThis.t = (app, text) => `t:${text}`
globalThis.__adminHttp = { calls: [], answers: [] }

const STUBS = {
	'@nextcloud/axios': `
		const http = globalThis.__adminHttp
		const answer = (verb) => async (url, body) => {
			http.calls.push({ verb, url, body })
			const next = http.answers.length > 1 ? http.answers.shift() : http.answers[0]
			if (next && next.reject) {
				throw next.reject
			}
			return { data: next ? next.data : {} }
		}
		export default { get: answer('get'), put: answer('put'), post: answer('post') }
	`,
	'@nextcloud/router': 'export const generateUrl = (path) => `/nc${path}`',
	'@nextcloud/initial-state': 'export const loadState = (app, key, fallback) => fallback',
	'@conduction/nextcloud-vue': 'export const CnAdminSettingsShell = {}',
	'@nextcloud/vue': `
		export const NcButton = {}, NcCheckboxRadioSwitch = {}, NcNoteCard = {}, NcSelect = {},
			NcSettingsSection = {}, NcTextField = {}, NcPasswordField = {}, NcLoadingIcon = {}
	`,
}

const VIEW = await loadSfc('src/views/AdminRoot.vue', STUBS)

/**
 * The screen's state with its computed options, and the server's answers.
 *
 * @param {Array<object>} answers Each call's `{data}` or `{reject}`; the last repeats.
 * @return {object} The state.
 */
function screen(answers) {
	globalThis.__adminHttp.calls = []
	globalThis.__adminHttp.answers = answers
	const vm = { ...VIEW.data.call({ t: globalThis.t }) }
	vm.geoProviderOptions = VIEW.computed.geoProviderOptions.call(vm)
	vm.geoEditionOptions = VIEW.computed.geoEditionOptions.call(vm)
	for (const [name, method] of Object.entries(VIEW.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

const calls = () => globalThis.__adminHttp.calls

test('the editor groups load as options, a deleted group stays as its id, a failure says so', async () => {
	const vm = screen([{ data: { availableGroups: [{ id: 'redactie', label: 'Redactie' }], editor_groups: ['redactie', 'oud'] } }])
	await vm.loadEditorGroups()
	assert.deepEqual(calls()[0], { verb: 'get', url: '/nc/apps/portaliq/api/settings', body: undefined })
	assert.deepEqual(vm.groupOptions, [{ id: 'redactie', label: 'Redactie' }])
	assert.deepEqual(vm.editorGroups, [{ id: 'redactie', label: 'Redactie' }, { id: 'oud', label: 'oud' }])

	const empty = screen([{ data: {} }])
	await empty.loadEditorGroups()
	assert.deepEqual(empty.groupOptions, [])
	assert.deepEqual(empty.editorGroups, [])

	const failed = screen([{ reject: new Error('403') }])
	await failed.loadEditorGroups()
	assert.equal(failed.groupsError, 't:The editor groups could not be loaded.')
})

test('saving the editor groups sends their ids, or says page editing is unchanged', async () => {
	const vm = screen([{ data: {} }])
	vm.editorGroups = [{ id: 'redactie' }, { id: 'oud' }]
	await vm.saveEditorGroups()
	assert.deepEqual(calls()[0], { verb: 'put', url: '/nc/apps/portaliq/api/settings', body: { editor_groups: ['redactie', 'oud'] } })
	assert.equal(vm.groupsSaved, true)
	assert.equal(vm.savingGroups, false)
	const refused = screen([{ reject: { response: { status: 403 } } }])
	refused.editorGroups = []
	await refused.saveEditorGroups()
	assert.equal(refused.groupsSaved, false)
	assert.equal(refused.groupsError, 't:Saving the editor groups failed. Page editing is unchanged.')
	assert.equal(refused.savingGroups, false)
})

test('the geography loads its provider, account, key state, edition, status, address and switch', async () => {
	const vm = screen([{
		data: {
			traffic_geo: { provider: 'maxmind', maxmindAccountId: 42, maxmindLicenseKeySet: true, maxmindEdition: 'GeoIP2-City', status: { present: 1, metadata: { build: 'x' } } },
			internal_base_url: 'http://intern',
			email_link_signin_enabled: true,
		},
	}])
	await vm.loadGeo()
	assert.equal(vm.geoProvider.id, 'maxmind')
	assert.equal(vm.geoAccountId, '42')
	assert.equal(vm.geoLicenseKeySet, true)
	assert.equal(vm.geoEdition.id, 'GeoIP2-City')
	assert.deepEqual(vm.geoStatus, { present: true, metadata: { build: 'x' } })
	assert.equal(vm.internalBaseUrl, 'http://intern')
	assert.equal(vm.emailLinkEnabled, true)

	const defaults = screen([{ data: { traffic_geo: { provider: 'other', maxmindLicenseKeySet: 'yes', status: { present: 0 } } } }])
	await defaults.loadGeo()
	assert.equal(defaults.geoProvider.id, 'dbip')
	assert.equal(defaults.geoAccountId, '')
	assert.equal(defaults.geoLicenseKeySet, false)
	assert.equal(defaults.geoEdition.id, 'GeoLite2-City')
	assert.deepEqual(defaults.geoStatus, { present: false, metadata: {} })
	assert.equal(defaults.internalBaseUrl, '')
	assert.equal(defaults.emailLinkEnabled, false)

	const none = screen([{ data: {} }])
	await none.loadGeo()
	assert.deepEqual(none.geoStatus, { present: false, metadata: {} })

	const failed = screen([{ reject: new Error('down') }])
	await failed.loadGeo()
	assert.equal(failed.geoError, 't:The geography settings could not be loaded.')
})

test('saving the geography sends the key only when typed, reloads, and says when it failed', async () => {
	const vm = screen([{ data: {} }])
	vm.geoProvider = { id: 'maxmind' }
	vm.geoAccountId = '42'
	vm.geoEdition = { id: 'GeoIP2-City' }
	vm.geoLicenseKey = 'secret'
	await vm.saveGeo()
	assert.deepEqual(calls()[0], { verb: 'put', url: '/nc/apps/portaliq/api/settings', body: { traffic_geo: { provider: 'maxmind', maxmindAccountId: '42', maxmindEdition: 'GeoIP2-City', maxmindLicenseKey: 'secret' } } })
	assert.equal(calls()[1].verb, 'get', 'reloaded after the save')
	assert.equal(vm.geoLicenseKey, '')
	assert.equal(vm.geoSaved, true)
	assert.equal(vm.savingGeo, false)

	const noKey = screen([{ data: {} }])
	noKey.geoProvider = { id: 'dbip' }
	noKey.geoEdition = null
	await noKey.saveGeo()
	assert.deepEqual(calls()[0].body, { traffic_geo: { provider: 'dbip', maxmindAccountId: '', maxmindEdition: 'GeoLite2-City' } })

	const none = screen([{ data: {} }])
	none.geoProvider = null
	await none.saveGeo()
	assert.equal(calls().length, 0)
	assert.equal(none.savingGeo, false)

	const failed = screen([{ reject: new Error('down') }])
	failed.geoProvider = { id: 'dbip' }
	failed.geoLicenseKey = 'kept'
	await failed.saveGeo()
	assert.equal(failed.geoError, 't:Saving the geography settings failed.')
	assert.equal(failed.geoLicenseKey, 'kept')
	assert.equal(failed.geoSaved, false)
	assert.equal(failed.savingGeo, false)
})

test('the e-mail link switch shows what is stored, and flips back when the save fails', async () => {
	const vm = screen([{ data: { config: { email_link_signin_enabled: true } } }])
	await vm.saveEmailLink(true)
	assert.deepEqual(calls()[0].body, { email_link_signin_enabled: true })
	assert.equal(vm.emailLinkEnabled, true)
	assert.equal(vm.emailLinkSaved, true)
	const stored = screen([{ data: {} }])
	await stored.saveEmailLink('yes')
	assert.deepEqual(calls()[0].body, { email_link_signin_enabled: false })
	assert.equal(stored.emailLinkEnabled, false)
	const failed = screen([{ reject: new Error('down') }])
	await failed.saveEmailLink(true)
	assert.equal(failed.emailLinkEnabled, false)
	assert.equal(failed.emailLinkError, 't:The setting could not be saved. Try again.')
	assert.equal(failed.savingEmailLink, false)
})

test('the internal address: stored, refused by the server, or failed', async () => {
	const vm = screen([{ data: { config: { internal_base_url: 'http://intern:8080' } } }])
	vm.internalBaseUrl = '  http://intern:8080  '
	await vm.saveInternalBaseUrl()
	assert.deepEqual(calls()[0].body, { internal_base_url: 'http://intern:8080' })
	assert.equal(vm.internalBaseUrl, 'http://intern:8080')
	assert.equal(vm.internalBaseUrlSaved, true)
	assert.equal(vm.savingInternalBaseUrl, false)

	const refused = screen([{ data: { config: { internal_base_url_refused: true, internal_base_url: 'old' } } }])
	refused.internalBaseUrl = 'ftp://x'
	await refused.saveInternalBaseUrl()
	assert.equal(refused.internalBaseUrlError, 't:This address is not valid. Use http or https, without a password, a query or "..".')
	assert.equal(refused.internalBaseUrl, 'ftp://x')
	assert.equal(refused.internalBaseUrlSaved, false)
	assert.equal(refused.savingInternalBaseUrl, false)

	const bare = screen([{ data: null }])
	bare.internalBaseUrl = 'x'
	await bare.saveInternalBaseUrl()
	assert.equal(bare.internalBaseUrl, '')

	const failed = screen([{ reject: new Error('down') }])
	failed.internalBaseUrl = 'x'
	await failed.saveInternalBaseUrl()
	assert.equal(failed.internalBaseUrlError, 't:Saving the address failed.')
})

test('revoking an organisation: none named, revoked, refused with a count, refused without', async () => {
	const nothing = screen([{ data: {} }])
	await nothing.revokeOrganisation()
	assert.equal(calls().length, 0)
	const vm = screen([{ data: { revoked: 3 } }])
	vm.organisationInput = 'gemeente-x'
	await vm.revokeOrganisation()
	assert.deepEqual(calls()[0], { verb: 'post', url: '/nc/apps/portaliq/api/session-admin/revoke-organisation', body: { organisation: 'gemeente-x' } })
	assert.equal(vm.revokeResult, 3)
	assert.equal(vm.revokeFailed, false)
	assert.equal(vm.revoking, false)
	const none = screen([{ data: {} }])
	none.organisationInput = 'x'
	await none.revokeOrganisation()
	assert.equal(none.revokeResult, 0)
	const partial = screen([{ reject: { response: { status: 503, data: { revoked: 2 } } } }])
	partial.organisationInput = 'x'
	await partial.revokeOrganisation()
	assert.equal(partial.revokeFailed, true)
	assert.equal(partial.revokeResult, 2)
	const refused = screen([{ reject: { response: { status: 403 } } }])
	refused.organisationInput = 'x'
	await refused.revokeOrganisation()
	assert.equal(refused.revokeFailed, true)
	assert.equal(refused.revokeResult, 0)
	assert.equal(refused.revoking, false)
})
