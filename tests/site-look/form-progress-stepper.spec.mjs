#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-progress-stepper.spec.mjs: a form in steps shows its progress as the
// Mijn Zuiddrecht form boards draw it (WooVerzoek, WooVerzoekGegevens,
// WooVerzoekControleren, FormulierFouten): "Stap 1 van 3: Uw verzoek" above
// one row of steps on a grey band, a 36px marker per step, the title over the
// state in words, a tick on a done step and a line between the steps.
//
// Usage:
//   node --test tests/site-look/form-progress-stepper.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { mountSfc } from '../support/mount-sfc.mjs'

const FILE = 'src/site/components/forms/FormProgress.vue'
const STEPS = [
	{ id: 'verzoek', title: 'Uw verzoek' },
	{ id: 'gegevens', title: 'Uw gegevens' },
	{ id: 'controle', title: 'Controleren en versturen' },
]

/**
 * The component's style block, whitespace collapsed.
 *
 * @return {string} The CSS.
 */
function style() {
	const source = readFileSync(new URL(`../../${FILE}`, import.meta.url), 'utf8')
	const css = source.slice(source.indexOf('<style'))
	return css.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/\s+/g, ' ')
}

/**
 * The CSS inside the wide-screen media query.
 *
 * @return {string} The rules.
 */
function wide() {
	const css = style()
	const start = css.indexOf('@media (min-width: 48rem)')
	assert.ok(start >= 0, 'a wide-screen rule exists')
	return css.slice(start)
}

test('the short line names the current step', async () => {
	const view = await mountSfc(FILE, {
		steps: STEPS,
		current: 1,
		shortPattern: 'Stap {n} van {m}: {title}',
	})
	assert.equal(
		view.textOf(view.find('form-progress-short')),
		'Stap 2 van 3: Uw gegevens',
	)
})

test('a pattern without a title still reads "Stap n van m"', async () => {
	const view = await mountSfc(FILE, { steps: STEPS, current: 0 })
	assert.equal(view.textOf(view.find('form-progress-short')), 'Stap 1 van 3')
})

test('a done step shows a tick, the others their number, all with words', async () => {
	const view = await mountSfc(FILE, { steps: STEPS, current: 1 })
	assert.ok(view.find('form-progress-tick-0') !== null, 'tick on the done step')
	assert.ok(view.find('form-progress-tick-1') === null)
	assert.match(view.textOf(view.find('form-progress-step-0')), /Klaar/)
	assert.match(
		view.textOf(view.find('form-progress-step-1')),
		/2\s*Uw gegevens\s*Huidige stap/,
	)
	assert.match(
		view.textOf(view.find('form-progress-step-2')),
		/3\s*Controleren en versturen\s*Nog te doen/,
	)
	assert.equal(view.find('form-progress-step-1').props['aria-current'], 'step')
})

test('on a wide screen the steps are one row on the surface band', () => {
	const css = wide()
	assert.match(css, /\.pq-form-progress__list \{[^}]*display: flex/)
	assert.match(
		css,
		/\.pq-form-progress__list \{[^}]*background: var\(\s*--nldesign-color-surface/,
	)
	assert.match(
		css,
		/\.pq-form-progress__item:not\(:last-child\)::after \{[^}]*content: ''/,
	)
	assert.doesNotMatch(
		css,
		/\.pq-form-progress__short[^{]*\{[^}]*display: none/,
		'the short line stays on a wide screen',
	)
})

test('markers read the theme step tokens: size, current ring, done fill', () => {
	const css = style()
	assert.match(
		css,
		/\.pq-form-progress__marker \{[^}]*inline-size: var\(\s*--nldesign-website-step-marker-size/,
	)
	assert.match(
		css,
		/\.pq-form-progress__item--current \.pq-form-progress__marker \{[^}]*border-color: var\(\s*--nldesign-website-step-current-color/,
	)
	assert.match(
		css,
		/\.pq-form-progress__item--done \.pq-form-progress__marker \{[^}]*background: var\(\s*--nldesign-website-step-done-color/,
	)
})

test('steps set as the Woo boards draw them (WooVerzoek, WooVerzoekGegevens)', () => {
	const css = style()
	assert.match(css, /\.pq-form-progress__title \{ font-weight: 500; \}/)
	assert.match(
		css,
		/\.pq-form-progress__item--current \.pq-form-progress__title \{ font-weight: var\(--utrecht-typography-weight-scale-bold, 700\); \}/,
	)
	assert.match(
		css,
		/\.pq-form-progress__item--todo \.pq-form-progress__title \{ color: var\(--nldesign-color-text-muted/,
	)
	assert.match(
		css,
		/\.pq-form-progress__item--current \.pq-form-progress__marker \{ border-width: 3px;/,
	)
	assert.match(css, /\.pq-form-progress__state \{[^}]*font-size: 0\.875rem/)
	const band = wide()
	assert.match(band, /\.pq-form-progress__list \{[^}]*padding: 1rem 1\.25rem/)
	assert.match(
		band,
		/\.pq-form-progress__item:not\(:last-child\)::after \{[^}]*block-size: 3px/,
	)
})
