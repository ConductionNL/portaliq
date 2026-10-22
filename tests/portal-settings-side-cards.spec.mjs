#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-settings-side-cards.spec.mjs: the PtPortalInstellingen board's two
// side cards. "Berichtenbox van MijnOverheid" switches the organisation's
// message box offer through the admin routes; "Als app installeren" switches
// the portal's `installable` field, which the manifest route reads.
//
// Usage:
//   node --test tests/portal-settings-side-cards.spec.mjs
//
// @spec openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-the-berichtenbox-offer-from-the-portals-settings

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const REGISTER = JSON.parse(read('lib/Settings/portaliq_register.json'))
const ROUTES = read('appinfo/routes.php')
const CONFIG = MANIFEST.pages.find((page) => page.id === 'PortalDetail').config
const NL = MANIFEST.i18n.labels.nl
const widget = (id) => CONFIG.widgets.find((w) => w.id === id)

test('the Berichtenbox card sits beside Instellingen and talks to the admin routes', () => {
	const card = widget('portal-side-message-box')
	assert.equal(card.type, 'PortalMessageBox')
	assert.deepEqual(card.tabs, ['portal-settings'])
	assert.equal(NL[card.title], 'Berichtenbox van MijnOverheid')
	assert.match(ROUTES, /'portalMessageBox#index', 'url' => '\/api\/portals\/\{slug\}\/message-box', 'verb' => 'GET'/)
	assert.match(ROUTES, /'portalMessageBox#update', 'url' => '\/api\/portals\/\{slug\}\/message-box', 'verb' => 'PUT'/)
	assert.match(read('src/widgets/PortalMessageBox.vue'), /:disabled="loading \|\| saving \|\| !configured"/)
})

// @spec openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-whether-a-portal-can-be-installed
test('the install card switches the portal installable field, true by default', () => {
	const card = widget('portal-side-install')
	assert.equal(card.type, 'settings-form')
	assert.deepEqual(card.tabs, ['portal-settings'])
	assert.equal(NL[card.title], 'Als app installeren')
	assert.deepEqual(card.content.fields.map((field) => field.key), ['installable'])
	assert.equal(REGISTER.components.schemas.portal.properties.installable.default, true)
})

test('both cards follow Verkeer meten in the side column', () => {
	const at = CONFIG.sideColumn.indexOf('portal-side-traffic')
	assert.deepEqual(CONFIG.sideColumn.slice(at, at + 3), ['portal-side-traffic', 'portal-side-message-box', 'portal-side-install'])
})
