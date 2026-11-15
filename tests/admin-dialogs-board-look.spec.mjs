#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// admin-dialogs-board-look.spec.mjs: every admin dialog is the library's
// CnDialog with a `look` prop, and every spawned dialog gets the manifest's
// look (a spawned dialog is mounted outside CnAppRoot, so nothing else hands
// it the look).
//
// Usage:
//   node --test tests/admin-dialogs-board-look.spec.mjs
//
// @spec openspec/changes/admin-dialogs-board-look/specs/admin-dialogs-board-look/spec.md

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (rel) => readFileSync(join(ROOT, rel), 'utf8')
const DIALOGS = readdirSync(join(ROOT, 'src/dialogs')).filter((f) => f.endsWith('.vue'))

/**
 * The opening tag of a component's root element.
 *
 * @param {string} source The single-file component.
 * @return {string} The tag up to its closing `>`.
 */
function rootTag(source) {
	const template = source.slice(source.indexOf('<template>') + '<template>'.length)
	const start = template.indexOf('<')
	return template.slice(start, template.indexOf('>', start) + 1)
}

test('every dialog renders CnDialog and passes its look prop on', () => {
	assert.ok(DIALOGS.length >= 8, DIALOGS.join(', '))
	for (const file of DIALOGS) {
		const source = read(`src/dialogs/${file}`)
		const root = rootTag(source)
		assert.match(root, /^<CnDialog\b/, file)
		assert.match(root, /:look="look"/, file)
		assert.doesNotMatch(source, /\bNcDialog\b/, file)
		assert.match(source, /import \{ CnDialog \} from '@conduction\/nextcloud-vue'/, file)
		assert.match(source, /\n\t\tlook: \{\n\t\t\ttype: String,\n\t\t\tdefault: undefined,/, file)
	}
})

test('the media picker is the wide dialog, the others keep the form width', () => {
	for (const file of DIALOGS) {
		const root = rootTag(read(`src/dialogs/${file}`))
		if (file === 'MediaPickerDialog.vue') {
			assert.match(root, /\bwidth="wizard"/)
		} else {
			assert.doesNotMatch(root, /\bwidth=/, file)
		}
	}
})

test('every spawned dialog goes through spawnLookDialog', () => {
	for (const rel of ['src/customComponents.js', 'src/widgets/PortalRegistration.vue', 'src/widgets/PortalAccountWithdraw.vue']) {
		const source = read(rel)
		assert.doesNotMatch(source, /\bspawnDialog\b/, rel)
		assert.match(source, /spawnLookDialog\(/, rel)
	}
})

test('spawnLookDialog hands the manifest look over, and an explicit look wins', () => {
	const source = read('src/lib/dialogLook.js')
	assert.match(source, /bundledManifest\.look === 'board' \? 'board' : 'nextcloud'/)
	assert.match(source, /spawnDialog\(dialog, \{ look: DIALOG_LOOK, \.\.\.props \}\)/)
	assert.equal(JSON.parse(read('src/manifest.json')).look, 'board')
})

test('the invite, issue and media dialogs carry the boards\' context line', () => {
	assert.match(rootTag(read('src/dialogs/InviteDialog.vue')), /:eyebrow="t\('portaliq', 'Invitations'\)"/)
	assert.match(rootTag(read('src/dialogs/IssueAccountDialog.vue')), /:eyebrow="t\('portaliq', 'Accounts'\)"/)
	const media = read('src/dialogs/MediaPickerDialog.vue')
	assert.match(rootTag(media), /:eyebrow="eyebrow"/)
	assert.match(media, /t\('portaliq', '\{title\}, choose media', \{ title: this\.pageTitle \}\)/)
	assert.match(read('src/views/PageLayoutDesigner.vue'), /<MediaPickerDialog[^>]*:pageTitle="page\.title \|\| ''"/)
	const nl = JSON.parse(read('l10n/nl.json')).translations
	assert.equal(nl.Invitations, 'Uitnodigingen')
	assert.equal(nl['{title}, choose media'], '{title}, media kiezen')
	assert.equal(nl['Choose media'], 'Media kiezen')
})
