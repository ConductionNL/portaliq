#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// message-box-channel.spec.mjs: the resident sees where a letter went, and
// can switch the government message box off (inbox-berichtenbox-channel,
// REQ-MBC-004, REQ-MBC-005). The delivery line shows only for a delivery the
// server reported; the settings row shows only when the organisation offers
// the channel. The inbox and the settings section are wired in, and both
// locales carry the strings.
//
// Usage:
//   node --test tests/message-box-channel.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	deliveryLine,
	messageBoxChoice,
	withMessageBoxChoice,
} from '../src/portal/lib/messageBox.js'

/**
 * A translator that fills `{name}` placeholders, as the portal's does.
 *
 * @param {string} key The key.
 * @param {object} params The values.
 * @return {string}
 */
function t(key, params = {}) {
	return key.replace(/\{(\w+)\}/g, (_, name) => String(params[name] ?? ''))
}

test('a delivered message box send gives the delivery line', () => {
	const message = {
		_deliveries: [{ channel: 'messageBox', label: 'MijnOverheid Berichtenbox' }],
	}
	assert.equal(deliveryLine(message, t), 'Also sent to MijnOverheid Berichtenbox.')
})

test('no delivery, another channel or no label gives no line', () => {
	assert.equal(deliveryLine({}, t), null)
	assert.equal(deliveryLine({ _deliveries: [] }, t), null)
	assert.equal(
		deliveryLine({ _deliveries: [{ channel: 'email', label: 'x' }] }, t),
		null,
	)
	assert.equal(
		deliveryLine({ _deliveries: [{ channel: 'messageBox', label: '' }] }, t),
		null,
	)
	assert.equal(deliveryLine({ _deliveries: 'yes' }, t), null)
})

test('the settings row shows only when the organisation offers the channel', () => {
	assert.equal(messageBoxChoice({ preferences: {} }), null)
	assert.equal(messageBoxChoice({ preferences: {}, messageBox: null }), null)
	assert.equal(
		messageBoxChoice({ preferences: {}, messageBox: { label: '' } }),
		null,
	)
	assert.deepEqual(
		messageBoxChoice({
			preferences: {},
			messageBox: { label: 'MijnOverheid Berichtenbox' },
		}),
		{ label: 'MijnOverheid Berichtenbox', enabled: true },
	)
	assert.deepEqual(
		messageBoxChoice({
			preferences: { messageBox: { enabled: false } },
			messageBox: { label: 'MijnOverheid Berichtenbox' },
		}),
		{ label: 'MijnOverheid Berichtenbox', enabled: false },
	)
})

test('switching it sets messageBox.enabled and keeps the other choices', () => {
	const choices = { 'case.updated': { email: true, push: false } }
	assert.deepEqual(withMessageBoxChoice(choices, false), {
		'case.updated': { email: true, push: false },
		messageBox: { enabled: false },
	})
	assert.deepEqual(choices, { 'case.updated': { email: true, push: false } })
})

test('the inbox and the settings section use it, and both locales say it', () => {
	const inbox = readFileSync(
		new URL('../src/portal/components/InboxPage.jsx', import.meta.url),
		'utf8',
	)
	assert.match(inbox, /deliveryLine\(message, t\)/)
	const settings = readFileSync(
		new URL(
			'../src/portal/components/NotificationSettings.jsx',
			import.meta.url,
		),
		'utf8',
	)
	assert.match(settings, /messageBoxChoice\(loaded\)/)
	assert.match(settings, /withMessageBoxChoice\(/)
	const expected = {
		nl: {
			'Also sent to {label}.': 'Ook verstuurd naar {label}.',
			'Also send letters to {label}': 'Brieven ook naar {label} sturen',
		},
		en: {
			'Also sent to {label}.': 'Also sent to {label}.',
			'Also send letters to {label}': 'Also send letters to {label}',
		},
	}
	for (const locale of ['en', 'nl']) {
		const bundle = JSON.parse(
			readFileSync(
				new URL(`../src/shared/i18n/${locale}.json`, import.meta.url),
				'utf8',
			),
		)
		for (const [key, value] of Object.entries(expected[locale])) {
			assert.equal(bundle[key], value, `${locale}: ${key}`)
		}
	}
})
