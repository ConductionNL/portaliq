#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-binding-preview.spec.mjs: the "Which form?" row action on the Form
// bindings page (portal-intake-form-as-an-object T03) posts the row to the
// preview route and says, in the administrator's language, which form the
// binding opens today, or that it opens none and why.
//
// Usage:
//   node --test tests/form-binding-preview.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { createFormBindingPreview } from '../src/lib/formBindingPreview.js'

const ROW = {
	uuid: 'bind-1',
	portal: 'gemeente',
	route: '/aanvragen/formulier',
	typeId: 'type-1',
	audience: 'citizen',
	'@self': { id: 'bind-1', register: 'portaliq' },
}

/**
 * The handler over recording collaborators.
 *
 * @param {object} answer What the preview route answers.
 * @return {{previewFormBinding: Function, calls: object}}
 */
function build(answer) {
	const calls = { posts: [], notices: [], warnings: [], errors: [] }
	const { previewFormBinding } = createFormBindingPreview({
		post: async (url, body) => {
			calls.posts.push({ url, body })
			if (answer instanceof Error) {
				throw answer
			}
			return { data: answer }
		},
		generateUrl: (path) => path,
		notify: (text) => calls.notices.push(text),
		notifyWarning: (text) => calls.warnings.push(text),
		notifyError: (text) => calls.errors.push(text),
		translate: (text, vars = {}) =>
			text.replace(/\{(\w+)\}/g, (m, key) => (key in vars ? vars[key] : m)),
	})
	return { previewFormBinding, calls }
}

test('the row is posted to the preview route without its metadata', async () => {
	const { previewFormBinding, calls } = build({
		state: 'resolved',
		formName: 'Melding',
	})
	await previewFormBinding({ item: ROW })
	assert.equal(calls.posts.length, 1)
	assert.equal(calls.posts[0].url, '/apps/portaliq/api/form-bindings/preview')
	assert.equal(calls.posts[0].body.binding.typeId, 'type-1')
	assert.equal('@self' in calls.posts[0].body.binding, false)
})

test('a resolved binding names the form it opens today', async () => {
	const { previewFormBinding, calls } = build({
		state: 'resolved',
		formName: 'Melding openbare ruimte',
	})
	assert.equal(await previewFormBinding({ item: ROW }), true)
	assert.deepEqual(calls.notices, [
		'This entry opens "Melding openbare ruimte" today.',
	])
	assert.deepEqual(calls.warnings, [])
})

test('a binding that resolves to no form says so as a warning, with its reason', async () => {
	for (const [answer, key] of [
		[
			{ state: 'resolves_to_none', reason: 'no_form_for_type_and_audience' },
			'This entry opens no form today. No published form matches its case type and audience.',
		],
		[
			{
				state: 'resolves_to_none',
				reason: 'named_form_not_published',
				askedFor: 'Kapvergunning',
			},
			'This entry opens no form today. It asks for "Kapvergunning", and no form of that name is published to its audience.',
		],
		[
			{ state: 'resolves_to_none', reason: 'external_without_address' },
			'This entry sends people to another website, but has no address. Nobody can start it.',
		],
		[
			{ state: 'resolves_to_none', reason: 'hidden_case_type' },
			"This entry opens no form: this portal does not show its case type. Show it again under Case types on the portal's page.",
		],
	]) {
		const { previewFormBinding, calls } = build(answer)
		assert.equal(await previewFormBinding({ item: ROW }), true)
		assert.deepEqual(calls.warnings, [key])
		assert.deepEqual(calls.notices, [])
	}
})

test('an external binding names its destination', async () => {
	const { previewFormBinding, calls } = build({
		state: 'external',
		destination: 'formulieren.example.nl',
	})
	await previewFormBinding({ item: ROW })
	assert.deepEqual(calls.notices, [
		'This entry sends people to formulieren.example.nl. Nothing arrives here.',
	])
})

test('a failed check says so and claims nothing about the form', async () => {
	const { previewFormBinding, calls } = build(new Error('network'))
	assert.equal(await previewFormBinding({ item: ROW }), false)
	assert.deepEqual(calls.errors, [
		'Could not check which form this entry opens. Try again.',
	])
	assert.deepEqual(calls.notices, [])
})

test('the Form bindings page offers the action and the app registers its handler', () => {
	const manifest = JSON.parse(
		readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'),
	)
	const page = manifest.pages.find((p) => p.id === 'FormBindings')
	assert.ok(page, 'a FormBindings page exists')
	assert.equal(page.config.schema, 'portalFormBinding')
	assert.ok(
		page.config.actions.some(
			(a) => a.type === 'handler' && a.handler === 'previewFormBinding',
		),
		'the page offers the previewFormBinding handler',
	)
	assert.ok(
		manifest.menu.some((m) => m.route === 'FormBindings'),
		'the menu reaches the page',
	)
	const registry = readFileSync(
		new URL('../src/customComponents.js', import.meta.url),
		'utf8',
	)
	assert.match(registry, /createFormBindingPreview\(/)
})
