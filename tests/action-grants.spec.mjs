// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// operate-roles-for-content-and-actions T05 (REQ-ORA-001): the admin
// settings' Actions section loads each action with its groups, and a save
// sends action to group ids.
//
// Usage:
//   node --test tests/action-grants.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	grantRows,
	grantsBody,
	loadGrants,
	saveGrants,
} from '../src/lib/actionGrants.js'

const ANSWER = {
	actions: [
		{
			action: 'portal.provision',
			label: 'Create and manage portal accounts',
			description: 'Invite residents.',
			groups: ['Service desk', 'gone'],
		},
		{ action: 'portal.create-poll', label: 'Create polls', groups: [] },
	],
	availableGroups: [{ id: 'Service desk', label: 'Service desk' }],
}

test('rows carry the picker options, and a deleted group stays visible', () => {
	const { rows, groupOptions } = grantRows(ANSWER)
	assert.equal(groupOptions.length, 1)
	assert.deepEqual(rows[0].groups, [
		{ id: 'Service desk', label: 'Service desk' },
		{ id: 'gone', label: 'gone' },
	])
	assert.deepEqual(rows[1].groups, [], 'only administrators')
})

test('an empty or broken answer gives no rows rather than throwing', () => {
	assert.deepEqual(grantRows(null).rows, [])
	assert.deepEqual(grantRows({}).groupOptions, [])
})

test('a save sends action to group ids, and reads back what was stored', async () => {
	const sent = []
	const http = {
		get: async () => ({ data: ANSWER }),
		put: async (url, body) => {
			sent.push([url, body])
			return {
				data: {
					...ANSWER,
					actions: [{ ...ANSWER.actions[0], groups: ['Service desk'] }],
				},
			}
		},
	}
	const loaded = await loadGrants(http, '/u')
	loaded.rows[0].groups = [loaded.groupOptions[0]]
	const stored = await saveGrants(http, '/u', loaded.rows)

	assert.deepEqual(sent[0][1], {
		grants: { 'portal.provision': ['Service desk'], 'portal.create-poll': [] },
	})
	assert.deepEqual(grantsBody(stored.rows).grants['portal.provision'], [
		'Service desk',
	])
})

test('the admin screen has the section, wired to the helper and the route', () => {
	const view = readFileSync('src/views/AdminRoot.vue', 'utf8')
	assert.match(view, /t\('portaliq', 'Actions'\)/)
	assert.match(view, /loadGrants\(/)
	assert.match(view, /\/apps\/portaliq\/api\/settings\/actions/)
	assert.match(view, /Only administrators/)
})
