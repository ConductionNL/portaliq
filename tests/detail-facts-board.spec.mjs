#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// detail-facts-board.spec.mjs: the account and message pages show the facts
// the portaliq/PtAccount and PtBericht boards draw, not every property of the
// record. The account's details leave out the token hashes, invitation codes
// and counters; its sessions list leaves out the token id; its messages show
// when each arrived. A message is titled by its subject and shows its body
// first, then recipient, dates, nature, legal effect, term and reference.
//
// Usage:
//   node --test tests/detail-facts-board.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const SCHEMAS = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas
const NL = JSON.parse(read('l10n/nl.json')).translations
const page = (id) => MANIFEST.pages.find((candidate) => candidate.id === id).config
const widget = (config, id) => config.widgets.find((w) => w.id === id)

test('the account shows the board\'s facts and no secrets', () => {
	const config = page('PortalAccountDetail')
	const data = widget(config, 'account-data').content
	assert.deepEqual(data.include, [
		'displayName', 'email', 'audience', 'identityType', 'identityRef',
		'organisation', 'contactChannel', 'messageLanguage', 'provisionedBy', 'lastLoginAt',
	])
	assert.equal(data.columns, 3)
	for (const key of data.include) {
		assert.ok(SCHEMAS.portalAccount.properties[key], `the account has no "${key}"`)
		assert.doesNotMatch(key, /Hash|Attempts/, `"${key}" is a secret or a counter`)
	}
	const sessions = widget(config, 'account-sessions').content.columns.map((c) => c.key)
	assert.deepEqual(sessions, ['issuedAt', 'trustLevel', 'expiresAt', 'revoked'])
	const messages = widget(config, 'account-messages').content
	assert.deepEqual(messages.columns.map((c) => c.key), ['subject', 'read', 'receivedAt'])
	assert.deepEqual(messages.sort, { field: 'receivedAt', dir: 'desc' })
	for (const column of messages.columns) {
		assert.ok(NL[column.label], `"${column.label}" is not in the Dutch catalogue`)
	}
})

test('a message is titled by its subject and shows its body first', () => {
	const config = page('PortalMessageDetail')
	assert.equal(config.titleField, 'subject')
	const data = widget(config, 'message-data').content
	assert.equal(data.include[0], 'body')
	assert.deepEqual(data.include, ['body', 'subjectRef', 'receivedAt', 'read', 'nature', 'rechtsgevolg', 'term', 'referenceId'])
	for (const key of data.include) {
		assert.ok(SCHEMAS.portalMessage.properties[key], `the message has no "${key}"`)
	}
})
