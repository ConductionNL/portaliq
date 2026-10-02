#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// embed-frame.spec.mjs — the Vue embed frame (site-reaches-portal-parity
// REQ-SRP-047): its own small entry, the form or its refusal in words, and a
// template that actually loads it.
//
// Usage:
//   node --test tests/embed-frame.spec.mjs
//
// 🔴 THE TEMPLATE TEST IS THE ONE THAT MATTERS MOST. The frame route rendered
// with RENDER_AS_BLANK and still asked Nextcloud's layout to print its script
// and its data; with no layout, the frame served an empty div. A browser test
// would catch it, a template test catches it on every run.

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	initialAnswers,
	readEmbedConfig,
	submissionErrors,
	submitAnswers,
} from '../src/embed/frame.js'
import strings, {
	createEmbedTranslator,
	REFUSAL_FALLBACK_KEY,
	REFUSAL_KEYS,
	refusalKey,
} from '../src/embed/strings.js'
import { EMBED_REFUSALS, refusalSentence } from '../src/shared/embedCopy.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const nl = createEmbedTranslator('nl')
const en = createEmbedTranslator('en-GB')

/**
 * A document with a config block holding `text`.
 *
 * @param {string|null} text The block's content, or null for no block.
 * @return {object} The document.
 */
function docWith(text) {
	return {
		getElementById: (id) =>
			id === 'portaliq-embed-config' && text !== null
				? { textContent: text }
				: null,
	}
}

test('the template owns its document and loads the embed entry, not the portal', () => {
	// The code only: the header comment explains what the template used to call.
	const template = readFileSync(join(ROOT, 'templates', 'embed.php'), 'utf8')
		.split('\n')
		.filter((line) => !line.trim().startsWith('//'))
		.join('\n')

	assert.match(template, /<!DOCTYPE html>/)
	assert.match(template, /emit_script_tag\(\$bundleUrl\)/)
	assert.match(template, /'-embed\.js'/)
	assert.match(template, /id="portaliq-embed-config"/)
	assert.match(template, /id="portaliq-embed"/)
	assert.doesNotMatch(template, /Util::addScript/)
	assert.doesNotMatch(template, /provideInitialState/)
	// No other bundle: not the site's, not the retired portal's.
	assert.doesNotMatch(template, /-(portal|site)\.js/)
})

test('the frame has a skip link to a target the template itself renders (WCAG 2.4.1)', () => {
	const template = readFileSync(join(ROOT, 'templates', 'embed.php'), 'utf8')
		.split('\n')
		.filter((line) => !line.trim().startsWith('//'))
		.join('\n')

	const link = template.match(/<a\b[^>]*\bid="skip-link"[^>]*>([\s\S]*?)<\/a>/)
	assert.ok(link, 'the document carries an <a id="skip-link">')
	const href = link[0].match(/href="#([\w-]+)"/)
	assert.ok(href, 'the skip link points at a fragment')
	// The target is in the template, not rendered by the bundle: the link works
	// before the frame has booted and when it never boots.
	const target = new RegExp(`<main\\b[^>]*\\bid="${href[1]}"[^>]*\\btabindex="-1"`)
	assert.match(template, target, 'the fragment is a focusable <main> in the template')
	assert.ok(
		template.indexOf(link[0]) < template.indexOf(`id="${href[1]}"`),
		'the link comes before the content it skips to',
	)
	assert.match(template, /id="portaliq-embed"/, 'the mount point stays inside the document')
	// The link text is in the page language.
	assert.match(link[1], /p\(\$skipLabel\)/)
	assert.match(template, /\$skipLabel = 'Direct naar de inhoud';/)
	assert.match(template, /\$skipLabel = 'Skip to content';/)
})

test('the embed entry pulls in neither the site nor the React portal', () => {
	const dir = join(ROOT, 'src', 'embed')
	for (const file of readdirSync(dir)) {
		const source = readFileSync(join(dir, file), 'utf8')
		assert.doesNotMatch(
			source,
			/from '\.\.\/(site|portal)\//,
			`${file} imports only src/embed and src/shared`,
		)
	}
	const webpack = readFileSync(join(ROOT, 'webpack.site.js'), 'utf8')
	assert.match(
		webpack,
		/'portaliq-embed': path\.join\(__dirname, 'src', 'embed', 'main\.js'\)/,
	)
})

test('the boot data is read from the JSON block', () => {
	const config = readEmbedConfig(
		docWith(
			JSON.stringify({
				payload: {
					route: 'aanvragen/verhuizing',
					fields: [{ name: 'postcode' }],
				},
				submitUrl: '/index.php/apps/portaliq/portal/api/embed/submit',
				locale: 'en',
			}),
		),
	)

	assert.equal(config.payload.route, 'aanvragen/verhuizing')
	assert.equal(
		config.submitUrl,
		'/index.php/apps/portaliq/portal/api/embed/submit',
	)
	assert.equal(config.locale, 'en')
})

// A broken or missing block must still produce words, never a blank frame.
test('a missing or broken block gives a refusal, not an empty frame', () => {
	for (const doc of [
		docWith(null),
		docWith('{not json'),
		docWith('[]'),
		docWith('{"payload":null}'),
		null,
	]) {
		const config = readEmbedConfig(doc)
		assert.ok(refusalKey(config.payload), 'there is a refusal to say')
		assert.equal(config.locale, 'nl')
	}
})

test('every Dutch refusal is word for word what embedCopy.js says', () => {
	for (const reason of Object.keys(EMBED_REFUSALS)) {
		assert.equal(
			nl(refusalKey({ refused: reason })),
			refusalSentence({ refused: reason }),
		)
	}
	assert.equal(
		nl(refusalKey({ refused: 'added_later' })),
		refusalSentence({ refused: 'added_later' }),
	)
	assert.deepEqual(
		Object.keys(REFUSAL_KEYS).sort(),
		Object.keys(EMBED_REFUSALS).sort(),
	)
	assert.equal(refusalKey({ fields: [] }), null)
	assert.equal(refusalKey({ refused: 'added_later' }), REFUSAL_FALLBACK_KEY)
})

test('every string the frame uses exists in Dutch and English, without em-dashes', () => {
	const dir = join(ROOT, 'src', 'embed')
	const keys = new Set(Object.values(REFUSAL_KEYS).concat(REFUSAL_FALLBACK_KEY))
	for (const file of readdirSync(dir).filter((f) => f.endsWith('.vue'))) {
		const source = readFileSync(join(dir, file), 'utf8')
		for (const match of source.matchAll(/\bt\('([^']+)'/g)) {
			keys.add(match[1])
		}
	}
	assert.ok(keys.size >= 10)
	for (const locale of ['nl', 'en']) {
		for (const key of keys) {
			assert.ok(strings[locale][key], `${locale} has "${key}"`)
			assert.doesNotMatch(strings[locale][key], /—/)
		}
	}
	assert.equal(
		en('Your reference: {reference}', { reference: 'Z-1' }),
		'Your reference: Z-1',
	)
	assert.equal(
		nl('Your reference: {reference}', { reference: 'Z-1' }),
		'Uw kenmerk: Z-1',
	)
	assert.equal(
		createEmbedTranslator('de')('Send'),
		'Versturen',
		'an unknown language falls back to Dutch',
	)
})

test('a preset is sent even when the visitor leaves it alone', () => {
	assert.deepEqual(
		initialAnswers([
			{ name: 'postcode', preset: '5038AB' },
			{ name: 'huisnummer' },
			{ name: 'leeg', preset: '' },
		]),
		{ postcode: '5038AB' },
	)
	assert.deepEqual(initialAnswers(undefined), {})
})

test('the answers go to the submit route with their form page', async () => {
	const calls = []
	const fetchImpl = async (url, options) => {
		calls.push([url, options])
		return { json: async () => ({ reference: 'ZK-1' }) }
	}

	const result = await submitAnswers({
		submitUrl: '/submit',
		route: 'a/b',
		answers: { postcode: '5038AB' },
		fetchImpl,
	})

	assert.deepEqual(result, { reference: 'ZK-1' })
	assert.equal(calls[0][0], '/submit')
	assert.equal(calls[0][1].method, 'POST')
	assert.deepEqual(JSON.parse(calls[0][1].body), {
		route: 'a/b',
		answers: { postcode: '5038AB' },
	})
})

// The server sends a map; the React frame mapped over it as a list and threw.
test("the server's errors are shown per field and as a list", () => {
	assert.deepEqual(
		submissionErrors({ errors: { postcode: 'Dit antwoord is verplicht.' } }),
		{
			fields: { postcode: 'Dit antwoord is verplicht.' },
			messages: ['Dit antwoord is verplicht.'],
		},
	)
	assert.deepEqual(submissionErrors({ errors: ['Fout'] }), {
		fields: {},
		messages: ['Fout'],
	})
	assert.deepEqual(submissionErrors({ error: 'too_many_requests' }), {
		fields: {},
		messages: [],
	})
})

test('a refused frame renders its sentence and no form', async () => {
	const html = await renderSfc('src/embed/EmbedFrame.vue', {
		payload: {
			refused: 'identified_intake',
			portalUrl: 'https://portaal.example/site?route=a',
		},
		t: nl,
	})

	assert.match(html, /data-testid="embed-refusal"/)
	assert.match(html, /Voor deze aanvraag moet u eerst inloggen\./)
	assert.match(html, /href="https:\/\/portaal\.example\/site\?route=a"/)
	assert.match(html, /Ga verder op ons eigen portaal/)
	assert.doesNotMatch(html, /embed-form/)
})

test('a frame with a form renders each field with its label', async () => {
	const html = await renderSfc('src/embed/EmbedFrame.vue', {
		payload: {
			route: 'a',
			fields: [
				{
					name: 'postcode',
					label: 'Postcode',
					required: true,
					preset: '5038AB',
				},
				{ name: 'huisnummer' },
			],
		},
		t: en,
	})

	assert.match(html, /data-testid="embed-form"/)
	assert.match(
		html,
		/<label class="utrecht-form-label" for="embed-postcode">Postcode<\/label>/,
	)
	assert.match(
		html,
		/<label class="utrecht-form-label" for="embed-huisnummer">huisnummer<\/label>/,
	)
	assert.match(html, /id="embed-postcode"[^>]*value="5038AB"/)
	assert.match(html, /data-testid="embed-submit"[^>]*>\s*Send/)
	assert.doesNotMatch(html, /embed-refusal/)
})

test('a sent form shows the reference; a refused one says why', async () => {
	const component = await loadSfc('src/embed/EmbedFrame.vue')
	const instance = (answer) => ({
		...component.data(),
		payload: { route: 'a' },
		submitUrl: '',
		t: nl,
		submitter: async () => answer(),
	})

	const ok = instance(() => ({ reference: 'ZK-2026-1' }))
	await component.methods.send.call(ok, { postcode: '5038AB' })
	assert.equal(ok.reference, 'ZK-2026-1')
	assert.equal(ok.busy, false)

	const refused = instance(() => ({
		errors: { postcode: 'Dit antwoord is verplicht.' },
	}))
	await component.methods.send.call(refused, {})
	assert.equal(refused.reference, null)
	assert.deepEqual(refused.fieldErrors, { postcode: 'Dit antwoord is verplicht.' })
	assert.deepEqual(refused.messages, ['Dit antwoord is verplicht.'])

	const silent = instance(() => ({ error: 'not_accepted' }))
	await component.methods.send.call(silent, {})
	assert.deepEqual(silent.messages, ['Uw aanvraag kon niet worden verstuurd.'])

	const offline = instance(() => {
		throw new Error('offline')
	})
	await component.methods.send.call(offline, {})
	assert.deepEqual(offline.messages, [
		'Uw aanvraag kon niet worden verstuurd. Probeer het later opnieuw.',
	])
	assert.equal(offline.busy, false)
})
