#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// contact-form.spec.mjs: a resident asks a question without a case
// (contact-page-question-form-and-not-found). Signed out, the block posts
// nothing and offers the sign-in routes. Signed in, it finds the create action
// among the resident's contributions and only then shows the form; a missing
// action says so. The block is registered where the page renders blocks.
//
// Usage:
//   node --test tests/contact-form.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const FORM = 'src/site/components/ContactForm.vue'
const ACTION = {
	id: 'askQuestion',
	type: 'create',
	register: 'pipelinq',
	schema: 'ticket',
	fields: ['title', 'description'],
}

test('signed out: no form, the sign-in routes, and nothing is read', async () => {
	let calls = 0
	const html = await renderSfc(FORM, {
		app: 'pipelinq',
		action: 'askQuestion',
		signedIn: false,
		ways: [{ id: 'digid', label: 'Inloggen met DigiD', href: '/inloggen' }],
		locale: 'nl',
		apiOverride: { getContributions: async () => { calls++ } },
	})
	assert.match(html, /data-testid="contact-form-signed-out"/)
	assert.match(html, /Log in om een vraag te stellen/)
	assert.match(html, /Inloggen met DigiD/)
	assert.doesNotMatch(html, /schema-form/)
	assert.equal(calls, 0)
})

test('load finds the create action of the named app and shows the form', async () => {
	const screen = await loadSfc(FORM)
	const vm = (contributions) => ({
		app: 'pipelinq',
		action: 'askQuestion',
		state: 'idle',
		resolved: null,
		api: { getContributions: async () => ({ contributions }) },
	})
	const ready = vm([{ app: 'other', actions: [ACTION] }, { app: 'pipelinq', actions: [ACTION] }])
	await screen.methods.load.call(ready)
	assert.equal(ready.state, 'ready')
	assert.equal(ready.resolved.id, 'askQuestion')

	for (const contributions of [
		[{ app: 'pipelinq', actions: [{ ...ACTION, type: 'update' }] }],
		[{ app: 'other', actions: [ACTION] }],
		[],
	]) {
		const missing = vm(contributions)
		await screen.methods.load.call(missing)
		assert.equal(missing.state, 'missing')
	}

	const broken = vm([])
	broken.api = { getContributions: async () => { throw new Error('down') } }
	await screen.methods.load.call(broken)
	assert.equal(broken.state, 'missing')
})

test('the block says what happened after a question was sent', async () => {
	const html = await renderSfc(FORM, { signedIn: true, locale: 'nl', apiOverride: {} })
	assert.doesNotMatch(html, /contact-form-sent/, 'nothing is claimed before a send')
})

test('the form reads in the page language, and its optional mark is spaced', async () => {
	const screen = await loadSfc(FORM)
	const dutch = screen.computed.translate.call({ locale: 'nl' })
	assert.equal(
		dutch('A field without "optional" must be filled in.'),
		'Een veld zonder "niet verplicht" moet u invullen.',
	)
	assert.equal(dutch('(optional)'), '(niet verplicht)')
	const english = screen.computed.translate.call({ locale: 'en' })
	assert.equal(english('(optional)'), '(optional)')
	assert.match(readFileSync(FORM, 'utf8'), /:t="translate"/, 'the schema form gets the translator')

	const label = await renderSfc('src/site/components/forms/FieldShell.vue', {
		id: 'q',
		label: 'Uw vraag',
		required: false,
		optionalLabel: dutch('(optional)'),
	})
	assert.match(label, /Uw vraag<span[^>]*> \(niet verplicht\)<\/span>/)
})

test('contactForm is a registered block the host feeds portal, session and ways', () => {
	const grid = readFileSync('src/site/components/WidgetGrid.vue', 'utf8')
	assert.match(grid, /contactForm: ContactForm/)
	assert.match(grid, /widgetKey === 'contactForm'\) \{\s*return \{\s*\.\.\.props,\s*portal: this\.portal,\s*signedIn: this\.signedIn === true/)
	const catalogue = readFileSync('src/lib/pageWidgetCatalogue.js', 'utf8')
	assert.match(catalogue, /contactForm: \['portal', 'signedIn', 'ways', 'apiOverride'\]/)
})

test('the example contact page binds to the question action pipelinq contributes', () => {
	const site = JSON.parse(readFileSync('lib/Settings/sites/zuiddrecht.json', 'utf8'))
	const page = site.pages.find((entry) => entry.route === '/contact/vraag')
	const block = page.body.widgets.find((widget) => widget.widgetKey === 'contactForm')
	assert.equal(block.props.app, 'pipelinq')
	assert.equal(block.props.action, 'askQuestion')
})
