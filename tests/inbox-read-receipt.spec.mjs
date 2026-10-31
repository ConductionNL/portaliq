#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// inbox-read-receipt.spec.mjs: a message that asks for a read receipt tells the
// resident before they open it, and staff see who read what
// (inbox-read-receipt-on-request REQ-IRR-003 to REQ-IRR-005).
//
// Usage:
//   node --test tests/inbox-read-receipt.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const InboxPage = await loadSfc('src/site/pages/inbox/InboxPage.vue')

function render (messages, locale = 'en') {
  return renderComponent(inState(InboxPage, { loading: false, messages }), { api: {}, t, locale, nav: [] })
}

function message (extra) {
  return {
	id: 'm1',
	subject: 'Brief groep 5B',
	receivedAt: '2026-10-07T08:00:00Z',
	read: false,
	_source: { appId: 'portaliq', collection: 'portalMessages', register: 'portaliq', schema: 'portalMessage' },
	...extra,
}
}

test('a message with a receipt request shows the notice, one without does not', async () => {
	const asked = await render([message({ readReceiptRequested: true })])
	assert.match(asked, /data-testid="inbox-row-receipt-notice"[^>]*>\s*The sender sees when you opened this message\./)

	const plain = await render([message({}), message({ id: 'm2', readReceiptRequested: false })])
	assert.doesNotMatch(plain, /inbox-row-receipt-notice/)
})

test('the notice is in Dutch for a Dutch page', async () => {
	const html = await render([message({ readReceiptRequested: true })], 'nl')
	assert.match(html, /De afzender ziet wanneer u dit bericht hebt geopend\./)
})

test('staff see the recipient, the read state and the read moment in the Portal Messages index', () => {
	const manifest = JSON.parse(readFileSync('src/manifest.json', 'utf8'))
	const page = manifest.pages.find((entry) => entry.id === 'PortalMessages')
	const columns = page.config.columns.map((column) => (typeof column === 'string' ? { key: column } : column))
	const byKey = Object.fromEntries(columns.map((column) => [column.key, column]))

	assert.equal(byKey.subjectRef.label, 'Recipient')
	assert.equal(byKey.read.label, 'Read')
	assert.equal(byKey.readAt.label, 'Read on')
	const nl = JSON.parse(readFileSync('l10n/nl.json', 'utf8')).translations
	assert.equal(nl.Recipient, 'Ontvanger')
	assert.equal(nl['Read on'], 'Gelezen op')
	assert.ok(byKey.sendingRef, 'a sending can be found by its reference')
})

test('the register declares the three receipt fields, optional, each with a title and a description', () => {
	const register = JSON.parse(readFileSync('lib/Settings/portaliq_register.json', 'utf8'))
	const schema = register.components.schemas.portalMessage
	for (const name of ['readReceiptRequested', 'readAt', 'sendingRef']) {
		assert.ok(schema.properties[name].title, `${name} has a title`)
		assert.ok(schema.properties[name].description, `${name} has a description`)
		assert.equal((schema.required || []).includes(name), false, `${name} is optional`)
	}
	assert.equal(schema.properties.readReceiptRequested.default, false)
})

test('the message page shows the read moment, the receipt request and the link to the sending', () => {
	const manifest = JSON.parse(readFileSync('src/manifest.json', 'utf8'))
	const page = manifest.pages.find((entry) => entry.id === 'PortalMessageDetail')
	const facts = page.config.widgets.find((widget) => widget.id === 'message-data')
	assert.equal(facts.type, 'facts')
	const fields = facts.content.fields

	const asked = fields.find((field) => field.path === 'readAt')
	assert.equal(asked.label, 'Read')
	assert.equal(asked.emptyValue, 'not yet')
	assert.deepEqual(asked.when, { field: 'readReceiptRequested', op: 'eq', value: true })

	const plain = fields.find((field) => field.path === 'read')
	assert.equal(plain.label, 'Read')
	assert.deepEqual(plain.map, { true: 'yes', false: 'not yet' })
	assert.deepEqual(plain.when, { field: 'readReceiptRequested', op: 'neq', value: true })

	const receipt = fields.find((field) => field.key === 'readReceipt')
	assert.equal(receipt.label, 'Read receipt')
	assert.deepEqual(receipt.when, asked.when)

	const recipient = fields.findIndex((field) => field.path === 'subjectRef')
	const sending = fields[recipient + 1]
	assert.equal(sending.key, 'sending', 'the link to the sending sits under the recipient')
	assert.deepEqual(sending.when, { field: 'sendingRef', op: 'notEmpty' })
	assert.deepEqual(sending.route, { name: 'PortalMessages', query: { sendingRef: '@source.sendingRef' } })
	assert.ok(manifest.pages.some((entry) => entry.id === 'PortalMessages'), 'the link names an existing page')

	const history = page.config.widgets.find((widget) => widget.id === 'message-history')
	assert.deepEqual(history.content.changeLabels, [{ field: 'readAt', label: 'Read by {object.subjectRef}' }])

	const nl = JSON.parse(readFileSync('l10n/nl.json', 'utf8')).translations
	assert.equal(nl['not yet'], 'nog niet')
	assert.equal(nl['Read receipt'], 'Leesbevestiging')
	assert.equal(nl['asked for'], 'gevraagd')
	assert.equal(nl['All recipients of this sending'], 'Alle ontvangers van deze verzending')
	assert.equal(nl['Read by {object.subjectRef}'], 'Gelezen door {object.subjectRef}')
})
