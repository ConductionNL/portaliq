#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// product-finder.spec.mjs: the product finder narrows the products with yes
// or no questions, skips a question that cannot change the outcome, takes a
// changed answer back into account, and sends no answer anywhere
// (public-faq-and-product-finder).
//
// Usage:
//   node --test tests/product-finder.spec.mjs
//
// @spec openspec/changes/public-faq-and-product-finder/specs/portaliq-cms/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { minutesLeft, planFinder, pruneAnswers } from '../src/site/lib/finderPlan.js'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const ROUTES = Array.from({ length: 12 }, (_, i) => `/p/${i + 1}`)
const FINDER = {
	id: 'f1',
	title: 'Welke vergunning past bij u?',
	intro: 'Beantwoord een paar vragen.',
	products: ROUTES.map((route, i) => ({ route, title: `Product ${i + 1}` })),
	questions: [
		{ id: 'centre', text: 'Woont u in de binnenstad?', excludesOnNo: ROUTES.slice(0, 8), excludesOnYes: [] },
		// Rules out only products the first question already ruled out when it is answered Nee.
		{ id: 'redundant', text: 'Hebt u een binnenstadspas?', excludesOnYes: ROUTES.slice(0, 2), excludesOnNo: [] },
		{ id: 'car', text: 'Hebt u een auto?', excludesOnNo: ['/p/9'], excludesOnYes: ['/p/10'] },
	],
}

test('an answer rules out the products the question lists for it', () => {
	const none = planFinder(FINDER, {})
	assert.equal(none.remaining.length, 12)
	assert.equal(none.excluded.length, 0)

	const nee = planFinder(FINDER, { centre: 'no' })
	assert.equal(nee.remaining.length, 4)
	assert.equal(nee.excluded.length, 8)
})

test('a question that cannot change the remaining set is skipped', () => {
	// Before any answer the second question could still rule out products.
	assert.deepEqual(planFinder(FINDER, {}).steps.map((s) => s.question.id), ['centre', 'redundant', 'car'])
	// After Nee on the first, the second would only rule out what is already gone.
	const plan = planFinder(FINDER, { centre: 'no' })
	assert.deepEqual(plan.steps.map((s) => s.question.id), ['centre', 'car'])
	assert.equal(plan.current, 1)
	assert.equal(plan.done, false)
})

test('changing an earlier answer computes everything again from all current answers', () => {
	const answers = { centre: 'no', car: 'yes' }
	const before = planFinder(FINDER, answers)
	assert.deepEqual(before.remaining.map((p) => p.route), ['/p/9', '/p/11', '/p/12'])
	assert.equal(before.done, true)

	const changed = planFinder(FINDER, { ...answers, centre: 'yes' })
	assert.equal(changed.remaining.length, 11, 'only the car answer rules one out now')
	// And a stale answer to a question that no longer counts is dropped.
	assert.deepEqual(pruneAnswers(FINDER, { centre: 'no', redundant: 'yes', car: 'no' }), { centre: 'no', car: 'no' })
})

test('the time left is 20 seconds a question, at least a minute', () => {
	assert.equal(minutesLeft(1), 1)
	assert.equal(minutesLeft(3), 1)
	assert.equal(minutesLeft(4), 2)
})

test('the page shows the position, the count, the folded list and the promise to keep nothing', async () => {
	const component = await loadSfc('src/site/widgets/nlProductFinder/NlProductFinder.vue', {
		'@conduction/nextcloud-vue': 'export const cnRenderMarkdown = (s) => s\n',
	})
	const html = await renderComponent(component, { initialFinder: FINDER })
	assert.match(html, /Vraag 1 van 3/)
	assert.match(html, /Nog ongeveer 1 minuut/)
	assert.match(html, /Nog 12 van de 12 producten passen bij uw antwoorden/)
	assert.match(html, /Wij bewaren uw antwoorden niet\. Sluit u deze pagina, dan begint u de volgende keer opnieuw\./)
	assert.match(html, /data-testid="nl-finder-yes"/)
	assert.doesNotMatch(html, /nl-finder-previous/, 'nothing to take back before the first answer')

	// The component's own answer handler, run against a stand-in `this`.
	const vm = {
		definition: FINDER,
		answers: {},
		editingId: '',
		get asking() {
			return component.computed.asking.call(this)
		},
		get plan() {
			return planFinder(FINDER, this.answers)
		},
		get editing() {
			return component.computed.editing.call(this)
		},
	}
	component.methods.answer.call(vm, 'no')
	assert.deepEqual(vm.answers, { centre: 'no' })
	assert.equal(vm.asking.question.id, 'car', 'the redundant question is skipped')
	const countText = component.computed.countText.call({
		plan: vm.plan,
		say: (key) => component.methods.say.call({}, key),
	})
	assert.equal(countText, 'Nog 4 van de 12 producten passen bij uw antwoorden')
	component.methods.restart.call(vm)
	assert.deepEqual(vm.answers, {})
})

test('no request carries an answer, and nothing is stored', () => {
	const sources = [
		'../src/site/widgets/nlProductFinder/NlProductFinder.vue',
		'../src/site/lib/finderPlan.js',
	].map((file) => readFileSync(new URL(file, import.meta.url), 'utf8'))
	for (const source of sources) {
		assert.doesNotMatch(source, /\b(XMLHttpRequest|sendBeacon|localStorage|sessionStorage|indexedDB|document\.cookie)\b/)
	}
	const [component, plan] = sources
	assert.doesNotMatch(plan, /\bfetch\b/, 'the evaluation never touches the network')
	// The one read the component makes names the portal and the finder, nothing else.
	assert.match(component, /fetchFinder\(this\.portal, this\.finder\)/)
	const client = readFileSync(new URL('../src/site/lib/publicFaq.js', import.meta.url), 'utf8')
	assert.match(client, /export async function fetchFinder\(portal, id\)/)
	assert.doesNotMatch(client.replace(/\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, ''), /answers?/i, 'the client has no parameter for an answer')
})
