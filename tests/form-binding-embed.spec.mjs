#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-binding-embed.spec.mjs: the "Insert form" row action posts the binding
// to the embed-snippet route and opens the dialog with the answer.
//
// Usage:
//   node --test tests/form-binding-embed.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createFormBindingEmbed } from '../src/lib/formBindingEmbed.js'

const ROW = {
	uuid: 'bind-1',
	formName: 'Melding openbare ruimte',
	route: '/melding',
	'@self': { id: 'bind-1' },
}

/**
 * @param {object|Error} answer What the route answers.
 * @return {object} The handler and what it called.
 */
function build(answer) {
	const calls = { posts: [], dialogs: [], errors: [] }
	const { openFormBindingEmbed } = createFormBindingEmbed({
		post: async (url, body) => {
			calls.posts.push({ url, body })
			if (answer instanceof Error) {
				throw answer
			}
			return { data: answer }
		},
		generateUrl: (path) => path,
		openDialog: (props) => calls.dialogs.push(props),
		notifyError: (text) => calls.errors.push(text),
		translate: (text) => text,
	})
	return { openFormBindingEmbed, calls }
}

test('posts the binding without bookkeeping keys and opens the dialog', async () => {
	const description = { embeddable: true, origins: ['https://www.example.nl'], snippet: '<iframe></iframe>' }
	const { openFormBindingEmbed, calls } = build(description)

	assert.equal(await openFormBindingEmbed({ item: ROW }), true)
	assert.equal(calls.posts[0].url, '/apps/portaliq/api/form-bindings/embed-snippet')
	assert.deepEqual(Object.keys(calls.posts[0].body.binding).sort(), ['formName', 'route'])
	assert.deepEqual(calls.dialogs[0], { formName: 'Melding openbare ruimte', route: '/melding', description })
})

test('a failed request says so and opens nothing', async () => {
	const { openFormBindingEmbed, calls } = build(new Error('boom'))

	assert.equal(await openFormBindingEmbed({ item: ROW }), false)
	assert.equal(calls.dialogs.length, 0)
	assert.equal(calls.errors.length, 1)
})
