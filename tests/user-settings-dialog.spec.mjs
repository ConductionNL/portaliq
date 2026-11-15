import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The Persoonlijke instellingen dialog shows CnAppRoot's default sections
// (board PtPersoonlijkeInstellingen). Filling the #user-settings slot in
// App.vue replaces them, which once left the dialog on one empty "Algemeen".
import { test } from 'node:test'

const app = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8')
const template = app.slice(app.indexOf('<template>'), app.indexOf('<script>'))

test('App.vue does not fill the user-settings slot', () => {
	assert.doesNotMatch(template, /#user-settings|v-slot:user-settings/)
	assert.doesNotMatch(app, /NcAppSettingsSection/)
})
