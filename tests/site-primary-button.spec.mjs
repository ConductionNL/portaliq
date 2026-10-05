#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-primary-button.spec.mjs: a form's submit is a primary button, and it
// still looks like one on a portal without the theme app.
//
// WHY BOTH HALVES. The submit of the absence form ("Afwezigheid melden") read
// as a line of body text on the recording instance. The markup was right: a
// `<button type="submit">` with `utrecht-button--primary-action`. What was
// missing is everything the Utrecht button reads from a token, because the
// component declares no fallback of its own and that instance has no theme
// app, so no token layer. A markup test alone stays green on that bug, so the
// second half reads the site's own stylesheet and asserts the baseline that
// makes the class mean something without a theme.
//
// Usage:
//   node --test tests/site-primary-button.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { mountSfc } from './support/mount-sfc.mjs'

const FORM = 'src/site/components/c/SchemaForm.vue'
const THEME_CSS = new URL('../css/site-theme.css', import.meta.url)
const TEMPLATE = new URL('../templates/site.php', import.meta.url)

/**
 * The stylesheet without comments, so prose that names a value is not read as
 * the value.
 *
 * @param {string} css The stylesheet.
 * @return {string} The same, without comments.
 */
function withoutComments(css) {
	return css.replace(/\/\*[\s\S]*?\*\//g, ' ')
}

/**
 * The declarations of the first rule whose selector is exactly `selector`.
 *
 * @param {string} css The stylesheet, without comments.
 * @param {string} selector The selector.
 * @return {string} The rule's body, whitespace collapsed, or ''.
 */
function ruleBody(css, selector) {
	const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	const match = css.match(new RegExp(`(?:^|})\\s*${escaped}\\s*{([^}]*)}`))
	return match ? match[1].replace(/\s+/g, ' ').trim() : ''
}

/**
 * One declaration's value from a rule body.
 *
 * @param {string} body The rule body.
 * @param {string} property The property.
 * @return {string} Its value, or ''.
 */
function valueOf(body, property) {
	const escaped = property.replace(/[-]/g, '\\-')
	const match = body.match(new RegExp(`(?:^|;)\\s*${escaped}\\s*:\\s*([^;]+)`))
	return match ? match[1].trim() : ''
}

test('the submit of an action form is a submit button with the primary button class', async () => {
	const action = {
		id: 'createExcuseRequest',
		type: 'create',
		label: 'Afwezigheid van uw kind melden',
		submitLabel: 'Afwezigheid melden',
		fields: ['reason'],
		fieldConfigs: { reason: { label: 'Reden' } },
	}
	const api = {
		async fetchOptions() {
			return []
		},
		async createObject(a, body) {
			return { ok: true, object: { id: 'new-1', ...body } }
		},
	}
	const form = await mountSfc(FORM, { action, api })
	await form.flush()

	const submit = form.find('schema-form-submit')
	assert.ok(submit, 'the form renders its submit')
	assert.equal(submit.tag, 'button')
	assert.equal(submit.props.type, 'submit')
	const classes = String(submit.props.class).split(/\s+/)
	assert.ok(
		classes.includes('utrecht-button'),
		`utrecht-button in "${submit.props.class}"`,
	)
	assert.ok(
		classes.includes('utrecht-button--primary-action'),
		`utrecht-button--primary-action in "${submit.props.class}"`,
	)
	assert.equal(form.textOf(submit), 'Afwezigheid melden')
})

test('without a theme the primary button keeps a filled background, a contrasting colour and padding', () => {
	const css = withoutComments(readFileSync(THEME_CSS, 'utf8'))
	const body = ruleBody(css, '.pq-site .utrecht-button--primary-action')
	assert.notEqual(
		body,
		'',
		'site-theme.css has a baseline rule for the primary action',
	)

	// The theme's token first, so a themed portal renders as before; a system
	// colour last, so an unthemed one has the page's own contrast.
	const background = valueOf(body, '--_utrecht-button-appearance-background-color')
	assert.match(
		background,
		/^var\(\s*--utrecht-button-primary-action-background-color\b/,
	)
	assert.match(background, /\bCanvasText\s*\)\s*\)$/)

	const colour = valueOf(body, '--_utrecht-button-appearance-color')
	assert.match(colour, /^var\(\s*--utrecht-button-primary-action-color\b/)
	assert.match(colour, /\bCanvas\s*\)\s*\)$/)

	for (const side of ['block', 'inline']) {
		const padding = valueOf(body, `padding-${side}`)
		assert.match(
			padding,
			new RegExp(
				`^var\\(--utrecht-button-padding-${side}-start, [^)]+\\) var\\(--utrecht-button-padding-${side}-end, [^)]+\\)$`,
			),
			`padding-${side} reads the theme's token and falls back to a size`,
		)
	}
})

test('the primary button has a visible focus ring without a theme', () => {
	const css = withoutComments(readFileSync(THEME_CSS, 'utf8'))
	const body = ruleBody(
		css,
		'.pq-site .utrecht-button--primary-action:focus-visible',
	)
	assert.match(
		valueOf(body, 'outline-style'),
		/^var\(--utrecht-focus-outline-style, solid\)$/,
	)
	assert.match(
		valueOf(body, 'outline-width'),
		/^var\(--utrecht-focus-outline-width, \d+px\)$/,
	)
	assert.match(
		valueOf(body, 'outline-color'),
		/^var\(--utrecht-focus-outline-color, CanvasText\)$/,
	)
})

test('without a theme a secondary button in a form keeps an outline and padding', () => {
	const css = withoutComments(readFileSync(THEME_CSS, 'utf8'))
	const body = ruleBody(
		css,
		'.pq-site .pq-schema-form .utrecht-button--secondary-action',
	)
	assert.notEqual(body, '', 'site-theme.css has a baseline for a form secondary')
	assert.match(
		valueOf(body, '--_utrecht-button-appearance-border-color'),
		/^var\( --utrecht-button-secondary-action-border-color, .*\bcurrentcolor\s*\)\s*\)$/,
	)
	assert.match(
		valueOf(body, '--_utrecht-button-appearance-border-width'),
		/^var\( --utrecht-button-secondary-action-border-width, .*\b\d+px\s*\)\s*\)$/,
	)
	assert.match(
		valueOf(body, 'padding-inline'),
		/^var\(--utrecht-button-padding-inline-start, /,
	)
})

test('the baseline is token references and system colours only', () => {
	const css = withoutComments(readFileSync(THEME_CSS, 'utf8'))
	const start = css.indexOf('.pq-site .utrecht-button--primary-action')
	assert.notEqual(start, -1)
	const block = css.slice(start)
	assert.doesNotMatch(block, /#[0-9a-f]{3,8}\b/i, 'no hex colour')
	assert.doesNotMatch(block, /\b(?:rgba?|hsla?)\(/i, 'no rgb() or hsl()')
})

test('site-theme.css is linked whether or not a theme app is installed', () => {
	const template = readFileSync(TEMPLATE, 'utf8')
	const link = template.indexOf(
		"$stylesheets[] = $asset($appId, 'css/site-theme.css');",
	)
	assert.notEqual(link, -1, 'the template links site-theme.css')
	const guard = template.indexOf(
		"if ($themeStylesheet !== '' && $themeApp !== null) {",
	)
	const guardEnd = template.indexOf('}', guard)
	assert.ok(link < guard || link > guardEnd, 'the link is outside the theme guard')
})
