// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The inbox badge counts the unread messages the inbox shows
// (woo-inbox-notices). Found while filming J6: six saved-search notices
// arrived after sign-in, the inbox listed eight unread rows, and the badge
// still said 2.
//
// @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-the-inbox-badge-counts-the-unread-messages-the-inbox-shows-req-nap-011

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { unreadIn } from '../src/shared/inboxUnread.js'

test('the unread count is the number of loaded rows not marked read', () => {
	assert.equal(unreadIn([{ read: false }, { read: true }, {}, { read: null }]), 3)
	assert.equal(unreadIn([]), 0)
	assert.equal(unreadIn(undefined), 0)
	assert.equal(unreadIn([null, { read: true }]), 0)
})

test('the site inbox hands its loaded unread count to the shell badge', () => {
	const inbox = readFileSync(
		new URL('../src/site/pages/inbox/InboxPage.vue', import.meta.url),
		'utf8',
	)
	assert.match(
		inbox,
		/import \{ unreadIn \} from '\.\.\/\.\.\/\.\.\/shared\/inboxUnread\.js'/,
	)
	assert.match(inbox, /this\.unread = unreadIn\(this\.messages\)/)
	assert.match(inbox, /this\.\$emit\('unread', this\.unread\)/)
	// The signed-in area passes it on, and the shell's badge reads it.
	const area = readFileSync(
		new URL('../src/site/components/AccountArea.vue', import.meta.url),
		'utf8',
	)
	assert.match(area, /@unread="\$emit\('unread', \$event\)"/)
	const app = readFileSync(new URL('../src/site/App.vue', import.meta.url), 'utf8')
	assert.match(app, /@unread="unreadOverride = \$event"/)
})
