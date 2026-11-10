#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-small-reads-steps.spec.mjs: the embed frame's boot data and answers,
// the sign-in card's button and the theme choice's load and save outcomes,
// pinned before each was split into named steps.
//
// Usage:
//   node --test tests/site-small-reads-steps.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { initialAnswers, readEmbedConfig, submissionErrors } from '../src/embed/frame.js'
import { createPortalThemeChoice } from '../src/lib/portalThemeChoice.js'
import { cardButton } from '../src/site/widgets/nlSignIn/signIn.js'

/**
 * A document whose config block holds the given text, or none.
 *
 * @param {string|null} text The block's text.
 * @return {object} The document.
 */
function docWith(text) {
	return { getElementById: (id) => (id === 'portaliq-embed-config' && text !== null ? { textContent: text } : null) }
}

const FALLBACK = { payload: { refused: 'form_not_found' }, submitUrl: '', locale: 'nl' }

test('readEmbedConfig falls back on every broken block and keeps what is well-formed', () => {
	assert.deepEqual(readEmbedConfig(null), FALLBACK)
	assert.deepEqual(readEmbedConfig(docWith(null)), FALLBACK)
	assert.deepEqual(readEmbedConfig(docWith('')), FALLBACK)
	assert.deepEqual(readEmbedConfig(docWith('{broken')), FALLBACK)
	assert.deepEqual(readEmbedConfig(docWith('"text"')), FALLBACK)
	assert.deepEqual(readEmbedConfig(docWith('{"payload": null}')), FALLBACK)
	assert.deepEqual(readEmbedConfig(docWith('{"payload": "x"}')), FALLBACK)
	assert.deepEqual(readEmbedConfig(docWith('{"payload": {"a": 1}, "submitUrl": 5, "locale": ""}')), {
		payload: { a: 1 },
		submitUrl: '',
		locale: 'nl',
	})
	assert.deepEqual(readEmbedConfig(docWith('{"payload": {"a": 1}, "submitUrl": "/s", "locale": "en"}')), {
		payload: { a: 1 },
		submitUrl: '/s',
		locale: 'en',
	})
	assert.deepEqual(readEmbedConfig(docWith('{"payload": {}, "locale": 3}')).locale, 'nl')
})

test('initialAnswers keeps only named fields with a preset, as text', () => {
	assert.deepEqual(
		initialAnswers([
			{ name: 'a', preset: 0 },
			{ name: 'b', preset: '' },
			{ name: 'c', preset: null },
			{ name: 'd' },
			{ preset: 'x' },
			null,
			{ name: 'e', preset: false },
			{ name: 'f', preset: 'ja' },
		]),
		{ a: '0', e: 'false', f: 'ja' },
	)
	assert.deepEqual(initialAnswers('nope'), {})
})

test('submissionErrors reads a map, a list or nothing', () => {
	assert.deepEqual(submissionErrors({ errors: { a: 'x', b: 2 } }), { fields: { a: 'x', b: '2' }, messages: ['x', '2'] })
	assert.deepEqual(submissionErrors({ errors: ['x', 3] }), { fields: {}, messages: ['x', '3'] })
	assert.deepEqual(submissionErrors({ errors: null }), { fields: {}, messages: [] })
	assert.deepEqual(submissionErrors({ errors: 'x' }), { fields: {}, messages: [] })
	assert.deepEqual(submissionErrors(null), { fields: {}, messages: [] })
})

/**
 * The widget's words, echoed.
 *
 * @param {string} key The word.
 * @param {object} vars The variables.
 * @return {string} The text.
 */
function say(key, vars) {
	return vars ? `${key}:${JSON.stringify(vars)}` : key
}

test('cardButton: signed in, one way, several ways and the sign-in page', () => {
	const way = { id: 'digid', label: 'DigiD', href: '/inloggen/digid' }
	assert.deepEqual(cardButton({ ways: [], signedIn: true, heading: 'Mijn school', buttonLabel: 'x', signInHref: '/in', say }), {
		id: 'own-area',
		label: 'goTo:{"name":"Mijn school"}',
		href: '/mijn',
		route: '/mijn',
	})
	assert.deepEqual(cardButton({ ways: [way], signedIn: true, heading: '', buttonLabel: '', signInHref: '', say }).label, 'ownArea')
	assert.deepEqual(cardButton({ ways: [way], signedIn: false, heading: '', buttonLabel: '', signInHref: '', say }), {
		id: 'digid',
		label: 'DigiD',
		href: '/inloggen/digid',
		route: '',
	})
	assert.equal(cardButton({ ways: [way], signedIn: false, heading: '', buttonLabel: 'Inloggen', signInHref: '', say }).label, 'Inloggen')
	assert.deepEqual(cardButton({ ways: [way, way], signedIn: false, heading: '', buttonLabel: '', signInHref: '/inloggen', say }), {
		id: 'choose',
		label: 'signIn',
		href: '/inloggen',
		route: '/inloggen',
	})
	for (const href of ['//evil.example', 'https://evil.example', '', undefined]) {
		const button = cardButton({ ways: [], signedIn: false, heading: '', buttonLabel: 'Kies', signInHref: href, say })
		assert.deepEqual(button, { id: 'choose', label: 'Kies', href: '/mijn', route: '/mijn' })
	}
})

/**
 * The theme choice over a transport that answers or throws.
 *
 * @param {object} answer `{data}` to answer, or `{error}` to throw.
 * @return {{choice: object, calls: Array<object>}} The choice and the calls.
 */
function themeChoice(answer) {
	const calls = []
	const reply = async (address, body) => {
		calls.push({ address, body })
		if (answer.error) {
			throw answer.error
		}
		return { data: answer.data }
	}
	const choice = createPortalThemeChoice({
		get: reply,
		put: reply,
		url: (path, params) => path.replace('{slug}', params.slug),
	})
	return { choice, calls }
}

const NO_THEME = { state: 'error', current: '', currentResolves: false, sets: [] }

test('the theme choice loads a portal\'s theme and sets, or says it could not', async () => {
	assert.deepEqual(await themeChoice({ data: {} }).choice.load(''), NO_THEME)
	assert.deepEqual(await themeChoice({ error: new Error('x') }).choice.load('school'), NO_THEME)
	const { choice, calls } = themeChoice({ data: { current: 'rood', currentResolves: true, sets: [{ id: 'rood' }] } })
	assert.deepEqual(await choice.load('school'), { state: 'ready', current: 'rood', currentResolves: true, sets: [{ id: 'rood' }] })
	assert.equal(calls[0].address, '/api/portals/school/theme')
	assert.deepEqual(await themeChoice({ data: null }).choice.load('school'), { state: 'ready', current: '', currentResolves: false, sets: [] })
	assert.deepEqual(await themeChoice({ data: { currentResolves: 'yes', sets: 'x' } }).choice.load('school'), { state: 'ready', current: '', currentResolves: false, sets: [] })
})

test('the theme choice saves and names every refusal', async () => {
	const saved = themeChoice({ data: { ok: 1 } })
	assert.deepEqual(await saved.choice.save('school', 'rood'), { outcome: 'saved', data: { ok: 1 } })
	assert.deepEqual(saved.calls[0], { address: '/api/portals/school/theme', body: { theme: 'rood', acceptFindings: false } })
	await saved.choice.save('school', 'rood', true)
	assert.deepEqual(saved.calls[1].body, { theme: 'rood', acceptFindings: true })

	const refusal = (data) => themeChoice({ error: { response: { data } } }).choice.save('school', 'rood')
	assert.deepEqual(await refusal({ error: 'contrast', verdict: { passes: false } }), { outcome: 'contrast', verdict: { passes: false } })
	assert.deepEqual(await refusal({ error: 'contrast' }), { outcome: 'contrast', verdict: {} })
	assert.deepEqual(await refusal({ error: 'unknown_theme' }), { outcome: 'unknown' })
	assert.deepEqual(await refusal({ error: 'refused', refusal: 'no' }), { outcome: 'refused', refusal: 'no' })
	assert.deepEqual(await refusal({ error: 'refused' }), { outcome: 'refused', refusal: '' })
	assert.deepEqual(await refusal({ error: 'other' }), { outcome: 'failed' })
	assert.deepEqual(await themeChoice({ error: new Error('down') }).choice.save('school', 'rood'), { outcome: 'failed' })
	assert.deepEqual(await themeChoice({ error: null }).choice.save('school', 'rood'), { outcome: 'saved', data: undefined })
})
