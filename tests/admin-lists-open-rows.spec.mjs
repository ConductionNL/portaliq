#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// admin-lists-open-rows.spec.mjs: the invitations and access requests lists
// tint the rows still waiting on someone, colour their states as drawn and
// write the second line under the address and the answer
// (PtUitnodigingen, PtToegangsverzoeken).
//
// Usage:
//   node --test tests/admin-lists-open-rows.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const manifest = JSON.parse(readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'))
const nl = JSON.parse(readFileSync(new URL('../l10n/nl.json', import.meta.url), 'utf8')).translations

/**
 * The config of the page with this id.
 *
 * @param {string} id The page id.
 * @return {object} The page's config.
 */
function config(id) {
	const page = manifest.pages.find((p) => p.id === id)
	assert.ok(page, `page ${id} exists`)
	return page.config
}

/**
 * The column of a page with this key.
 *
 * @param {string} id The page id.
 * @param {string} key The column key.
 * @return {object} The column.
 */
function column(id, key) {
	const found = config(id).columns.find((c) => c.key === key)
	assert.ok(found, `${id} has a ${key} column`)
	return found
}

/**
 * The states whose rows carry the attention class.
 *
 * @param {string} id The page id.
 * @return {string[]} The states, in rule order.
 */
function attentionStates(id) {
	return (config(id).rowClass || [])
		.filter((rule) => rule.class === 'cn-row--attention')
		.map((rule) => {
			assert.equal(rule.when.field, 'state')
			return rule.when.value
		})
}

test('an invitation still open (sent or opened) is tinted as asking for attention', () => {
	assert.deepEqual(attentionStates('Invitations'), ['sent', 'opened'])
})

test('an access request awaiting a decision is tinted as asking for attention', () => {
	assert.deepEqual(attentionStates('AccessRequests'), ['pending'])
})

test('the invitation states take the board\'s pill tones and a sent one reads Sent', () => {
	const state = column('Invitations', 'state')
	assert.deepEqual(state.widgetProps.colorMap, { sent: 'info', opened: 'purple', accepted: 'success', expired: 'default', revoked: 'default' })
	assert.equal(state.enumLabels.sent, 'Sent')
	assert.equal(nl.Sent, 'Verstuurd')
})

test('the address carries the audience as its second line, in words', () => {
	const email = column('Invitations', 'email')
	assert.equal(email.secondary, 'audience')
	const labels = email.secondaryLabels.audience
	assert.deepEqual(labels, { citizen: 'Resident', client: 'Client', organisation: 'Business', business: 'Business', supplier: 'Supplier', partner: 'Partner' })
	for (const key of Object.values(labels)) {
		assert.ok(nl[key], `${key} has a Dutch translation`)
	}
})

test('the inviter is named, not shown as an account id', () => {
	assert.equal(column('Invitations', 'invitedBy').formatter, 'userName')
})

test('an answered access request names who answered and when, under its state', () => {
	const key = 'by {decidedBy|user}, {decidedAt|date}'
	assert.deepEqual(column('AccessRequests', 'state').secondary, [key])
	assert.equal(nl[key], 'door {decidedBy|user}, {decidedAt|date}')
})

test('an access request reads Open, Approved or Refused, as the list\'s tabs do', () => {
	const state = column('AccessRequests', 'state')
	assert.deepEqual(state.enumLabels, { pending: 'Open', granted: 'Approved', refused: 'Refused' })
	assert.equal(nl.Approved, 'Goedgekeurd')
	assert.equal(nl.Refused, 'Afgewezen')
})

test('the requester stands beside the grey initials chip, as dossiq draws a person', () => {
	assert.deepEqual(column('AccessRequests', 'displayName').widgetProps, { tone: 'neutral' })
})
